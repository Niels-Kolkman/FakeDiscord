# FakeDiscord

[![CI](https://github.com/Niels-Kolkman/FakeDiscord/actions/workflows/ci.yml/badge.svg)](https://github.com/Niels-Kolkman/FakeDiscord/actions/workflows/ci.yml)
![PHP 8.2 – 8.4](https://img.shields.io/badge/PHP-8.2%20–%208.4-777bb4?logo=php&logoColor=white)
![Laravel 12](https://img.shields.io/badge/Laravel-12-ff2d20?logo=laravel&logoColor=white)
![Tailwind CSS 4](https://img.shields.io/badge/Tailwind%20CSS-4-38bdf8?logo=tailwindcss&logoColor=white)
![Tests: Pest, node:test, Playwright](https://img.shields.io/badge/tests-Pest%20·%20node%3Atest%20·%20Playwright-22c55e)

A Discord-style realtime chat app built with **Laravel 12**, **Laravel Reverb** websockets,
Blade, plain ES modules and **Tailwind CSS v4**. It has servers, channels, roles, direct
messages, moderation and live presence, and it runs locally on SQLite with no other services.

I built it to show how I work on a real-sized Laravel codebase. That means a
permission system modelled on Discord's, realtime updates that respect those
permissions, security hardening, accessibility, and a test suite that runs in CI
on every push.

## Contents

- [Features](#features)
- [Engineering highlights](#engineering-highlights)
- [Tech stack](#tech-stack)
- [Getting started](#getting-started)
- [Testing and quality](#testing-and-quality)
- [Project structure](#project-structure)
- [Documentation](#documentation)

## Features

**Chat**
- Realtime messages in server channels and DMs: no reload, with infinite-scroll history and a "jump to latest" button
- Editing, deleting, replies, pins, emoji reactions and file and image attachments
- Discord-style markdown, link detection and an emoji picker with search and recently used emoji
- `@user`, `@role` and `@everyone` mentions with autocomplete, an inbox and desktop notifications
- Typing indicators, unread dots and mention badges per channel and server
- Message search with channel, author and date filters

**Servers and communities**
- Servers with icons, text channels and categories (drag-and-drop or keyboard reordering), and invite links with an expiry and a use limit
- Roles with 14 permission flags, a role hierarchy, and private channels with per-role overrides
- Moderation: kick, ban (optionally deleting recent messages) and timeouts, plus an audit log for the owner
- A member list grouped by role with live online, idle and do-not-disturb presence

**People**
- Registration, login, email verification, password reset and account deletion (Laravel Fortify)
- Profiles with avatars, bios and a profile card
- Friends, friend requests and blocking
- 1:1 and group DMs

**Interface**
- Discord's four-pane layout, with swipeable drawers on phones
- Dark and light themes, saved per user
- WCAG 2.2 AA work: keyboard channel switching, a skip link, screen-reader announcements for new messages, focus traps and contrast-checked colour tokens

## Engineering highlights

- **A permission engine like Discord's.** `App\Services\PermissionService` resolves
  role bitmasks, Administrator, channel overrides (where an allow beats a deny across
  roles), timeouts and the role hierarchy in one place. The server, channel, role and
  message policies ask it, and so do the websocket channel rules. A member who can't see a channel can't load it, search
  it, or listen to it.
- **Realtime that stays authorised.** Messages, edits, reactions, pins, typing, presence,
  friend requests and moderation events go over Reverb on private and presence channels.
  When someone is kicked or banned, the server ends their websocket connections, so
  every subscription is checked again.
- **Correct under concurrency.** Invite uses, friend requests, reactions, DM creation and
  group DM seats are protected by guarded `UPDATE`s, unique indexes and row locks, not
  by checks in PHP.
- **Safe rendering.** Message markdown is parsed into a tree of known nodes and written
  with escaping, once in PHP and once in JS. Both parsers run against the same fixture
  file (`tests/fixtures/markdown.json`).
- **Security hardening.** Named rate limiters, CSRF on every write, security headers,
  signed URLs for private attachments, and upload type sniffing. The tests in
  `tests/Feature/Security` pin these down (XSS, CSRF/CORS, mass assignment and
  broadcast channel authorisation).
- **Works without JavaScript.** Most actions are plain forms that JavaScript enhances
  with `fetch()`. The same endpoint answers a form post with a redirect and a `fetch()`
  call with JSON.

More in [docs/architecture.md](docs/architecture.md).

## Tech stack

| Area | Tools |
| --- | --- |
| Backend | PHP 8.2+, Laravel 12, Laravel Fortify (auth), Laravel Scout (search, database driver) |
| Realtime | Laravel Reverb, Laravel Echo, pusher-js |
| Frontend | Blade components, plain ES modules, Tailwind CSS v4, Vite 7 |
| Database | SQLite (foreign keys on); sessions, cache and queue use the database too |
| Testing | Pest 3 (feature and unit tests), Node's built-in test runner (JS), Playwright (browser end to end) |
| Quality | Laravel Pint, Larastan (PHPStan level 6), GitHub Actions |

## Getting started

You need **PHP 8.2+** (with `pdo_sqlite`, `mbstring`, `openssl`, `fileinfo` and `gd`),
**Composer 2** and **Node.js 20.19+ or 22.12+**.

```bash
git clone https://github.com/Niels-Kolkman/FakeDiscord.git
cd FakeDiscord
composer setup                    # install, .env, APP_KEY, SQLite database, migrations, storage link, build
php artisan migrate:fresh --seed  # optional: demo users, servers and messages
composer dev                      # app, queue, logs, Vite and Reverb together
```

Open <http://127.0.0.1:8000> and log in as **`test@example.com`** with the password
**`password`**. Every demo user has that password, and the invite codes `lounge` and
`pixelpals` join the two demo servers. Emails such as verification links are written to
`storage/logs/laravel.log`.

<details>
<summary>Manual setup, step by step</summary>

```bash
composer install
cp .env.example .env            # Windows (PowerShell): Copy-Item .env.example .env
php artisan key:generate
php -r "file_exists('database/database.sqlite') || touch('database/database.sqlite');"
php artisan migrate
php artisan storage:link        # serves uploaded avatars from public/storage
npm install
```

Then run each of these in its own terminal:

```bash
php artisan serve        # http://127.0.0.1:8000
npm run dev              # Vite dev server with hot reload
php artisan reverb:start # websockets on localhost:8080
```

</details>

The [development guide](docs/development.md) covers Reverb, Mailpit, demo data and
troubleshooting.

## Testing and quality

```bash
composer test           # Pest: feature and unit tests
composer test:coverage  # the same, and fails below 80% coverage of app/ (needs PCOV or Xdebug)
npm test                # JavaScript unit tests (tests/js)
npm run test:e2e        # Playwright: two browsers chatting live over Reverb
composer lint           # Pint code style
composer analyse        # Larastan, level 6
```

- **PHP tests in `tests/Feature` and `tests/Unit`**: flows end to end (auth, servers,
  channels, messages, DMs, friends, permissions, moderation) and the edge cases around
  them. They also check that broadcasts go out on the right channel with the right
  payload.
- **JavaScript tests in `tests/js`** for every frontend module, run with `node --test`
  and a small fake DOM (`tests/js/helpers/fake-dom.js`). No browser is needed.
- **A Playwright test** in which two users register, verify their email, join a server
  through an invite and see each other's messages arrive live.
- **GitHub Actions** runs all of it on every push and pull request: Pint, Larastan, the
  test suite on PHP 8.2, 8.3 and 8.4, coverage (at least 80%) and the browser tests.

## Project structure

```text
app/
├── Actions/        one class per use case (SendMessage, BanMember, AcceptInvite, …)
├── Enums/          Permission flags (Discord's bit values)
├── Events/         broadcast events (MessageSent, MemberModerated, …)
├── Http/           thin controllers, form requests, SecurityHeaders middleware
├── Models/         Eloquent models
├── Notifications/  mention notifications (database + broadcast)
├── Observers/      audit log writers
├── Policies/       authorisation, backed by PermissionService
├── Services/       PermissionService
└── Support/        Markdown, Mentions, UnreadState, MessageSearch, RateLimits, …
resources/
├── js/             one ES module per feature (channel-messages, presence, typing-indicator, …)
├── css/app.css     Tailwind v4 theme tokens (dark and light)
└── views/          Blade pages and components
routes/
├── web.php         HTTP routes
└── channels.php    websocket channel authorisation
tests/
├── Feature/, Unit/ Pest and PHPUnit tests
├── js/             frontend unit tests
└── e2e/            Playwright browser tests
docs/               architecture, features, security, schema, development
```

## Documentation

| Document | What's in it |
| --- | --- |
| [docs/architecture.md](docs/architecture.md) | Layers, the permission resolution, realtime channels and events, data and concurrency |
| [docs/features.md](docs/features.md) | Every feature in detail: routes, rules, limits and the classes behind them |
| [docs/security.md](docs/security.md) | Rate limits, XSS, CSRF/CORS, mass assignment, websocket and upload safety |
| [docs/database-schema.md](docs/database-schema.md) | ERD, tables, indexes and delete rules (a test checks it against the migrations) |
| [docs/development.md](docs/development.md) | Setup, Reverb, email, demo data, commands, CI and how to write tests |
