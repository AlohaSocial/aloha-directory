# Running the directory

What runs, how to start and stop it, and the commands for everyday work. Key handling, monitoring details, retention, backups and incident handling are in [operations.md](operations.md). Installation is in [installation.md](installation.md), new versions in [upgrading.md](upgrading.md).

## What runs

| Part | What it does | Docker service | On a server |
|---|---|---|---|
| nginx | Serves the signed lists, media and static website from `output_dir`; passes the API, the console and `/report` to PHP | `web` | `nginx` |
| PHP-FPM | The application: server API and curator console | `app` | `php8.4-fpm` |
| Worker | Domain verification first, then automatic checks | `worker` | `aloha-directory-worker` |
| Checks worker | Automatic checks only, so verification floods cannot stop them | `worker-checks` | `aloha-directory-worker-checks` |
| Cron | The four scheduled jobs below | `cron` | `/etc/cron.d/aloha-directory` |
| MariaDB | Everything that is not a static file, including sessions, rate limits and the job queues | `db` | `mariadb` |

| Schedule | Job | What it does |
|---|---|---|
| every minute | `app:tick` | Automatic pauses and resumes, logos and banners, list rebuild and daily re-sign, website, Git mirror, sealing the audit chain |
| hourly at :07 | `app:check` | Queues one automatic check per enrolled server for the workers |
| daily at 03:30 | `app:maintenance` | Retention, fairness factors, the daily audit chain head and its verification, website refresh |
| quarterly | `app:transparency:report` | The quarterly transparency report |

All jobs take locks, so a slow run never overlaps the next one.

**Nothing on the user path depends on PHP or the database.** Apps fetch the lists as static files, and every list stays valid for 30 days. If PHP, the workers or the database stop, users notice nothing; only list updates, the console and the server API stop.

## Starting and stopping

With Docker (in the checkout, for example `/opt/aloha-directory`):

```sh
docker compose -f docker/compose.yaml up -d          # start everything
docker compose -f docker/compose.yaml ps             # what is running
docker compose -f docker/compose.yaml stop           # stop everything
docker compose -f docker/compose.yaml restart app    # restart one service
```

On a server:

```sh
systemctl status nginx php8.4-fpm mariadb aloha-directory-worker aloha-directory-worker-checks
systemctl reload php8.4-fpm                          # after a configuration or code change
systemctl restart aloha-directory-worker aloha-directory-worker-checks
```

Workers finish their current job before they stop. `bin/console messenger:stop-workers` asks every worker on every host to exit after its current job; systemd or Docker starts it again with fresh code and configuration.

## Commands for everyday work

Run them in the checkout as the PHP user: `sudo -u www-data bin/console …` on a server, or `docker compose -f docker/compose.yaml exec app bin/console …` with Docker.

| Task | Command |
|---|---|
| Is anything wrong? | `app:monitor` (prints alarms, exit code 1 if there are any) |
| Run the minute job now and see what it did | `app:tick -v` |
| Rebuild and re-sign the lists and the website now | `app:tick --force --site` |
| Check one server now | `app:check --domain=social.example.org` |
| Run all checks without workers | `app:check --sync` |
| See the job queues | `messenger:stats` |
| Look at failed jobs, retry them | `messenger:failed:show`, `messenger:failed:retry` |
| Verify a published list like a client | `app:lists:verify /srv/www/lists/v1/servers.json --keyset=/srv/www/lists/v1/keys.json` |
| Verify the audit log | `app:audit:verify` |
| Invite a curator from the shell | `app:curator:invite <email> "<name>" [--admin]` |
| Import a shared block list | `app:blocklist:import <name> <file>` |
| Install a new key set | `app:keys:install <keys.json>` |

Most curator work, such as reviews, decisions, pauses, complaints, releasing a held list and inviting curators, happens in the console at `/console`.

## Logs

- **Application, workers and cron jobs** log to standard error as JSON lines: `docker compose -f docker/compose.yaml logs -f app worker cron` with Docker, or the PHP-FPM log and `journalctl -u aloha-directory-worker` on a server. Messages are written only when an error occurs, together with the messages leading up to it (Monolog "fingers crossed"); console commands also print to their own output.
- **nginx** logs requests without IP addresses, in the `anonymous` format of [`docker/nginx.conf`](../docker/nginx.conf).
- **The audit log** in the console (*Audit log*) records every curator decision, automatic state change, publication, key installation and maintenance run. It is part of the database, not a file.

## Health and monitoring

| | |
|---|---|
| `GET /healthz` | For the load balancer: `{"status":"ok","database":true}`, or HTTP 503 when the database is unreachable |
| `GET /metrics` | Prometheus metrics, with `Authorization: Bearer <metrics_token>` |
| `app:monitor` | The alarms, for cron-based alerting |

What the alarms mean and what to do is in [operations.md](operations.md#monitoring-concept-138) and [operations.md](operations.md#failure-handling-concept-137).

## Growing

- **Checks take too long:** add workers (`docker compose -f docker/compose.yaml up -d --scale worker-checks=3`, or more systemd units on more hosts). They share the database queues.
- **A second application host:** sessions, rate limits and queues are already in the database. Set `lock_dsn` to the database URL, and make sure every nginx serves the same `output_dir`: either run cron on the host whose `output_dir` nginx serves, or put `output_dir` on shared storage. A CDN in front that pulls from one host also works.
- **Load:** [loadtest.md](loadtest.md) shows how to measure a staging copy against the response-time targets.
