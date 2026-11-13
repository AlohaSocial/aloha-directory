# Upgrading

How to move a running directory to a new version. An upgrade takes a few minutes. While it runs, the server API and the console are briefly unavailable. Users notice nothing: apps use the static lists, which stay valid for 30 days. Aloha servers simply send their next heartbeat an hour later.

## Before you start

1. **Read the release notes** of every version between yours and the new one (on GitHub, under *Releases*). They say when an upgrade needs more than the steps below, for example a new setting, a new PHP or MariaDB version, or a long migration.
2. **Check the requirements**: PHP version and extensions, MariaDB version (see [installation.md](installation.md#what-you-need)).
3. **Compare the configuration.** `config.php` rejects unknown keys, so a setting that was removed must be removed from your file too, or the application does not start. New settings have defaults; add them only if you want a different value. To see what changed:

   ```sh
   git fetch --tags
   git diff <current version> <new version> -- config.sample.php
   ```

4. **Try it on staging first** if you run one. It should have its own keys and a recent copy of the production database.
5. **Back up** the database, `config.php` and the list key files. The database dump is what you go back to if something fails:

   ```sh
   # Docker
   docker compose -f docker/compose.yaml exec db sh -c 'mariadb-dump -u directory -p"$MARIADB_PASSWORD" --single-transaction directory' > directory-$(date +%F).sql
   # On a server
   mariadb-dump --single-transaction directory > directory-$(date +%F).sql
   ```

## Upgrading with Docker

In the checkout, for example `/opt/aloha-directory`:

```sh
git fetch --tags
git checkout <new version>                                     # a release tag, or: git pull on main
docker compose -f docker/compose.yaml build
docker compose -f docker/compose.yaml stop cron worker worker-checks
docker compose -f docker/compose.yaml up -d app                # runs the database migrations on start
docker compose -f docker/compose.yaml logs -f app              # wait for "ready to handle connections", then Ctrl-C
docker compose -f docker/compose.yaml up -d                    # web, cron and workers with the new image
docker compose -f docker/compose.yaml exec app bin/console app:monitor
```

Stopping cron and the workers first means nothing uses the database while the `app` container migrates it. On start, `app` also clears the cache and renders the website again.

## Upgrading on a server

In `/opt/aloha-directory`, as root:

```sh
# 1. Pause background work
systemctl stop aloha-directory-worker aloha-directory-worker-checks
mv /etc/cron.d/aloha-directory /root/aloha-directory.cron

# 2. New code and dependencies (scripts off: the cache is cleared as www-data below)
git fetch --tags
git checkout <new version>
composer install --no-dev --classmap-authoritative --no-scripts --no-interaction

# 3. Cache and database
sudo -u www-data bin/console cache:clear --no-interaction
sudo -u www-data bin/console doctrine:migrations:migrate --no-interaction

# 4. Back to work
systemctl reload php8.4-fpm
mv /root/aloha-directory.cron /etc/cron.d/aloha-directory
systemctl start aloha-directory-worker aloha-directory-worker-checks
sudo -u www-data bin/console app:tick --force --site
sudo -u www-data bin/console app:monitor
```

Reloading PHP-FPM is required: the production settings never re-check PHP files on disk (`opcache.validate_timestamps = 0`), so without a reload the old code keeps running.

With **two application hosts**, run steps 2 and 4 on each host, but step 3 (migrations) only once. Upgrade both hosts within a few minutes of each other.

## After the upgrade

- `app:monitor` shows no new alarms.
- The website and the console open. Sign in once.
- `bin/console messenger:stats` shows the queues emptying after the next hourly check run.
- `app:tick -v` runs without errors.

## Database migrations

Migrations live in `migrations/` and run in order; each version brings the ones it needs. They only move forward.

```sh
bin/console doctrine:migrations:status                       # what is applied, what is new
bin/console doctrine:migrations:migrate --dry-run            # what would run
bin/console doctrine:migrations:migrate --write-sql=/tmp     # the SQL, without running it
```

Most migrations take seconds. When one rewrites a large table, such as `heartbeat` or `audit_log`, the release notes say so and give an estimate; run it at a quiet time. The lists keep being served during the migration either way.

Never edit the database schema by hand, and never mark a migration as executed without running it.

## Going back

If the new version misbehaves:

1. Stop cron and the workers as in step 1 above.
2. Restore the database dump you took before the upgrade.
3. Check out the previous version and repeat steps 2–4 above, without running the migrations. With Docker, check out the previous version, `build`, and `up -d`.

Changes made between the upgrade and the restore are lost; the audit log shows what they were. Do not use `doctrine:migrations:migrate prev` to undo migrations: going back to the dump is the tested way.

Restoring an older database is safe for the lists: serials follow the clock and only go up, so the directory can never publish a serial that apps have already seen, and the next minute job signs fresh lists.

## Upgrading PHP, MariaDB and the operating system

- **Docker:** the image is built on `php:8.4-fpm-alpine`, and the database runs `mariadb:11.4`. Rebuild regularly to get security updates (`docker compose -f docker/compose.yaml build --pull`, then the steps above). Change the MariaDB image tag only as the release notes describe, after a backup.
- **On a server:** install security updates as usual. After a PHP update, reload PHP-FPM and restart the workers. For a new PHP minor version (for example 8.5), install the same extensions for it and switch the PHP-FPM socket in nginx, once the release notes say the version is supported.
- **Keys** are not part of upgrades. Rotating list keys is described in [operations.md](operations.md#rotating-a-list-key).
