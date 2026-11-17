# FakeDiscord

A Discord-style chat app built with **Laravel 12**, **Vite 7** and **Tailwind CSS v4**.
Local development uses **SQLite**, so no database server is needed.

## Requirements

| Tool     | Version                                                       |
| -------- | ------------------------------------------------------------- |
| PHP      | 8.2+ with the `pdo_sqlite`, `mbstring`, `openssl` and `fileinfo` extensions |
| Composer | 2.x                                                           |
| Node.js  | 20.19+ or 22.12+ (needed by Vite 7), with npm                 |

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

Then open <http://127.0.0.1:8000> to see the welcome page.

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
- Private channel rules live in `routes/channels.php`.

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
`ON DELETE` rules. [docs/database-schema.md](docs/database-schema.md) has the
schema, the ERD, the indexes and the delete rules.

## Authentication

[Laravel Fortify](https://laravel.com/docs/fortify) provides the backend for
registration, login, logout and password reset. The routes are `/register`,
`/login`, `POST /logout`, `/forgot-password` and `/reset-password/{token}`.
The Blade views are in `resources/views/auth`.

- To register, a user gives a display name, a username, an email address and a
  password. Usernames are stored in lowercase and must be unique. They can be
  2–32 characters long and may contain only `a-z`, `0-9`, `_` and `.`. The rules
  are in `app/Actions/Fortify/CreateNewUser.php`.
- Users log in with their email address and password. The login form is limited
  to 5 attempts per minute for each email and IP address.
- After login, users land on `/dashboard`, a placeholder until the app shell is
  built.
- Password reset links are sent by email. In local development, `MAIL_MAILER=log`
  writes the email to `storage/logs/laravel.log`.

Fortify's other features (two-factor auth, passkeys, profile and password
updates, email verification) are off in `config/fortify.php`.

## Profiles and avatars

Signed-in users edit their profile at `/settings/profile` (`ProfileController`).

- **Display name** (required, up to 255 characters) and **bio** ("About me", up
  to 190 characters). The username and email cannot be changed here.
- **Avatar**: PNG, JPG, GIF or WebP, up to 4 MB and 4096×4096 px (SVG is
  rejected). `App\Actions\Profile\UpdateUserAvatar` uses GD (the `gd` PHP
  extension is required) to centre-crop the image to a square, resize it to
  256×256 and store it as PNG under `avatars/{user_id}/` on the disk set by
  `AVATAR_DISK` (default `public`, so run `php artisan storage:link`). The old
  file is deleted when the avatar is replaced or removed.
- **Default avatar**: users without an upload get a generated SVG with their
  initials on a colour picked from their username (`App\Support\DefaultAvatar`).
  `$user->avatarUrl()` returns either URL.
- **Profile popover**: `<x-user-avatar :user="$user" />` renders a clickable
  avatar. Clicking it fetches `GET /users/{username}/profile` (JSON: name,
  username, bio, status, avatar URL, member since; never the email) and shows a
  profile card (`resources/js/profile-popover.js`). Escape or an outside click
  closes it.

## Frontend

- Entry points: `resources/css/app.css` and `resources/js/app.js`, set in `vite.config.js`.
- Tailwind v4 loads through the `@tailwindcss/vite` plugin and needs no `tailwind.config.js`.
  The `@source` and `@theme` directives in `resources/css/app.css` configure it.
- `npm run build` writes production assets to `public/build/`. Git ignores this folder.

## Useful commands

```bash
composer test        # run the PHPUnit test suite
composer format      # format PHP code (Laravel Pint)
composer lint        # check code style without changing files
composer analyse     # static analysis (Larastan / PHPStan, config in phpstan.neon)
npm run build        # production frontend build
```

## Code quality and CI

- **Pint** formats code with the `laravel` preset (`pint.json`).
- **Larastan** checks `app`, `config`, `database`, `routes` and `tests` at level 6 (`phpstan.neon`).
  Fix new errors in the code instead of adding a baseline or `@phpstan-ignore`.
- **GitHub Actions** (`.github/workflows/ci.yml`) runs `composer lint`, `composer analyse`
  and `composer test` (on PHP 8.2, 8.3 and 8.4) on every push and pull request.

Run `composer lint && composer analyse && composer test` before you push.
