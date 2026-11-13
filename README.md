# Aloha Directory

The directory service behind **directory.aloha.social**. It collects facts from Aloha servers and decisions from human curators, and publishes two signed lists:

- **`servers.json`**: hand-picked Aloha servers that offer free accounts. The mobile and desktop apps use it to suggest where a newcomer signs up.
- **`search.json`**: curated Aloha servers that offer search to other servers, plus the Bluesky AppView. The Aloha server app uses it instead of hard-coded search sources.

The directory is never on the path of normal use. Signup, login, search and follows go straight between apps and servers. If the directory is offline, everyone keeps working from their last signed copy of each list.

The design is described in the concept document *Aloha Directory: server discovery and search*. [docs/concept-mapping.md](docs/concept-mapping.md) shows where each section of the concept is implemented and lists the decisions made during implementation.

## What is in this repository

| Part | What it does | Where |
|---|---|---|
| Server API | Enrolment with domain proof, profile, hourly heartbeat, status, withdrawal, key rotation. Every request is signed (RFC 9421, Ed25519). | `src/Controller/Api`, `src/Service/ServerLifecycle.php`, [docs/server-integration.md](docs/server-integration.md) |
| Automatic checks | HTTPS, nodeinfo, terms, abuse contact, signup state, block lists, heartbeat punctuality, Bluesky handles. Run hourly as background jobs; domain verification runs in the same queue. | `src/Check`, `src/MessageHandler` |
| Curator console | Queue, server review with checklist, two-curator decisions, conflicts of interest, complaints, list builds and diffs, fairness, audit log, curator management. Sign-in with password and passkey. | `src/Controller/Console`, `src/Curation`, `src/Security`, `templates/console` |
| List builder | Rebuilds and signs the lists within a minute of a change and re-signs them daily. A change guard holds builds that add or remove too many servers at once. Writes static files atomically and keeps every build. | `src/Lists` |
| Fairness | Daily balance factors that spread newcomers across the featured servers of a language. | `src/Fairness` |
| Audit chain | Hash chain over the audit log, with a signed head published daily and verified daily. | `src/Audit` |
| Public website | Static pages rendered from the signed list: directory, server pages with uptime and check history, covenant, criteria, transparency, keys, privacy. One dynamic page: `/report`. | `src/Site`, `templates/site` |
| Load test | k6 scenarios with response-time targets, and fake servers for staging. | `tools/loadtest`, [docs/loadtest.md](docs/loadtest.md) |
| Reference verifiers | The verification algorithm clients must implement, in PHP and JavaScript. | `src/Crypto/ListVerifier.php`, `tools/verify-list.mjs`, [docs/lists.md](docs/lists.md) |

Stack: PHP 8.4+ and Symfony 8.1, Doctrine ORM with MariaDB 10.11+, Twig. There is no JavaScript framework and no external CDN; the only script is the console's small passkey helper.

## Configuration: `config.php`

Every setting lives in one file, **`config.php`**, in the project root. Copy [`config.sample.php`](config.sample.php), which documents each key with its default:

```sh
cp config.sample.php config.php
chmod 640 config.php
```

The file is read before the application starts. Unknown keys are rejected, so a typo fails loudly. An environment variable with the same meaning overrides a value in `config.php`; the variable is named in brackets next to each key. Container deployments use this to inject secrets. Thresholds, such as capacity buckets, stale and pause times, retention periods and rate limits, sit under `thresholds`, and you only list the ones you change.

## Running locally

Requirements: PHP 8.4+ with `intl`, `pdo_mysql` (or `pdo_sqlite`), `gd` with WebP, and `sodium`; Composer; MariaDB.

```sh
composer install
cp config.sample.php config.php      # set app_env 'dev', debug true, database_url, public_url 'http://localhost:8000'
mkdir -p keys
bin/console app:secrets:generate-encryption-key          # → encryption_key
bin/console app:keys:generate keys/dev-list-a.key        # → list_signing_keys
bin/console app:keys:generate keys/dev-root.key          # → root_public_keys (dev only: production keeps the root key offline)
bin/console doctrine:migrations:migrate
bin/console app:keys:sign-keyset --root-key=keys/dev-root.key --root-kid=dev-root --serial=1 \
    --key="dev-list-a=<public key of dev-list-a>" --out=var/keys.json
bin/console app:keys:install var/keys.json
bin/console app:curator:invite you@example.org "Your Name" --admin   # open the printed link
bin/console app:tick --site
php -S 127.0.0.1:8000 -t public
```

## Running with Docker

`docker/compose.yaml` runs MariaDB, PHP-FPM, a cron container and nginx:

```sh
cp config.sample.php config.php      # output_dir '/srv/www'; key paths under /etc/aloha-directory/keys/
mkdir keys && …                      # list key files, readable by uid 82 (www-data in Alpine), mode 640
DIRECTORY_DB_PASSWORD=… docker compose -f docker/compose.yaml up -d --build
docker compose -f docker/compose.yaml exec app bin/console app:curator:invite you@example.org "Your Name" --admin
```

The compose file sets `DATABASE_URL`, `LOCK_DSN` and `DIRECTORY_OUTPUT_DIR` as environment variables, and they override `config.php`. nginx serves the lists, media and website directly from the shared volume, and asks PHP only for the API, the console and `/report`. TLS is terminated in front of nginx; set `trusted_proxies` accordingly.

## Cron jobs

| Schedule | Command | Purpose |
|---|---|---|
| every minute | `bin/console app:tick` | Time-based state rules (stale, pause, resume), pending logos and banners, list rebuild and re-sign, website, Git mirror |
| hourly | `bin/console app:check` | Queues the automatic checks of every enrolled server (`--sync` runs them in the cron process instead) |
| daily | `bin/console app:maintenance` | Retention, fairness factors, audit chain head and verification, website refresh |

## Background worker

Domain verification and the hourly checks run as jobs in the database-backed queues `verify` and `checks`, so slow or hostile servers never hold a web request:

```sh
bin/console messenger:consume verify checks --time-limit=3600 --memory-limit=256M
```

Run at least two workers: one for `verify checks` and one for `checks` only (the `worker` and `worker-checks` services in `docker/compose.yaml`), so a flood of verification requests can never stop the hourly checks. More replicas check faster. A run of `app:check` queues nothing while checks from the previous run are still waiting. Failed check jobs are retried once after 5 minutes and then land in the `failed` queue (`bin/console messenger:failed:show`). `/metrics` reports the age of the oldest waiting job per queue, and `app:monitor` raises an alarm when a worker seems to be down.
| quarterly | `bin/console app:transparency:report` | Quarterly transparency report on the public website |

`docker/crontab` contains the same schedule. All jobs take locks, so an overlapping run does nothing. Use the database as `lock_dsn` if cron runs on more than one host.

## Operations

See [docs/operations.md](docs/operations.md) for the key ceremony and key rotation, monitoring (`/metrics` and `app:monitor`), backups, retention and failure handling.

## Development

```sh
php vendor/bin/phpunit                    # SQLite, tests/config.test.php
DATABASE_URL='mysql://user:pass@127.0.0.1:3306/directory_test?serverVersion=10.11.0-MariaDB&charset=utf8mb4' php vendor/bin/phpunit
php vendor/bin/phpstan analyse           # level 8
```

The functional tests simulate Aloha servers (`tests/Support/FakeAlohaServer.php`), cover the complete path from enrolment to a signed list that verifies against the root key, and cross-check the JavaScript verifier. [AGENTS.md](AGENTS.md) lists the conventions.

## License

Copyright © 2026 the Aloha Directory contributors.

The Aloha Directory is free software: you can redistribute it and/or modify it under the terms of the **GNU Affero General Public License, version 3 or (at your option) any later version** (AGPL-3.0-or-later), as published by the Free Software Foundation. See [LICENSE](LICENSE).

If you run a modified version as a network service, the AGPL requires you to offer its source code to its users. The public website links to the source in its footer; point that link at your version.

`tools/loadtest/lib/nacl-fast.js` is [TweetNaCl.js](https://github.com/dchest/tweetnacl-js), which is in the public domain.
