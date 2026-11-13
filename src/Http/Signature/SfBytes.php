<?php

declare(strict_types=1);

namespace App\Http\Signature;

final readonly class SfBytes
{
    public function __construct(public string $bytes)
    {
    }
}
