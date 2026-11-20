# Hosting on a Raspberry Pi

The app runs on one SQLite file, the file cache and the database queue: no
Redis, MySQL or other services.

- **SQLite** (`config/database.php`): WAL journal mode, so pages keep reading
  while someone writes; `busy_timeout` 5000 ms, so a writer waits for the lock
  instead of failing with "database is locked"; `synchronous=NORMAL` (safe with
  WAL, one fsync less per write, kinder to SD cards); and `IMMEDIATE`
  transactions, which take the write lock up front where the timeout applies
  (`App\Database\SQLiteConnector` does this on PHP 8.2/8.3 too). Override with
  `DB_JOURNAL_MODE`, `DB_BUSY_TIMEOUT`, `DB_SYNCHRONOUS`, `DB_TRANSACTION_MODE`.
  Keep the database on local storage: WAL does not work over a network share.
  Write transactions stay short (no HTTP calls or file uploads inside them).
- **Queue**: broadcasts (messages, edits, reactions, presence, DMs, friend
  requests, moderation) and the mention notifications' broadcasts go onto the
  `database` queue. What they send is decided when the request queues them
  (`App\Events\Concerns\QueuesBroadcast`), so the worker runs no queries for
  them. Run exactly **one** worker, so broadcasts stay in order and only one
  process polls the jobs table:

  ```ini
  # /etc/systemd/system/fakediscord-queue.service
  [Service]
  User=www-data
  WorkingDirectory=/var/www/fakediscord
  ExecStart=/usr/bin/php artisan queue:work database --sleep=1 --tries=1 --max-time=3600
  Restart=always
  ```

  `--sleep=1` keeps live messages within about a second when the queue was
  idle. Run `php artisan queue:restart` after deploying.
- **Cache**: `CACHE_STORE=file` (`storage/framework/cache`). Rate limits,
  permissions and member lists are cached there per server
  (`App\Support\ServerCache`). Anything that changes them drops the server's
  entries once it is committed, and `migrate` starts over. After restoring a
  database backup, run `php artisan cache:clear`. Redis still works
  (`CACHE_STORE=redis`) but is optional.
- **Load test**: `php artisan app:load-test --users=8 --messages=25` sends from
  that many processes at once next to one queue worker, on a throwaway copy of
  the schema with the same settings, and fails on any "database is locked".
  `--untuned` runs it with SQLite's defaults for comparison (on a laptop: 0
  lock errors tuned, dozens untuned).
- **N+1 queries**: outside production, lazy loading a relation in a loop
  throws (`Model::preventLazyLoading()`); production logs a warning instead.
  `tests/Feature/Performance` checks that the main chat views run as many
  queries for a big server as for a small one. Locally, [Laravel
  Debugbar](https://github.com/barryvdh/laravel-debugbar) (a dev dependency)
  shows each page's queries, with duplicates, when `APP_DEBUG=true`; it is not
  installed with `composer install --no-dev`.

