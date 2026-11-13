<?php

declare(strict_types=1);

namespace App\Security;

use App\Api\RateLimits;
use App\Curation\CurationException;
use App\Entity\Curator;
use App\Entity\CuratorInvite;
use App\Entity\CuratorPasskey;
use App\Repository\CuratorRepository;
use App\Service\AuditLogger;
use App\Service\Notifier;
use App\Service\DirectorySettings;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Curator accounts (concept 13.4): invitations, password and passkey enrolment, recovery codes,
 * roles and deactivation. Every change is audited.
 */
final class CuratorAccounts
{
    public const MIN_PASSWORD_LENGTH = 12;
    public const ADD_PASSKEY_PURPOSE = 'account.add_passkey';
    public const ACCOUNT_PURPOSE = 'account.confirm';
    private const INVITE_TTL = '+72 hours';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CuratorRepository $curators,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly Passkeys $passkeys,
        private readonly SecondFactor $secondFactor,
        private readonly RecoveryCodes $recoveryCodes,
        private readonly AuditLogger $audit,
        private readonly Notifier $notifier,
        private readonly DirectorySettings $settings,
        private readonly ClockInterface $clock,
        private readonly RateLimits $limits,
    ) {
    }

    /**
     * @return string the invitation URL (shown once to the admin and e-mailed to the invitee)
     */
    public function invite(?Curator $admin, string $email, string $displayName, bool $asAdmin): string
    {
        $email = mb_strtolower(trim($email));
        if (false === filter_var($email, \FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 180) {
            throw new CurationException('Enter a valid e-mail address.');
        }
        $displayName = trim($displayName);
        if ('' === $displayName || mb_strlen($displayName) > 100) {
            throw new CurationException('Enter a name of at most 100 characters.');
        }
        if (null !== $this->curators->findOneByEmail($email)) {
            throw new CurationException('A curator with this e-mail address exists already.');
        }
        $token = bin2hex(random_bytes(32));
        $now = $this->clock->now();
        $this->em->persist(new CuratorInvite($email, $displayName, $asAdmin, hash('sha256', $token), $admin, $now, $now->modify(self::INVITE_TTL)));
        if (null !== $admin) {
            $this->audit->curator($admin, 'curator.invited', null, ['email' => $email, 'admin' => $asAdmin]);
        } else {
            $this->audit->system('curator.invited', null, ['email' => $email, 'admin' => $asAdmin]);
        }
        $this->em->flush();

        $url = $this->settings->publicUrl.'/console/invite/'.$token;
        $this->notifier->send($email, 'You are invited as a curator', "You were invited to curate the Aloha Directory.\n\nSet your password and register a passkey here within 72 hours:\n{$url}");

        return $url;
    }

    public function findInvite(string $token): ?CuratorInvite
    {
        if (1 !== preg_match('/\A[0-9a-f]{64}\z/', $token)) {
            return null;
        }
        $invite = $this->em->getRepository(CuratorInvite::class)->findOneBy(['tokenHash' => hash('sha256', $token)]);
        if (null === $invite || null !== $invite->usedAt || $invite->expiresAt < $this->clock->now()) {
            return null;
        }

        return $invite;
    }

    /**
     * Completes an invitation: password, a verified passkey, recovery codes.
     *
     * @param string $userHandle the WebAuthn user handle the creation options were issued for
     *
     * @return list<string> recovery codes, shown once
     */
    public function acceptInvite(CuratorInvite $invite, string $password, SessionInterface $session, string $userHandle, string $attestationJson, string $passkeyName): array
    {
        if (null !== $this->curators->findOneByEmail($invite->email)) {
            throw new CurationException('This invitation was already used.');
        }
        $this->assertPassword($password, $invite->email);

        $now = $this->clock->now();
        $curator = new Curator($invite->email, $invite->displayName, $now);
        $curator->roles = $invite->admin ? [Curator::ROLE_CURATOR, Curator::ROLE_ADMIN] : [Curator::ROLE_CURATOR];
        $curator->webauthnUserHandle = $userHandle;
        $passkey = $this->passkeys->register($session, self::invitePurpose($invite), $attestationJson, $curator, $passkeyName);
        $curator->passwordHash = $this->hasher->hashPassword($curator, $password);
        $curator->passwordChangedAt = $now;
        $codes = $this->recoveryCodes->regenerate($curator);
        $invite->usedAt = $now;
        $this->em->persist($curator);
        $this->em->persist($passkey);
        $this->em->flush();
        $this->audit->curator($curator, 'curator.enrolled', null, ['admin' => $invite->admin, 'passkey' => $passkey->name]);
        $this->em->flush();

        return $codes;
    }

    public static function invitePurpose(CuratorInvite $invite): string
    {
        return 'invite.'.substr($invite->tokenHash, 0, 16);
    }

    public function changePassword(Curator $curator, string $current, string $new, SecondFactorProof $proof): void
    {
        $this->assertPasswordAndSecondFactor($curator, $current, $proof);
        $this->assertPassword($new, $curator->email);
        $curator->passwordHash = $this->hasher->hashPassword($curator, $new);
        $curator->passwordChangedAt = $this->clock->now();
        ++$curator->credentialsVersion;
        $this->audit->curator($curator, 'curator.password_changed');
        $this->em->flush();
    }

    /**
     * Needs the password and an existing passkey (or a recovery code, if the device is lost), so a
     * stolen session plus the password is not enough to add an attacker's passkey.
     */
    public function addPasskey(Curator $curator, string $password, SecondFactorProof $proof, string $attestationJson, string $name): CuratorPasskey
    {
        $this->limitPasswordAttempts($curator);
        if (!$this->hasher->isPasswordValid($curator, $password)) {
            throw new CurationException('The password, passkey or recovery code is not correct.');
        }
        // The new passkey is verified first: a recovery code is used up by a successful check.
        $passkey = $this->passkeys->register($proof->session, self::ADD_PASSKEY_PURPOSE, $attestationJson, $curator, $name);
        if (!$this->secondFactor->check($curator, $proof->session, $proof->purpose, $proof->passkeyJson, $proof->recoveryCode)) {
            throw new CurationException('The password, passkey or recovery code is not correct.');
        }
        $this->em->persist($passkey);
        $this->audit->curator($curator, 'curator.passkey_added', null, ['name' => $passkey->name]);
        $this->em->flush();
        $this->notifier->send($curator->email, 'A passkey was added to your curator account',
            "The passkey \"{$passkey->name}\" was added to your Aloha Directory curator account.\n\nIf this was not you, sign in, remove it and change your password, and tell the other curators.");

        return $passkey;
    }

    public function removePasskey(Curator $curator, string $password, SecondFactorProof $proof, int $passkeyId): void
    {
        $passkeys = $this->passkeys->passkeysOf($curator);
        $target = null;
        foreach ($passkeys as $passkey) {
            if ($passkey->id === $passkeyId) {
                $target = $passkey;
            }
        }
        if (null === $target) {
            throw new CurationException('Unknown passkey.');
        }
        if (\count($passkeys) <= 1) {
            throw new CurationException('Add another passkey before you remove your last one.');
        }
        $this->assertPasswordAndSecondFactor($curator, $password, $proof);
        $this->em->remove($target);
        ++$curator->credentialsVersion;
        $this->audit->curator($curator, 'curator.passkey_removed', null, ['name' => $target->name]);
        $this->em->flush();
        $this->notifier->send($curator->email, 'A passkey was removed from your curator account',
            "The passkey \"{$target->name}\" was removed from your Aloha Directory curator account.\n\nIf this was not you, sign in and change your password, and tell the other curators.");
    }

    /**
     * Needs a passkey; a recovery code cannot be used to make new recovery codes.
     *
     * @return list<string>
     */
    public function regenerateRecoveryCodes(Curator $curator, string $password, SecondFactorProof $proof): array
    {
        $this->assertPasswordAndSecondFactor($curator, $password, new SecondFactorProof($proof->session, $proof->purpose, $proof->passkeyJson, ''));
        $codes = $this->recoveryCodes->regenerate($curator);
        $this->audit->curator($curator, 'curator.recovery_codes_regenerated');
        $this->em->flush();

        return $codes;
    }

    /**
     * A stolen session must not be a way to guess the password without limit.
     */
    private function limitPasswordAttempts(Curator $curator): void
    {
        if (!$this->limits->tryConsume('account_confirm', 'curator:'.$curator->id)) {
            throw new CurationException('Too many attempts. Please wait an hour and try again.');
        }
    }

    private function assertPasswordAndSecondFactor(Curator $curator, string $password, SecondFactorProof $proof): void
    {
        $this->limitPasswordAttempts($curator);
        // The second factor is only checked after the password, so recovery codes cannot be burnt.
        if (!$this->hasher->isPasswordValid($curator, $password)
            || !$this->secondFactor->check($curator, $proof->session, $proof->purpose, $proof->passkeyJson, $proof->recoveryCode)) {
            $this->em->flush(); // a used recovery code stays used
            throw new CurationException('The password, passkey or recovery code is not correct.');
        }
    }

    public function setActive(Curator $admin, Curator $curator, bool $active): void
    {
        if ($admin->id === $curator->id) {
            throw new CurationException('You cannot deactivate yourself.');
        }
        if (!$active && $curator->isAdmin() && $this->curators->countActiveAdmins() <= 1) {
            throw new CurationException('At least one active admin is required.');
        }
        $curator->active = $active;
        ++$curator->credentialsVersion;
        $this->audit->curator($admin, $active ? 'curator.activated' : 'curator.deactivated', null, ['curator' => $curator->email]);
        $this->em->flush();
    }

    public function setAdmin(Curator $admin, Curator $curator, bool $isAdmin): void
    {
        if ($admin->id === $curator->id && !$isAdmin) {
            throw new CurationException('You cannot remove your own admin role.');
        }
        $curator->roles = $isAdmin ? [Curator::ROLE_CURATOR, Curator::ROLE_ADMIN] : [Curator::ROLE_CURATOR];
        ++$curator->credentialsVersion;
        $this->audit->curator($admin, 'curator.role_changed', null, ['curator' => $curator->email, 'admin' => $isAdmin]);
        $this->em->flush();
    }

    private function assertPassword(string $password, string $email): void
    {
        if (mb_strlen($password) < self::MIN_PASSWORD_LENGTH || mb_strlen($password) > 4096) {
            throw new CurationException(\sprintf('The password must have at least %d characters.', self::MIN_PASSWORD_LENGTH));
        }
        if (str_contains(mb_strtolower($password), explode('@', $email)[0]) || 1 === preg_match('/\A(.)\1+\z/u', $password)) {
            throw new CurationException('Choose a password that does not contain your e-mail name or repeat one character.');
        }
    }
}
