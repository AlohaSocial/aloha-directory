<?php

declare(strict_types=1);

namespace App\Controller\Console;

use App\Curation\CurationException;
use App\Security\CuratorAccounts;
use App\Security\Passkeys;
use App\Security\SecondFactorProof;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Password, passkeys and recovery codes (concept 13.4). Every change asks for the password and a
 * passkey (or a recovery code) again; a changed password or a removed passkey ends all sessions.
 */
#[Route('/console/account')]
final class AccountController extends AbstractConsoleController
{
    public function __construct(
        private readonly CuratorAccounts $accounts,
        private readonly Passkeys $passkeys,
        private readonly TokenStorageInterface $tokens,
    ) {
    }

    /** Ends this session too (the credentials version already ends all other sessions). */
    private function signOut(Request $request, string $message): Response
    {
        $this->tokens->setToken(null);
        $request->getSession()->invalidate();
        $this->addFlash('success', $message);

        return $this->redirectToRoute('console_login');
    }

    #[Route('', name: 'console_account', methods: ['GET', 'POST'])]
    public function __invoke(Request $request): Response
    {
        $me = $this->curator();
        $codes = null;

        if ($request->isMethod('POST')) {
            $this->requireCsrf($request);
            $proof = SecondFactorProof::fromRequest($request, CuratorAccounts::ACCOUNT_PURPOSE);
            $password = (string) $request->request->get('password');
            try {
                switch ($request->request->get('form')) {
                    case 'password':
                        if ((string) $request->request->get('new_password') !== (string) $request->request->get('new_password_repeat')) {
                            throw new CurationException('The two new passwords differ.');
                        }
                        $this->accounts->changePassword($me, $password, (string) $request->request->get('new_password'), $proof);

                        return $this->signOut($request, 'Password changed. Please sign in again.');
                    case 'add_passkey':
                        $passkey = $this->accounts->addPasskey($me, $password, $proof, mb_substr((string) $request->request->get('attestation', ''), 0, 16_384), (string) $request->request->get('name', ''));
                        $this->addFlash('success', \sprintf('Passkey "%s" added.', $passkey->name));

                        return $this->redirectToRoute('console_account');
                    case 'remove_passkey':
                        $this->accounts->removePasskey($me, $password, $proof, $request->request->getInt('passkey_id'));

                        return $this->signOut($request, 'Passkey removed. Please sign in again.');
                    case 'recovery':
                        $codes = $this->accounts->regenerateRecoveryCodes($me, $password, $proof);
                        break;
                }
            } catch (CurationException $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }

        if (null !== $codes) {
            $response = $this->render('console/recovery_codes.html.twig', ['codes' => $codes, 'first_time' => false]);
        } else {
            $session = $request->getSession();
            $response = $this->render('console/account.html.twig', [
                'me' => $me,
                'passkeys' => $this->passkeys->passkeysOf($me),
                'confirm_options' => $this->passkeys->requestOptions($session, CuratorAccounts::ACCOUNT_PURPOSE, $me),
                'create_options' => $this->passkeys->creationOptions($session, CuratorAccounts::ADD_PASSKEY_PURPOSE, (string) $me->webauthnUserHandle, $me->email, $me->displayName, $me),
                'recovery_left' => \count($me->recoveryCodes),
                'min_length' => CuratorAccounts::MIN_PASSWORD_LENGTH,
            ]);
        }
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
