# Security

## Reporting a vulnerability

Please report vulnerabilities privately to the directory operator. **Operator: add the security contact here before launch.** Do not open public issues for security problems.

## Security design in brief

The worst outcome is a malicious server in the published list, sending newcomers to a hostile site (concept 11). The design blocks this in layers:

**List integrity**
- Every list is signed with Ed25519 over RFC 8785 canonical JSON.
- Clients pin an offline root key, which signs only the key set. List keys sign the lists.
- Serials only go up, so an old list cannot be replayed. Before publishing, a list is verified the way clients do it, against the installed root-signed `keys.json`, so a list signed with a key clients do not trust is never published.
- Logos are content-addressed, so the list signature covers them.

**Getting into the list**
- Domain control is proven with an HTTPS challenge, and nodeinfo must name Aloha.
- Automatic checks run every hour.
- Two different curators must agree, and a curator with a declared conflict of interest is excluded. An admin's own conflict can only be removed by another admin.
- Reviews and listing proposals record a fingerprint of the reviewed profile (terms, jurisdiction, operator, moderation). A listing cannot be confirmed for a profile that changed after the review. Resuming a paused server never approves profile changes that are waiting for review.
- Withdrawing while a delisting is proposed counts as a delisting: it is published and the six-month wait applies.
- Any single curator can pause a server immediately. A pause, or a server failing its checks, leaves the list within a minute.

**Server API**
- Every request is signed (RFC 9421, Ed25519) with content digests and a ±5-minute window.
- Each signature is accepted only once. The authority comes from configuration, never from the `Host` header.
- Bodies are size-limited and validated against an allowlist, and unknown fields are dropped.
- Anonymous endpoints are rate-limited per IP address, and `verify` also per pending key. Limits are never per domain, so nobody can use up another server's quota.
- Enrolment cannot be disturbed by strangers: every enrol call creates its own pending record, and the contact address becomes authoritative only through the domain proof.
- One `verify` call has a single 12-second budget for all its outbound requests.
- Concurrent writes are safe: `Server` and `Proposal` carry an optimistic-lock version, and background jobs apply their results to a freshly read, row-locked server. A stale write fails instead of undoing a pause or a delisting.

**Outbound requests (SSRF)**
- HTTPS only, on the default port.
- Private, loopback and reserved addresses are refused, also after DNS resolution and redirects. Redirects are re-validated and restricted to the same host where that matters.
- Response bodies are capped, and every request has a time budget.
- Images are decoded with bounded dimensions (16 megapixels, since GD's memory does not count against `memory_limit`) and re-encoded.
- Test mails to a server's abuse contact are limited to one per server per day.

**Curator console**
- One-step sign-in with password and a **passkey** (WebAuthn), or a single-use recovery code if the device is lost. Passkeys are bound to the directory's origin, so they cannot be phished. User verification (biometrics or PIN) is required. Challenges are stored server-side and used once. Credentials are discoverable, so sign-in never reveals which e-mail addresses belong to curators. The second factor is checked only after the password, so nobody without the password can burn recovery codes.
- Failed sign-ins are throttled per IP, per account and IP, and per account alone (20 per hour).
- Adding a passkey needs the password and an existing passkey (or a recovery code). Removing one needs the same, and the last passkey cannot be removed. Curators get an e-mail for every added or removed passkey.
- Recovery codes are stored as keyed hashes; new codes need a passkey. Password checks in the account forms are limited to 20 per hour per curator, so a stolen session cannot be used to guess the password.
- Sessions are short (30 minutes idle, 8 hours at most), stored in the database, and SameSite=Strict.
- A change of password, a removed passkey, a role change or deactivation ends all sessions.
- CSRF tokens protect every form, including logout.
- A strict Content-Security-Policy without inline scripts or styles applies, `noindex` is set, and nothing is cached.

**Privacy**
- The directory never sees signups or search queries.
- Exact capacity numbers stay internal; the list carries buckets only.
- Complaints reach server admins without the reporter's identity.
- No IP addresses are stored or logged. Rate-limiter keys are an HMAC with the app secret, so they cannot be reversed, and they expire.
- Retention periods are enforced daily.

**Audit**
- Every curator action, automatic transition and publication is written to an append-only audit log, in the same transaction as the change.
- Within a minute, every entry is chained to the previous one by its SHA-256 hash. Each day, the head of the chain is signed with the list key and published on the transparency page and in the Git mirror; head files are never overwritten. A daily job recomputes the chain and alerts the curators if anything was changed, inserted, deleted or backdated. It checks every head's signature, with list keys taken only from key sets signed by the pinned root keys, and compares each head with the published file, so heads edited in the database are detected too.

**Change guard**
- A list build that would add more than 10% of the entries (and more than 3) at once, or drop that many because they were paused automatically, holds back exactly those changes. Everything else is published as usual: pauses, delistings and withdrawals always leave the list within a minute. The curators are alerted, and one of them must release the held change with a reason. This catches an outage of the directory's own network (which looks like many failing servers), a bug, or an attack before it floods or empties the list.

**Background jobs**
- Domain verification and the hourly checks run in database-backed queues (`messenger:consume`), so slow or hostile servers never hold a web request. The API answers `202` for verification, and the server polls the result. At most 50 verifications wait at a time, rate limits count IPv6 addresses by /64, and a dedicated worker for checks keeps verification floods from stopping them.

The test suite covers these properties (`tests/Unit/MessageSignatureTest.php`, `tests/Unit/ListSigningTest.php`, `tests/Unit/SafeHttpClientTest.php`, `tests/Functional/ApiSecurityTest.php`, `tests/Functional/ConsoleTest.php`, `tests/Functional/SecurityReviewTest.php`, and others).

## Independent review

An internal security review (2026-11-13) found no way to forge or replay a list and no way for one server to act on another's resources. It reported one high, three medium and several low findings, all fixed with regression tests in `tests/Functional/SecurityReviewTest.php`:

| Severity | Finding | Fix |
|---|---|---|
| High | A listing could be confirmed for a profile changed after the review | Profile fingerprint on reviews and proposals |
| Medium | Lost updates could undo a pause or delisting | Optimistic lock versions; background jobs write under row locks |
| Medium | Anonymous enrol could evict a pending key or set the admin e-mail | Separate pending enrolments; contact committed only by the domain proof |
| Medium | `verify` could tie up PHP workers | Per-IP and per-key limits; one 12-second budget per call |
| Low | Authenticator replaceable without the current one | Current code or recovery code required (now: an existing passkey) |
| Low | Admin could remove their own conflict of interest | Another admin must remove it |
| Low | Withdrawal escaped delisting | Treated as a delisting |
| Low | List self-check used the signing keys | Checked against the installed `keys.json` |
| Low | Second-factor guessing limited per IP only | Per-account limiter |
| Low | Image decoding memory | Pixel cap lowered to 16 MP |
| Hardening | Key rotation by challenge, abuse test mails, reversible limiter keys | Old contact notified; one test mail per day; HMAC keys |

A second internal review (2026-11-13) of the passkeys, background queue, audit chain, change guard, fairness and load-test code found no critical issues. Its findings are fixed:

| Severity | Finding | Fix |
|---|---|---|
| High | The change guard could hold back pauses and delistings | Only additions and automatic pauses are held; everything else is published at once |
| Medium | The audit check trusted heads stored in the database | Head signatures, payloads and published files are checked; backdated entries are flagged |
| Medium | Verification jobs could starve the checks | Dedicated checks worker, cap on waiting verifications, IPv6 /64 limits, no duplicate check runs |
| Low | The load-test seeder could run on production | `loadtest_enabled` config switch, seed from the environment, audit entries |
| Low | A verification failure could be lost | Recorded with plain SQL |
| Low | Fairness relied on one self-reported figure | Uses the larger of account growth and free places taken |
| Low | Unlimited password checks in account forms | 20 per hour per curator |

