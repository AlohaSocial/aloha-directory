# The signed lists

For the Aloha mobile and desktop apps and the Aloha server app (concept section 8, Appendix A.2 and A.3).

| URL | Signed by | Consumer |
|---|---|---|
| `https://directory.aloha.social/lists/v1/keys.json` | root key (offline) | everyone |
| `https://directory.aloha.social/lists/v1/servers.json` | list key(s) | mobile and desktop apps |
| `https://directory.aloha.social/lists/v1/search.json` | list key(s) | Aloha server app |
| `https://directory.aloha.social/media/<sha256>.webp` | covered by its hash in `servers.json` | apps |

The files are static and cached by the CDN (`max-age=300`, ETag). Use `If-None-Match`. Mirrors such as the Git repository `alohasocial/directory-lists` carry the same files. **Trust the signature, not where the file came from.**

## Envelope

```json
{
  "payload": "<base64url of the RFC 8785 canonical JSON payload>",
  "signatures": [{"kid": "aloha-list-2026a", "alg": "Ed25519", "sig": "<base64url>"}]
}
```

The signature covers the **exact bytes** of the decoded payload. Verify those bytes first, and only then parse them. During a key rotation a list carries two signatures; one valid signature from a trusted key is enough.

## Verification algorithm

The algorithm is implemented in `tools/verify-list.mjs` (Node.js, no dependencies) and `src/Crypto/ListVerifier.php`. A test checks that both accept the published lists.

1. **Pin the root public key(s)** in the app, and ship the current `keys.json` and `servers.json` with each release as a bootstrap.
2. **Verify `keys.json`** against a pinned root key: a valid `Ed25519` signature by a root `kid`, with `list == "keys"` and `version == 1`. Its serial must be higher than the last key set you accepted. Trust only keys with `"status": "active"` whose `not_after`, if present, is in the future.
3. **Verify the list** against those list keys, with the same envelope rules. `list` must be the name you expect (`servers` or `search`), and `version == 1`.
4. **Serial**: reject any list whose `serial` is not higher than the last one you accepted. This stops replays of an old list that still contained a server which has since been delisted. Persist the accepted serial.
5. **Time**: reject an `issued_at` more than 5 minutes in the future. An expired list (`expires_at`, 30 days after issue) is still used but marked as old. **After 90 days without a fresh list, stop recommending servers.**
6. **On a signature failure, fetch `keys.json` again** (a list key may have been rotated or revoked), then retry once. Report failures anonymously with `POST /api/v1/lists/failures {"list": "servers", "reason": "signature"}`. Reasons are `signature`, `serial`, `format`, `expired` and `keys`. Only a counter is kept.

```sh
node tools/verify-list.mjs --root aloha-root-2026=<base64url root key> --keys keys.json --list servers.json
```

## Payload of `servers.json`

```json
{
  "list": "servers", "version": 1, "serial": 1794586000,
  "issued_at": "2026-11-13T12:00:00Z", "expires_at": "2026-12-13T12:00:00Z",
  "min_server_version": "0.27.0",
  "media_base_url": "https://directory.aloha.social/media/",
  "entries": [{
    "domain": "social.example.org",
    "name": "Example Social",
    "description": "A friendly general-purpose server for people in Hamburg.",
    "long_description": "…",
    "logo": "sha256:9f2c…", "banner": "sha256:…",
    "languages": ["de", "en"], "topic": "general", "audience": "…",
    "region": {"hosting": "DE", "operator_seat": "DE", "gdpr": true},
    "operator": {"name": "Example e.V.", "type": "association", "website": "https://…", "funding": "donations",
                 "commitment_until": "2028-12", "shutdown_notice_months": 3},
    "moderation": {"moderators": 4, "response_hours": 24, "policy_url": "https://…"},
    "terms_url": "https://…", "privacy_url": "https://…", "rules": ["…"], "min_age": 16,
    "signup": {"mode": "approval", "email_required": true, "state": "open", "capacity": "plenty", "approval_wait": "short"},
    "account": {"storage_mb": 2048, "media_mb": 40, "features": ["dm", "shorts", "video"]},
    "atproto": {"enabled": true, "handle_example": "alice.social.example.org", "move_away": false},
    "state": "featured",
    "weight": 1,
    "balance": 1,
    "listed_since": "2026-06-30", "reviewed_at": "2026-09-30"
  }]
}
```

- **`state`** is `featured`, `listed` or `full`. Paused, delisted and withdrawn servers are not in the list.
- **`signup.state`** is `open`, `closed` (no free accounts or signup closed) or `unavailable` (no heartbeat for 3 hours).
- **`capacity`** is `plenty` (100 or more free accounts), `some` (20–99), `few` (1–19) or `full`.
- **`approval_wait`** is `none`, `short` or `long`.
- **Optional fields are omitted** rather than set to `null`. Ignore fields you do not know.
- **`atproto`** is present only while the automatic checks confirm Bluesky handles work.
- **Logos and banners**: `logo` and `banner` are `sha256:<hex>`. Fetch `media_base_url + hex + ".webp"` and check that the SHA-256 of the bytes matches before showing the image.
- **Numbers in canonical JSON**: `weight` is a number from 0.5 to 2, `balance` from 0.25 to 1. RFC 8785 writes `1.0` as `1`.
- **`weight`** is set by the curators (for example, to support a new server in an underserved language).
- **`balance`** is computed daily by the directory. It drops below 1 while a featured server takes much more than its fair share of the newcomers in its primary language (from the account totals in heartbeats), and returns to 1 when the spread evens out. Treat a missing `balance` as 1.

### Choosing the suggested server (concept 9.2, on the device)

1. **Filter.** Keep entries with `state == "featured"`, `signup.state == "open"` and `capacity != "full"`. The person's language must be in `languages`, with English as the fallback, and the person must meet `min_age`.
2. **Score.** Favour the person's region, a short `approval_wait` and a higher `capacity`, with a soft cap. Multiply by `weight` and by `balance`.
3. **Pick.** Among the top five, pick one at random, weighted by score.
4. **Live check.** Before signup, call the chosen server's `/api/v2/instance`. If it is now full, move on to the next server and say so.

No data about the person leaves the device.

## Payload of `search.json`

```json
{
  "list": "search", "version": 1, "serial": 1794586000, "issued_at": "…", "expires_at": "…",
  "entries": [
    {"kind": "aloha", "domain": "search.example.net", "capabilities": ["accounts", "hashtags"],
     "languages": ["en", "de"], "rate_limit_per_minute": 60, "index_policy_url": "https://…",
     "log_retention_days": 7, "weight": 1},
    {"kind": "atproto_appview", "url": "https://public.api.bsky.app", "capabilities": ["accounts"],
     "methods": ["app.bsky.actor.searchActorsTypeahead", "app.bsky.actor.searchActors"], "authenticated": false}
  ]
}
```

- **Which providers appear.** A provider is included only while it is listed, two curators have accepted it as a provider, its heartbeat is fresh, and its heartbeat confirms each capability.
- **Weight.** Its `weight` already includes the load hint.
- **How servers use the list.** Servers use up to 4 providers, preferring shared languages and then rotating at random. They respect `429` and `Retry-After`, and query the AppView without authentication (concept 10.3, 15.3).
- **Unknown kinds.** Ignore any `kind` you do not know.

## Payload of `keys.json`

```json
{"list": "keys", "version": 1, "serial": 3, "issued_at": "…", "expires_at": "…",
 "keys": [{"alg": "Ed25519", "kid": "aloha-list-2026a", "public_key": "<base64url>", "status": "active"},
          {"alg": "Ed25519", "kid": "aloha-list-2025b", "public_key": "<base64url>", "status": "revoked"}]}
```
