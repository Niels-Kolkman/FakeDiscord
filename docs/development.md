# Development guide

Everything you need to run, test and change FakeDiscord locally. The
[README](../README.md) has the short version.

## Requirements

| Tool     | Version                                                       |
| -------- | ------------------------------------------------------------- |
| PHP      | 8.2+ with the `pdo_sqlite`, `mbstring`, `openssl`, `fileinfo` and `gd` extensions (`gd` resizes avatars and server icons) |
| Composer | 2.x                                                           |
| Node.js  | 20.19+ or 22.12+ (needed by Vite 7), with npm                 |

No database server, Redis or mail server is needed. Sessions, the cache and the
queue all use the SQLite database, and emails are written to the log.

## Setup

### Quick setup

```bash
composer setup
```

This installs the PHP and npm dependencies, copies `.env.example` to `.env`,
generates `APP_KEY`, creates `database/database.sqlite`, runs the migrations,
links `public/storage` (for uploaded avatars) and builds the frontend assets.

### Manual setup

```bash
composer install
cp .env.example .env            # Windows (PowerShell): Copy-Item .env.example .env
php artisan key:generate
php -r "file_exists('database/database.sqlite') || touch('database/database.sqlite');"
php artisan migrate
php artisan storage:link        # serves uploaded avatars from public/storage
npm install
```

## Running locally

Run these in two terminals:

```bash
php artisan serve   # http://127.0.0.1:8000
npm run dev         # Vite dev server with hot reload
```

Then open <http://127.0.0.1:8000>. It sends you to the login page (or to the
dashboard if you are already signed in).

You can also start the server, queue worker, log tail (`pail`), Vite and the
Reverb websocket server together:

```bash
composer dev
```

## Realtime (Laravel Reverb)

Broadcasting uses [Laravel Reverb](https://laravel.com/docs/reverb), a websocket
server that runs on `localhost:8080` (`php artisan reverb:start`, included in `composer dev`).
The browser connects with Laravel Echo + `pusher-js` (`resources/js/echo.js`).

- `.env.example` sets `BROADCAST_CONNECTION=reverb` and local `REVERB_*` credentials.
  `VITE_REVERB_*` passes them to the frontend. Restart `npm run dev` after you change them.
  In production, set your own random `REVERB_APP_KEY` / `REVERB_APP_SECRET`.
- Private channel rules live in `routes/channels.php`. Channel messages use
  `channel.{id}` (see [Messages](features.md#messages)). The full list of channels
  and events is in [architecture.md](architecture.md#realtime).
- Reverb only accepts websockets from pages on the `APP_URL` host. With `APP_ENV=local`
  it also accepts `localhost` and `127.0.0.1`. Set `REVERB_ALLOWED_ORIGINS`
  (comma-separated hosts) when the app is served from somewhere else. See [security.md](security.md).

### Smoke test

1. Run `composer dev` and open <http://127.0.0.1:8000> with the browser console open.
2. In another terminal, run:

   ```bash
   php artisan tinker
   > event(new App\Events\TestBroadcast('Hello from tinker'));
   ```

3. The console logs `[Reverb] test.message received: {message: 'Hello from tinker', ...}`.

`TestBroadcast` broadcasts right away (`ShouldBroadcastNow`) on the public `test` channel,
so it doesn't need the queue worker. The console listener is only active in the Vite
dev server, not in `npm run build` output.

## Database

`.env.example` sets `DB_CONNECTION=sqlite`. When `DB_DATABASE` is not set, Laravel
uses `database/database.sqlite`. Git ignores that file. To reset it, run:

```bash
php artisan migrate:fresh
```

### Demo data

To fill the database with demo data for manual testing, run:

```bash
php artisan migrate:fresh --seed
```

`DemoSeeder` creates 10 users, two servers ("Laravel Lounge" and "Pixel Pals") with
roles, channels and invites, about 300 channel messages with replies, edits,
reactions and attachments, a direct message, a group DM, friendships and a ban.
Log in as `test@example.com`. Every demo user has the password `password`. The
invite codes are `lounge` and `pixelpals`; `expired` is an expired invite.

Every model in `app/Models` has a factory in `database/factories`. The factories
create only their own row plus the parents it needs. For example,
`Server::factory()` does not add the owner as a member.

To use MySQL or another database instead, set the `DB_*` variables in `.env`.

`DB_FOREIGN_KEYS=true` (on by default) makes SQLite enforce foreign keys and
`ON DELETE` rules. [database-schema.md](database-schema.md) has the
schema, the ERD, the indexes and the delete rules.
`tests/Feature/SchemaDocumentationTest.php` fails when that file and the
migrations disagree, so update it together with a migration.

## Useful commands

```bash
composer test        # run the test suite (Pest; runs the PHPUnit classes too)
composer test:coverage  # the same with a coverage report; fails below 80% of app/ (needs PCOV or Xdebug)
composer format      # format PHP code (Laravel Pint)
composer lint        # check code style without changing files
composer analyse     # static analysis (Larastan / PHPStan, config in phpstan.neon)
npm run build        # production frontend build
npm test             # JavaScript unit tests (tests/js, Node's built-in test runner)
npm run test:e2e     # browser tests (Playwright, tests/e2e); see "Browser tests"
```

## Code quality and CI

- **Pint** formats code with the `laravel` preset (`pint.json`).
- **Larastan** checks `app`, `config`, `database`, `routes` and `tests` at level 6 (`phpstan.neon`).
  Fix new errors in the code instead of adding a baseline or `@phpstan-ignore`.
- **GitHub Actions** (`.github/workflows/ci.yml`) runs `composer lint`, `composer analyse`
  and `composer test` (on PHP 8.2, 8.3 and 8.4) on every push and pull request, plus
  `composer test:coverage` with PCOV on PHP 8.3, and the Playwright browser tests
  (`npm run test:e2e`; traces, screenshots and videos are uploaded when they fail).

## Tests

The suite runs on [Pest](https://pestphp.com) 3. Older tests are PHPUnit classes, and
Pest runs them unchanged. New feature tests are written as Pest functions:

- `tests/Pest.php` binds every function-style test under `tests/Feature` to
  `Tests\FeatureTestCase`, which gives it a fresh database (`RefreshDatabase`), the
  permission helpers from `Tests\Concerns\GivesPermissions` (`memberWith()`, `makeRole()`,
  `giveRole()`, `overrideChannel()`, `revokeFromEveryone()`) and no need for a Vite build.
  It also defines helpers: `serverOwnedBy()`, `memberOf()`, `generalChannel()`,
  `messagesUrl()` and `serverScene()` (owner, server, #general and a member as a typed
  `Tests\Support\ServerScene`).
- The `*FlowTest.php` files cover auth, servers (invites, moderation), channels,
  messages, DMs and friends, and permissions end to end. Broadcasts are checked with
  `Event::fake([...])` and `Event::assertDispatched()`, including the channel each
  event goes out on (`broadcastOn()`) and its payload (`broadcastWith()`).
- Keep shared state in local variables or `serverScene()` instead of `$this->foo`:
  `tests/stubs/pest.stub` tells Larastan that `$this` in `it()`, `test()` and
  `beforeEach()` is `Tests\FeatureTestCase`, so dynamic properties would fail analysis.

```php
it('broadcasts a new message on its channel', function () {
    Event::fake([MessageSent::class]);
    $s = serverScene();

    $this->actingAs($s->member)->postJson($s->messagesUrl(), ['content' => 'hi'])->assertCreated();

    Event::assertDispatched(MessageSent::class, fn (MessageSent $e) => $e->broadcastOn()->name === "private-channel.{$s->channel->id}");
});
```

Run `composer lint && composer analyse && composer test` before you push.

### Browser tests

`tests/e2e` holds end-to-end tests in real browsers with [Playwright](https://playwright.dev).
`realtime-messaging.spec.js` covers the critical flow: Alice registers, verifies her email,
creates a server and an invite link; Bob registers, verifies and accepts the invite; then
they exchange messages in #general from two separate browser sessions, and each sees the
other's message arrive over Reverb without a reload.

```bash
npx playwright install chromium   # once
npm run build                     # the bundle carries the Reverb host and port from .env
npm run test:e2e                  # add --headed or --ui to watch it
```

`playwright.config.js` starts what the test needs:

- **The app** on `http://127.0.0.1:8001` (`tests/e2e/support/serve.js`), on its own
  database `database/e2e.sqlite`, wiped and migrated on every run. Your development
  database and a `composer dev` server on :8000 are not touched. The overrides are in
  `tests/e2e/support/environment.js` (sync queue, log mailer, Reverb broadcasting); the
  rest, such as `APP_KEY`, comes from `.env`. The script runs `config:clear`, because a
  cached config would ignore the overrides.
- **Reverb** on `REVERB_PORT` (8080). Locally an already running Reverb, for example the
  one from `composer dev`, is reused; it only needs the same app credentials.

The verification email is not parsed out of the log: `tests/e2e/support/verification-url.php`
prints the link that the app's own `VerifyEmail` notification would send, and the browser
opens it. Before sending, `waitForRealtime()` waits until each page's websocket is subscribed
to the channel, so a message can't be missed while the other page is still connecting.
When a test fails, its trace, screenshots and video are in `storage/framework/testing/e2e-results`
(`npx playwright show-trace <path>/trace.zip`). Set `E2E_APP_PORT` or `E2E_REVERB_PORT` to use
other ports (a different Reverb port also needs a rebuild with that `VITE_REVERB_PORT`), and
`PHP_BINARY` for another PHP.

## Troubleshooting

- **`composer setup` stops with "exceeded the timeout of 300 seconds".** The first
  `composer install` can take longer than Composer's script timeout on a cold cache.
  Run `composer install` on its own (it has no such limit), then `composer setup`
  again. The install step is then quick.
- **Messages only show up after a reload.** Reverb isn't running, or the frontend was
  built with other `VITE_REVERB_*` values. Start `php artisan reverb:start` (or
  `composer dev`), and restart `npm run dev` or rerun `npm run build` after changing
  `.env`. The [smoke test](#smoke-test) checks the connection.
- **Uploads fail with a `413` or a validation error.** Raise `upload_max_filesize` to
  at least `10M` and `post_max_size` to at least `55M` in `php.ini`.
- **Avatars or server icons fail to save.** The `gd` extension is missing, or
  `php artisan storage:link` has not been run.
- **A config change in `.env` does nothing.** Run `php artisan config:clear`.
  `composer test` does this before every run.
