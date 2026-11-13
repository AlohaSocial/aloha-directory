<?php

declare(strict_types=1);

namespace App\Service;

/**
 * A client-facing error of the directory API, rendered as {"error": code, "message": ..., "details": ...}.
 */
final class ApiProblem extends \RuntimeException
{
    /**
     * @param array<string, mixed> $details
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly array $details = [],
        public readonly array $headers = [],
    ) {
        parent::__construct($message);
    }
}
