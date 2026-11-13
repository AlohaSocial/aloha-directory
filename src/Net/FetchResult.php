<?php

declare(strict_types=1);

namespace App\Net;

final readonly class FetchResult
{
    /**
     * @param array<string, list<string>> $headers lowercase names
     */
    public function __construct(
        public string $url,
        public int $status,
        public array $headers,
        public string $body,
    ) {
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)][0] ?? null;
    }

    /**
     * @param int<1, 512> $depth
     *
     * @return array<mixed>
     */
    public function json(int $depth = 16): array
    {
        try {
            $data = json_decode($this->body, true, $depth, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new FetchException(\sprintf('%s did not return valid JSON.', $this->url));
        }
        if (!\is_array($data)) {
            throw new FetchException(\sprintf('%s did not return a JSON object.', $this->url));
        }

        return $data;
    }
}
