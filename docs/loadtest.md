# Load testing

The load test checks that a directory deployment meets its response-time targets with thousands of servers sending heartbeats while apps download the lists and people browse the website. It uses [k6](https://k6.io) and fake servers that exist only in a **staging copy** of the directory.

Never run it against production: it adds fake servers to the lists.

## Targets

| What | Who calls it | p95 | p99 |
|---|---|---|---|
| `PUT /api/v1/servers/{domain}/heartbeat` | every server, hourly | 250 ms | 600 ms |
| `GET /api/v1/servers/{domain}/status` | servers, admins | 200 ms | 500 ms |
| `GET /lists/v1/servers.json` (static, with `If-None-Match`) | apps on onboarding | 50 ms | 150 ms |
| Website pages (static) | visitors | 50 ms | 150 ms |

On top of that, fewer than 1% of requests may fail, and every heartbeat must be accepted. The thresholds in `tools/loadtest/directory.js` encode these targets; k6 exits with a non-zero code when one is missed, so the test can run in CI against a staging deployment.

For scale: 5,000 servers sending one heartbeat per hour is 1.4 requests per second. The default rates test several times that, so there is headroom for retries after an outage, when many servers report at once.

## Preparing staging

1. Use a copy of the production setup: same hardware, PHP-FPM settings, MariaDB and nginx.
2. Set `'loadtest_enabled' => true` in staging's `config.php` (it is off by default), and make sure `mirror_repo_dir` is **not** set. The seed command refuses to run otherwise, so fake servers can never reach the public Git mirror. Give staging its own list and root keys, so its lists can never pass for production's.
3. Stop the hourly `app:check` cron job for the duration of the test. The fake domains do not resolve, so the checks would pause them after two runs.
4. Seed the fake servers. Choose a secret seed, passed as an environment variable so it stays out of the shell history; k6 derives the same key pairs from it. Seeding and purging are recorded in the audit log.

   ```sh
   export LOADTEST_SEED=$(openssl rand -hex 16)
   bin/console app:loadtest:seed --servers=5000 --not-production
   ```

   The command prints the domain suffix, `.loadtest.<directory host>`. Only the operator controls that name, so nothing is ever sent to a third party.
5. Publish the list. Adding thousands of servers at once trips the [change guard](../SECURITY.md), so release the held build in the console under *List builds*, with "load test" as the reason.

## Running

Install k6 (version 0.50 or newer), then:

```sh
k6 run \
  -e BASE_URL=https://staging-directory.example.org \
  -e SEED="$LOADTEST_SEED" -e SERVERS=5000 -e SUFFIX=.loadtest.staging-directory.example.org \
  tools/loadtest/directory.js
```

| Variable | Default | Meaning |
|---|---|---|
| `BASE_URL` | `http://localhost:8080` | The staging directory |
| `AUTHORITY` | host of `BASE_URL` | Must equal `public_url`'s host, which signatures cover |
| `SEED`, `SERVERS`, `SUFFIX` | — | As given to and printed by `app:loadtest:seed` |
| `HEARTBEAT_RATE` | 10 | Heartbeats per second; `SERVERS / HEARTBEAT_RATE` must be at least 300, because the directory accepts one heartbeat per server every 5 minutes |
| `STATUS_RATE`, `LIST_RATE`, `PAGE_RATE` | 5, 50, 10 | Requests per second of the other scenarios |
| `DURATION` | `5m` | Length of every scenario |
| `MIN_VERSION` | `0.27.0` | Software version reported in the heartbeats |

The heartbeat scenario signs every request exactly as an Aloha server does (RFC 9421, Ed25519, a fresh nonce each time), so the test includes signature verification and replay protection. `tests/Functional/LoadtestTest.php` checks the k6 signing code against the directory's verifier.

### What to watch

- `/metrics` during the run: `aloha_directory_heartbeats_last_hour`, the job ages, and `aloha_directory_queue_oldest_job_seconds`.
- PHP-FPM: busy workers and listen queue. If the queue grows, raise `pm.max_children` or add an application host.
- MariaDB: slow-query log, row-lock waits.

Then, with the minute job running, look at how long the list rebuild and website build take for this number of servers (`app:tick -v`).

## Cleaning up

```sh
bin/console app:loadtest:seed --purge --not-production
```

The next minute job publishes a list without the fake servers; release it in the console if the change guard holds it. Then restart the `app:check` cron job.
