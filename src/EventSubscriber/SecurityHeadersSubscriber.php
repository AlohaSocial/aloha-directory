<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Strict headers on every dynamic response. No inline scripts or styles exist anywhere in the
 * application, so the CSP needs no 'unsafe-inline' and no nonces.
 */
#[AsEventListener(event: KernelEvents::RESPONSE, priority: -10)]
final class SecurityHeadersSubscriber
{
    public function __invoke(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $request = $event->getRequest();
        $response = $event->getResponse();
        $headers = $response->headers;
        $path = $request->getPathInfo();
        $isApi = str_starts_with($path, '/api/') || str_starts_with($path, '/metrics') || str_starts_with($path, '/healthz');

        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        $headers->set('Cross-Origin-Resource-Policy', $isApi ? 'cross-origin' : 'same-origin');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=(), interest-cohort=()');
        $headers->set('Referrer-Policy', $isApi ? 'no-referrer' : 'same-origin');
        $headers->set('Content-Security-Policy', $isApi
            ? "default-src 'none'; frame-ancestors 'none'"
            : "default-src 'none'; script-src 'self'; style-src 'self'; img-src 'self' data:; font-src 'self'; connect-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
        if ($request->isSecure()) {
            $headers->set('Strict-Transport-Security', 'max-age=63072000; includeSubDomains');
        }
        if (str_starts_with($path, '/console') || $isApi) {
            $headers->set('Cache-Control', 'no-store, private');
        }
        $headers->remove('X-Powered-By');
    }
}
