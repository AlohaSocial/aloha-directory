<?php

declare(strict_types=1);

namespace App\Http\Signature;

/**
 * The directory's RFC 9421 profile. Aloha servers must sign every API request like this:
 *
 *   Content-Digest: sha-256=:<base64 SHA-256 of the body>:          (only when there is a body)
 *   Signature-Input: dir=("@method" "@authority" "@path" "content-digest");created=<unix>;keyid="<fingerprint>";alg="ed25519";tag="aloha-directory";nonce="<random>"
 *   Signature: dir=:<base64 Ed25519 signature over the signature base>:
 *
 * Every signature is accepted once (replay protection). Ed25519 signatures are deterministic, so a
 * random nonce is needed to send the same request twice within a second (e.g. a retry).
 *
 * keyid is base64url(SHA-256(raw 32-byte public key)). "@query" must be covered when the URL has a query.
 */
final class SignatureProfile
{
    public const TAG = 'aloha-directory';
    public const ALG = 'ed25519';
    public const MAX_AGE_SECONDS = 300;
    public const REPLAY_WINDOW_SECONDS = 2 * self::MAX_AGE_SECONDS;

    /** Components a signature may cover. Anything else is rejected rather than ignored. */
    public const ALLOWED_COMPONENTS = ['@method', '@authority', '@path', '@query', '@target-uri', 'content-digest', 'content-type', 'content-length', 'date'];
}
