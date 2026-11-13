<?php

declare(strict_types=1);

namespace App\Check;

final readonly class NodeinfoResult
{
    public function __construct(
        public string $softwareName,
        public string $version,
        public ?bool $openRegistrations,
    ) {
    }
}
