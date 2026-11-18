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
  `channel.{id}` (see [Messages](#messages)).

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

## Messages

An open text channel shows its newest 50 messages (`App\Support\MessageFeed`),
oldest at the top, with a composer below. Scrolling up loads older messages.
Voice channels have no messages.

- **History**: `GET /servers/{id}/channels/{channel}/messages` returns one page
  of 50 messages, oldest first, as
  `{"messages": [...], "next_cursor": "..."}`. Pages come from Laravel's
  `cursorPaginate()` on the message id, so new messages never shift the older
  pages. Pass `?before={next_cursor}` for the page before. `next_cursor` is
  `null` on the page that holds the first message of the channel. Only cursors
  the API hands out are accepted (others are a `422`). Members only; voice
  channels are a `404`; limited to 60 requests per minute. The channel page
  puts the first cursor in `data-older-cursor`.
- **Infinite scroll**: `resources/js/channel-messages.js` fetches the next older
  page when you are within 400px of the top and prepends it. It keeps your
  distance from the bottom, so the message you were reading does not move.
  After the last page it shows the "Welcome to #channel" header. If a page fails
  to load, a "Try again" button appears.
- **Jump to latest**: when you are more than a screen away from the bottom, a
  button scrolls back down. It counts the new messages that arrived while you
  were scrolled up.

- **Sending**: any server member may post (`ServerPolicy::sendMessages`) with
  `POST /servers/{id}/channels/{channel}/messages`, field `content`. The content is
  trimmed, required and at most 2000 characters. Sending is limited to 30
  messages per minute. <kbd>Enter</kbd> sends and <kbd>Shift</kbd>+<kbd>Enter</kbd>
  adds a new line. The composer (`resources/js/channel-messages.js`) posts JSON
  and gets back `201 {"message": {...}}`. Without JavaScript, the form posts
  normally and redirects back to the channel.
- **Receiving**: `App\Actions\Messages\SendMessage` stores the message and
  broadcasts `App\Events\MessageSent` (as `message.sent`) on the private channel
  `channel.{id}`. Everyone viewing the channel gets it right away. The sender's
  own socket is skipped (`X-Socket-ID`), because the sender already has the
  message from the response. The event uses `ShouldBroadcastNow`, so no queue
  worker is needed. If Reverb is down, the message is still saved and the error
  is reported; others see the message after a reload.
- **Authorisation**: `routes/channels.php` lets only members of the channel's
  server subscribe to `channel.{id}`. This is checked when the browser
  subscribes. A user who leaves the server keeps an open subscription until they
  reload.
- **Editing**: hover a message you wrote and choose **Edit**. <kbd>Enter</kbd>
  saves, <kbd>Escape</kbd> cancels. The browser sends
  `PATCH /servers/{id}/channels/{channel}/messages/{message}` with `content` (same
  rules as sending) and gets back `200 {"message": {...}}`.
  `App\Actions\Messages\EditMessage` sets `edited_at`, and the message then shows
  "(edited)". Saving unchanged text does nothing. Only the author can edit, and
  only while they are still a member (`App\Policies\MessagePolicy::update`).
  Moderators cannot edit other people's messages.
- **Deleting**: **Delete** asks for confirmation, then sends
  `DELETE /servers/{id}/channels/{channel}/messages/{message}`, which returns
  `204`. The author can delete their own messages. Moderators can delete anyone's,
  including messages of deleted accounts (`MessagePolicy::delete`,
  `ServerPolicy::manageMessages`, owner only until role permissions arrive in
  ISSUE-26). Messages are **soft deleted** (`messages.deleted_at`): they vanish
  from the channel and the history API, but the row stays so replies keep their
  `reply_to_id`. A deleted message is a `404` for further edits and deletes.
  Without JavaScript, Delete is a normal form that redirects back to the
  channel. Edit needs JavaScript.
- **Live updates**: `App\Events\MessageUpdated` (`message.updated`, same payload
  as below) and `App\Events\MessageDeleted` (`message.deleted`,
  `{"id": 1, "channel_id": 1}`, no content) go out on `channel.{id}` right away
  (`ShouldBroadcastNow`). They skip the socket of the person who made the
  change. Others see the new text, or see the message disappear. If Reverb is
  down, the change is still saved.
  Both routes are limited to 30 requests per minute.
- **Reactions** (ISSUE-18): any member can react to any message. Hover a
  message and choose 🙂 to pick an emoji, or click an existing reaction to add
  yours or take it back. Reactions are grouped per emoji with a count. The
  tooltip names the first three people ("Alice, Bob, Carol and 2 others reacted
  with 👍"). Your own reactions are highlighted (`aria-pressed`).
  `POST /servers/{id}/channels/{channel}/messages/{message}/reactions` adds one and
  `DELETE` on the same URL removes yours. Both take the field `emoji` and return
  `200 {"reactions": [...]}`. The `reactions` table has a unique
  `(message_id, emoji, user_id)` index, so reacting twice changes nothing
  (`App\Actions\Messages\AddReaction` uses `createOrFirst()`). Only a single
  unicode emoji can be added (`App\Support\Reactions::isValidEmoji()`); custom
  `:name:` emoji are not supported yet, but stored ones can still be removed. A
  message can have at most 20 different emoji (`422`). Allowed for members only
  (`MessagePolicy::react`); limited to 60 requests per minute.
  `App\Events\ReactionsUpdated` (`reactions.updated`) goes out on `channel.{id}`
  right away, skipping the reactor's socket:
  `{"message_id": 1, "channel_id": 1, "user_id": 2, "emoji": "👍", "added": true, "reactions": [...]}`.
  Its groups have no `me`, so each browser keeps track of its own reactions.
  Without JavaScript the picker (a `<details>`) and the reaction buttons are
  plain forms that redirect back to the channel.
- **Attachments** (ISSUE-19): click **＋** in the composer, paste, or drop files
  on it to attach up to **5 files** of at most **10 MB** each. The queued files
  are listed above the composer and can be removed before sending. With files
  the text is optional. The composer then posts `multipart/form-data` with
  `attachments[]` (`StoreMessageRequest`):
  - Allowed: images (png, jpg/jpeg, gif, webp), documents (pdf, txt, md, csv,
    json, log, doc/docx, xls/xlsx, ppt/pptx, odt/ods/odp), archives (zip, 7z,
    gz, tar) and audio/video (mp3, ogg, wav, mp4, webm, mov). Both the file name
    (`extensions`) and the type sniffed from the contents (`File::types()`)
    must be on the list, and a file named as an image must really be one. SVG
    and HTML are never accepted. The list lives in
    `MessageAttachment::ALLOWED_EXTENSIONS`.
  - Files are stored on `ATTACHMENT_DISK` (`filesystems.attachment_disk`,
    default the private `local` disk) at `attachments/{channel}/{uuid}.{ext}`.
    The original name is only kept in the database. Each row remembers its disk,
    so changing the setting later keeps older files working. If saving the
    message fails, the stored files are deleted again.
  - Files are only served through **temporary signed URLs**:
    `GET /attachments/{id}/{filename}?expires=…&signature=…`
    (`AttachmentController`), valid for `ATTACHMENT_URL_TTL` minutes (default
    1440, one day). Like Discord's CDN links they work without a session, so
    anyone with an unexpired link can open the file. Altered or expired links
    are a `403`, and so are unknown ids, so ids cannot be probed. Files of
    deleted messages are a `404`. PNG, JPEG, GIF and WebP are shown inline;
    everything else, and `?download=1`, is sent as a download
    (`application/octet-stream`, `nosniff`, a sandbox CSP). Limited to 600
    requests per minute.
  - Messages show images as previews of at most 400×300 (with their size
    reserved, so the scroll position does not jump while they load) and other
    files as a card with name, size and a download link. Deleting a channel or
    server also deletes its files.
  - PHP must accept the uploads: set `upload_max_filesize` to at least `10M`
    and `post_max_size` to at least `55M` in `php.ini` (5 × 10 MB plus the
    form). A request over `post_max_size` gets a `413`.

The broadcast payload is the same as the JSON response (`Message::toPayload()`):

```json
{"id": 1, "channel_id": 1, "content": "Hello!", "created_at": "2026-11-17T12:00:00+00:00",
 "edited_at": null, "pinned_at": null,
 "author": {"id": 1, "name": "Test User", "username": "test", "avatar_url": "...", "profile_url": "..."},
 "reply_to": null,
 "reactions": [{"emoji": "👍", "count": 2, "users": [{"id": 1, "name": "Test User"}, {"id": 2, "name": "Alice"}], "me": true}],
 "attachments": [{"id": 1, "name": "cat.png", "size": 48213, "mime_type": "image/png", "is_image": true,
                  "width": 800, "height": 600, "url": "...", "download_url": "..."}]}
```

`author` is `null` for messages of deleted accounts, which show as "Deleted User".
`edited_at` is `null` until the message is edited. `reactions` lists at most the
first three `users` per emoji; `me` is only there in responses to the viewer
(the history API and the reaction routes), not in broadcasts.

### Search

The search box in the channel header opens `GET /servers/{id}/search?q=...`, a
page that searches every text channel of the server (`App\Support\MessageSearch`).
Members only; limited to 60 requests per minute.

- **Filters**: `channel` (a text channel id of this server), `author` (a user id;
  the form lists current members, but people who left can still be searched by
  id), and `from` / `to` (`Y-m-d`, whole days in the app timezone, both
  included). They also work without `q`, for example "everything Ann wrote in
  #general".
- **Results**: newest first, 25 per page, with the matches highlighted and a link
  to the message's channel. Messages in DMs, in other servers and deleted
  messages are never found. Invalid input shows the errors on the page.
- **Engine**: [Laravel Scout](https://laravel.com/docs/scout) with the
  `database` driver (`SCOUT_DRIVER=database`, the default in `config/scout.php`).
  A search is a `LIKE '%text%'` on `messages.content`, so there is no index to
  build or sync and no external service. `%` and `_` in the text match
  literally. On SQLite the match ignores case for ASCII letters only. If LIKE
  gets too slow on large servers, the next step is an SQLite FTS5 table.

### Replies and pins

- **Replying**: "Reply" in a message's hover toolbar shows "Replying to …"
  above the composer (✕ or Escape cancels) and sends `reply_to_id` with the
  message. It must be a message of the same channel that is not deleted
  (`StoreMessageRequest`, otherwise a `422` on `reply_to_id`). The reply
  quotes the original above its header. In the payload, `reply_to` is
  `{"id", "deleted", "author": {"id", "name"} | null, "content", "has_attachments"}`.
  `content` is one line of at most 100 characters (`Message::excerpt()`).
  When the original is deleted the quote says "Original message was deleted"
  (`deleted: true`). Clicking the quote scrolls to the original
  (`#message-{id}`) and highlights it. If it is not on screen yet, older pages
  are loaded until it is (at most 20 pages). Quotes follow live edits and
  deletes of the original.
- **Pinning**: moderators (`MessagePolicy::pin`, i.e.
  `ServerPolicy::manageMessages`, the owner until roles land) get Pin / Unpin
  on every message: `POST` / `DELETE /servers/{id}/channels/{channel}/messages/{message}/pin`.
  Pinned messages show 📌 and have `pinned_at` set (`pinned_by_id` remembers
  who pinned them). A channel holds at most 50 pins (`422` on `message`
  beyond that); deleted messages do not count. `App\Events\MessagePinned`
  (`.message.pinned`, `{id, channel_id, pinned_at, pinned_by}`) goes out on
  `channel.{id}`. Pinning a pinned message changes nothing and broadcasts
  nothing.
- **Pinned list**: "📌 Pinned" in the channel header opens a panel with
  `GET /servers/{id}/channels/{channel}/pins` (any member, newest pin first;
  JSON `{"pins": [... message payload + "pinned_by"]}`). It has Jump and,
  for moderators, Unpin. Without JavaScript the same link opens a pins page,
  and Pin / Unpin are plain forms that redirect back.

### Typing indicators

While you type in a text channel, everyone else viewing it sees "**Ann** is
typing…" under the composer. Two or three people read "Ann and Bob are typing…"
or "Ann, Bob and Cy are typing…", and more read "Several people are typing…". No
request goes through Laravel. Everything happens over Reverb
(`resources/js/typing-indicator.js`):

- Each viewer joins the **presence** channel `typing.{id}`. `routes/channels.php`
  allows only members of the channel's server, the same rule as `channel.{id}`,
  and gives every subscriber `{id, name}` as member info. `name` is the server
  nickname, or the user's name if they have none.
- Typing sends the client event (whisper) `client-typing` with
  `{"typing": true}`, throttled to **once every 3 seconds**. Sending the message
  or clearing the box whispers `{"typing": false}`, so the indicator goes away
  at once.
- Receivers drop a person **5 seconds** after their last whisper. They also drop
  them right away when the person leaves the presence channel (closes the tab)
  or when their message arrives.
- The sender is taken from the `user_id` that Reverb adds to whispers on
  presence channels, never from the payload. That `user_id` must belong to a
  current presence member, and the name comes from the member info. This relies
  on `REVERB_APP_ACCEPT_CLIENT_EVENTS_FROM=members` (the default in
  `config/reverb.php`): with `all`, whispers carry no trusted `user_id` and are
  ignored, and with `none` there are no typing indicators.
- Members who cannot send messages still see who is typing. Whispers cannot be
  authorised one by one, so a member without the send permission could still
  whisper "typing".

`npm test` runs the JavaScript tests in `tests/js` with Node's built-in test runner.

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

## Friends

The friends page (`/friends`, `FriendController`, in the `['auth', 'verified']`
group, linked from the dashboard) has three tabs: `?tab=all` (accepted friends,
by name, with online status), `?tab=pending` (incoming and outgoing requests,
newest first) and `?tab=blocked` (people you blocked). Every action is a plain
form, so the page also works without JavaScript.

- **Send** (`POST /friends`, field `username`): `App\Actions\Friends\SendFriendRequest`
  looks the user up by username. A leading `@`, capitals and surrounding spaces
  are fine. It refuses yourself, someone you already asked, and an existing
  friend. If they already sent you a request, sending one back **accepts** it.
- **Accept / Decline** (`POST /friends/{id}/accept`, `/decline`): only the
  recipient. **Cancel** (`DELETE /friends/{id}/request`): only the sender.
  **Remove** (`DELETE /friends/{id}`): either friend, with a confirmation.
  **Unblock** (`DELETE /friends/{id}/block`): only the one who blocked.
  Blocking itself comes with ISSUE-25.
- `App\Policies\FriendshipPolicy` decides. Anyone who is not one of the two
  users gets a **404**, so friendship ids do not reveal who is friends with
  whom. A party asking for the wrong thing (accepting their own request) gets
  a 403.
- **Blocks stay private.** Sending a request to someone who blocked you gives
  the same error as an unknown username. The blocked user never sees the block
  in any tab, and gets a 404 for it. If you blocked them, you are told to
  unblock them first.
- Each write only matches the row in the state it was checked in (`UPDATE …
  WHERE status = 'pending'`), so two clicks at once, or an accept racing a
  cancel, make one change and one broadcast.
- **Live updates**: `App\Events\FriendshipChanged` (`.friendship.changed`) goes
  to the *other* person on their private `App.Models.User.{id}` channel, with
  `type` = `request`, `accepted` or `removed`. Declining, cancelling and
  removing all send `removed`, so the sender cannot tell which happened.
  Unblocking sends nothing. The payload also carries `incoming_count`.
  `resources/js/friends.js` runs on every signed-in page (the layouts add
  `<meta name="user-id">`). It shows a toast for requests and accepts, keeps
  the request badges (`[data-friend-request-badge]`) on the dashboard and the
  friends page up to date, and on the friends page reloads the tabs and lists.
  If Reverb is down, the change is still saved.
- Sending allows 20 requests per minute, and the other actions allow 60.

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
