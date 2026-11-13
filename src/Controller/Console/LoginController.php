<?php

declare(strict_types=1);

namespace App\Controller\Console;

use App\Curation\CurationException;
use App\Security\CuratorAccounts;
use App\Security\Passkeys;
use App\Security\SecondFactorListener;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

final class LoginController extends AbstractController
{
    #[Route('/console/login', name: 'console_login', methods: ['GET', 'POST'])]
    public function login(Request $request, AuthenticationUtils $auth, Passkeys $passkeys): Response
    {
        if (null !== $this->getUser()) {
            return $this->redirectToRoute('console_queue');
        }
        $error = $auth->getLastAuthenticationError();

        $response = $this->render('console/login.html.twig', [
            'last_email' => $auth->getLastUsername(),
            // Never say which of e-mail, password or passkey was wrong.
            'error' => null === $error ? null : (str_contains($error->getMessageKey(), 'Too many') ? 'Too many failed attempts. Please wait a while and try again.' : 'The e-mail address, password or passkey is not correct.'),
            'expired' => $request->query->getBoolean('expired'),
            'passkey_options' => $passkeys->requestOptions($request->getSession(), SecondFactorListener::PURPOSE),
        ], new Response(null, null === $error ? 200 : 401));
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    #[Route('/console/logout', name: 'console_logout', methods: ['POST'])]
    public function logout(): never
    {
        throw new \LogicException('Handled by the firewall.');
    }

    /**
     * Accepting an invitation: password, passkey, recovery codes (concept 13.4).
     */
    #[Route('/console/invite/{token}', name: 'console_invite', requirements: ['token' => '[0-9a-f]{64}'], methods: ['GET', 'POST'])]
    public function invite(Request $request, string $token, CuratorAccounts $accounts, Passkeys $passkeys): Response
    {
        $invite = $accounts->findInvite($token);
        if (null === $invite) {
            return $this->render('console/invite_invalid.html.twig', [], new Response(null, 404));
        }
        $session = $request->getSession();
        $purpose = CuratorAccounts::invitePurpose($invite);
        $handleKey = '_aloha_invite_handle.'.$purpose;
        $userHandle = $session->get($handleKey);
        if (!\is_string($userHandle)) {
            $userHandle = Passkeys::newUserHandle();
            $session->set($handleKey, $userHandle);
        }
        $error = null;

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('invite', (string) $request->request->get('_csrf_token'))) {
                $error = 'Your session expired. Please try again.';
            } elseif ((string) $request->request->get('password') !== (string) $request->request->get('password_repeat')) {
                $error = 'The two passwords differ.';
            } else {
                try {
                    $codes = $accounts->acceptInvite(
                        $invite,
                        (string) $request->request->get('password'),
                        $session,
                        $userHandle,
                        mb_substr((string) $request->request->get('attestation', ''), 0, 16_384),
                        (string) $request->request->get('passkey_name', ''),
                    );
                    $session->remove($handleKey);
                    $session->migrate(true);

                    $response = $this->render('console/recovery_codes.html.twig', ['codes' => $codes, 'first_time' => true]);
                    $response->headers->set('Cache-Control', 'no-store');

                    return $response;
                } catch (CurationException $e) {
                    $error = $e->getMessage();
                }
            }
        }

        $response = $this->render('console/invite.html.twig', [
            'invite' => $invite,
            'passkey_options' => $passkeys->creationOptions($session, $purpose, $userHandle, $invite->email, $invite->displayName),
            'error' => $error,
            'min_length' => CuratorAccounts::MIN_PASSWORD_LENGTH,
        ], new Response(null, null === $error ? 200 : 422));
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
