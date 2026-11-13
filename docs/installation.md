# Installation

This guide sets up a production instance of the Aloha Directory. There are two ways:

- **[With Docker](#installing-with-docker)**: one host, everything in containers. The quickest way.
- **[On a server](#installing-on-a-server)**: nginx, PHP-FPM and MariaDB installed directly, for example on Debian 13. Use this for the two-VM setup from the concept (13.1).

Both share the [preparation](#preparation) steps. Afterwards, see [running.md](running.md) for day-to-day operation and [upgrading.md](upgrading.md) for new versions. For a development setup on your laptop, the [README](../README.md#running-locally) is enough.

## What you need

| | Minimum | Notes |
|---|---|---|
| Hosts | 1 small VM (2 vCPU, 2 GB RAM) | Two VMs behind a CDN for redundancy, see [operations.md](operations.md#topology-concept-131) |
| PHP | 8.4 with `intl`, `pdo_mysql`, `gd` (with WebP), `sodium`, `openssl`, `mbstring`, `xml`, `curl`, `opcache` | Provided by the Docker image |
| Database | MariaDB 10.11 or newer | MariaDB 11.4 in the Docker setup |
| Web server | nginx | Serves the static lists and website itself and passes the rest to PHP-FPM |
| TLS | A certificate for the directory's host name | Terminated by nginx, a reverse proxy or a CDN |
| Mail | An SMTP account | Notifications to curators and server admins |
| Keys | The offline root key and a list signing key | See [Preparation](#preparation) |

The directory host name, for example `directory.aloha.social`, is part of every request signature from Aloha servers. Decide on it before you start, and do not change it later.

## Preparation

### 1. Create the signing keys

The lists are signed with a **list key**. The key set that names the list keys (`keys.json`) is signed with the **root key**, whose public key the apps and the Aloha server app pin.

Follow the [key ceremony](operations.md#key-ceremony-concept-83). You come out of it with:

- the root public key and its key id (for example `aloha-root-2026`);
- a list key file, for example `aloha-list-2026a.key`, and its public key;
- a signed `keys.json`.

The root key itself never goes to the directory host.

For a **staging** instance, create a separate root key and list key. Never copy production keys to staging.

### 2. Prepare `config.php`

Every setting lives in `config.php`. Start from [`config.sample.php`](../config.sample.php), which documents each key:

```sh
cp config.sample.php config.php
```

Set at least:

| Key | Value |
|---|---|
| `app_env`, `debug` | `'prod'`, `false` |
| `app_secret` | A random value: `php -r 'echo bin2hex(random_bytes(32)), "\n";'` |
| `public_url` | `https://<your directory host>`, exactly as servers will call it |
| `trusted_proxies` | The address range of your reverse proxy or load balancer, if TLS ends there |
| `database_url` | `mysql://directory:<password>@<db host>:3306/directory?serverVersion=10.11.0-MariaDB&charset=utf8mb4` |
| `lock_dsn` | `'flock'` on one host; the same value as `database_url` if cron runs on more than one host |
| `output_dir` | Where the static lists and website are written; nginx serves it |
| `encryption_key` | `bin/console app:secrets:generate-encryption-key` (Docker: see below) |
| `list_signing_keys` | `['<list key id>' => '/etc/aloha-directory/keys/<list key id>.key']` |
| `root_public_keys` | `['<root key id>' => '<root public key>']` |
| `mailer_dsn`, `mail_from` | Your SMTP account and sender |
| `metrics_token` | A random value, if you scrape `/metrics` |
| `mirror_repo_dir` | A clone of the public list mirror with push access, or `null` |

`config.php` contains secrets: keep it out of version control, owned by root and readable by the PHP user's group (`chmod 640`). An environment variable with the same meaning overrides a value in the file; its name is shown in brackets next to each key in `config.sample.php`.

## Installing with Docker

The [compose file](../docker/compose.yaml) runs MariaDB, PHP-FPM (`app`), two background workers, a cron container and nginx (`web`, port 8080). Put a TLS-terminating reverse proxy (for example Caddy, Traefik or your load balancer) in front of port 8080.

1. **Get the code** on the host:

   ```sh
   git clone https://github.com/AlohaSocial/aloha-directory.git /opt/aloha-directory
   cd /opt/aloha-directory
   ```

   For production, check out a release tag instead of `main` once releases are tagged.

2. **Configuration.** Create `config.php` as described in [Preparation](#2-prepare-configphp), with these values for the containers:

   ```php
   'output_dir' => '/srv/www',
   'list_signing_keys' => ['aloha-list-2026a' => '/etc/aloha-directory/keys/aloha-list-2026a.key'],
   'trusted_proxies' => ['172.16.0.0/12'],   // the Docker networks, if the proxy runs on the same host
   ```

   The compose file sets `DATABASE_URL`, `LOCK_DSN` and `DIRECTORY_OUTPUT_DIR` itself, so their values in `config.php` do not matter. To create `encryption_key` without a local PHP installation:

   ```sh
   docker run --rm php:8.4-cli php -r 'echo rtrim(strtr(base64_encode(random_bytes(32)), "+/", "-_"), "="), "\n";'
   ```

3. **Database password.** Create `docker/.env` (it is ignored by Git):

   ```sh
   echo "DIRECTORY_DB_PASSWORD=$(openssl rand -hex 24)" > docker/.env
   chmod 600 docker/.env
   ```

4. **Keys.** Put the list key file and the signed `keys.json` into `keys/`. The PHP user in the image is `www-data` with uid and gid 82:

   ```sh
   mkdir -p keys
   cp /path/to/aloha-list-2026a.key /path/to/keys.json keys/
   sudo chown 82:82 keys/aloha-list-2026a.key && sudo chmod 640 keys/aloha-list-2026a.key
   ```

   Key files that other users can read are refused.

5. **Start** the containers. The `app` container runs the database migrations when it starts:

   ```sh
   docker compose -f docker/compose.yaml up -d --build
   docker compose -f docker/compose.yaml logs -f app      # wait until php-fpm is ready, then Ctrl-C
   ```

6. **Install the key set** and publish the first lists and the website:

   ```sh
   docker compose -f docker/compose.yaml exec app bin/console app:keys:install /etc/aloha-directory/keys/keys.json
   docker compose -f docker/compose.yaml exec app bin/console app:tick --force --site
   ```

7. Continue with [the first curator](#the-first-curator).

## Installing on a server

This walkthrough uses Debian 13, where PHP 8.4 is the default version. Other distributions work the same way with their package names. The layout:

| Path | Contents | Owner |
|---|---|---|
| `/opt/aloha-directory` | The code (a Git checkout) and `config.php` | root; `var/` owned by `www-data` |
| `/etc/aloha-directory/keys` | List key files and `keys.json` | root:www-data, files `640` |
| `/srv/www` | `output_dir`: lists, media and the static website | `www-data` |

### 1. Packages

```sh
apt install nginx mariadb-server git unzip composer \
    php8.4-fpm php8.4-cli php8.4-intl php8.4-mysql php8.4-gd php8.4-mbstring php8.4-xml php8.4-curl
```

`sodium`, `openssl` and `opcache` are part of the PHP packages.

### 2. Database

```sh
mariadb -u root <<'SQL'
CREATE DATABASE directory CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'directory'@'localhost' IDENTIFIED BY '<a long random password>';
GRANT ALL ON directory.* TO 'directory'@'localhost';
SQL
```

Turn on binary logs for point-in-time recovery (`log_bin`, `binlog_format = ROW`) and set up nightly dumps, see [operations.md](operations.md#backups-and-restore).

### 3. Code and configuration

```sh
git clone https://github.com/AlohaSocial/aloha-directory.git /opt/aloha-directory
cd /opt/aloha-directory
cp config.sample.php config.php          # then edit it, see Preparation
chown root:www-data config.php && chmod 640 config.php
composer install --no-dev --classmap-authoritative --no-interaction
mkdir -p var /srv/www && chown -R www-data:www-data var /srv/www
```

In `config.php`, set `output_dir` to `'/srv/www'` and the key paths to `/etc/aloha-directory/keys/…`.

### 4. Keys and database schema

```sh
install -d -o root -g www-data -m 750 /etc/aloha-directory/keys
install -o root -g www-data -m 640 /path/to/aloha-list-2026a.key /path/to/keys.json /etc/aloha-directory/keys/
sudo -u www-data bin/console doctrine:migrations:migrate --no-interaction
sudo -u www-data bin/console app:keys:install /etc/aloha-directory/keys/keys.json
sudo -u www-data bin/console app:tick --force --site
```

Run every `bin/console` command as `www-data`, so files in `var/` and `/srv/www` keep the right owner.

### 5. PHP-FPM

Copy the production settings from the Docker image and adjust the preload path:

```sh
sed 's#/app/#/opt/aloha-directory/#' docker/php.ini > /etc/php/8.4/fpm/conf.d/90-aloha-directory.ini
systemctl restart php8.4-fpm
```

These settings turn off `opcache.validate_timestamps` for speed, so PHP-FPM must be reloaded after every code change ([upgrading.md](upgrading.md) does this). The pool settings in [`docker/fpm.conf`](../docker/fpm.conf) (20 children) are a good start for `/etc/php/8.4/fpm/pool.d/www.conf`.

### 6. nginx

Start from [`docker/nginx.conf`](../docker/nginx.conf). It serves the lists, media and website from `output_dir`, never logs IP addresses, and passes everything else to PHP. Change:

- `root /srv/www;` (your `output_dir`);
- `fastcgi_pass unix:/run/php/php8.4-fpm.sock;`;
- `SCRIPT_FILENAME /opt/aloha-directory/public/index.php` and `DOCUMENT_ROOT /opt/aloha-directory/public`;
- the snippet path to wherever you put [`security-headers.conf`](../docker/nginx-snippets/security-headers.conf);
- TLS: if nginx terminates TLS itself, add `listen 443 ssl;` with your certificate, redirect port 80 to HTTPS, and replace `fastcgi_param HTTPS $fwd_https;` with `fastcgi_param HTTPS on;`.

### 7. Cron jobs

Create `/etc/cron.d/aloha-directory`:

```cron
* * * * *        www-data  php /opt/aloha-directory/bin/console app:tick --no-interaction
7 * * * *        www-data  php /opt/aloha-directory/bin/console app:check --no-interaction
30 3 * * *       www-data  php /opt/aloha-directory/bin/console app:maintenance --no-interaction
0 6 1 1,4,7,10 * www-data  php /opt/aloha-directory/bin/console app:transparency:report --no-interaction
```

With two hosts, run cron on one of them, or on both with `lock_dsn` set to the database.

### 8. Background workers

Domain verification and the hourly checks run in two workers under systemd. Create `/etc/systemd/system/aloha-directory-worker.service`:

```ini
[Unit]
Description=Aloha Directory background worker (verification, then checks)
After=network-online.target mariadb.service
Wants=network-online.target

[Service]
User=www-data
Group=www-data
WorkingDirectory=/opt/aloha-directory
ExecStart=/usr/bin/php bin/console messenger:consume verify checks --time-limit=3600 --memory-limit=256M --no-interaction
Restart=always
RestartSec=5
NoNewPrivileges=true
ProtectSystem=strict
ReadWritePaths=/opt/aloha-directory/var
PrivateTmp=true

[Install]
WantedBy=multi-user.target
```

Create `/etc/systemd/system/aloha-directory-worker-checks.service` as a copy, with `Description=Aloha Directory background worker (checks)` and `ExecStart=/usr/bin/php bin/console messenger:consume checks --time-limit=3600 --memory-limit=256M --no-interaction`. Then:

```sh
systemctl daemon-reload
systemctl enable --now aloha-directory-worker aloha-directory-worker-checks
```

The first worker serves domain verification first and then checks. The second only runs checks, so a flood of verification requests can never stop them. A worker exits after an hour (or when told to after an upgrade), and systemd starts it again.

Then continue with [the first curator](#the-first-curator).

## The first curator

Create an invitation for the first admin and open the printed link within 72 hours:

```sh
bin/console app:curator:invite you@example.org "Your Name" --admin
```

(With Docker, prefix commands with `docker compose -f docker/compose.yaml exec app`.)

The invitation asks for a password and registers a **passkey**: Touch ID, Windows Hello, a phone or a security key. Passkeys need HTTPS on the directory's host name, so open the link on the real address, not on an IP. Keep the ten recovery codes in a safe place, and register a second passkey under *Account*. Further curators are invited from the console (*Curators*).

## Checking the installation

```sh
curl -s https://<your directory host>/healthz           # {"status":"ok","database":true}
bin/console app:monitor                                 # no alarms (except "free places" while the list is empty)
bin/console app:lists:verify /srv/www/lists/v1/servers.json --keyset=/srv/www/lists/v1/keys.json --list=servers
bin/console messenger:stats                             # verify, checks and failed queues exist
```

Then open the website, sign in to the console, and check that test mail arrives (an invitation is a good test).

Finally:

- **Monitoring:** wire `bin/console app:monitor` (exit code 1 on an alarm) or `/metrics` into your alerting, see [operations.md](operations.md#monitoring-concept-138).
- **Backups:** the database, `config.php` and the list key files, see [operations.md](operations.md#backups-and-restore).
- **Block lists:** import the shared block lists you follow, see [operations.md](operations.md#block-lists).
- **Mirror:** if `mirror_repo_dir` is set, check that the first list was pushed.

Aloha servers can now enrol, following [server-integration.md](server-integration.md).
