# Aloha Directory: server discovery and search

Oct 8, 2026 · @Frank Karlitschek

## 1. Executive summary

A new service, the Aloha Directory at directory.aloha.social, publishes two signed lists. Aloha servers advertise themselves to it, human curators choose the best entries, and apps and servers download the result. The two lists answer the two biggest gaps in the Aloha experience today:

1. **Where do I sign up?** A person who installs the Aloha mobile or desktop app has no server and no way to find one. The *server list* names a small, hand-picked set of Aloha servers that offer free accounts right now. For each server it gives the terms, the moderation policy, the languages and its current capacity. The app recommends one server and lets the person pick another, then creates the account natively through a new registration API.
2. **How do I find people?** Search on an Aloha server reaches only its own users plus a hard-coded set of large third-party servers. The *search provider list* names curated Aloha servers that offer account, hashtag, full-text and trend search over opted-in accounts. It replaces the hard-coded sources in the server app.

The design rests on five decisions taken in the interview of 2026-10-08:

| Decision               | Choice                                                                                                             |
|------------------------|--------------------------------------------------------------------------------------------------------------------|
| Operator               | Nextcloud runs directory.aloha.social first; governance moves to a community foundation once the service is stable |
| Eligibility            | Only Aloha servers may advertise, verified through nodeinfo and a signed challenge                                 |
| Curation               | A small hand-picked list (roughly 10 to 50 servers), re-reviewed regularly; quality over reach                     |
| directory.aloha.social | A directory only. It hosts no user accounts, so users do not pile up on one flagship server                        |
| Integrity              | Every list is signed JSON (Ed25519); the public key is pinned in the apps and in the server app                    |

The target is to ship both lists together within about six months: the directory service, the server-side advertisement and search work in the Aloha app, and onboarding in the mobile and desktop apps.

Starter packs, which suggest whom to follow first, are not part of the directory; they are handled independently.

The directory never sits in the path of normal use. Signup, login, search queries and follows go straight from app to server or from server to server. If directory.aloha.social is offline, the apps and servers keep working from their last signed copy of each list.

The same design covers Aloha's Bluesky compatibility (section 15). An account created through the directory is also a Bluesky account. Bluesky search goes through Bluesky's own AppView.

## 2. Problem statement

Both problems come from the same root: the knowledge needed to get started sits on individual servers, and nothing collects it. This section describes the current state of Aloha Social (version 0.26.140) and where each problem bites.

### 2.1 Onboarding without a server

The fediverse asks a newcomer to choose a server before they have seen anything. Mastodon answers this with a flagship server and the joinmastodon.org list. Bluesky answers it with one default host. Aloha has neither.

The server side is mostly ready. An Aloha server can already take people who have no Nextcloud account:

- `SignupController` serves `/signup` and `/signup/verify/{token}`.
- `external_signup_mode` is `open`, `approval` (the default) or `invite`. Email confirmation and a maximum account count are optional.
- Admins approve or reject requests under `/admin/external`, and users can send invites.

Three gaps stop a native app from using any of this:

1. **The public APIs say registration is closed.** `InstanceService` always sets `registrations`, `approval_required` and `invites_enabled` to false in `/api/v1/instance` and `/api/v2/instance`. Nodeinfo therefore always reports `openRegistrations: false`, even when external signup is open.
2. **There is no registration API.** Mastodon clients create accounts with `POST /api/v1/accounts`. Aloha only offers the web form, so a native app would have to embed a browser.
3. **Nothing tells the app which servers exist.** Even with points 1 and 2 fixed, an app cannot find servers that have room. No server reports its capacity anywhere public.

### 2.2 Finding people

Search in Aloha is federated already. `FediverseDirectoryService` sends each query in parallel to several directory sources, with a 4-second timeout per source and a 10-second budget overall. Results are cached for five minutes.

The weak point is choosing the sources. Today they come from three places:

- **Hard-coded defaults:** fedi.directory, mastodon.social, misskey.io, lemmy.world and pixelfed.social.
- **A few federated peers.** The `directory_peers` setting controls how many; the default is 4.
- **Optional discovery** through fediverse.info, capped at three servers.

Admins can override the list only by writing JSON to the `directories` app value; there is no settings UI. The defaults are large third-party servers that never agreed to serve Aloha's traffic. Their APIs can change or be rate-limited at any time, and some limit public directory access. No Aloha server can offer itself as a search provider, and nobody vouches for the quality or privacy of a source.

### 2.3 Why a central directory, and why curated

Peer-to-peer discovery has been tried. Fediverse crawlers and instance lists exist, but they rank by size or list everything. A newcomer cannot judge moderation quality, longevity or legal jurisdiction from a list of 30,000 domains.

A small curated list fixes the newcomer's problem, and it also makes the Aloha experience the same on every server the apps recommend. The price is a point of editorial control. Sections 11 and 12 address that with signing, transparency and a path to a foundation.

## 3. Goals, non-goals and design principles

Success means a newcomer can go from installing the app to a working account on a well-run server in under three minutes, with no fediverse knowledge.

### 3.1 Goals

| #  | Goal                                                                   | Measure of success                                                                                |
|----|------------------------------------------------------------------------|---------------------------------------------------------------------------------------------------|
| G1 | A person with no account can create one from the mobile or desktop app | Install to first post possible without leaving the app; signup completion rate tracked per server |
| G2 | Newcomers land on well-run servers                                     | Every recommended server passed human review in the last 6 months; no server listed while full    |
| G3 | Accounts are spread across many servers                                | No single server receives more than 25% of app signups in a month                                 |
| G4 | Search finds people across the Aloha network and beyond                | The curated providers replace the hard-coded sources; median search answer under 2 s              |
| G5 | The directory is never a single point of failure                       | Apps and servers keep working for at least 30 days on a cached list                               |
| G6 | Privacy holds                                                          | directory.aloha.social never learns who signs up where or who searches for what                   |

### 3.2 Non-goals

- **Hosting accounts.** directory.aloha.social is a directory, not a server, and will not become a fallback home server.
- **Listing the whole fediverse.** Non-Aloha servers are not listed for signup. They remain fully reachable through federation and search.
- **Central search.** directory.aloha.social names search providers but answers no queries itself.
- **Ranking content.** The directory curates servers, not posts. Timelines and For You stay local to each server.
- **Replacing local admin control.** Every server admin can ignore, override or disable each list.
- **Starter packs.** Suggesting whom to follow first is handled independently of the directory.
- **Payments.** The first version lists free accounts only. Paid tiers are an open question (section 16).

### 3.3 Design principles

1. **Off the hot path.** The directory publishes static, signed files. Nothing a user does at runtime needs directory.aloha.social to be online.
2. **Signed, not trusted.** Clients trust the signature, not the transport. A CDN, mirror or proxy can carry the lists without being able to change them.
3. **Push facts, pull lists.** Servers push facts about themselves in a signed heartbeat. Clients pull finished lists. The directory never crawls user data.
4. **Humans decide, machines check.** Automatic checks (reachability, TLS, version, capacity) decide whether a server is *eligible*. Only a curator decides whether it is *listed*.
5. **Consent first.** A person appears in a search index served to other servers only if they opted in.
6. **Same shape as the ecosystem.** Field names follow Mastodon's `/api/v2/instance` and nodeinfo where those already have an answer, so existing tools can read the data.
7. **Transparent curation.** Listing criteria, every delisting and its reason, and the signing keys are public.

## 4. Actors and roles

Six roles take part. Only curators and the directory operator act on directory.aloha.social; everyone else just publishes facts or consumes lists.

| Role               | Who                                                                                 | Does                                                                                                      | Never does                                                                 |
|--------------------|-------------------------------------------------------------------------------------|-----------------------------------------------------------------------------------------------------------|----------------------------------------------------------------------------|
| Server admin       | Runs an Aloha server (Nextcloud + Aloha app)                                        | Opts in to advertising; fills in the listing profile; answers curator questions; can withdraw at any time | Sees other servers' private review notes                                   |
| Curator            | Reviewers chosen by the operator; Nextcloud staff at first, community members later | Reviews applications; lists, features, pauses and delists servers; re-reviews every 6 months              | Edits a server's self-description; lists a server against its admin's will |
| Directory operator | Nextcloud now, the foundation later                                                 | Runs directory.aloha.social; holds the signing keys; publishes the policy and the transparency log        | Hosts user accounts; sees signups or search queries                        |
| End user, newcomer | Installs the mobile or desktop app                                                  | Picks a server (or accepts the suggestion); signs up                                                      | Talks to directory.aloha.social except to download lists                   |
| End user, existing | Has an Aloha account                                                                | Searches; opts in to being discoverable                                                                   | Is indexed for search without opting in                                    |
| Client app         | Aloha mobile (iOS, Android) and desktop apps                                        | Downloads and checks the server list; recommends a server; calls the registration API                     | Trusts an unsigned list                                                    |

### 4.1 Curator team in practice

The team starts with three to five people at Nextcloud. Every listing decision needs two curators to sign it off, and a curator who is involved with a server never reviews that server.

Curators work in a web console on directory.aloha.social (sections 7 and 13.4). They sign in with a password and a TOTP code, and every action is logged. When governance moves to the foundation, the team grows with volunteers, and paid staff remain for the escalation queue.

### 4.2 Server admins as the main contributors

The directory is only as good as the servers in it, so the admin experience matters. Advertising is one switch in the Aloha admin settings. The form takes its defaults from the data the server already has: name, description, rules, terms, privacy policy and contact. A short status panel shows where the application stands, which curator comments are open, and when the last heartbeat was accepted.

## 5. Architecture overview

directory.aloha.social collects facts from servers and decisions from curators, and publishes them as two signed files. Apps and servers pull those files and then talk to each other directly.

The directory is one PHP application with a MariaDB database (section 13). The lists are static files, regenerated whenever something in them changes, so reading them never touches the application or the database.

\[embedded content: Aloha Directory architecture · who pushes, who decides, who pulls\]

The directory runs only the write side (top row). All runtime traffic, meaning signup, login, search queries and follows, goes over the dashed paths and never touches directory.aloha.social. That is why an outage of the directory delays updates to the lists, but never stops a user.

## 6. Server advertisement protocol

A server joins in three steps: it enrols with a key, sends its profile once, then sends a signed heartbeat every hour. The profile changes rarely and is reviewed by a human. The heartbeat changes often and is checked by machine only.

### 6.1 Enrolment and proof of control

1. The admin turns on *Advertise on directory.aloha.social* in the Aloha admin settings.
2. The server creates an Ed25519 key pair for the directory, kept separate from its ActivityPub keys. It stores the private key in Nextcloud's encrypted app config.
3. It sends `POST https://directory.aloha.social/api/v1/servers/enrol` with its domain and public key.
4. directory.aloha.social returns a random challenge. The server publishes the challenge at `https://<domain>/.well-known/aloha-directory`, and directory.aloha.social fetches it over HTTPS. This proves control of the domain, the same way an ACME HTTP-01 check does.
5. directory.aloha.social reads `/.well-known/nodeinfo`. It requires `software.name` to be the Aloha app's identifier and the version to be at least the current minimum (13.6). That makes this an Aloha-only directory, as decided.

From then on, every request from the server carries an HTTP Message Signature (RFC 9421) made with the directory key. Rotating the key needs a request signed with the old key, or a new challenge.

### 6.2 The listing profile

The profile is what curators review and what users see. It reuses `/api/v2/instance` fields wherever they exist, and the server app fills it in from data it already has.

| Group         | Fields                                                                                                                                                                    | Source on the server                                            |
|---------------|---------------------------------------------------------------------------------------------------------------------------------------------------------------------------|-----------------------------------------------------------------|
| Identity      | domain, name, short description (max 280 chars), long description, logo, banner                                                                                           | instance settings, theming                                      |
| Terms         | terms of service URL, privacy policy URL, rules (list), minimum age                                                                                                       | `/terms_of_service`, `/privacy_policy`, `rules` app value       |
| Audience      | primary languages (BCP 47), topic (general or a named topic), intended audience                                                                                           | new fields in the profile form                                  |
| Jurisdiction  | hosting country, operator's legal seat, GDPR applies yes/no, data processing agreement URL                                                                                | new fields                                                      |
| Operator      | operator name, type (person, association, company, public body), website, how it is funded, commitment period (e.g. "until at least 2028"), notice period before shutdown | new fields                                                      |
| Moderation    | number of active moderators, abuse contact (email + ActivityPub actor), target response time, blocklist policy, link to moderation policy                                 | new fields, plus the moderator count read from Nextcloud groups |
| Account offer | signup mode (`open` or `approval`), email check required, storage quota per account, media size limit, account features (video, shorts, DMs)                              | `external_signup_mode`, quota settings                          |
| Search offer  | which search features it provides (section 10), index policy URL, query log retention                                                                                     | new fields                                                      |

Changing a field in the *Terms*, *Jurisdiction*, *Operator* or *Moderation* groups sends the listing back to a light re-review (section 7). Changes to descriptions and images go live at once, and curators can revert them.

### 6.3 The heartbeat

Once an hour, the server's background job (next to `Cron\Cache`) sends `PUT /api/v1/servers/{domain}/heartbeat`:

```json
{
  "domain": "social.example.org",
  "software": {"name": "aloha", "version": "0.27.0", "nextcloud": "33.0.2"},
  "signup": {"open": true, "mode": "approval", "available_accounts": 412, "approval_backlog": 3},
  "usage": {"users_total": 1588, "users_active_month": 940},
  "search": {"accounts": true, "hashtags": true, "statuses": true, "trends": true},
  "health": {"queue_lag_seconds": 12, "federation_ok": true},
  "profile_etag": "W/\"7c1e\"",
  "sent_at": "2026-10-08T14:00:00Z"
}
```

- `available_accounts` is the admin's *maximum accounts* setting minus the external accounts that exist now. The admin can also give a lower advertised number, to keep a reserve for invites.
- `approval_backlog` is the number of signup requests waiting for approval. A large backlog drops the server's position in the recommendation, because newcomers would wait a long time.
- **Usage figures** are totals the server already publishes in nodeinfo. Nothing about individual users is sent.

### 6.4 What directory.aloha.social does with heartbeats

No human is involved. The service applies four rules:

- `available_accounts` of 0, or `open: false`, marks the server as **full**. It stays in the list, but apps do not offer it for signup.
- No accepted heartbeat for 3 hours marks it **stale**: it is hidden from signup. After 7 days it is **paused** and the curators are told.
- A version below the minimum, a failed TLS check or `federation_ok: false` for 24 hours also pauses it.
- A changed `profile_etag` makes directory.aloha.social fetch the profile again.

The directory keeps the exact numbers, but the server list shows capacity in buckets (13.3), and it is rebuilt within a minute whenever a server changes state or bucket (13.2). Just before signup the app also asks the server itself (section 9), so a full server is caught even if the list is a little out of date.

### 6.5 Changes needed in the Aloha server app

- `InstanceService` reports `registrations`, `approval_required` and `invites_enabled` from `external_signup_mode`, and nodeinfo reports `openRegistrations` correctly.
- A new admin settings section, *Aloha directory*, holds the opt-in switch, the profile form, the status panel and a withdraw button.
- A new background job sends the heartbeat. A new well-known handler answers the challenge.
- A registration API, described in section 9.

## 7. Curation and moderation workflow

A server gets onto the list only when two curators approve it, and it stays there only while it keeps passing the automatic checks and its review every six months. Machines decide whether a server is eligible; people decide whether it is listed.

### 7.1 Listing states

| State     | Meaning                                                                         | Visible in the apps              |
|-----------|---------------------------------------------------------------------------------|----------------------------------|
| Applied   | Enrolled and profile sent; waiting for automatic checks                         | No                               |
| In review | Checks passed; waiting for two curators                                         | No                               |
| Listed    | Approved; heartbeat fresh; has free accounts                                    | Yes, signup offered              |
| Full      | Listed, but no free accounts or signup closed                                   | Yes, marked full, no signup      |
| Featured  | Listed, and also eligible to be recommended as the default choice               | Yes, can be the suggested server |
| Paused    | Stale for 7 days, failed checks, or a curator paused it during an investigation | No                               |
| Delisted  | Removed by curators, with a public reason                                       | No                               |
| Withdrawn | Removed by the admin                                                            | No                               |

Only *Listed* and *Featured* servers can be chosen for signup. A server that keeps working through a temporary outage returns to *Listed* automatically. A server that was delisted must apply again.

### 7.2 Automatic checks before a human looks

- The domain answers over HTTPS with a valid certificate. HSTS is recommended.
- Nodeinfo names Aloha, at or above the minimum version.
- The terms of service, privacy policy and rules URLs load, and the rules list is not empty.
- An abuse contact is set, and test mail to it does not bounce.
- `/api/v2/instance` reports open or approval signup that matches the heartbeat.
- The domain is not on the directory's own block list, or on the shared fediverse block lists the curators follow (for example the Oliphant tier-0 list).
- At least three heartbeats have arrived on time.

### 7.3 The human review

Curators use a checklist so that decisions stay consistent and can be explained:

1. **Operator.** Is the operator a real, reachable person or organisation? Is the funding story believable for the promised commitment period?
2. **Moderation.** Are the rules clear and in line with the Aloha baseline policy (section 12)? Are there enough moderators for the user count, roughly one active moderator per 1,000 monthly active users? The curator sends a test report to the abuse contact. Was it answered within the promised time?
3. **Legal.** Do the terms and privacy policy exist in the server's primary languages? Do they cover data deletion and export? Is the jurisdiction stated?
4. **Experience.** A curator creates a test account. Did signup work, was approval reasonably quick, and do federation, media and search work?
5. **Mix.** Does the server add to the balance of languages, regions and topics, or does it duplicate servers already listed?

The *Mix* criterion is what keeps the list small. A good server that adds nothing new to the mix can be put on a waiting list rather than rejected.

### 7.4 Featured status

Only featured servers can be recommended as the default choice in the apps (section 9). A server must have been listed for 90 days, keep at least 98% heartbeat availability, have a low approval backlog and have no unresolved complaints. Curators feature servers so that each major language has at least two.

### 7.5 Ongoing oversight

- **Re-review every 6 months.** It uses a shortened checklist: a new test report, a test signup and a look at complaints.
- **Complaints.** Anyone can report a listed server at directory.aloha.social/report. Reports go to the curator queue, and the server admin sees the complaint (without the reporter's identity) and can respond.
- **Escalation.** For an urgent issue, such as illegal content left up, a data breach, or the operator disappearing, any single curator can *pause* a server at once. Delisting still needs two curators.
- **Notice to users.** If a server is delisted or announces its shutdown, the apps show the server's existing users a banner pointing to the account move guide. The move itself uses the existing account move tooling.

## 8. Published lists: format, signing and distribution

directory.aloha.social publishes two static JSON documents, each wrapped in a signed envelope. They can be served from any CDN or mirror, because clients check the signature and not where the file came from.

### 8.1 The two lists

| List             | URL                      | Consumers               | Rebuilt                                      | Typical size               |
|------------------|--------------------------|-------------------------|----------------------------------------------|----------------------------|
| Servers          | `/lists/v1/servers.json` | Mobile and desktop apps | Within a minute of a change; re-signed daily | \~50 entries, under 100 KB |
| Search providers | `/lists/v1/search.json`  | Aloha server app        | Within a minute of a change; re-signed daily | \~20 entries, under 20 KB  |

Logos and banners are served from the same host, under content-addressed names (`/media/<sha256>.webp`). The lists refer to them by hash, so the signature covers the images too.

### 8.2 The signed envelope

```json
{
  "payload": "<base64url of the canonical JSON list>",
  "signatures": [
    {"kid": "aloha-list-2026a", "alg": "Ed25519", "sig": "<base64url>"}
  ]
}
```

The decoded payload always contains:

- `list` (`servers` or `search`) and `version` (the schema version, `1`).
- `serial`, which only ever goes up. A client rejects any list whose serial is not higher than the one it has. This stops an attacker replaying an old list that still contained a server which has since been delisted.
- `issued_at`, plus `expires_at` 30 days later. A client still uses an expired list, but marks the data as old. After 90 days it stops recommending servers until it gets a fresh list.
- `entries`, described in the appendix.

The JSON is canonicalised (RFC 8785) before signing, so any implementation can verify it.

### 8.3 Keys and rotation

- A **root key** is kept offline, on two hardware tokens in two places. It signs only the key set.
- One or two **list keys** sign the lists. They live in an HSM or KMS on the build host.
- The apps and the server app ship with the **root public key pinned**, plus the current key set as a bootstrap. The current key set is published at `/lists/v1/keys.json`, signed by the root key.
- To rotate a list key, publish a new key set with both keys, then sign with both for 30 days, then drop the old key. A compromised list key is revoked through a new key set; clients fetch the key set whenever a list fails to verify.
- If the root key itself is lost or compromised, a new one has to be shipped in app and server releases. This is the reason it is kept offline and used no more than once or twice a year.

### 8.4 Distribution and caching

- **Mobile and desktop apps** fetch the server list on first launch and when onboarding opens, using `If-None-Match`. Each app release also bundles a copy, so onboarding works even when directory.aloha.social cannot be reached on the first run.
- **Server app.** A daily background job fetches the search list and stores it in app config. A server under heavy use can check more often, but never more than every 15 minutes.
- **Mirrors.** The lists are also published to a GitHub repository (`alohasocial/directory-lists`). Anyone can mirror them, and admins can point their server at a mirror with a config value.
- **No tracking.** The list endpoints log only aggregate counts. They set no cookies and keep no IP addresses beyond what is needed to block abuse (24 hours).

## 9. Onboarding in the mobile and desktop apps

The app suggests one server and lets the person change it in one tap. Then it creates the account natively and logs in with OAuth, and the person never sees a browser.

### 9.1 The flow

 1. **Welcome.** Two buttons: *Create account* and *I already have an account*. The second opens the existing login (server address, then OAuth).
 2. **About you (optional).** The app takes the person's language from the device and lets them change it. Nothing from this step leaves the device.
 3. **Suggested server.** The app chooses a featured server with open capacity (section 9.2) and shows it as a card. The card has the name, logo, a one-line description, language, country, operator, the moderation summary and the capacity, such as "Plenty of places" (13.3). The link *Choose a different server* opens the full list with filters for language, country, topic and signup mode.
 4. **Live check.** Before going on, the app calls the chosen server's `/api/v2/instance`. This confirms that signup is open and fetches the current rules. If the server is now full, the app moves on to the next one and says so.
 5. **Rules and terms.** The app shows the server's rules and links to its terms and privacy policy. The person must accept them before continuing. If the server has a minimum age, the app asks for a date of birth or an age confirmation.
 6. **Account details.** Username (checked live for availability), email, password, display name. The app registers itself with `POST /api/v1/apps`, then calls the registration API (9.3).
 7. **Confirm.** If email confirmation is on, the app waits for the person to open the link, which reopens the app through a universal link. In *approval* mode, the app shows "Your request is with the moderators" and the expected response time from the profile, then notifies the person when they are approved.
 8. **Log in.** OAuth with PKCE against the new server. Because the account was just created, the app uses the token the registration API returned, so the person does not enter the password again.
 9. **Home.** The person lands in their new account. Suggestions of whom to follow first are handled independently of the directory.

### 9.2 How the suggested server is chosen

The choice is made on the device, from the signed list. No data about the person is sent anywhere:

- **Filter:** state *Featured*, signup open, `capacity` not `full`, the person's language among the server's languages (or English as a fallback), and the minimum age met.
- **Score:** more weight for servers in the person's region (from the device locale), a short approval wait and a higher capacity bucket, but with a soft cap so large servers do not dominate.
- **Spread:** among the top five, pick one at random, weighted by score. Every device then makes its own choice, so new users spread across servers without any central coordination. This is how goal G3 is met.

Curators can set a weight per server within a fixed range (0.5 to 2), for example to support a new server in an underserved language. The weight is visible in the public list.

### 9.3 Registration API on the Aloha server

The app needs a Mastodon-compatible `POST /api/v1/accounts`, built on top of the existing `ExternalSignupService`:

| Aspect     | Behaviour                                                                                                                           |
|------------|-------------------------------------------------------------------------------------------------------------------------------------|
| Auth       | App token from the client-credentials grant (`POST /oauth/token`, `grant_type=client_credentials`, scope `write:accounts`)          |
| Input      | `username`, `email`, `password`, `agreement` (true), `locale`, `reason` (for approval mode), optional `date_of_birth`               |
| Output     | An access token for the new account, or `202 Accepted` with `approval_pending`                                                      |
| Limits     | Per-IP and per-app rate limits; the `max accounts` limit is respected; disposable email domains can be blocked                      |
| Anti-abuse | Optional proof of work or captcha challenge (`/api/v1/accounts/challenge`), so the native app does not need a web captcha           |
| Errors     | Mastodon's error shape (`{"error": ..., "details": {"username": [{"error": "ERR_TAKEN"}]}}`), so existing Mastodon clients work too |

### 9.4 Without the directory

The apps always accept a server typed in by hand. Server admins can share an invite link (`https://<domain>/signup?invite=...`) which opens the app directly at step 5, so servers that are not listed still get the full native experience.

## 10. Search provider directory

Curated Aloha servers take the place of the hard-coded third-party sources. A user's home server asks them on the user's behalf and never passes on who is asking, and each provider searches only accounts and posts whose authors opted in.

### 10.1 What a provider offers

A provider declares any combination of four capabilities in its profile and heartbeat:

| Capability | Endpoint on the provider            | Answers with                                                                                           |
|------------|-------------------------------------|--------------------------------------------------------------------------------------------------------|
| Accounts   | `GET /api/v1/directory/search?q=`   | Accounts (handle, display name, bio excerpt, avatar, follower count)                                   |
| Hashtags   | `GET /api/v1/directory/hashtags?q=` | Tags with use counts over 7 days                                                                       |
| Posts      | `GET /api/v1/directory/statuses?q=` | Public posts (URL, author, excerpt, date); the caller fetches the full post itself through ActivityPub |
| Trends     | `GET /api/v1/directory/trends`      | Trending tags and links over the provider's view of the network                                        |

The endpoints reuse the shapes the app already serves under `/api/v1/directories` and `/api/v1/trends`. A provider is simply an Aloha server that has these endpoints switched on for other servers.

### 10.2 What may be indexed

Only opted-in content may be indexed, as decided:

- **Accounts:** only accounts with `discoverable: true`. For remote accounts, the flag comes from their actor document (`discoverable`, or Mastodon's `indexable` for posts). Accounts without the flag are never indexed, even if the provider has seen them.
- **Posts:** only public posts by authors with `indexable: true`. Deleting a post or turning off `indexable` removes the post from the index within 24 hours.
- **Hashtags and trends:** counts are built only from indexable posts, and tags are reviewed before they trend, as the existing trends review already does.
- **Retention:** posts are indexed for at most 2 years. A provider may choose less.

A provider puts its index policy (sources, retention, opt-out) in its profile. Curators check it during review.

### 10.3 How the Aloha server uses the list

`FediverseDirectoryService` keeps its fan-out engine and gets a new source kind, `aloha`:

1. The daily job fetches `search.json`, checks its signature and stores the providers.
2. When the `directories` app value is not set, the default source list is now: local search, plus up to 4 curated providers. Providers that share the user's language come first, then a random rotation, so the load is spread.
3. The existing timeouts stay: 4 s per source, 10 s overall, with results cached for 5 minutes.
4. The current hard-coded sources (fedi.directory, mastodon.social and the others) are no longer used by default. Admins can add them back through `directories`.
5. The `directory_discovery` option (fediverse.info) and `directory_peers` remain, and are off by default when the curated list is available.
6. An admin can turn off the curated list completely (`directory_aloha_list = off`), which leaves only local and admin-chosen sources.

The Discover page and the unified search provider show which server each result came from, so users understand that search spans several servers.

### 10.4 Privacy of queries

- The query goes from the user's home server to the provider, not from the user's device. The provider sees the home server's IP and domain, but never the user's handle, IP or token.
- Requests are signed with the home server's directory key (section 6.1). This lets providers rate-limit per server instead of per user, and lets them refuse servers that abuse the service.
- Providers must keep query logs for no more than 7 days and use them only for abuse prevention. This is part of the curated policy, and a provider that breaks it is delisted.
- Home servers send no search terms to directory.aloha.social. The directory only learns how many providers a server uses, from its heartbeat.

### 10.5 Capacity and fairness

Search costs the provider CPU and storage, so it is a service the provider gives to the network. To keep it fair:

- Providers state a rate limit per calling server, for example 60 queries per minute, in the list.
- Callers respect `429 Too Many Requests` and `Retry-After`, and back off from that provider for an hour.
- The heartbeat reports a `search_load` hint. When a provider is under strain, the list builder lowers its weight.
- An Aloha server cannot be listed as a provider without itself being a well-run listed server, so the providers are servers the curators already trust.

## 11. Trust, security and privacy

The worst outcome is a malicious server in the list, sending thousands of newcomers to a phishing site. The design blocks it at three layers: signed lists, domain proof and human review. The second risk is the directory becoming a surveillance point, and the design avoids it by keeping directory.aloha.social off every runtime path.

### 11.1 Threats and mitigations

| Threat                                             | Impact                          | Mitigation                                                                                                                    |
|----------------------------------------------------|---------------------------------|-------------------------------------------------------------------------------------------------------------------------------|
| CDN, mirror or network attacker changes a list     | Users sent to a hostile server  | Ed25519 signatures with a pinned root key; serial numbers stop replays (8.2, 8.3)                                             |
| List signing key stolen                            | Attacker can sign lists         | Key in an HSM; root key offline; revocation through the key set; serial jumps are monitored and alarmed                       |
| Fake server applies with someone else's domain     | Impersonation                   | HTTP challenge on the domain; the nodeinfo check; human review with a test account                                            |
| Listed server is taken over or turns hostile later | Newcomers sent to a bad server  | Hourly heartbeats; TLS and nodeinfo monitoring; any single curator can pause at once; a pause leaves the list within a minute |
| Server lies about capacity or moderation           | Bad experience                  | The live check before signup; test reports in reviews; complaints queue                                                       |
| Spam bots use the registration API                 | Spam accounts on listed servers | Rate limits, challenge endpoint, approval mode, blocking disposable email domains (9.3)                                       |
| Directory account of a curator compromised         | Bad listing decisions           | Password plus TOTP; two curators for every listing and delisting; audit log                                                   |
| Search provider logs or sells queries              | Privacy breach                  | Server-side proxying without user identity; 7-day log limit in the policy; delisting                                          |
| Directory becomes a single point of failure        | No onboarding                   | Bundled lists in the apps, mirrors, 30-day validity (13.7)                                                                    |

### 11.2 What directory.aloha.social knows

| Data                           | Held                                         | Retention                                                        |
|--------------------------------|----------------------------------------------|------------------------------------------------------------------|
| Server profiles and heartbeats | Yes, they are public facts about the servers | Profiles while listed; heartbeats 90 days, aggregated after that |
| Admin contact for each server  | Yes                                          | While enrolled, plus 1 year                                      |
| Curator notes and decisions    | Yes; the reasons for delistings are public   | Permanent audit log                                              |
| Who signed up where            | **No**                                       | Signup goes straight from app to server                          |
| Search queries                 | **No**                                       | Queries go from server to provider                               |
| IP addresses of list downloads | Only for abuse blocking                      | 24 hours                                                         |

No analytics SDK runs in the onboarding screens. Signup counts per server come from the servers' own aggregated heartbeats.

### 11.3 Legal frame

Nextcloud GmbH (Germany) is the operator in phase one, so the GDPR applies. directory.aloha.social processes the personal data of server admins, while user data stays the responsibility of each server. The privacy notice for directory.aloha.social will say so. Listed servers outside the EU are allowed, but the server card shows their jurisdiction clearly. A *Digital Services Act* question is still open: whether curating a list makes Nextcloud responsible for the servers on it (section 16).

## 12. Governance, policies and delisting

Nextcloud runs the directory in phase one, under published rules. Control moves to a community foundation once the directory is stable, which is planned for about 12 months after launch. Rules, decisions and keys are designed from the start so that this handover is a transfer, not a rebuild.

### 12.1 The Aloha Server Covenant

Every listed server agrees to a short baseline. It follows the Mastodon Server Covenant, adapted for Aloha:

1. **Active moderation** against racism, sexism, homophobia and transphobia, and against harassment and illegal content.
2. **Daily backups**, and a tested restore process.
3. **At least two people** with emergency access to the server's infrastructure.
4. **At least 3 months' notice** before shutting down, while keeping account export and move working.
5. **Data portability:** account export and account move are never switched off.
6. **No tracking or advertising** that uses users' personal data.
7. **Honest data:** the profile and heartbeat are true and up to date.
8. **Search providers only:** the opt-in index rules and the 7-day log limit (section 10).

### 12.2 Phase one: Nextcloud as operator

- Nextcloud names the curator team and holds the signing keys.
- An **advisory board** of five, including at least two admins of listed servers, reviews the policy twice a year and hears appeals.
- Changes to the policy are announced 30 days ahead on directory.aloha.social and in the Aloha admin settings.
- Nextcloud does not list its own commercial hosting with any advantage. If a Nextcloud-run server is listed, it goes through the same review, and members of the advisory board who are not Nextcloud staff decide on it.

### 12.3 Phase two: foundation

The handover is complete when a non-profit foundation or association is set up with a charter, funding for at least two years and its own curator team. The handover then moves:

- the directory.aloha.social host name (delegated in DNS) and the infrastructure;
- the root signing key, through a key ceremony with a signed announcement;
- the curator roles and the audit log.

The apps and the server app need no change, because they trust the root key, not the organisation.

### 12.4 Delisting and appeals

| Step                                                                           | Who            | Time                           |
|--------------------------------------------------------------------------------|----------------|--------------------------------|
| Pause during an investigation (optional)                                       | One curator    | Immediately                    |
| Notice to the admin with the specific concern                                  | Curators       | Within 24 h of the pause       |
| Admin's response                                                               | Admin          | 14 days                        |
| Decision: back to listed, or delisted                                          | Two curators   | Within 7 days of the response  |
| Public entry in the transparency log (domain, date, rule broken, short reason) | Automatic      | When the decision is made      |
| Appeal                                                                         | Advisory board | Within 30 days of the decision |

A delisted server may apply again after 6 months. A server withdrawn by its admin may come back at any time, through the normal review.

### 12.5 Transparency

- A public page shows every listed server, its state, its review dates and its curator weight.
- A **transparency report** comes out every quarter. It gives the number of applications, approvals, rejections by reason, delistings, appeals and complaints.
- The signed lists and their history are public on GitHub, so anyone can see exactly what changed and when.

## 13. The directory application, operations and failure modes

directory.aloha.social is one PHP application with a MariaDB database. It takes a few thousand signed heartbeats an hour, serves a small curator console and writes static files that the web server and a CDN hand out. It can run on two small VMs plus a CDN, and it can lose its whole backend for weeks without users noticing.

### 13.1 Technology and components

| Component               | Load estimate                                                             | Technology                                                                       |
|-------------------------|---------------------------------------------------------------------------|----------------------------------------------------------------------------------|
| Application             | 50 to 500 servers × 1 heartbeat per hour, under 1 request per second      | PHP 8.3+ with Symfony, behind a rate limiter                                     |
| Database                | Under 1 GB in the first years                                             | MariaDB 10.11+; nightly dumps plus binary logs for point-in-time restore         |
| Curator console         | A handful of users                                                        | Server-rendered pages (Twig) in the same application; password plus TOTP         |
| Checker                 | Fetches nodeinfo, TLS, instance and well-known for each server every hour | Symfony console command, run by cron                                             |
| List builder and signer | Within a minute of a change; every list re-signed daily                   | Symfony console command, run by cron every minute; signs with the list key (8.3) |
| Static publication      | All app installs; heavy spikes on app launches or press coverage          | Files served by the web server, a CDN in front, the GitHub mirror                |
| Public website          | Visitors browsing servers and policies                                    | Static HTML written by the list builder; one dynamic page, the complaint form    |

The application has three entry points in one codebase: the API for Aloha servers (enrolment, profile, heartbeat, status and withdrawal; Appendix A.1), the curator console, and console commands run by cron (checks, list building, the daily re-sign, and cleanup of old heartbeats and logs). Symfony is widely known, so a foundation team or outside contributors can take the code over without learning a niche framework.

The directory is built as its own open-source project (`alohasocial/directory`, AGPL-3.0), so the foundation can run it later and anyone can check what it does. A Docker Compose setup lets a contributor run it locally in a few minutes.

### 13.2 Static lists, regenerated on change

Reading a list never touches PHP or the database. `servers.json`, `search.json` and `keys.json` are files on disk, served by the web server with ETags and cached by the CDN. The load stays flat even when every app install fetches the list on the same day.

The lists are rebuilt only when something in them changes:

1. Every write that affects a list marks that list as changed: a curator decision, an accepted profile change, and a heartbeat that moves a server to another state or another capacity bucket (13.3).
2. A console command runs every minute from cron. It rebuilds each list marked as changed, signs it, writes it to a temporary file and renames it into place, so a client never reads half a file. Several changes within the same minute produce one rebuild.
3. Each build is kept with its serial in a `list_builds` table and pushed to the GitHub mirror. The history feeds the transparency page (12.5) and lets curators compare two versions.
4. Once a day every list is signed again even if nothing changed, because a list expires 30 days after it was issued (8.2).

A pause or a delisting is therefore out of the published list within about a minute.

### 13.3 Capacity in buckets

Servers report exact numbers in their heartbeat, and the directory keeps them for monitoring and for the curators. The published list carries buckets instead, so an hourly heartbeat changes the list only when a server crosses a threshold:

| Field           | Buckets                                                                                                        |
|-----------------|----------------------------------------------------------------------------------------------------------------|
| `capacity`      | `plenty` (100 or more free accounts), `some` (20 to 99), `few` (1 to 19), `full` (none free, or signup closed) |
| `approval_wait` | `none` (open signup, or nothing waiting), `short` (under 20 requests waiting), `long` (20 or more)             |

The thresholds are settings of the directory, not part of the list format. The apps show the bucket ("Plenty of places", "A few places left") and use it to score servers (9.2). Just before signup they still ask the server itself (9.1, step 4), which gives the exact state.

### 13.4 Curator web interface

The console is simple but complete: server-rendered pages (Symfony with Twig and a little plain JavaScript), no single-page app.

| Page             | What it does                                                                                                                                                                             |
|------------------|------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| Queue            | Applications, re-reviews that are due, open complaints and servers paused automatically, oldest first, with a count on each tab                                                          |
| Server           | The profile and a diff against the last approved version; the automatic check results with their reasons; the heartbeat history; curator notes; the review checklist of 7.3; the actions |
| Search providers | The same page for providers, plus their capabilities, rate limit and load                                                                                                                |
| Complaints       | The private queue of reports; the server admin sees the text without the reporter's identity, in the Aloha admin settings                                                                |
| List builds      | The current version of each list, its history, a diff between two versions and a button to rebuild now                                                                                   |
| Audit log        | Every action with who did it and when, filterable and read-only                                                                                                                          |
| Curators         | Admins invite and deactivate curators, set roles (curator, admin) and record conflicts of interest                                                                                       |
| Account          | Password, TOTP device and recovery codes                                                                                                                                                 |

The interface enforces the two-curator rule: one curator *proposes* listing, featuring, delisting or a weight change, with a reason, and a second curator *confirms* it. A curator with a declared conflict cannot confirm for that server. A pause needs only one curator and takes effect at once.

Curators sign in with a password and a TOTP code, and get recovery codes at setup. Sessions are short, failed sign-ins are rate-limited, and every action goes to the audit log. Server admins never sign in here: they see their status, the curators' questions and complaints in the Aloha admin settings, through the API.

### 13.5 Public website

The public website at directory.aloha.social is part of the same application. The list builder renders it as static HTML from the same data whenever a list changes:

- a browsable directory of the listed servers, with filters for language, country, topic and signup mode;
- the Aloha Server Covenant, the listing criteria and the policies (section 12);
- the transparency page: delistings with their reasons, the quarterly reports and the list history (12.5);
- the signing keys and how to check a list against them.

The only dynamic page is the complaint form at `/report`, which writes into the curators' queue (7.5).

### 13.6 Minimum versions

The directory publishes a `min_server_version`. Servers below it are paused after 30 days' warning. The minimum is raised for security fixes and for changes the directory depends on. This also gives listed servers a reason to keep up with releases, which protects the newcomers sent to them.

### 13.7 Failure modes

| Failure                                     | What users notice                                                                                 | Recovery                                                                                                                  |
|---------------------------------------------|---------------------------------------------------------------------------------------------------|---------------------------------------------------------------------------------------------------------------------------|
| directory.aloha.social API down             | Nothing; heartbeats are queued by servers and resent                                              | Restart; heartbeats catch up                                                                                              |
| CDN down                                    | Apps use cached or bundled list; servers use stored lists                                         | Mirror or second CDN                                                                                                      |
| List builder stops                          | Lists keep their last state and capacity buckets go stale; the live check before signup covers it | Alarm when a change waits longer than 10 minutes, or no list was signed for 26 hours; lists valid 30 days                 |
| Signing key revoked                         | Brief delay while clients fetch the new key set                                                   | Key set signed by the root key                                                                                            |
| A listed server goes down                   | It disappears from signup within 3 hours                                                          | Automatic return when heartbeats resume                                                                                   |
| Search provider slow                        | Its results come late or not at all (4 s timeout)                                                 | Load hint lowers its weight; caller backs off                                                                             |
| Sudden surge (for example a Twitter exodus) | Featured servers fill up                                                                          | Weighted spread; full servers hidden at once; curators can fast-track waiting applications; admins can raise their limits |

### 13.8 Monitoring

The team watches heartbeat arrival rate, the number of servers with free places, total free places, list build age, signature failures reported by clients, and the curator queue age. An alarm fires when total free places fall below 1,000 or when no list has been signed for 26 hours.

### 13.9 Cost

The infrastructure costs a few hundred euros a month. The real cost is people. At about 50 servers with reviews every 6 months, plus applications and complaints, the curator workload is estimated at 1 to 1.5 full-time staff in the first year.

## 14. Rollout plan and milestones

As decided, server signup and search launch together, about six months after the project starts. Three gates protect the date: no build work before the legal opinion, no beta before 10 reviewed servers, and no launch without 20 listed servers covering 5 languages.

\[embedded content: Rollout · four phases, three gates, launch in month 6\]

The work runs in three parallel streams. The **directory service** is a new repository: the Symfony application with its curator console and public website. The **Aloha server app** changes are split into small PRs: instance API fixes, then the registration API, heartbeat and settings, and search provider endpoints. The **mobile and desktop apps** add onboarding. Recruiting servers for the beta should start in month 2, because the list is only as good as the servers in it. After launch, the first quarterly transparency report is due in month 9, and work on the foundation handover starts in month 12.

## 15. Bluesky (AT Protocol)

The directory also works for Aloha's Bluesky side, with one change per list:

- **Signup** carries over unchanged, and the newcomer gets a Bluesky identity as well.
- **Search** for Bluesky goes through Bluesky's own AppView rather than the curated providers.

This section builds on the atproto spec (PR #2497, decisions D1 to D21). Under that spec, every Aloha account is also a Bluesky account hosted by its own server.

### 15.1 Signup: one account, two networks

When a server has Bluesky turned on, every account created through the directory also gets a `did:plc` identity and the handle `alice.<host>` (D3, D4). The newcomer signs up once and is reachable from both networks. Nothing changes in the flow of section 9 except four additions:

1. **Profile and heartbeat** gain an optional `atproto` block:

| Field              | Meaning                                                               |
|--------------------|-----------------------------------------------------------------------|
| `enabled`          | Bluesky is turned on for the server (`atproto_enabled`)               |
| `handle_example`   | What a new handle looks like, e.g. `alice.social.example.org`         |
| `handles_ok`       | Wildcard DNS and certificate work; `/.well-known/atproto-did` answers |
| `relay_last_crawl` | When the relay last read the server's firehose                        |
| `move_away`        | Moving an account away to another PDS is supported (atproto phase 4)  |

2. **Automatic checks** (7.2) also fetch `/.well-known/did.json` and resolve the handle of a test account. A server with Bluesky enabled but broken handles is listed without Bluesky, never with a broken promise.
3. **The registration API** checks the Bluesky handle the username maps to: lowercase, `_` and `.` turned into `-`, and a numeric suffix on collision. The app shows both handles in step 6, before the account is created.
4. **The recovery phrase.** With `did:plc`, a person can take their identity elsewhere even if their server disappears, if they kept the recovery phrase. The apps prompt the person to save it after the first login. Once phase 4 ships, the covenant (12.1) adds that moving away over atproto stays enabled.

In the server list, Bluesky support is a filter and a badge on the server card, not a listing requirement.

### 15.2 Signup from the Bluesky app

The atproto spec never offers open registration through `com.atproto.server.createAccount`; it is used only to move accounts in. People using the official Bluesky app therefore cannot create an account on an Aloha server; they sign up through the Aloha apps.

| Option                                               | For                                                                                                             | Against                                                                                              |
|------------------------------------------------------|-----------------------------------------------------------------------------------------------------------------|------------------------------------------------------------------------------------------------------|
| Keep it closed (the spec today)                      | One signup path, one place for the terms, the approval queue and abuse limits                                   | Bluesky users who want to try Aloha must install another app                                         |
| Open `createAccount` on listed servers after phase 3 | Signup from the Bluesky app's "hosting provider" screen; the terms and privacy links come from `describeServer` | A second signup path to secure, rate-limit and keep in line with the approval mode and account limit |

Recommendation: keep it closed at launch, and decide again once the Bluesky app can log in to Aloha (phase 3).

### 15.3 Search

Bluesky already has a central search index: the AppView indexes every repository the relay crawls, Aloha's included. The spec adds a `bluesky` source to `FediverseDirectoryService` (atproto §9.1), using `app.bsky.actor.searchActorsTypeahead` on the public AppView. The directory changes accordingly:

- **The AppView is named in** `search.json` as its own entry kind (`atproto_appview`), not hard-coded in the app. This lets the endpoint be switched centrally if the public endpoint becomes rate-limited, or if an alternative AppView becomes a better choice.
- **Curated providers do not index Bluesky content.** They stay an ActivityPub feature, and Bluesky search is the AppView's job.
- **Queries stay anonymous.** The home server calls the public AppView without logging in. It does not use the user's service-auth token, because that would show the AppView the user's DID and break the rule in 10.4.

One mismatch must be stated openly. Section 10.2 indexes only opted-in accounts, but every public post goes to Bluesky (D8), and the AppView indexes it whatever the user's Aloha `indexable` setting says. Two mitigations, neither complete:

1. Map `discoverable: false` to the account self-label `!no-unauthenticated`, which asks Bluesky apps to hide the account from logged-out viewers. Verify how the AppView treats it before relying on it.
2. The per-user Bluesky opt-out that the atproto spec already lists as an open question (deactivating the person's repository).

Whichever is chosen, the privacy settings must say plainly that public posts are visible and searchable on Bluesky.

### 15.4 Timing

| Directory feature                                                               | Needs atproto phase           |
|---------------------------------------------------------------------------------|-------------------------------|
| Bluesky fields in the profile and heartbeat; signup that gives a Bluesky handle | 1 (visible on Bluesky)        |
| Bluesky search through the AppView                                              | 2 (reading Bluesky)           |
| Signup from the Bluesky app (`createAccount`)                                   | after 3 (Bluesky apps log in) |
| Moving away as a covenant item                                                  | 4 (moving)                    |

Every atproto field in the list schemas is optional, so the directory launches in month 6 whatever stage the atproto work has reached.

## 16. Open questions and decisions log

### 16.1 Decisions taken (interview, 2026-10-08)

| #   | Question                                   | Decision                                                                                                               |
|-----|--------------------------------------------|------------------------------------------------------------------------------------------------------------------------|
| D1  | Who runs directory.aloha.social?           | Nextcloud first, then a community foundation                                                                           |
| D2  | Who may advertise free accounts?           | Aloha servers only                                                                                                     |
| D3  | How does the app pick a server?            | It recommends one, picked at random (weighted by score) among the top five matches, and lets the person choose another |
| D4  | How is the account created?                | Natively, through a registration API on the server                                                                     |
| D5  | Profile contents                           | Languages and jurisdiction, moderation policy and contact, operator and funding, topic and media                       |
| D6  | How is capacity reported?                  | A signed hourly heartbeat pushed by the server                                                                         |
| D7  | How strict is curation?                    | A small hand-picked list                                                                                               |
| D8  | Does directory.aloha.social host accounts? | No, it is a directory only                                                                                             |
| D9  | Search capabilities                        | Accounts, hashtags, full-text posts and trends                                                                         |
| D10 | Search index scope                         | Opted-in accounts and posts only                                                                                       |
| D11 | How the server app uses the search list    | It replaces the hard-coded sources; the admin can override it                                                          |
| D12 | Query privacy                              | The home server proxies queries without any user identity                                                              |
| D13 | List integrity                             | Signed JSON, with the key pinned in the apps and the server app                                                        |
| D14 | Timeline                                   | Both parts in about 6 months                                                                                           |

### 16.2 Decisions taken (2026-11-13)

| #   | Question                       | Decision                                                                    |
|-----|--------------------------------|-----------------------------------------------------------------------------|
| D15 | Where does the directory live? | `https://directory.aloha.social`                                            |
| D16 | How is the directory built?    | A standalone application in PHP (Symfony) with MariaDB                      |
| D17 | How are the lists served?      | As static files, rebuilt when something in them changes and re-signed daily |
| D18 | How is capacity published?     | In buckets; the exact numbers stay in the directory                         |
| D19 | How do curators sign in?       | Password plus TOTP                                                          |
| D20 | Where is the public website?   | Part of the same application, written as static pages                       |

### 16.3 Open questions

- [ ] **Legal (DSA):** does curating the list make Nextcloud responsible for the listed servers? This needs a legal opinion before launch.
- [ ] **Paid accounts:** should the list later include servers that offer paid plans, including Nextcloud's own hosting partners? If so, under what neutrality rules?
- [ ] **Non-Aloha servers in search:** curated search providers are Aloha-only. Should well-run Mastodon servers with a public directory API also be allowed as providers?
- [ ] **App store review:** Apple and Google require in-app account deletion and content moderation for apps with signup. Confirm that the Aloha account deletion flow and the report features in the apps meet these rules.
- [ ] **Age checks:** some countries require age verification. Is the self-declared minimum age enough?
- [ ] **Foundation:** which legal form, and who funds it?
- [ ] **Curator staffing:** 1 to 1.5 full-time staff in year one. Is the budget there?
- [ ] **Branding:** the directory lives at `https://directory.aloha.social`. Is its user-facing name *Aloha Directory*, and what does aloha.social itself show?
- [ ] **atproto:** the Aloha atproto spec rules out registration over atproto. Should the server list still show which servers bridge to Bluesky?


* [ ] **Bluesky app signup:** should listed servers accept `createAccount` from the Bluesky app once atproto phase 3 ships (15.2)?
* [ ] **Bluesky indexing and opt-in:** public posts become searchable on Bluesky whatever the Aloha `indexable` setting says. Should the answer be the `!no-unauthenticated` label, a per-user Bluesky opt-out, or both (15.3)?

## Appendix A. Schemas and API reference

### A.1 Directory API (directory.aloha.social)

| Method + path                              | Caller          | Purpose                                                                               |
|--------------------------------------------|-----------------|---------------------------------------------------------------------------------------|
| `POST /api/v1/servers/enrol`               | Server          | Send domain and directory public key; returns a challenge                             |
| `POST /api/v1/servers/{domain}/verify`     | Server          | Ask directory.aloha.social to fetch the challenge from `/.well-known/aloha-directory` |
| `PUT /api/v1/servers/{domain}/profile`     | Server (signed) | Send or update the listing profile                                                    |
| `PUT /api/v1/servers/{domain}/heartbeat`   | Server (signed) | Hourly heartbeat                                                                      |
| `GET /api/v1/servers/{domain}/status`      | Server (signed) | Listing state, open curator comments, last accepted heartbeat                         |
| `DELETE /api/v1/servers/{domain}`          | Server (signed) | Withdraw from the directory                                                           |
| `POST /api/v1/reports`                     | Anyone          | Complain about a listed server                                                        |
| `GET /lists/v1/{servers,search,keys}.json` | Everyone        | The signed lists                                                                      |

### A.2 Server list entry

```json
{
  "domain": "social.example.org",
  "name": "Example Social",
  "description": "A friendly general-purpose server for people in Hamburg.",
  "logo": "sha256:9f2c...",
  "languages": ["de", "en"],
  "topic": "general",
  "region": {"hosting": "DE", "operator_seat": "DE", "gdpr": true},
  "operator": {"name": "Example e.V.", "type": "association", "funding": "donations", "commitment_until": "2028-12"},
  "moderation": {"moderators": 4, "response_hours": 24, "policy_url": "https://social.example.org/rules"},
  "terms_url": "https://social.example.org/terms",
  "privacy_url": "https://social.example.org/privacy",
  "min_age": 16,
  "signup": {"mode": "approval", "email_required": true, "state": "open", "capacity": "plenty", "approval_wait": "short"},
  "account": {"storage_mb": 2048, "features": ["video", "shorts", "dm"]},
  "state": "featured",
  "weight": 1.0,
  "reviewed_at": "2026-09-30"
}
```

### A.3 Search provider entry

```json
{
  "domain": "search.example.net",
  "capabilities": ["accounts", "hashtags", "statuses", "trends"],
  "languages": ["en", "de", "fr"],
  "rate_limit_per_minute": 60,
  "index_policy_url": "https://search.example.net/index-policy",
  "log_retention_days": 7,
  "weight": 1.0
}
```

### A.4 New and changed endpoints in the Aloha server app

| Endpoint                                                  | Change                                                                                         |
|-----------------------------------------------------------|------------------------------------------------------------------------------------------------|
| `/api/v1/instance`, `/api/v2/instance`, nodeinfo          | Report registration state from `external_signup_mode`                                          |
| `POST /api/v1/accounts`                                   | New: native registration (9.3)                                                                 |
| `GET /api/v1/accounts/challenge`                          | New: anti-abuse challenge for native signup                                                    |
| `/.well-known/aloha-directory`                            | New: domain proof for enrolment                                                                |
| `GET /api/v1/directory/{search,hashtags,statuses,trends}` | New for providers: answer signed queries from other servers (10.1)                             |
| Admin settings                                            | New *Aloha directory* section: opt-in, profile, status, search provider switch, list overrides |

### A.5 New app config values

| Key                             | Default | Meaning                                                         |
|---------------------------------|---------|-----------------------------------------------------------------|
| `directory_advertise`           | `off`   | Take part in the server list                                    |
| `directory_advertised_accounts` | empty   | Upper limit for `available_accounts` sent in the heartbeat      |
| `directory_search_provider`     | `off`   | Offer search to other servers                                   |
| `directory_aloha_list`          | `on`    | Use the curated search providers                                |
| `directory_list_mirror`         | empty   | Fetch the lists from a mirror instead of directory.aloha.social |
