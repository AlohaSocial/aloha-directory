# Operating the directory

For the directory operator (Nextcloud in phase one, the foundation later).

## Topology (concept 13.1)

Two small VMs behind a CDN are enough:

- **Web/app hosts**: nginx and PHP-FPM.
- **One cron host**: or both hosts, if `lock_dsn` points at the database.
- **Background workers** under systemd or as containers: one running `bin/console messenger:consume verify checks --time-limit=3600 --memory-limit=256M` and one running `messenger:consume checks …`, so verification requests can never starve the checks. They verify new domains and run the hourly checks. Add workers to check faster.
- **MariaDB 10.11+**: nightly dumps plus binary logs for point-in-time restore.

Curator sessions and rate-limiter state are stored in the database in production, so any host can answer any request.

The lists, media and website are **static files** in `output_dir`, served by nginx and cached by the CDN. The application and the database can be down for weeks without users noticing (concept 13.7). Only list updates stop.

## Key ceremony (concept 8.3)

On an **offline** machine with a checkout of this repository:

```sh
bin/console app:keys:generate /media/token/aloha-root-2026.key       # root key: on a hardware token, kept in two places
bin/console app:keys:generate aloha-list-2026a.key                    # list key: moves to the build host or HSM
bin/console app:keys:sign-keyset --root-key=/media/token/aloha-root-2026.key --root-kid=aloha-root-2026 \
    --serial=1 --key="aloha-list-2026a=<public key>" --out=keys.json
```

1. Ship the **root public key** in the apps and in the server app, and set it as `root_public_keys` in `config.php`.
2. Copy the list key file to the directory host. Make it mode `0640`, owned by the PHP user's group. Files that other users can read are refused.
3. Set it in `list_signing_keys`.
4. Run `bin/console app:keys:install keys.json`. The install verifies the key set against the pinned root key before publishing it, and warns if a configured signing key is not active in it.

The list builder verifies every list with the same code clients use before publishing it, so a wrong key configuration never reaches a client. To use an HSM or KMS, implement `App\Crypto\ListSigner` and alias it in `config/services.yaml`.

### Rotating a list key

1. Generate the new list key.
2. Sign a key set (next serial) that contains **both** keys as `active`, and install it.
3. Configure both keys in `list_signing_keys`. Every list now carries two signatures.
4. After 30 days, sign a key set with the old key `revoked`, install it, and remove the old key from `config.php`.

### A compromised list key

1. Sign and install a key set that marks it `revoked`, and remove it from `config.php`.
2. Run `bin/console app:tick --force` to re-sign the lists with the remaining key.
3. Clients fetch `keys.json` again when a list fails to verify.

### Loss of the root key

The apps and the server app must ship a new pinned root key. This is why the root key stays offline and is used only for key sets.

## Monitoring (concept 13.8)

- **`GET /metrics`** (Prometheus format, `Authorization: Bearer <metrics_token>`) reports:
  - servers by state;
  - servers with free places and the total of free places;
  - heartbeats in the last hour;
  - the age of each list;
  - how long a change has waited for a rebuild;
  - the oldest item in the curator queue;
  - client-reported verification failures;
  - the age of the cron jobs;
  - waiting background jobs and the age of the oldest per queue;
  - whether a list is held by the change guard;
  - how concentrated newcomers are (Herfindahl index and the largest server's share);
  - the audit chain: unsealed entries and whether the last verification succeeded.
- **`bin/console app:monitor`** exits with status 1 and prints the alarms of the concept:
  - fewer than 1,000 free places;
  - no list signed for 26 hours;
  - a change waiting more than 10 minutes;
  - the minute or hourly job not running;
  - a list held by the change guard;
  - a job waiting too long in a queue (no worker running?) or failed jobs;
  - one server taking more than half of all newcomers;
  - an audit chain that does not verify, or entries not chained.

  Wire it into your alerting.
- **`GET /healthz`** is for the load balancer.
- **Serial jumps**: alert on a serial that does not come from your own builds. The Git mirror and the `list_build` table hold the full history.

## Curators

1. Create the first admin with `bin/console app:curator:invite <email> "<name>" --admin`.
2. Open the printed link within 72 hours, then set a password and register a passkey (phone, computer or security key). The person gets ten recovery codes.

After that, admins invite curators in the console (*Curators*). Every curator should register a second passkey under *Account*, so losing one device does not lock them out.

## Audit chain

`app:maintenance` publishes the signed head of the audit hash chain every day (`/transparency/audit/<day>.json`, also in the Git mirror) and verifies the whole chain against all published heads. `bin/console app:audit:verify` does the same on demand. If it fails, treat it as a security incident: the audit log was changed after the fact. Compare the database with a backup and with the published heads to find out which entries were touched.

## List change guard

When a build would add or remove more than `list_change_guard_percent` (10%) of the entries, and more than `list_change_guard_min` (3), it is held. The curators get an e-mail, and *List builds* shows what would change. Check for an outage of the directory's network first (servers paused for failing checks), then a bug or an attack. Release the build with a reason, or wait: if the cause goes away, the hold clears by itself.

## Load testing

See [loadtest.md](loadtest.md).

## Block lists

The directory's own list is edited in the console. Shared lists are imported, and the import replaces the previous import of the same name:

```sh
bin/console app:blocklist:import oliphant-tier0 _unified_tier0_blocklist.csv
```

A listed server that becomes blocked is paused by the next hourly check.

## Retention (concept 11.2)

| Data | Kept |
|---|---|
| Heartbeats | 90 days, then aggregated per day (`heartbeat_daily`) |
| Admin contact | while enrolled, plus 1 year after withdrawal, rejection or delisting |
| Reporter e-mail of a complaint | until 90 days after the complaint is closed |
| Unverified enrolments | 7 days |
| Replay-protection records | 10 minutes |
| IP addresses | never stored; only hashed rate-limiter keys, which expire after one hour; nginx and PHP-FPM log no IPs |
| Audit log, list builds, transparency entries | permanent (list builds: `list_build_retention_days`, 10 years by default) |

`app:maintenance` applies the retention rules daily. All periods are `thresholds` in `config.php`.

## Backups and restore

- **What to back up:**
  - the database (nightly dump plus binary logs);
  - `config.php`;
  - the list key files.

  The output directory can be regenerated with `bin/console app:tick --force --site`.
- **After restoring an old database**, serials still only go up. A serial is `max(previous + 1, current Unix time)`, so a restored database can never publish a serial that clients have already seen.

## Failure handling (concept 13.7)

| Symptom | Action |
|---|---|
| `app:monitor` reports a stale list | Check the cron container or host and the logs, then run `bin/console app:tick -v` by hand. |
| Signing fails | `app:tick` logs the reason (missing or unreadable key file, file permissions, key not in key set). The lists keep their last version and stay valid for 30 days. |
| Surge of new users | Full servers drop out of signup within a minute. Curators can fast-track waiting applications (*Queue → Waiting list*) and ask featured admins to raise their limits. |
| A listed server is hostile | Any single curator can *Pause now*. The list is rebuilt within a minute. |
| New servers stay "queued" in verification | No worker is running: start `messenger:consume verify checks`. `app:monitor` reports it. |
| A list is held | See *List change guard* above. |
| A curator lost all passkeys | They sign in with a recovery code and add a new passkey. Without codes, an admin deactivates the account and invites the person again. |
