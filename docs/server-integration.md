# Integrating the Aloha server app

This document describes everything the Aloha server app needs to do to take part in the directory (concept sections 6, 7.5, 9.4 and Appendix A.1). The reference client is `src/Http/Signature/MessageSigner.php`, and `tests/Support/FakeAlohaServer.php` shows a complete simulated server.

Base URL: `https://directory.aloha.social`. All bodies are JSON (`Content-Type: application/json`) in UTF-8. Timestamps are RFC 3339 in UTC.

## 1. Key

Create an Ed25519 key pair just for the directory. Keep it separate from the ActivityPub keys and store the private key in the encrypted app config.

- **Public key**: the raw 32 bytes, base64url without padding.
- **Key id**: `base64url(SHA-256(raw public key))`, 43 characters.

## 2. Enrol and prove control of the domain

```http
POST /api/v1/servers/enrol
{"domain": "social.example.org", "public_key": "<base64url>", "contact_email": "admin@example.org"}
```

The response is `201`:

```json
{"domain": "social.example.org", "key_id": "…", "challenge": "…", "expires_at": "…",
 "well_known_url": "https://social.example.org/.well-known/aloha-directory",
 "well_known_body": {"challenge": "…", "key_id": "…"}}
```

Serve `well_known_body` exactly as `application/json` at `https://<domain>/.well-known/aloha-directory`. Redirects are followed only on the same host. Then call, **signed with the new key**:

```http
POST /api/v1/servers/{domain}/verify      → 202 {"status": "queued", "status_url": "/api/v1/servers/{domain}/verify"}
GET  /api/v1/servers/{domain}/verify      (signed with the new key) → 200, see below
```

Verification runs in the background. Poll `GET …/verify` every few seconds (it sends `Retry-After`). The answer is one of:

```json
{"domain": "…", "status": "queued", "expires_at": "…"}
{"domain": "…", "status": "failed", "error": "challenge_mismatch", "message": "…", "expires_at": "…"}
{"domain": "…", "status": "verified", "state": "applied", "key_id": "…"}
```

The `error` codes are `challenge_unreachable`, `challenge_mismatch`, `nodeinfo_unavailable`, `not_aloha`, `version_too_old`, `challenge_expired` and `internal_error`. After a failure, fix the cause and call `POST …/verify` again. While a verification is queued, another `POST` adds nothing.

The directory fetches the challenge and reads `/.well-known/nodeinfo`. `software.name` must be one of the configured Aloha identifiers, and the version must be at least the minimum version. The challenge expires after one hour. On success the server is in state `applied`, and from now on every request is signed with this key. The `contact_email` sent with the enrolment whose challenge the domain serves becomes the admin contact.

Every enrol call creates a separate pending enrolment with its own challenge, even for the same domain and key. Enrolling again therefore never invalidates an earlier challenge, and nobody else's enrol call can disturb yours. The verify call uses whichever pending enrolment's challenge the domain serves.

Limits:
- `enrol`: 10 requests per IP address per hour.
- `verify`: 30 per IP address per hour, and 12 per pending key per hour.
- All requests of one `verify` call (challenge, nodeinfo index, nodeinfo document, redirects included) share a budget of 12 seconds.

A blocked domain gets `403 domain_blocked`. A delisted server gets `403 reapply_too_early` until six months after the decision.

## 3. Signing requests (RFC 9421)

Every request except `enrol` carries:

```http
Content-Digest: sha-256=:<base64 SHA-256 of the body>:
Signature-Input: dir=("@method" "@authority" "@path" "content-digest");created=1794586000;keyid="<key id>";alg="ed25519";tag="aloha-directory";nonce="<random>"
Signature: dir=:<base64 Ed25519 signature over the signature base>:
```

- `@authority` is `directory.aloha.social`. The directory uses its own configured name, never the `Host` header, so a signature made for another host never verifies.
- Cover `content-digest` whenever there is a body, and `@query` whenever the URL has a query string.
- `created` must be within ±5 minutes of the directory's clock. `tag="aloha-directory"` is required.
- **Every signature is accepted only once.** Ed25519 signatures are deterministic, so add a random `nonce` (16 bytes, base64url). Without it, a retry within the same second is rejected as `replayed_signature`.
- Only one signature per request. Component parameters (`;sf`, `;req`) are not supported.

The signature base follows RFC 9421 section 2.5. For a heartbeat:

```
"@method": PUT
"@authority": directory.aloha.social
"@path": /api/v1/servers/social.example.org/heartbeat
"content-digest": sha-256=:X48E9qOokqqrvdts8nOJRJN3OWDUoyWxBf7kbu9DBPE=:
"@signature-params": ("@method" "@authority" "@path" "content-digest");created=1794586000;keyid="…";alg="ed25519";tag="aloha-directory";nonce="…"
```

Errors return `401` with one of: `missing_signature`, `malformed_signature`, `unsupported_component`, `insufficient_coverage`, `signature_expired`, `unknown_key`, `bad_signature`, `bad_digest`, `replayed_signature`.

## 4. Profile

```http
PUT /api/v1/servers/{domain}/profile        (signed, at most 64 KB)
```

The body uses the groups of concept 6.2. Unknown fields are ignored and never stored. `contact_email` at the top level updates the admin contact.

```json
{
  "identity": {"name": "Example Social", "description": "≤ 280 characters", "long_description": "≤ 5,000, optional",
               "logo_url": "https://…", "banner_url": "https://…"},
  "terms": {"terms_url": "https://…", "privacy_url": "https://…", "rules": ["Be kind.", "…"], "min_age": 16},
  "audience": {"languages": ["de", "en"], "topic": "general", "audience": "optional"},
  "jurisdiction": {"hosting_country": "DE", "operator_seat": "DE", "gdpr": true, "dpa_url": null},
  "operator": {"name": "Example e.V.", "type": "association", "website": "https://…", "funding": "Donations",
               "commitment_until": "2028-12", "shutdown_notice_months": 3},
  "moderation": {"moderators": 4, "abuse_email": "abuse@…", "abuse_actor": "https://…", "response_hours": 24,
                 "blocklist_policy": "optional", "policy_url": "https://…"},
  "account": {"signup_mode": "approval", "email_required": true, "storage_mb": 2048, "media_mb": 40, "features": ["dm", "shorts", "video"]},
  "search": {"capabilities": ["accounts", "hashtags", "statuses", "trends"], "index_policy_url": "https://…",
             "log_retention_days": 7, "rate_limit_per_minute": 60},
  "atproto": {"enabled": true, "handle_example": "alice.social.example.org", "move_away": false},
  "etag": "W/\"7c1e\"",
  "contact_email": "admin@example.org"
}
```

Constraints:

- All URLs must be absolute `https://` URLs.
- `languages` are BCP 47 tags (1 to 10).
- Countries are ISO 3166-1 alpha-2 codes.
- `operator.type` is one of `person`, `association`, `company` or `public_body`.
- `rules` must contain 1 to 50 entries.
- When `search.capabilities` is not empty, `index_policy_url`, `log_retention_days` (at most 7) and `rate_limit_per_minute` are required.
- Validation errors return `422` with `details` keyed by field path, for example `{"terms.rules": ["is required"]}`.

**Review rules.**

- Before listing, every PUT replaces the profile.
- Once listed, changes to `terms`, `jurisdiction`, `operator` or `moderation` wait for a curator, and the response says `"pending_review": true`.
- Changes to all other groups go live at once, and curators can revert them.
- `logo_url` and `banner_url` are fetched by the directory (PNG, JPEG, WebP or GIF; at most 5 MB). They are re-encoded as WebP and published under their SHA-256. Problems appear in `media_error` in the status.

## 5. Heartbeat

```http
PUT /api/v1/servers/{domain}/heartbeat      (signed, once an hour, at most 8 KB)
```

```json
{
  "domain": "social.example.org",
  "software": {"name": "aloha", "version": "0.27.0", "nextcloud": "33.0.2"},
  "signup": {"open": true, "mode": "approval", "available_accounts": 412, "approval_backlog": 3},
  "usage": {"users_total": 1588, "users_active_month": 940},
  "search": {"accounts": true, "hashtags": true, "statuses": true, "trends": true, "load": 0.3, "providers_used": 4},
  "health": {"queue_lag_seconds": 12, "federation_ok": true},
  "atproto": {"enabled": true, "handles_ok": true, "relay_last_crawl": "2026-10-08T13:58:00Z"},
  "profile_etag": "W/\"7c1e\"",
  "sent_at": "2026-10-08T14:00:00Z"
}
```

- `available_accounts` is the *maximum accounts* setting minus the existing external accounts, capped by `directory_advertised_accounts`.
- `search.load` (0 to 1) is the load hint of concept 10.5; above 0.8 the provider's weight is lowered.
- `sent_at` must be within 10 minutes of the directory's clock. Heartbeats closer together than 5 minutes get `429` with `Retry-After`.

The response is `{"accepted": true, "state": "listed", "profile_requested": false, "next_heartbeat_seconds": 3600}`. When `profile_requested` is true, the heartbeat named a different `profile_etag` than the last profile the directory received, and the server should PUT its profile again.

Exact numbers stay inside the directory. The public list carries only buckets (concept 13.3).

**Queue heartbeats while the directory is down.** If a heartbeat fails, retry with backoff, and send the newest state, not a backlog of old ones.

## 6. Status, questions and complaints

```http
GET /api/v1/servers/{domain}/status         (signed)
```

The status shows:

- the listing state and pause reason;
- the published buckets;
- automatic check results;
- curator questions and decisions (`comments`);
- complaints the curators shared (without the reporter's identity);
- the next review date;
- warnings, such as an outdated version.

Show this in the *Aloha directory* admin settings panel. To answer:

```http
POST /api/v1/servers/{domain}/comments                          {"body": "…"}   (signed)
POST /api/v1/servers/{domain}/complaints/{id}/responses         {"body": "…"}   (signed)
```

The body is 1 to 5,000 characters. Comments and complaint responses share a limit of 20 messages per server per day
(`messages_per_server_day`). Beyond it, the API answers `429 rate_limited` with `Retry-After`; show that to the admin
instead of retrying automatically. Requests that fail the signature or validation do not count.

## 7. Withdraw and rotate the key

```http
DELETE /api/v1/servers/{domain}             (signed) → state "withdrawn"; may enrol again at any time
POST /api/v1/servers/{domain}/key           (signed with the CURRENT key)
     {"public_key": "<new>", "proof": "<base64url Ed25519 signature by the NEW key over
                                        'aloha-directory-key-rotation:<domain>:<new key id>'>"}
```

If the old key is lost, enrol again with a new key and prove the domain with a new challenge. The listing state is kept, and the previous admin contact is told by e-mail that the key was replaced.

If a write collides with a curator decision or the directory's own jobs, the API answers `409 conflict` with `Retry-After`; repeat the request.

## 8. What the server app also needs (concept 6.5, 9.3, 10)

These parts live in the Aloha server app, not in this repository:

- `/api/v1/instance`, `/api/v2/instance` and nodeinfo must report the real registration state. The directory's `instance_signup` check compares them with the heartbeat.
- `/status.php` must be reachable over HTTPS; the HTTPS check uses it.
- The native registration API (`POST /api/v1/accounts`, `GET /api/v1/accounts/challenge`).
- Search provider endpoints (`/api/v1/directory/{search,hashtags,statuses,trends}`).
- The daily fetch of `search.json`, verified as described in [lists.md](lists.md).
- The app config values of concept A.5.
