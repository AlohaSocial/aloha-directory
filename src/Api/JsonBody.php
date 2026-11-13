<?php

declare(strict_types=1);

namespace App\Api;

use App\Service\ApiProblem;
use Symfony\Component\HttpFoundation\Request;

/**
 * Reads a JSON request body with a hard size limit, before anything else touches it.
 */
final class JsonBody
{
    /**
     * @return array{raw: string, data: array<mixed>}
     */
    public static function read(Request $request, int $maxBytes, bool $allowEmpty = false): array
    {
        $length = $request->headers->get('Content-Length');
        if (null !== $length && (int) $length > $maxBytes) {
            throw new ApiProblem(413, 'body_too_large', \sprintf('The body must be at most %d bytes.', $maxBytes));
        }
        $raw = $request->getContent();
        if (\strlen($raw) > $maxBytes) {
            throw new ApiProblem(413, 'body_too_large', \sprintf('The body must be at most %d bytes.', $maxBytes));
        }
        if ('' === $raw) {
            if ($allowEmpty) {
                return ['raw' => '', 'data' => []];
            }
            throw new ApiProblem(400, 'empty_body', 'A JSON body is required.');
        }
        $type = (string) $request->headers->get('Content-Type', '');
        if (!str_starts_with(strtolower($type), 'application/json')) {
            throw new ApiProblem(415, 'unsupported_media_type', 'Content-Type must be application/json.');
        }
        try {
            $data = json_decode($raw, true, 16, \JSON_THROW_ON_ERROR | \JSON_BIGINT_AS_STRING);
        } catch (\JsonException) {
            throw new ApiProblem(400, 'invalid_json', 'The body is not valid JSON.');
        }
        if (!\is_array($data) || array_is_list($data) && [] !== $data) {
            throw new ApiProblem(400, 'invalid_json', 'The body must be a JSON object.');
        }

        return ['raw' => $raw, 'data' => $data];
    }
}
