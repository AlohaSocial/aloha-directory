<?php

declare(strict_types=1);

namespace App\Controller\Console;

use App\Curation\CurationException;
use App\Entity\Curator;
use Doctrine\ORM\OptimisticLockException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

abstract class AbstractConsoleController extends AbstractController
{
    protected function curator(): Curator
    {
        $user = $this->getUser();
        if (!$user instanceof Curator) {
            throw new AccessDeniedHttpException();
        }

        return $user;
    }

    /**
     * Every state-changing form of the console carries a CSRF token with this id.
     */
    protected function requireCsrf(Request $request, string $id = 'console'): void
    {
        if (!$this->isCsrfTokenValid($id, (string) $request->request->get('_csrf_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token. Reload the page and try again.');
        }
    }

    /**
     * Runs an action and turns curator-facing errors into a flash message (post/redirect/get).
     */
    protected function act(callable $action, string $success, string $redirectUrl): RedirectResponse
    {
        try {
            $action();
            $this->addFlash('success', $success);
        } catch (CurationException $e) {
            $this->addFlash('error', $e->getMessage());
        } catch (OptimisticLockException) {
            $this->addFlash('error', 'Someone else (or the server itself) changed this at the same moment. Nothing was saved; please look again and retry.');
        }

        return $this->redirect($redirectUrl);
    }

    protected function text(Request $request, string $field, int $max = 5000): string
    {
        return mb_substr((string) $request->request->get($field, ''), 0, $max + 1);
    }
}
