# Working on the Aloha Directory

Symfony 8.1, PHP 8.4+, Doctrine ORM on MariaDB 10.11+. Read `README.md` and `docs/` first.

- **Configuration lives in `config.php` only** (`config.sample.php` documents every key). `src/Config/ConfigLoader.php`
  maps it to environment variables before the kernel boots; there are no `.env` files. A new setting needs an entry
  in `ConfigLoader::MAP`, in `config.sample.php`, and (for thresholds) in `DirectorySettings::DEFAULT_THRESHOLDS`.
- **State changes of servers** go through `App\Service\ServerLifecycle` (servers and machines) or
  `App\Curation\CurationService` (curators). Both write the audit log in the same unit of work.
- **Lists** are rebuilt from the database by `App\Lists\ListBuilder` whenever their content hash changes; never write
  list files elsewhere. List format changes need the reference verifier (`App\Crypto\ListVerifier`,
  `tools/verify-list.mjs`) and `docs/lists.md` updated in the same change.
- **Outbound HTTP** only through `App\Net\SafeHttpClient`. Slow work (domain verification, checks) runs as a
  Messenger job (`App\Message\*`, queues `verify` and `checks`), never in a web request. Background jobs write
  their results through `ServerLifecycle::underLock()`; `Server` and `Proposal` are optimistically locked.
- **Curator authentication**: password plus passkey (`App\Security\Passkeys`, `SecondFactor`). Every sensitive
  account change takes a `SecondFactorProof` and checks the password first. WebAuthn challenges are per purpose and
  single use.
- **Audit log** rows are never updated except by `App\Audit\AuditChain::seal()` (hash chain). Never delete them.
- **Tests**: `runJobs()` handles queued jobs, `consoleLogin()` signs in with the curator's software passkey
  (`tests/Support/SoftAuthenticator.php`).
- **Templates**: no inline scripts or styles (strict CSP), never `|raw`.
- Run `php vendor/bin/phpunit` (SQLite) and, for SQL changes, with
  `DATABASE_URL=mysql://…/directory_test?serverVersion=10.11.0-MariaDB` against MariaDB too.
  `php vendor/bin/phpstan analyse` must stay clean at level 8.
- Database changes: `bin/console doctrine:migrations:diff` against MariaDB, review the SQL, avoid reserved words.
