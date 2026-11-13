<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Http\Signature\SignatureException;
use App\Service\ApiProblem;
use App\Validation\ValidationFailed;
use Doctrine\ORM\OptimisticLockException;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * JSON errors for the API: {"error": code, "message": text, "details": {...}}. Internal errors
 * never leak details to the client.
 */
#[AsEventListener(event: KernelEvents::EXCEPTION, priority: 10)]
final class ApiExceptionSubscriber
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        if (!str_starts_with($event->getRequest()->getPathInfo(), '/api/')) {
            return;
        }
        $e = $event->getThrowable();

        [$status, $body, $headers] = match (true) {
            $e instanceof ApiProblem => [$e->status, ['error' => $e->errorCode, 'message' => $e->getMessage()] + ([] !== $e->details ? ['details' => $e->details] : []), $e->headers],
            $e instanceof ValidationFailed => [422, ['error' => 'validation_failed', 'message' => 'Some fields are invalid.', 'details' => $e->errors], []],
            // A curator, the minute job or another request changed the server at the same moment.
            $e instanceof OptimisticLockException => [409, ['error' => 'conflict', 'message' => 'The server record changed at the same time; please retry.'], ['Retry-After' => '5']],
            $e instanceof SignatureException => [401, ['error' => $e->reason, 'message' => $e->getMessage()], ['WWW-Authenticate' => 'Signature']],
            $e instanceof HttpExceptionInterface => [$e->getStatusCode(), ['error' => 'http_'.$e->getStatusCode(), 'message' => $e->getStatusCode() < 500 ? $e->getMessage() : 'Internal error.'], $e->getHeaders()],
            default => [500, ['error' => 'internal_error', 'message' => 'Internal error.'], []],
        };
        if ($status >= 500) {
            $this->logger->error('API request failed.', ['exception' => $e]);
        }

        $event->setResponse(new JsonResponse($body, $status, $headers));
    }
}
