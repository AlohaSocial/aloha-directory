# Concept → implementation

This file maps the concept *Aloha Directory: server discovery and search* to the code, and records the decisions taken where the concept leaves room. Section numbers refer to the concept.

## Where each part lives

| Concept | Implementation |
|---|---|
| 5, 13.1 One PHP application, three entry points | Server API `src/Controller/Api`, curator console `src/Controller/Console`, cron commands `src/Command` |
| 6.1 Enrolment, challenge, nodeinfo, signed requests | `ServerLifecycle::enrol/verify`, `NodeinfoReader`, `Http/Signature/*` (RFC 9421, RFC 8941, RFC 9530) |
| 6.2 Listing profile, re-review groups | `Validation/ProfileValidator` (`REVIEWED_GROUPS`, `LIVE_GROUPS`), `ServerLifecycle::acceptProfile` |
| 6.3, 6.4 Heartbeat and its rules | `Validation/HeartbeatValidator`, `ServerLifecycle::acceptHeartbeat/evaluate` |
| 7.1 Listing states | `Enum/ServerState`, `Enum/PauseKind`; "full" and "featured" are derived or a flag, as in the published list |
| 7.2 Automatic checks | `Check/Checker`, `Check/Checks` |
| 7.3–7.5 Review, featuring, re-review, complaints, pause | `Curation/CurationService`, `Curation/FeaturedEligibility`, `Curation/Covenant`, `Service/ComplaintIntake` |
| 8 Lists, envelope, keys, distribution | `Lists/*`, `Crypto/*`, `docs/lists.md`, `tools/verify-list.mjs` |
| 9.2 Choosing a server | On the device; documented in `docs/lists.md` |
| 10 Search provider list | `Lists/SearchListEntryFactory`, provider status set by a curator decision |
| 11 Trust, security, privacy | Throughout; summary in `SECURITY.md` |
| 12.4, 12.5 Delisting, transparency | `TransparencyEntry`, `Service/TransparencyReporter`, `templates/site/transparency.html.twig` |
| 13.2 Static lists, rebuilt on change | `Lists/ListBuilder`, `Service/Ticker` |
| 13.3 Buckets | `Service/Buckets`; thresholds in `config.php` |
| 13.4 Curator console | `templates/console`, `Security/*` (password + passkey, recovery codes, short sessions) |
| 9.2, G3 Spreading newcomers | `Fairness/FairnessCalculator` (`balance` in the list), console *Fairness* |
| 11, 12.5 Tamper evidence | `Audit/AuditChain` (daily signed head), `ListBuilder::guard` (change guard) |
| 13.5 Public website | `Site/SiteBuilder`, `templates/site`, `/report` |
| 13.6 Minimum version | `min_server_version` in `config.php`; pause after `min_version_grace_days` |
| 13.8 Monitoring | `Monitoring/Metrics`, `/metrics`, `app:monitor` |
| 15 Bluesky | `atproto` blocks in profile and heartbeat, `Checks::ATPROTO`, `atproto_appview` entry in `search.json` |
| D20 Website in the same application, as static pages | yes |

## Decisions taken during implementation

1. **Changes are detected by content hash, not by dirty flags (13.2).** Every minute the builder recomputes each list from the database, which is a few hundred rows. It rebuilds only when the hash of the entries changed, or when the daily re-sign is due. The effect is the one the concept asks for: a rebuild within a minute of a change, and none without one. Time-based changes, such as a heartbeat going stale, cannot be missed this way.
2. **Serials are `max(previous + 1, Unix time)`.** They only go up, even after a database restore (8.2).
3. **"Fetch the profile again" on a new `profile_etag` (6.4).** The concept defines no endpoint on the server for this. The directory sets `profile_requested` in the heartbeat response and in the status, and the server PUTs its profile again.
4. **Critical checks pause a server after two consecutive hourly failures.** The critical checks are HTTPS, nodeinfo and block list, and the count is the `check_failures_before_pause` threshold. A single network hiccup does not pause a listed server.
5. **The minimum version has its own 30-day grace period (13.6).** The nodeinfo check does not fail on an old version.
6. **Every signature is accepted only once (replay protection), and servers send a random `nonce`.** Ed25519 is deterministic, so without a nonce a legitimate retry within the same second would look like a replay.
7. **`tag="aloha-directory"` is required**, and `@authority` comes from configuration. This keeps signatures meant for other services, such as search providers (10.4), from being replayed against the directory.
8. **Key rotation with the old key (6.1) also needs a proof by the new key.** Otherwise a typo in the new key would lock the server out.
9. **One curator decides everything (D21).** The concept asked for two curators on listing decisions; this was dropped on 2026-11-14. Decisions (list, including lifting a curator pause; feature, unfeature, delist, weight, reject, and accepting or removing a search provider) take effect at once and are recorded with their reason in the audit log. So are pauses, the waiting list, profile decisions, re-reviews, notes and complaint handling. Listing an application needs a complete review checklist by the deciding curator, of the current profile, from the last 60 days.
10. **Abuse contact check (7.2).** The domain of the address must accept mail (MX or address record), and a test mail is sent once per address. Whether it bounced is not detected automatically: point the sender's bounce address at a mailbox the curators read.
11. **Exact heartbeat numbers stay private.** They are shown in the console and used for monitoring. The public list carries buckets only, and the status API shows the admin the published buckets.
12. **Logos and banners are re-encoded**, never passed through. They are decoded with bounded dimensions, re-encoded as WebP and named by their SHA-256.
13. **PHP 8.4** instead of 8.3: Symfony 8 requires it.
14. **Configuration in one `config.php`**, which overrides nothing that real environment variables set.
15. **Passkeys instead of TOTP (13.4).** WebAuthn credentials are bound to the directory's origin and cannot be phished. Sign-in stays one step: e-mail, password and passkey on one form. Recovery codes remain for a lost device.
16. **Verification and checks are background jobs (13.1).** The queues live in the database (Symfony Messenger, Doctrine transport), so no extra service is needed. `POST …/verify` answers `202`, and the server polls `GET …/verify`.
17. **Fairness without data about people (9.2, G3).** The directory only sees each server's account total from heartbeats. Within a language, a featured server that gained more than 150% of its fair share of new accounts in 7 days gets a `balance` factor (at least 0.25) that devices multiply into their score.
18. **The audit log is chained after the fact.** Chaining at write time would serialise every audited transaction. Entries are sealed every minute in id order, and a late commit is appended at the end of the chain, which therefore never has gaps.
19. **Change guard (11).** A build adding or removing more than 10% of the entries (and more than 3) at once is held until a curator releases it. Meanwhile the previous list keeps being re-signed.

## Not part of this repository

- **Other repositories.** The Aloha server app changes (6.5, 9.3, 10.1, A.4, A.5) and onboarding in the mobile and desktop apps (9) belong elsewhere. `docs/server-integration.md` and `docs/lists.md` are the contract for those teams.
- **Process items that only need data, not code.** These are the advisory board, appeals and policy announcements (12.2, 12.4). `TransparencyEntry::appealOutcome` can hold the outcome of an appeal, but the console has no form for it yet.

## Open questions this implementation surfaced

- **How do providers verify a calling server's key (10.4)?** The directory publishes no per-server keys, by design: it stays off the runtime path. A simple answer: callers publish their directory public key at `/.well-known/aloha-directory`, which already exists for enrolment, and providers fetch and cache it.
- **Should waitlisted servers keep sending heartbeats indefinitely**, or be asked to re-apply after a period?
- The open questions in section 16.3 of the concept are unchanged.
