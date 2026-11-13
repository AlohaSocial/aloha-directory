<?php

declare(strict_types=1);

namespace App\Http\Signature;

final readonly class SfToken
{
    public function __construct(public string $value)
    {
    }
}
