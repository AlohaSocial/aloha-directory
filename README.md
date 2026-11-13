# Aloha Directory

The directory service behind **directory.aloha.social**. It collects facts from Aloha servers and decisions from human curators, and publishes two signed lists:

- **`servers.json`**: hand-picked Aloha servers that offer free accounts. The mobile and desktop apps use it to suggest where a newcomer signs up.
- **`search.json`**: curated Aloha servers that offer search to other servers, plus the Bluesky AppView. The Aloha server app uses it instead of hard-coded search sources.

The directory is never on the path of normal use. Signup, login, search and follows go straight between apps and servers. If the directory is offline, everyone keeps working from their last signed copy of each list.

The design is described in the concept document [*Aloha Directory: server discovery and search*](docs/Aloha%20Directory%20server%20discovery,%20andsearch.md). [docs/concept-mapping.md](docs/concept-mapping.md) shows where each section of the concept is implemented and lists the decisions made during implementation.

## Screenshots

The screenshots show demo data; all servers in them are fictional.

### Public website

The website is static HTML, rendered from the signed list, so it shows exactly what the apps see.

| | |
|---|---|
| [![Server directory](docs/screenshots/website-directory.png)](docs/screenshots/website-directory.png) | [![Server page with uptime and check history](docs/screenshots/website-server.png)](docs/screenshots/website-server.png) |
| **Directory.** Every listed server, with filters for language, country, topic and signup mode. | **Server page.** Who runs it, moderation, rules, a 90-day uptime chart and 30 days of automatic check results. |
| [![Transparency page](docs/screenshots/website-transparency.png)](docs/screenshots/website-transparency.png) | [![Directory on a phone](docs/screenshots/website-mobile.png)](docs/screenshots/website-mobile.png) |
| **Transparency.** Delistings with reasons, quarterly reports, the history of every signed list and the daily signed head of the audit log. | **On a phone.** All pages work at phone width. |

### Curator console

| | |
|---|---|
| [![Sign-in with password and passkey](docs/screenshots/console-login.png)](docs/screenshots/console-login.png) | [![Queue](docs/screenshots/console-queue.png)](docs/screenshots/console-queue.png) |
| **Sign-in.** Password and passkey on one form; a recovery code if the device is lost. | **Queue.** Everything waiting for a curator: proposals that need a second curator, applications, profile changes, complaints, automatic pauses and re-reviews. |
| [![Reviewing an application](docs/screenshots/console-review.png)](docs/screenshots/console-review.png) | [![List change guard](docs/screenshots/console-list-guard.png)](docs/screenshots/console-list-guard.png) |
| **Review.** Profile, automatic checks, heartbeats, the review checklist, and a proposal from one curator waiting for a second. | **Change guard.** A build that would add many servers at once is held until a curator releases it. |
| [![Fairness](docs/screenshots/console-fairness.png)](docs/screenshots/console-fairness.png) | [![Audit log](docs/screenshots/console-audit.png)](docs/screenshots/console-audit.png) |
| **Fairness.** How newcomers spread across servers, by language and country, with the balance factors in the list. | **Audit log.** Every decision and automatic change, chained by hashes and verified daily. |
| [![Accepting an invitation](docs/screenshots/console-invite.png)](docs/screenshots/console-invite.png) | [![Account with passkeys](docs/screenshots/console-account.png)](docs/screenshots/console-account.png) |
| **Invitation.** A new curator sets a password and registers a passkey. | **Account.** Passkeys, password and recovery codes; every change needs the password and a passkey. |

## Features

| Part | What it does | Where |
|---|---|---|
| Server API | Enrolment with domain proof, profile, hourly heartbeat, status, withdrawal and key rotation. Every request is signed (RFC 9421, Ed25519). | `src/Controller/Api`, `src/Service/ServerLifecycle.php`, [docs/server-integration.md](docs/server-integration.md) |
| Automatic checks | HTTPS, nodeinfo, terms, abuse contact, signup state, block lists, heartbeat punctuality and Bluesky handles. They run hourly as background jobs, and domain verification uses the same queue. | `src/Check`, `src/MessageHandler` |
| Curator console | Queue, server review with checklist, two-curator decisions, conflicts of interest, complaints, list builds and diffs, fairness, audit log and curator management. Sign-in with password and passkey. | `src/Controller/Console`, `src/Curation`, `src/Security`, `templates/console` |
| List builder | Rebuilds and signs the lists within a minute of a change and re-signs them daily. A change guard holds back mass additions and mass automatic pauses. Writes static files atomically and keeps every build. | `src/Lists` |
| Fairness | Daily balance factors that spread newcomers across the featured servers of each language. | `src/Fairness` |
| Audit chain | Hash chain over the audit log. A signed head is published daily, and the chain is verified daily. | `src/Audit` |
| Public website | Static pages: directory, server pages with uptime and check history, covenant, criteria, transparency, keys and privacy. One dynamic page: `/report`. | `src/Site`, `templates/site` |
| Reference verifiers | The verification algorithm clients must implement, in PHP and JavaScript. | `src/Crypto/ListVerifier.php`, `tools/verify-list.mjs`, [docs/lists.md](docs/lists.md) |
| Load test | k6 scenarios with response-time targets, and fake servers for staging. | `tools/loadtest`, [docs/loadtest.md](docs/loadtest.md) |

Stack: PHP 8.4+ and Symfony 8.1, Doctrine ORM with MariaDB 10.11+, Twig, Symfony Messenger with database queues. There is no JavaScript framework and no external CDN; the only script is the console's small passkey helper.

[SECURITY.md](SECURITY.md) summarises the security design and the findings of two internal reviews.

## Configuration: `config.php`

Every setting lives in one file, **`config.php`**, in the project root. Copy [`config.sample.php`](config.sample.php), which documents each key with its default:

```sh
cp config.sample.php config.php
chmod 640 config.php
```

The file is read before the application starts. Unknown keys are rejected, so a typo fails loudly. An environment variable with the same meaning overrides a value in `config.php`; the variable is named in brackets next to each key. Container deployments use this to inject secrets. Thresholds, such as capacity buckets, stale and pause times, retention periods, rate limits, the change guard and fairness, sit under `thresholds`; you only list the ones you change.

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
php -S localhost:8000 -t public tools/dev-router.php
```

Open <http://localhost:8000> for the website and <http://localhost:8000/console> for the console. Passkeys need a secure context, which `localhost` is; use the same host name as in `public_url`. For background jobs, run `bin/console messenger:consume verify checks` in a second terminal.

## Running with Docker

`docker/compose.yaml` runs MariaDB, PHP-FPM, two background workers, a cron container and nginx. The full walkthrough, including keys and TLS, is in [docs/installation.md](docs/installation.md#installing-with-docker); in short:

```sh
cp config.sample.php config.php      # output_dir '/srv/www'; key paths under /etc/aloha-directory/keys/
mkdir keys && …                      # list key files, readable by uid 82 (www-data in Alpine), mode 640
echo "DIRECTORY_DB_PASSWORD=$(openssl rand -hex 24)" > docker/.env
docker compose -f docker/compose.yaml up -d --build
docker compose -f docker/compose.yaml exec app bin/console app:curator:invite you@example.org "Your Name" --admin
```

The compose file sets `DATABASE_URL`, `LOCK_DSN` and `DIRECTORY_OUTPUT_DIR` as environment variables, and they override `config.php`. nginx serves the lists, media and website directly from the shared volume, and asks PHP only for the API, the console and `/report`. TLS is terminated in front of nginx; set `trusted_proxies` accordingly.

## Cron jobs

| Schedule | Command | Purpose |
|---|---|---|
| every minute | `bin/console app:tick` | Time-based state rules (stale, pause, resume), pending logos and banners, list rebuild and re-sign, website, Git mirror, sealing the audit chain |
| hourly | `bin/console app:check` | Queues the automatic checks of every enrolled server (`--sync` runs them in the cron process instead) |
| daily | `bin/console app:maintenance` | Retention, fairness factors, the audit chain head and its verification, website refresh |
| quarterly | `bin/console app:transparency:report` | Quarterly transparency report on the public website |

`docker/crontab` contains the same schedule. All jobs take locks, so an overlapping run does nothing. Use the database as `lock_dsn` if cron runs on more than one host.

## Background workers

Domain verification and the hourly checks run as jobs in the database-backed queues `verify` and `checks`, so slow or hostile servers never hold a web request:

```sh
bin/console messenger:consume verify checks --time-limit=3600 --memory-limit=256M
bin/console messenger:consume checks --time-limit=3600 --memory-limit=256M
```

Run at least these two workers (the `worker` and `worker-checks` services in `docker/compose.yaml`), so a flood of verification requests can never stop the hourly checks. More replicas check faster. A run of `app:check` queues nothing while checks from the previous run are still waiting. Failed check jobs are retried once after 5 minutes and then land in the `failed` queue (`bin/console messenger:failed:show`). `/metrics` reports the age of the oldest waiting job per queue, and `app:monitor` raises an alarm when a worker seems to be down.

## Documentation

| Guide | For |
|---|---|
| [docs/installation.md](docs/installation.md) | Installing a production instance, with Docker or on a server |
| [docs/running.md](docs/running.md) | What runs, starting and stopping, everyday commands, logs, growing |
| [docs/upgrading.md](docs/upgrading.md) | Moving to a new version, migrations, going back |
| [docs/operations.md](docs/operations.md) | Key ceremony and rotation, monitoring and alarms, audit chain, change guard, retention, backups, failure handling |
| [docs/server-integration.md](docs/server-integration.md) | The API contract for the Aloha server app |
| [docs/lists.md](docs/lists.md) | The signed list format and how clients verify it |
| [docs/loadtest.md](docs/loadtest.md) | The load test and its response-time targets |
| [docs/concept-mapping.md](docs/concept-mapping.md) | Where each part of the concept is implemented |

## Development

```sh
php vendor/bin/phpunit                    # SQLite, tests/config.test.php
DATABASE_URL='mysql://user:pass@127.0.0.1:3306/directory_test?serverVersion=10.11.0-MariaDB&charset=utf8mb4' php vendor/bin/phpunit
php vendor/bin/phpstan analyse           # level 8
```

The functional tests simulate Aloha servers (`tests/Support/FakeAlohaServer.php`) and passkeys (`tests/Support/SoftAuthenticator.php`). They cover the complete path from enrolment to a signed list that verifies against the root key, and they cross-check the JavaScript list verifier and the load test's request signing. [AGENTS.md](AGENTS.md) lists the conventions.

## License

Copyright © 2026 the Aloha Directory contributors.

The Aloha Directory is free software: you can redistribute it and/or modify it under the terms of the **GNU Affero General Public License, version 3 or (at your option) any later version** (AGPL-3.0-or-later), as published by the Free Software Foundation. See [LICENSE](LICENSE).

If you run a modified version as a network service, the AGPL requires you to offer its source code to its users. The public website links to the source in its footer; point that link at your version.

`tools/loadtest/lib/nacl-fast.js` is [TweetNaCl.js](https://github.com/dchest/tweetnacl-js), which is in the public domain.
