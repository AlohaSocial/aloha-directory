<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Crypto\Base64Url;
use App\Crypto\Ed25519;

final class TestKeys
{
    public const ROOT_KID = 'test-root';
    public const LIST_KID = 'test-list-a';

    /**
     * @return array{0: string, 1: string} [secret, public]
     */
    public static function root(): array
    {
        return Ed25519::keyPairFromSeed(str_repeat("\x02", 32));
    }

    /**
     * @return array{0: string, 1: string}
     */
    public static function list(): array
    {
        return Ed25519::keyPairFromSeed(str_repeat("\x01", 32));
    }

    /**
     * @return array{0: string, 1: string}
     */
    public static function random(): array
    {
        return Ed25519::keyPairFromSeed(random_bytes(32));
    }

    public static function encode(string $key): string
    {
        return Base64Url::encode($key);
    }
}
