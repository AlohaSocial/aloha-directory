<?php

declare(strict_types=1);

namespace App\Tests\Support;

/** Marks a string as a CBOR byte string (major type 2). */
final readonly class CborBytes
{
    public function __construct(public string $bytes)
    {
    }
}
