# Feature reference

How each feature works, with the routes, classes and rules behind it. For the
big picture start with the [README](../README.md) and [architecture.md](architecture.md).

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
  to 5 attempts per minute for each email and IP address, and 30 per minute for
  each IP address (see [security.md](security.md)).
- After login, users land on `/dashboard` (`resources/views/dashboard.blade.php`).
  It shows who is signed in, links to the profile, friends and inbox, a reminder
  to verify their email, and their servers with unread dots and mention badges.
  `/` sends guests to `/login` and signed-in users to the dashboard.
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
  verified email, `view` needs membership, `update` (name and icon) needs
  Manage Server, and `delete` is owner only. See [Permissions](#permissions).

### Roles

Roles (ISSUE-26) have a name (up to 100 characters), an optional `#rrggbb`
colour, a `position` (higher is higher in the hierarchy) and a permission
bitmask in `roles.permissions`. Managing them needs `ServerPolicy::manageRoles`
(Manage Roles), and an existing role can only be changed by someone above it
(see [Permissions](#permissions)). The pages are linked from the server
settings page and the channel sidebar ("Roles").

- **Permission flags** are the cases of `App\Enums\Permission`, using Discord's
  bit values: Create Invite, Kick Members, Ban Members, Administrator, Manage
  Channels, Manage Server, Add Reactions, View Channels, Send Messages, Manage
  Messages, Attach Files, Mention @everyone, Manage Roles and Timeout Members. Use
  `Permission::mask(...)`, `Permission::fromMask($bits)`,
  `$role->hasPermission(Permission::X)` and `$role->permissionList()`. Every
  flag stays within bits 0–62, because SQLite stores the column as a signed
  64-bit integer. What each flag allows is in [Permissions](#permissions);
  Kick Members, Ban Members and Timeout Members are used by
  [Moderation](#moderation).
- **@everyone**: every server has exactly one default role (`is_default`,
  position 0), created by `CreateServer`. Every member has it implicitly, so it
  has no `role_user` rows. It starts with `Permission::everyoneDefaults()`
  (Create Invite, Add Reactions, View Channels, Send Messages, Attach Files),
  which is what every member could already do. Only its permissions can be
  changed: it cannot be renamed, recoloured, moved, deleted, given or taken.
  The `2026_11_18_000002` data migration gives older `@everyone` roles with no
  bits these defaults, and adds `@everyone` to any server without one
  (`Server::defaultRole()` also creates it if it is missing).
- **List** (`GET /servers/{id}/roles`): highest role first, `@everyone` last,
  with member counts. The ↑/↓ buttons (`POST …/roles/{role}/move`, field
  `direction` = `up` or `down`) swap a role with its neighbour.
  `App\Actions\Roles\MoveRole` then renumbers the other roles 1..n.
- **Create** (`/servers/{id}/roles/create`): name, colour (or "No colour") and
  the permission checkboxes (`permissions[]` holds the flag values). The name
  `@everyone` is reserved, and repeated whitespace is collapsed. The owner's new
  roles go to the top; anyone else's go just below their own highest role. A
  server can have at most 250 roles, `@everyone` included
  (`App\Actions\Roles\CreateRole`).
- **Edit** (`/servers/{id}/roles/{role}/settings`): change the same fields, see
  who has the role, give it to members and take it away, or delete it. Deleting
  takes the role from everyone who had it (`role_user` cascades).
- **Members** (`POST …/roles/{role}/members` with `user_id`,
  `DELETE …/roles/{role}/members/{user}`): `App\Actions\Roles\AssignRole` only
  gives roles to members of the server, and checks the membership inside a
  locked transaction. Taking a role from someone who does not have it is a 404.
  The member sidebar groups and colours members by their highest role (see
  below). Leaving a server removes the member's roles there.
- A role of another server is a 404 (scoped bindings), and anyone without
  `manageRoles` gets a 403, as does opening, changing, moving, deleting, giving
  or taking a role that is not below your own highest role
  (`RolePolicy::manage`). Every role route allows 60 requests a minute.

### Permissions

`App\Services\PermissionService` (ISSUE-27) works out what a member may do, and
the policies ask it; nothing else reads `roles.permissions` to decide.

- **Effective permissions** in a server: none for someone who is not a member;
  every flag for the owner; for everyone else the `@everyone` flags OR-ed with
  the flags of every role they hold. **Administrator** turns into every flag.
  A **timed-out** member keeps only View Channels until the timeout ends (see
  [Moderation](#moderation)).
  A server without an `@everyone` row counts as having the defaults. Nothing is
  cached, so a change to a role counts from the next check.
- **Channel permissions** (`channelPermissions()`) are the server permissions
  with the channel's overrides applied. See
  [Private channels and overrides](#private-channels-and-overrides).
- **What each flag allows**:

  | Flag | Allows | Ability |
  | --- | --- | --- |
  | View Channels | open a channel, read its history and pins, find its messages in search, listen to its live and typing events; channels you cannot see are left out of the sidebar | `ChannelPolicy::view` |
  | Send Messages | post in a channel you can see | `ChannelPolicy::sendMessages` |
  | Attach Files | upload files with a message (else a 422 on `attachments`, and no attach button) | `ChannelPolicy::attachFiles` |
  | Mention @everyone | make `@everyone` notify every member who can see the channel (else it is plain text) | `App\Support\Mentions::resolve()` |
  | Add Reactions | react with an emoji nobody used yet on that message; joining an existing reaction and taking yours back needs only View Channels | `MessagePolicy::addReaction` |
  | Manage Messages | delete and pin anyone's messages (editing stays author only) | `ChannelPolicy::manageMessages`, `MessagePolicy::delete` / `pin` |
  | Manage Channels | create, edit, reorder and delete channels and categories | `ServerPolicy::manageChannels` |
  | Manage Server | rename the server and change its icon; see and revoke every invite | `ServerPolicy::update`, `manageInvites` |
  | Create Invite | create invites | `ServerPolicy::createInvite` |
  | Manage Roles | the role pages, for roles below your own highest role | `ServerPolicy::manageRoles`, `RolePolicy::manage` |
  | Kick Members | kick members below your own highest role | `ServerPolicy::kick` |
  | Ban Members | ban members below your own highest role; see the ban list and unban | `ServerPolicy::ban`, `manageBans` |
  | Timeout Members | time out members below your own highest role (not Administrators), and end timeouts | `ServerPolicy::timeout` |

- **Owner only**, also for administrators: deleting the server and
  transferring ownership. Viewing a server and leaving it need membership only.
- **Role hierarchy** (like Discord): a member with Manage Roles manages only the
  roles below their own highest role, and `@everyone`, which is below everyone.
  Administrator does not lift this; only the owner manages every role. A move
  may not take a role past your highest role. You can only turn on or off the
  flags you have yourself (`StoreRoleRequest`). A member whose only role is
  `@everyone` cannot create roles.
- Every channel ability needs View Channels first. DMs have no permissions.

### Private channels and overrides

ISSUE-28. A channel can be **private** (`channels.is_private`) and can have
**role overrides** (`channel_permission_overrides`, one row per channel and
role): `allow` and `deny` masks for **View Channels** and **Send Messages**
(`Permission::channelOverridable()`). A flag in neither mask is inherited.

- **Resolution** (`PermissionService::channelPermissions()`, like Discord):
  start from the server permissions; apply the `@everyone` override (remove
  its denies, add its allows); a private channel then removes View Channels;
  last, the overrides of every role the member holds together (remove all their
  denies, then add all their allows, so an allow on one role beats a deny on
  another). The owner and Administrator skip all this and see every channel.
- **Hidden channels** (no View Channels after overrides) are left out of the
  sidebar, the server's default channel, the search filter and search results.
  Their page, history, pins, messages, settings and permissions pages give a
  403, and the `channel.{id}` and `typing.{id}` broadcast channels refuse to
  authorise. `PermissionService::visibleChannels()` filters a list with one
  query for all the overrides.
- **Managing** (`GET /servers/{id}/channels/{channel}/permissions`, linked from
  the channel settings): needs Manage Channels and a channel you can see.
  `PATCH …/privacy` (`is_private`) toggles private. `PUT …/permissions/{role}`
  with `permissions[ViewChannels]` and `permissions[SendMessages]` set to
  `allow`, `inherit` or `deny` sets one role's override
  (`App\Actions\Channels\SetChannelOverride`; all inherit deletes the row). That
  also needs `RolePolicy::manage` for the role (Manage Roles and the role below
  your highest one), and you can only change a flag you have in that channel.
  A role from another server is a 404.
- **Reordering**: the sidebar of a member who cannot see every channel only
  holds the visible ones, so the drag and drop layout is checked against those;
  hidden channels and categories keep their place.
- Making a channel private hides it from yourself too unless one of your roles
  is allowed View Channels in it, or you are the owner or an Administrator.

### Members and leaving

- **Member sidebar**: server and channel pages show the members on the right
  (`servers/partials/member-list.blade.php`, built by `App\Support\MemberList`
  through `Server::memberList()`). Online members (see **Online presence**
  below) are grouped under their highest role other than `@everyone` (highest
  `position`, then lowest id). Online members without such a role go under
  **Online**, and everyone else goes under **Offline**, whatever their roles.
  Empty groups are hidden. Members are sorted by nickname, or by name when there
  is none, ignoring case. Online members' names use their highest role's colour
  (only `#rrggbb` values are used), and the owner has a crown. The list takes
  four queries, however many members there are.
- **Online presence**: each server has a presence channel `server.{id}`
  (`routes/channels.php`, members only; member info `{id, status}`).
  `resources/js/presence.js` joins it for **every** server you are in, on
  every page (`<meta name="presence-servers">`), so you are online in all your
  servers whichever page you are on. When a member list renders,
  `App\Support\ServerPresence` asks Reverb who is in the channel (Pusher's
  `GET /channels/{channel}/users`). You always count as online on your own
  page. After that the browser keeps the list live: people who join move up
  under their role, people who leave move down to **Offline**, and the counts
  follow.
  - **Several tabs**: Reverb counts a user once, however many tabs or devices
    they have open. "Joining" comes with the first connection and "leaving"
    after the last one closes.
  - **Grace period**: someone who leaves stays as they are for 10 seconds
    (`PRESENCE_GRACE_MS`) before they show as offline. Every page load closes
    the socket for a moment, and this keeps that from making people blink
    offline and back. A tab that dies without closing is dropped by Reverb's
    ping timeout.
  - **Status**: the picker at the bottom of the channel sidebar
    (`<x-status-picker>`, `PUT /settings/status`, 30 per minute) sets
    **Online**, **Idle** or **Do Not Disturb** (`users.status`, which is what
    connected members show). `App\Actions\Profile\UpdateUserStatus` saves it
    and `App\Events\UserStatusChanged` (`.user.status`) tells the presence
    channel of each of the user's servers, plus their own channel so their
    other tabs update. Member info is fixed when a tab subscribes, so for
    people already on the page the status in the page wins over it. There is
    no automatic idle and no invisible mode.
  - **Fallbacks**: with the `null` or `log` broadcaster (tests, no websockets)
    presence is unknown, and the stored status decides who is online, as
    before. When Reverb is down, only you show as online.
  - Kicked and banned members leave the server's presence channel
    (`moderation.js`) and are refused when pusher-js reconnects.
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

### Moderation

Kicking, banning and timing out members (ISSUE-29). The **Members** page
(`GET /servers/{id}/members`, linked from the channel sidebar) needs any of
Kick, Ban or Timeout Members, and shows for each member only the actions you
may take on them. The **Bans** page (`GET /servers/{id}/bans`) needs Ban
Members. Every route allows 60 requests a minute.

- **Who may act on whom** (`PermissionService::moderationCheck`, used by
  `ServerPolicy::kick`, `ban` and `timeout`), like Discord: you need the flag;
  the target must be a member (else a 404) and not yourself; nobody can act on
  the owner; and anyone but the owner can only act on members whose highest
  role is below their own. Administrator does not lift this, and
  Administrators cannot be timed out.
- **Kick** (`POST …/members/{user}/kick`, `App\Actions\Moderation\KickMember`):
  removes the membership and the member's roles, like leaving. Their messages
  stay, and they can rejoin with an invite.
- **Ban** (`POST …/members/{user}/ban`, `App\Actions\Moderation\BanMember`):
  fields `reason` (optional, up to 512 characters, shown on the ban list only)
  and `delete_messages`: seconds of message history to delete, one of 0 (the
  default), 1 hour, 6 hours, 12 hours, 24 hours, 3 days or 7 days. It writes a
  `bans` row (with `banned_by_id`) and removes the member in one transaction.
  Then it soft deletes their messages from that period in the server's channels
  (never DMs) through `DeleteMessage`, so open pages drop them live. A banned
  user gets a 403 on every invite of the server (`AcceptInvite`).
- **Unban** (`DELETE …/bans/{user}`): deletes the ban, so they can join again
  with an invite. They are not added back. A user who is not banned is a 404.
- **Timeout** (`PUT …/members/{user}/timeout`, field `duration` in seconds: 60,
  300, 600, 3600, 86400 or 604800; `DELETE` the same URL to end it early,
  `App\Actions\Moderation\TimeoutMember`): sets
  `server_members.timed_out_until`. Until then the member keeps only View
  Channels (`PermissionService::TIMED_OUT_MASK`): they can read, but not post,
  attach, react (also not joining existing reactions), invite or moderate. The
  composer is replaced by a notice saying when it ends. Nothing has to run when
  it ends: a time in the past counts as no timeout.
- **Live**: the affected user gets `App\Events\MemberModerated`
  (`.member.moderated`, type `kicked`, `banned`, `timed_out` or
  `timeout_removed`, with the server id and name but never the ban reason) on
  their private `App.Models.User.{id}` channel. `resources/js/moderation.js`
  then, on that server's page only, leaves the page's live channels, removes
  the message panel and shows a notice (kick or ban), or reloads (timeout).
- **Websocket disconnect**: for kicks and bans `App\Support\RealtimeSessions`
  also calls Reverb's Pusher-compatible `terminate_connections` API for the
  user. Channel authorization only runs when a browser subscribes, so this
  makes pusher-js reconnect, and every server channel is checked again, which
  a former member fails. Reverb links a connection to a user through presence
  channels (the `server.{id}` channels every page joins, and the typing
  channels of open message panels). With the
  `null` or `log` broadcaster, and when Reverb is down, this does nothing. The
  moderation itself has already happened.

### Audit log

Every moderation and settings change is recorded: who did it, what changed
and when (ISSUE-30). The **Audit log** page (`GET /servers/{id}/audit-log`,
linked from the channel sidebar) is for the **owner only**
(`ServerPolicy::viewAuditLog`), also when others are Administrators. It
lists entries newest first, 50 per page, and filters with `?action=` (an
`AuditLogEntry::ACTIONS` key) and `?user=` (the user who made the change).
Like search, invalid filters show an error rather than redirect.

- **What is logged**: server name, description and icon changes, ownership
  transfers; channels and categories created, renamed or deleted, a channel's
  topic or privacy; channel permission overrides (with the role); roles
  created, changed (permission changes read "+ Ban Members, − Kick Members")
  or deleted; roles given or taken; kicks, bans (with the reason), unbans,
  timeouts and timeouts ended early; invites created or revoked; and messages
  deleted by someone other than their author.
- **What is not**: reordering channels, categories or roles; deleting your
  own messages; the messages a ban purges (the ban itself is logged once);
  creating or deleting the server (the log belongs to it); and changes
  nobody is signed in for (seeders, `artisan`), since there is no "who".
- **How**: model observers in `app/Observers` (attached with
  `#[ObservedBy]`) write through `App\Support\AuditLog`. A kick and handing out
  a role change no model with events of its own (a kick deletes the same row
  as leaving; `role_user` is a pivot), so `KickMember` and `AssignRole` call
  `AuditLog::record()` themselves. `AuditLog::withoutRecording()` pauses it,
  for example for the default role and channel `CreateServer` makes.
- **Storage**: `audit_log_entries` (see `docs/database-schema.md`). An entry
  keeps the target's name as it was, so deleted channels and roles still read
  well. It goes with the server and stays when its actor deletes their
  account ("Deleted User").

## Channels and categories

A server's sidebar lists its uncategorised channels first, then its
**categories** (`channel_categories`), each with its own channels. Members with
View Channels can open a channel at `/servers/{id}/channels/{channel}`. The
header shows the channel name and **topic**. Everything else needs
`ServerPolicy::manageChannels` (Manage Channels, see [Permissions](#permissions)).

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

- **Sending**: members with View Channels and Send Messages in the channel may
  post (`ChannelPolicy::sendMessages`, see [Permissions](#permissions)) with
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
- **Authorisation**: `routes/channels.php` lets only members who can see the
  channel (`ChannelPolicy::view`) subscribe to `channel.{id}`. This is checked
  when the browser subscribes. A user who leaves the server on their own keeps an
  open subscription until they reload. Kicked and banned users are disconnected
  (see [Moderation](#moderation)).
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
  `ChannelPolicy::manageMessages`, the Manage Messages permission). Messages are **soft deleted** (`messages.deleted_at`): they vanish
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
- **Reactions** (ISSUE-18): anyone who can see a message can react to it. Hover a
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
  message can have at most 20 different emoji (`422`). Joining a reaction or
  taking yours back needs View Channels (`MessagePolicy::react`), and starting a
  new emoji also needs Add Reactions (`MessagePolicy::addReaction`). Timed-out
  members cannot react. Limited to 60 requests per minute.
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

### Formatting, links and emoji

Messages in channels and DMs support a small, Discord-like markdown subset:

| Type | Shows as |
| --- | --- |
| `**bold**` | **bold** |
| `*italic*` or `_italic_` | *italic* |
| `__underline__` | underlined |
| `~~strike~~` | ~~struck through~~ |
| `` `code` `` | inline code |
| ` ```js` … ` ``` ` | a code block (the language is optional) |
| `> text` | a quote (every line that starts with `> `) |
| `>>> text` | a quote of the rest of the message |
| `\*` | a literal `*` (any symbol can be escaped) |

`http://` and `https://` URLs (and `<https://…>`) become links that open in
a new tab (`rel="noopener noreferrer nofollow ugc"`). Headings, lists, images,
masked links (`[text](url)`) and raw HTML are not supported and show as typed.

Message text is never treated as HTML. `App\Support\Markdown` parses it into a
tree of known nodes and writes only those tags (`strong`, `em`, `u`, `s`,
`code`, `pre`, `blockquote`, `a`), escaping every piece of text and every
attribute; links are only made from http(s) URLs. Messages that arrive live
are formatted by `resources/js/markdown.js`, the same parser, which builds DOM
nodes instead of HTML. Both are checked against `tests/fixtures/markdown.json`.
The stored and broadcast `content` stays the raw text.

The 😀 button in the composer opens an emoji picker: search by name (e.g.
"thumbs up"), browse by category, or pick from your recently used emoji (kept
in this browser's localStorage). The emoji is inserted at the cursor. The
arrow keys move between emoji, Enter in the search box picks the first
result, and Escape closes the picker. The button needs JavaScript and is
hidden without it.

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
  `ChannelPolicy::manageMessages`, the Manage Messages permission) get Pin / Unpin
  on every message: `POST` / `DELETE /servers/{id}/channels/{channel}/messages/{message}/pin`.
  Pinned messages show 📌 and have `pinned_at` set (`pinned_by_id` remembers
  who pinned them). A channel holds at most 50 pins (`422` on `message`
  beyond that); deleted messages do not count. `App\Events\MessagePinned`
  (`.message.pinned`, `{id, channel_id, pinned_at, pinned_by}`) goes out on
  `channel.{id}`. Pinning a pinned message changes nothing and broadcasts
  nothing.
- **Pinned list**: "📌 Pinned" in the channel header opens a panel with
  `GET /servers/{id}/channels/{channel}/pins` (anyone who can see the channel, newest pin first;
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

### Unread and mentions

Each user has a read marker per channel (`channel_reads.last_read_message_id`,
`App\Actions\Channels\MarkChannelRead`). The marker only ever moves forward, and
never past the channel's newest message.

- **Marking read**: opening a text channel (or a server, which opens its first
  text channel) marks everything in it as read. While the channel stays open,
  `resources/js/unread.js` posts `POST /servers/{id}/channels/{channel}/read`
  (optional `message_id`, `ChannelReadController`) for messages that arrive
  live. The posts are batched over a second, and only go out while the tab is
  visible. A hidden tab waits until you come back to it. You need
  `ChannelPolicy::view` for the channel.
- **Unread**: a channel is unread when it has messages newer than your marker.
  Without a marker, only messages sent after you joined the server count. Your
  own messages, deleted ones and those of people you blocked never count, and
  neither do channels you cannot see. `App\Support\UnreadState` works this out
  in one query for all channels, plus one for mentions when anything is unread
  (and one for your roles when a role was mentioned).
- **Mentions**: see [Mentions and notifications](#mentions-and-notifications).
  A mention counts when it names you, a role you hold, or `@everyone`.
- **Where it shows**: in the channel sidebar, an unread channel gets a white dot
  and a bold name, and unread mentions show as a red badge. The open channel is
  never unread. In the server lists (dashboard, friends and DM sidebar), a
  server with unread channels gets a dot, or a badge with its total mentions.
- **Live**: the channel sidebar subscribes to `channel.{id}` for every text
  channel in it and updates dots and badges as `.message.sent` arrives. The
  server lists are worked out when the page loads.

### Mentions and notifications

ISSUE-33 adds `@user`, `@role` and `@everyone` mentions in server channels,
with notifications for the people mentioned.

- **Syntax** (`App\Support\Mentions`): `@username`, `@Role Name` (spaces are
  fine, any case) or `@everyone`. They are case-insensitive and don't match inside a word or an e-mail
  address (`me@bob`). `@bobby` does not mention bob, and a trailing period
  ends the sentence, not the name. `everyone` and `here` can't be usernames.
- **Who is mentioned** is worked out whenever a channel message's content is
  saved and stored in `messages.mentions` (`{users, roles, everyone}`). Only
  members of the server count as users. `@everyone` only counts when the author
  has the **Mention @everyone** permission in the channel (owner and
  Administrator have it, `@everyone` does not by default); otherwise it is plain
  text. Any member may mention any role. DMs have no mentions.
- **Autocomplete**: typing `@` in a channel's composer lists matching members
  (username, nickname or name, only those who can see the channel), roles,
  and `@everyone` if you may use it
  (`GET /servers/{id}/channels/{channel}/mentions?q=`,
  `MentionSuggestionController`, `resources/js/mention-autocomplete.js`). ↑/↓
  move, Enter or Tab inserts, Escape closes. While the list is open, Enter does not send.
- **Notifications** (`App\Notifications\MentionedInMessage`, sent by
  `App\Support\MentionNotifier`): a Laravel database notification for every
  member mentioned who can see the channel, except the author and anyone who
  blocked them. Each person gets one per message, with the most direct reason (by name, by
  role, `@everyone`). Each one is also broadcast on the user's private
  `App.Models.User.{id}` channel straight away, on the `sync` queue
  connection, so no worker is needed. If Reverb is down, the failure is reported and the notification is still stored.
  Editing a message notifies people newly mentioned and takes the notification
  back from people no longer mentioned. Deleting the message, or leaving the
  server (or being kicked or banned), deletes its notifications.
- **Inbox**: the 🔔 in the page header shows the unread count (`99+` at most),
  and `resources/js/notifications.js` opens a panel with the latest 50
  (`GET /notifications`, JSON). Without JavaScript it is the inbox page.
  An entry links to `GET /notifications/{id}`, which marks it read and jumps to the
  message. `POST /notifications/{id}/read` and `POST /notifications/read` (all)
  mark read. Opening a channel marks its mentions read up to your read marker,
  and a mention arriving in the channel you're looking at doesn't count up.
  Entries that no longer lead anywhere (message gone, author blocked, channel
  hidden) are dropped and deleted when the inbox is shown.
- **Highlight**: messages that mention you get a yellow bar, both server
  rendered and live.

### Desktop notifications and muting

ISSUE-34 adds browser notifications (the Web Notifications API) and per-user
mute settings.

- **What pops up** (`resources/js/push-notifications.js`): a mention (the
  `MentionedInMessage` broadcast) and every DM or group DM message
  (`App\Events\DirectMessageReceived`, `.dm.received`, sent by
  `App\Support\DirectMessageNotifier` to each other participant's
  `App.Models.User.{id}` channel with the author, a short excerpt and, for
  group DMs, the group's name as that person sees it; people who blocked the
  author get nothing). Clicking one focuses the window and opens the message
  (a mention goes through `GET /notifications/{id}`, so it is marked read).
- **When**: only while the tab is hidden or another window has the focus,
  only after you allowed notifications for the site, and never while your
  status is **Do Not Disturb**. Each notification has a tag per mention or
  message, so several open tabs show it once.
- **Turning them on**: browsers only ask for permission after a click, so
  this is done on **Notifications** settings (`GET /settings/notifications`,
  linked from the dashboard). The same switch turns them off again for this
  browser (a `localStorage` flag); a site you blocked has to be unblocked in
  the browser's own settings.
- **Muting** (`notification_mutes`, `App\Support\NotificationMutes`): 🔔 next
  to the server name in the channel sidebar mutes the whole server, and 🔕 on
  a channel row mutes that channel
  (`PUT`/`DELETE /servers/{id}/mute`,
  `PUT`/`DELETE /servers/{id}/channels/{channel}/mute`,
  `NotificationSettingsController`; JSON `{muted}` or a redirect back). You
  must be a member who can see the channel. The settings page lists your
  servers and muted channels to unmute them. A muted server or channel sends
  no desktop notifications. Its mentions are still stored and counted (the
  broadcast carries `push: false`), so the inbox, unread dots and mention
  badges work as before. DMs cannot be muted. Leaving a server (or being
  kicked or banned) clears your mutes for it.


## Invites

Invites (`InviteController`, also in the `['auth', 'verified']` group) let
people join a server.

- **Create** (`/servers/{id}/invites`, "Invite people" on the server page):
  members with Create Invite (part of the `@everyone` defaults) can generate a
  link. They choose an expiry (30 minutes, 1, 6 or 12
  hours, 1 day, 7 days (the default) or never) and a maximum number of uses (no
  limit, 1, 5, 10, 25, 50 or 100). `App\Actions\Servers\CreateInvite` makes a
  random 8-character, case-sensitive code (`[A-Za-z0-9]`) and retries if the
  code is already taken. The link is `/invite/{code}`.
- **List and revoke**: the same page lists usable invites. The owner and
  members with Manage Server see and can revoke every invite
  (`ServerPolicy::manageInvites`). Other members see only their own invites, and can
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
- **Block** (`POST /friends/block`, field `user_id`, ISSUE-25): **Block** on
  the friends and pending lists, and in the profile popover (which also has
  **Unblock**), both with a confirmation. `App\Actions\Friends\BlockUser`
  turns a pending request (either way) or a friendship into a block row
  (`user_id` = the blocker), and the other person gets the same `removed`
  event as for a removal. Blocking someone who already blocked you adds a
  second row, so each block is lifted on its own. You cannot block yourself.
- **What a block does**: no friend requests and no new DMs in either
  direction, and an existing DM becomes read-only for both (see Direct
  messages). In servers you share, the **blocker** no longer sees the blocked
  user's messages: `Message::visibleTo()` leaves them out of the channel page,
  the history JSON, the pins and search. The channel panel carries
  `data-blocked-user-ids`, so `channel-messages.js` also skips the ones that
  arrive live, and a reply quoting a blocked message says "Blocked message".
  The blocked person still sees everything, and DMs keep their whole history.
  The profile JSON says whether *you* blocked someone (`blocked`), never
  whether they blocked you.
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

## Direct messages

1:1 DMs (ISSUE-23) and group DMs (ISSUE-24) live under `/dms`, in the
`['auth', 'verified']` group.

- **Opening a DM**: **Message** on the friends list posts `user_id` to
  `POST /dms` (`DirectMessageController::store`), which goes to `/dms/{id}`.
  `App\Actions\Conversations\OpenDirectConversation` allows friends and people
  who share a server, never yourself, and never across a block in either
  direction. Someone who blocked you gets the same error as a stranger.
- **One conversation per pair**: `conversations.direct_key` is
  `"{lower user id}:{higher user id}"` with a unique index, so both people end
  up in the same conversation, also when they open it at the same moment
  (`createOrFirst()`). Existing DMs are backfilled by the migration.
- **Same messaging components as channels**: the DM page renders the shared
  `messages/panel.blade.php` (also used by `servers/show.blade.php`), and
  `resources/js/channel-messages.js` drives it the same way: history paging,
  sending with attachments and replies, editing, deleting, reactions and typing
  indicators. The routes mirror the channel ones (`/dms/{id}/messages`,
  `/dms/{id}/messages/{message}`, `…/reactions`) and reuse the same actions and
  form requests. A DM has no moderators, so only authors edit and delete, and
  there are no pins.
- **Privacy**: only the two participants see a conversation
  (`App\Policies\ConversationPolicy`). Everyone else gets a **404** on every DM
  route (`can:view,conversation`), so ids do not tell anyone who talks to whom.
  After a block, or once the other account is deleted, the history stays
  readable, but a notice replaces the composer.
- **Private broadcast channel per conversation**: `MessageSent`,
  `MessageUpdated`, `MessageDeleted` and `ReactionsUpdated` for DM messages go
  out on `private-conversation.{id}` (`Message::broadcastChannel()`), and typing
  goes on the presence channel `conversation-typing.{id}`. `routes/channels.php`
  lets only the participants subscribe. Payloads carry `conversation_id`
  (`channel_id` is `null`).
- **DM list in the sidebar**: the friends page and the DM page list your DMs
  between Friends and your servers, newest activity first
  (`conversations.last_message_at`, `Conversation::sidebarFor()`). Like Discord,
  a DM shows up for the other person only once someone writes in it. Every new
  message sends `App\Events\ConversationActivity` (`.conversation.activity`,
  ids and time only) to both participants' `App.Models.User.{id}` channels, and
  `resources/js/direct-messages.js` reloads `[data-dm-list]`, so the
  conversation moves to the top live.

### Group DMs

- **Creating**: **Create group DM** on the friends page (All tab) posts
  `user_ids[]` and an optional `name` to `POST /dms/groups`
  (`GroupConversationController::store`). Like Discord you can only pick your
  friends (accepted, so never across a block), and a group has at most **10
  people** (`Conversation::MAX_PARTICIPANTS`), you included. Picking a single
  friend opens your 1:1 DM with them instead. The creator owns the group.
- **The group page** is `/dms/{id}` too (`conversations/group.blade.php`): the
  same message panel as a DM, where every participant writes, and **Group
  settings** with the people, Rename, Add friends and Leave. A group shows in
  every participant's DM list from the moment they are added, without waiting
  for a message. An unnamed group is called after the other people in it
  ("Bob, Carol").
- **Rules** (`App\Actions\Conversations\ManageGroupConversation`,
  `App\Policies\ConversationPolicy`):
  - any participant renames (`PATCH /dms/{id}`, `name`, up to 100 characters;
    empty goes back to the people's names) and adds their own friends
    (`POST /dms/{id}/participants`, `user_ids[]`). Seats are counted with the
    conversation row locked, so two people adding at once cannot pass 10;
  - only the owner removes others (`DELETE /dms/{id}/participants/{user}`), and
    anyone may remove themselves, which is leaving. `{user}` is scoped to the
    participants, so anyone else is a 404. Removed people lose access at once
    (a 404 on every route), and their messages stay;
  - when the owner leaves, the longest-standing participant becomes owner.
    When the last person leaves, the group is deleted with its messages and
    their attachment files;
  - none of this exists for 1:1 DMs (404).
- **Live updates**: every change sends `App\Events\ConversationUpdated`
  (`.conversation.updated`, only the id) to the `App.Models.User.{id}` channel
  of everyone in the group and of whoever just left or was removed.
  `resources/js/direct-messages.js` reloads the DM list and, on that group's
  page, its header and settings (`[data-group-details]`). It sends people who
  are no longer in the group to the friends page. If Reverb is down, the change
  is still saved.
- Creating allows 20 requests per minute, and the other group actions allow 30.

## Frontend

- Entry points: `resources/css/app.css` and `resources/js/app.js`, set in `vite.config.js`.
- Tailwind v4 loads through the `@tailwindcss/vite` plugin and needs no `tailwind.config.js`.
  The `@source` and `@theme` directives in `resources/css/app.css` configure it.
- `npm run build` writes production assets to `public/build/`. Git ignores this folder.

### App layout

The chat pages use `components/layouts/app.blade.php`, which has four panes from left to right:

1. **Server rail** (`components/server-rail.blade.php`). It holds Home (friends and DMs, with the
   app's own logo from `components/brand-mark.blade.php`), every server you are in by name, and
   "Create a server". The open server, or Home on the friends, DM and notification pages, gets a
   tall pill and `aria-current="page"`. Unread servers get a short pill and mentions get a red
   badge. These are worked out when the page loads, like the dashboard list. Only users with a
   verified email address see the rail.
2. **Sidebar**: a server's channels, or friends and direct messages.
3. **Messages**: the page content.
4. **Member list**: on server pages only.

### Small screens

Below the `md` breakpoint (768px) the rail and the sidebar become one drawer that slides in from
the left, and the member list one that slides in from the right (`resources/js/mobile-nav.js`, CSS
in `resources/css/app.css`). A bar on top of the page has a button for each drawer. You can also
swipe: right opens the navigation, left opens the member list, and swiping back closes them.
Swipes that start in a text field, a select or a code block don't count. Escape or a tap on the
dimmed page also closes a drawer. While a drawer is open the rest of the page is `inert`, and
closing it puts focus back where it was. Without JavaScript the panes stack instead, rail first.

### Themes

There is a dark theme (the default) and a light theme. The toggle at the bottom of the server
rail, also on the dashboard (`components/theme-toggle.blade.php`), saves your choice in
`users.theme` through `PUT /settings/theme`. Your choice follows you to every browser.
`resources/js/theme.js` switches the page without a reload and keeps your other tabs in step.

The layouts put the theme on `<html data-theme>`. Neutral colours are Tailwind theme tokens
defined in `resources/css/app.css` (`bg-surface`, `bg-surface-2`, `text-fg`, `text-fg-muted`,
`border-edge`, `text-link` and so on), and `[data-theme="light"]` overrides them. Use these
tokens instead of hard-coded greys: `tests/Feature/Layout/MobileLayoutTest.php` fails on a
hex class for a neutral colour. Accent, danger and status colours (`#5865f2`, `#da373c`, ...)
stay the same in both themes.

### Accessibility

ISSUE-38 brought the app in line with WCAG 2.2 AA. The checks below run in CI.

- **Keyboard navigation** (`resources/js/keyboard-shortcuts.js`), like Discord:
  <kbd>Alt</kbd>+<kbd>↑</kbd>/<kbd>↓</kbd> moves to the previous or next channel
  (or DM on the friends and DM pages), and <kbd>Alt</kbd>+<kbd>Shift</kbd>+<kbd>↑</kbd>/<kbd>↓</kbd>
  moves to the previous or next unread one. Both wrap around. They leave a
  text field with a draft alone, so you never lose a message to a page load. On
  a sidebar you may reorder, <kbd>Alt</kbd>+arrows on a focused row still moves
  that row. After a switch, focus goes to the composer.
- **Skip link**: every app page starts with "Skip to content", which jumps over
  the server rail and sidebar to the main pane.
- **Screen reader announcements** (`resources/js/live-announcer.js`): the
  message list is a `role="log"` that is *not* live, so loading older history
  is not read aloud. New messages from other people are announced as one short
  line each ("New message from Alice: see you at 8", "Alice mentioned you: …")
  in a polite live region.
- **Focus management** (`resources/js/focus-trap.js`): the inbox, the pinned
  messages panel and the profile card take focus when they open, keep
  <kbd>Tab</kbd> inside, and give focus back on <kbd>Escape</kbd> or close. Open
  mobile drawers make the rest of the page `inert`.
- **Contrast**: the theme tokens meet 4.5:1 for text in both themes
  (`tests/js/contrast.test.js`), and `tests/Feature/Layout/ContrastTest.php`
  checks that views use those tokens. `tests/Feature/Layout/AccessibilityTest.php`
  checks the markup: the skip link, ARIA references that resolve, one current
  channel, the shortcut help for screen readers and a labelled inbox dialog.
