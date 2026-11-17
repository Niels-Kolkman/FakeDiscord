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

Fortify's password update feature is on for the account settings page (see
below). Its other features (two-factor auth, passkeys, profile updates) are off
in `config/fortify.php`.

### Account settings

`/settings/account` lets a logged-in user change their email address, change
their password and delete their account. Each form asks for the current
password again. Unverified users can open the page too, so they can fix a
mistyped address.

- **Email** (`PUT /settings/account/email`): the new address is lowercased and
  must be unique. It starts unverified and a new verification link is sent, so
  `verified` routes stay closed until the user confirms it. The logic is in
  `app/Actions/Account/UpdateUserEmail.php`.
- **Password** (`PUT /user/password`, Fortify): the new password follows the
  same rules as registration and must differ from the current one. The rules
  are in `app/Actions/Fortify/UpdateUserPassword.php`.
- **Delete account** (`DELETE /settings/account`): the user's messages are kept
  and anonymised, not removed. Their `user_id` becomes `NULL` and
  `Message::authorName()` returns "Deleted User". Memberships, roles,
  reactions, friendships, bans and DM participation are deleted with the
  account, and all of the user's sessions are signed out. Users who own a server
  can't delete their account until they transfer or delete it, because
  deleting the owner would delete the whole server, including other members'
  messages. The logic is in `app/Actions/Account/DeleteUser.php`.

The email and delete routes are limited to 6 requests per minute.

### Email verification

`App\Models\User` implements `MustVerifyEmail`, and Fortify's
`emailVerification` feature is on.

- After registering, users get an email with a signed verification link. The
  link is valid for 60 minutes. Users can log in and see `/dashboard` before
  they verify, and the dashboard shows a reminder until they do.
- `/email/verify` shows the "verify your email" page with a resend button
  (`POST /email/verification-notification`, limited to 6 per minute). The link
  goes to `/email/verify/{id}/{hash}`. Users must be logged in as the same user
  to open it.
- Routes that need a verified email, such as joining a server, go in the
  `['auth', 'verified']` group in `routes/web.php`. Unverified users who open
  them are redirected to `/email/verify`. JSON requests get a 403 instead.

To read the emails locally, use one of these:

- **Log driver (default):** with `MAIL_MAILER=log`, the email, including the
  verification link, is written to `storage/logs/laravel.log`. `composer dev`
  also shows it in the `logs` pane.
- **Mailpit:** start [Mailpit](https://mailpit.axllent.org/) (for example
  `mailpit`, or `docker run -p 8025:8025 -p 1025:1025 axllent/mailpit`), then set
  `MAIL_MAILER=smtp`, `MAIL_HOST=127.0.0.1` and `MAIL_PORT=1025` in `.env` and
  run `php artisan config:clear`. Open http://localhost:8025 to see the inbox.

To verify a user by hand, for example a demo account, run:
`php artisan tinker --execute="App\Models\User::where('email', 'you@example.test')->first()->markEmailAsVerified();"`

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

## Servers

Users with a verified email address manage servers under `/servers`
(`ServerController`, routes in the `['auth', 'verified']` group).

- **Create** (`/servers/create`): name (2–100 characters; surrounding and
  repeated whitespace is collapsed) and an optional icon. `App\Actions\Servers\CreateServer`
  creates, in one transaction, the server with the creator as `owner_id`, the
  owner's `server_members` row, the default `@everyone` role and a `#general`
  text channel.
- **Open** (`/servers/{id}`): members only. Shows the channel sidebar and
  opens the first text channel (see [Channels and categories](#channels-and-categories)).
- **Settings** (`/servers/{id}/settings`): the owner can rename the server and
  replace or remove the icon. A failed icon upload also rolls back the rename.
- **Delete**: the owner types the server name to confirm. Channels, messages,
  roles, invites, bans and memberships are removed by `ON DELETE CASCADE`, and
  the icon file is deleted.
- **Icons** use the same rules and processing as avatars (PNG, JPG, GIF or
  WebP, up to 4 MB and 4096×4096 px, centre-cropped and resized to a 256×256
  PNG by `App\Support\SquarePng`). They are stored under `server-icons/{server_id}/`
  on the disk set by `SERVER_ICON_DISK` (default `public`). Servers without an
  icon show their initials (`<x-server-icon :server="$server" />`).
- **Authorisation** is in `App\Policies\ServerPolicy`: `create` needs a
  verified email, `view` needs membership, and `update` and `delete` are owner
  only until role permissions arrive (ISSUE-26).

### Members and leaving

- **Member sidebar**: server and channel pages show the members on the right
  (`servers/partials/member-list.blade.php`, built by `App\Support\MemberList`
  through `Server::memberList()`). Online members (status `online`, `idle` or
  `dnd`) are grouped under their highest role other than `@everyone` (highest
  `position`, then lowest id). Online members without such a role go under
  **Online**, and everyone else goes under **Offline**, whatever their roles.
  Empty groups are hidden. Members are sorted by nickname, or by name when there
  is none, ignoring case. Online members' names use their highest role's colour
  (only `#rrggbb` values are used), and the owner has a crown. The list takes
  four queries, however many members there are.
- **Leave** ("Leave server" in the channel sidebar, `GET /servers/{id}/leave`
  to confirm, then `DELETE` the same URL): `App\Actions\Servers\LeaveServer`
  deletes the membership and the member's `role_user` rows for that server's
  roles. Invites they created keep working. They can rejoin with an invite.
- **The owner cannot leave** (`ServerPolicy::leave`). The leave page explains
  this and links to **Transfer ownership** on the settings page. The action
  checks the owner again inside its transaction.
- **Transfer ownership** (`PATCH /servers/{id}/owner`, owner only, 6 per
  minute): the owner picks another member and types the server name to confirm.
  `App\Actions\Servers\TransferServerOwnership` sets `owner_id`. The previous
  owner stays a member and can then leave. Errors use their own error bag
  (`transferOwnership`), so they never show on the delete form.

## Channels and categories

A server's sidebar lists its uncategorised channels first, then its
**categories** (`channel_categories`), each with its own channels. Members can
open any channel at `/servers/{id}/channels/{channel}`. The header shows the
channel name and **topic**. Everything else needs
`ServerPolicy::manageChannels`, which is owner only until role permissions
arrive (ISSUE-26).

- **Create channel** (`/servers/{id}/channels/create`, optional `?category={id}`):
  name, optional topic (up to 1024 characters) and optional category. Text
  channel names are normalised like Discord by `Channel::normalizeName()`:
  lower case, whitespace becomes `-`, only letters, digits, `-` and `_` are kept
  ("My Cool Chat!" → `my-cool-chat`). New channels go to the bottom of their
  group. A server can have at most 500 channels.
- **Channel settings** (`/servers/{id}/channels/{channel}/settings`): rename,
  change or clear the topic, move to another category (it goes to the bottom of
  that category), or delete. Deleting removes the channel's messages. Invites
  that pointed at it stay, with no channel. Voice channels keep spaces and
  capitals in their names and have no topic.
- **Categories** (`/servers/{id}/categories/create` and
  `/servers/{id}/categories/{category}/settings`): create, rename (up to 100
  characters) and delete. Deleting a category keeps its channels and moves them,
  in order, to the bottom of the uncategorised list. A server can have at most
  50 categories.
- **Collapsing**: click a category name to collapse or expand it. The collapsed
  categories are remembered per server in the browser's `localStorage`. A
  collapsed category still shows the channel you have open.
- **Reordering** (`resources/js/channel-sidebar.js`): managers can drag
  channels within or between categories, and drag categories. Or focus a
  channel or category and press <kbd>Alt</kbd>+<kbd>↑</kbd>/<kbd>↓</kbd>. Each
  change sends the whole sidebar to `PUT /servers/{id}/layout`:

  ```json
  {"uncategorized": [1, 2], "categories": [{"id": 3, "channels": [4, 5]}]}
  ```

  `App\Actions\Channels\ReorderChannels` accepts it only if it names every
  channel and category of the server exactly once. Otherwise (another tab
  added or deleted one in the meantime) it answers 422 and the sidebar asks you
  to reload, so a stale order never overwrites a newer one.

Changes made by other users show up after a reload. Live sidebar updates over
Reverb are not part of this feature.

## Invites

Invites (`InviteController`, also in the `['auth', 'verified']` group) let
people join a server.

- **Create** (`/servers/{id}/invites`, "Invite people" on the server page): any
  member can generate a link. They choose an expiry (30 minutes, 1, 6 or 12
  hours, 1 day, 7 days (the default) or never) and a maximum number of uses (no
  limit, 1, 5, 10, 25, 50 or 100). `App\Actions\Servers\CreateInvite` makes a
  random 8-character, case-sensitive code (`[A-Za-z0-9]`) and retries if the
  code is already taken. The link is `/invite/{code}`.
- **List and revoke**: the same page lists usable invites. The owner sees and
  can revoke every invite. Other members see only their own invites, and can
  revoke them while they are still a member (`App\Policies\InvitePolicy`).
- **Join**: `GET /invite/{code}` shows the server name, icon, inviter and member
  count. Opening the page does not count as a use. **Accept invite** posts to the
  same URL, and `App\Actions\Servers\AcceptInvite` adds the user and counts one
  use. Guests are sent to log in and then come back to the invite. Unverified
  users are sent to `/email/verify`.
- **Refusals**, checked in this order:
  1. Banned from the server: 403, even if the invite has also expired.
  2. Already a member: redirected to the server. No use is counted, and expired
     or full invites still work for members.
  3. Expired (`expires_at` has passed): 410.
  4. Full (`uses` has reached `max_uses`): 410.

  Unknown codes return 404.
- Uses are counted with one guarded `UPDATE … WHERE uses < max_uses AND not
  expired`, in the same transaction as the membership insert. Two people can
  therefore never both take the last use, and a failed join gives the use back.
- Both invite routes allow 30 requests per minute, and creating invites allows
  20 per minute.

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
