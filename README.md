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
generates `APP_KEY`, creates `database/database.sqlite`, runs the migrations
and builds the frontend assets.

### Manual setup

```bash
composer install
cp .env.example .env            # Windows (PowerShell): Copy-Item .env.example .env
php artisan key:generate
php -r "file_exists('database/database.sqlite') || touch('database/database.sqlite');"
php artisan migrate
npm install
```

## Running locally

Run these in two terminals:

```bash
php artisan serve   # http://127.0.0.1:8000
npm run dev         # Vite dev server with hot reload
```

Then open <http://127.0.0.1:8000> to see the welcome page.

You can also start the server, queue worker, log tail (`pail`) and Vite together:

```bash
composer dev
```

## Database

`.env.example` sets `DB_CONNECTION=sqlite`. When `DB_DATABASE` is not set, Laravel
uses `database/database.sqlite`. Git ignores that file. To reset it, run:

```bash
php artisan migrate:fresh
```

To use MySQL or another database instead, set the `DB_*` variables in `.env`.

## Frontend

- Entry points: `resources/css/app.css` and `resources/js/app.js`, set in `vite.config.js`.
- Tailwind v4 loads through the `@tailwindcss/vite` plugin and needs no `tailwind.config.js`.
  The `@source` and `@theme` directives in `resources/css/app.css` configure it.
- `npm run build` writes production assets to `public/build/`. Git ignores this folder.

## Useful commands

```bash
composer test        # run the PHPUnit test suite
./vendor/bin/pint    # format PHP code (Laravel Pint)
npm run build        # production frontend build
```
