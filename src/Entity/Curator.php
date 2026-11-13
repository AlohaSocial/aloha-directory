<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CuratorRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\EquatableInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: CuratorRepository::class)]
#[ORM\Table(name: 'curator')]
#[ORM\UniqueConstraint(name: 'uniq_curator_email', columns: ['email'])]
class Curator implements UserInterface, PasswordAuthenticatedUserInterface, EquatableInterface
{
    public const ROLE_CURATOR = 'ROLE_CURATOR';
    public const ROLE_ADMIN = 'ROLE_ADMIN';

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\Column(length: 180)]
    public string $email;

    #[ORM\Column(length: 100)]
    public string $displayName;

    #[ORM\Column(length: 255)]
    public string $passwordHash = '';

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    public array $roles = [self::ROLE_CURATOR];

    #[ORM\Column]
    public bool $active = true;

    /**
     * Random WebAuthn user handle (base64url of 32 bytes). Passkeys carry it instead of the e-mail
     * address, so the address never ends up on an authenticator.
     */
    #[ORM\Column(length: 64, nullable: true, unique: true)]
    public ?string $webauthnUserHandle = null;

    /** @var list<string> keyed hashes of unused recovery codes */
    #[ORM\Column(type: Types::JSON)]
    public array $recoveryCodes = [];

    #[ORM\Column]
    public \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $lastLoginAt = null;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $passwordChangedAt = null;

    /** Bumped on password or TOTP change; invalidates other sessions. */
    #[ORM\Column(options: ['default' => 1])]
    public int $credentialsVersion = 1;

    public function __construct(string $email, string $displayName, \DateTimeImmutable $now)
    {
        $this->email = mb_strtolower($email);
        $this->displayName = $displayName;
        $this->createdAt = $now;
    }

    public function getUserIdentifier(): string
    {
        if ('' === $this->email) {
            throw new \LogicException('A curator always has an e-mail address.');
        }

        return $this->email;
    }

    public function getRoles(): array
    {
        return array_values(array_unique([...$this->roles, self::ROLE_CURATOR]));
    }

    public function isAdmin(): bool
    {
        return \in_array(self::ROLE_ADMIN, $this->roles, true);
    }

    public function getPassword(): string
    {
        return $this->passwordHash;
    }

    public function eraseCredentials(): void
    {
    }

    /**
     * The session keeps only the id, the e-mail and the credentials version; the password hash and
     * TOTP seed are never written to session storage.
     *
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        return ['id' => $this->id, 'email' => $this->email, 'credentialsVersion' => $this->credentialsVersion];
    }

    /**
     * @param array<string, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        $this->id = $data['id'];
        $this->email = $data['email'];
        $this->credentialsVersion = $data['credentialsVersion'];
    }

    /**
     * Called with the freshly loaded user on every request: a deactivated curator, or a change of
     * password or TOTP device, ends all existing sessions.
     */
    public function isEqualTo(UserInterface $user): bool
    {
        return $user instanceof self
            && $user->id === $this->id
            && $user->email === $this->email
            && $user->credentialsVersion === $this->credentialsVersion
            && $user->active;
    }
}
