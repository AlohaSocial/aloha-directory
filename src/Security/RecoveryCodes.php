<?php

declare(strict_types=1);

namespace App\Security;

use App\Crypto\SecretBox;
use App\Entity\Curator;

/**
 * Ten single-use recovery codes, 60 bits each, stored as keyed hashes (concept 13.4).
 */
final class RecoveryCodes
{
    private const COUNT = 10;
    private const ALPHABET = 'abcdefghjkmnpqrstuvwxyz23456789';
    private const CONTEXT = 'recovery-code';

    public function __construct(private readonly SecretBox $box)
    {
    }

    /**
     * Replaces the curator's codes and returns the new plain codes (shown once).
     *
     * @return list<string>
     */
    public function regenerate(Curator $curator): array
    {
        $plain = [];
        for ($i = 0; $i < self::COUNT; ++$i) {
            $code = '';
            for ($j = 0; $j < 12; ++$j) {
                $code .= self::ALPHABET[random_int(0, \strlen(self::ALPHABET) - 1)];
            }
            $plain[] = implode('-', str_split($code, 4));
        }
        $curator->recoveryCodes = array_map(fn (string $c): string => $this->hash($c), $plain);

        return $plain;
    }

    public static function looksLikeRecoveryCode(string $input): bool
    {
        return 1 === preg_match('/\A[a-z0-9]{4}-?[a-z0-9]{4}-?[a-z0-9]{4}\z/i', trim($input)) && 1 !== preg_match('/\A\d+\z/', trim($input));
    }

    /**
     * Consumes a matching code. Returns false when none matches.
     */
    public function consume(Curator $curator, string $input): bool
    {
        $hash = $this->hash($input);
        $remaining = [];
        $found = false;
        foreach ($curator->recoveryCodes as $stored) {
            if (!$found && hash_equals($stored, $hash)) {
                $found = true; // consumed: not kept
            } else {
                $remaining[] = $stored;
            }
        }
        $curator->recoveryCodes = $remaining;

        return $found;
    }

    private function hash(string $code): string
    {
        $normalized = strtolower(str_replace(['-', ' '], '', trim($code)));

        return $this->box->mac($normalized, self::CONTEXT);
    }
}
