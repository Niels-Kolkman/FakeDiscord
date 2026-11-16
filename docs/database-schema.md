# Database schema

FakeDiscord runs on **SQLite**. Migrations live in `database/migrations`. To rebuild
the database from scratch, run:

```bash
php artisan migrate:fresh        # add --seed for the test user
```

## ERD

GitHub and most Markdown viewers render the Mermaid diagram below. Laravel's
framework tables (`password_reset_tokens`, `sessions`, `cache`, `jobs`, …) are
left out.

```mermaid
erDiagram
    users {
        bigint id PK
        string name
        string username UK
        string email UK
        string avatar_path "nullable"
        string status "online|idle|dnd|offline"
        timestamp email_verified_at "nullable"
        string password
        timestamps timestamps
    }

    servers {
        bigint id PK
        bigint owner_id FK "users, cascade"
        string name
        text description "nullable"
        string icon_path "nullable"
        timestamps timestamps
    }

    server_members {
        bigint id PK
        bigint server_id FK "cascade"
        bigint user_id FK "cascade"
        string nickname "nullable"
        timestamp joined_at
        timestamps timestamps
    }

    channels {
        bigint id PK
        bigint server_id FK "cascade"
        string name
        string type "text|voice|announcement"
        string topic "nullable"
        int position
        timestamps timestamps
    }

    roles {
        bigint id PK
        bigint server_id FK "cascade"
        string name
        string color "nullable, #RRGGBB"
        bigint permissions "bitmask"
        int position
        boolean is_default "@everyone"
        timestamps timestamps
    }

    role_user {
        bigint role_id PK,FK "cascade"
        bigint user_id PK,FK "cascade"
        timestamps timestamps
    }

    conversations {
        bigint id PK
        string type "direct|group"
        string name "nullable"
        bigint owner_id FK "nullable, users, set null"
        timestamps timestamps
    }

    conversation_participants {
        bigint id PK
        bigint conversation_id FK "cascade"
        bigint user_id FK "cascade"
        timestamp last_read_at "nullable"
        timestamps timestamps
    }

    messages {
        bigint id PK
        bigint channel_id FK "nullable, cascade"
        bigint conversation_id FK "nullable, cascade"
        bigint user_id FK "nullable, set null"
        bigint reply_to_id FK "nullable, messages, set null"
        text content
        timestamp edited_at "nullable"
        timestamps timestamps
    }

    message_attachments {
        bigint id PK
        bigint message_id FK "cascade"
        string disk
        string path
        string original_name
        string mime_type
        bigint size "bytes"
        json metadata "nullable, TEXT on SQLite"
        timestamps timestamps
    }

    reactions {
        bigint id PK
        bigint message_id FK "cascade"
        bigint user_id FK "cascade"
        string emoji
        timestamps timestamps
    }

    friendships {
        bigint id PK
        bigint user_id FK "requester, cascade"
        bigint friend_id FK "addressee, cascade"
        string status "pending|accepted|blocked"
        timestamp accepted_at "nullable"
        timestamps timestamps
    }

    invites {
        bigint id PK
        string code UK
        bigint server_id FK "cascade"
        bigint channel_id FK "nullable, set null"
        bigint inviter_id FK "nullable, users, set null"
        int max_uses "nullable = unlimited"
        int uses
        timestamp expires_at "nullable = never"
        timestamps timestamps
    }

    bans {
        bigint id PK
        bigint server_id FK "cascade"
        bigint user_id FK "cascade"
        bigint banned_by_id FK "nullable, users, set null"
        string reason "nullable"
        timestamps timestamps
    }

    users ||--o{ servers : owns
    users ||--o{ server_members : "joins via"
    servers ||--o{ server_members : has
    servers ||--o{ channels : has
    servers ||--o{ roles : defines
    roles ||--o{ role_user : "assigned via"
    users ||--o{ role_user : has
    servers ||--o{ invites : has
    channels |o--o{ invites : "lands in"
    users |o--o{ invites : creates
    servers ||--o{ bans : has
    users ||--o{ bans : "is banned"
    users |o--o{ bans : issues

    users |o--o{ conversations : "owns (group)"
    conversations ||--o{ conversation_participants : has
    users ||--o{ conversation_participants : "takes part"

    channels |o--o{ messages : contains
    conversations |o--o{ messages : contains
    users |o--o{ messages : writes
    messages |o--o{ messages : "replies to"
    messages ||--o{ message_attachments : has
    messages ||--o{ reactions : has
    users ||--o{ reactions : adds

    users ||--o{ friendships : requests
    users ||--o{ friendships : receives
```

## Indexes

| Table | Index | Used for |
|-------|-------|----------|
| `messages` | `(channel_id, created_at)` | Paging through a channel's history |
| `messages` | `(conversation_id, created_at)` | Paging through a DM's history |
| `messages` | `user_id`, `reply_to_id` | FK lookups and `ON DELETE` actions |
| `channels` | `(server_id, position)` | Sidebar order |
| `roles` | `(server_id, position)` | Role hierarchy |
| `server_members` | unique `(server_id, user_id)`, `user_id` | Member list / "my servers" |
| `role_user` | PK `(role_id, user_id)`, `user_id` | A user's roles |
| `conversation_participants` | unique `(conversation_id, user_id)`, `user_id` | DM list |
| `reactions` | unique `(message_id, emoji, user_id)`, `user_id` | Reaction counts; one reaction per emoji per user |
| `friendships` | unique `(user_id, friend_id)`, `(friend_id, status)` | Outgoing and incoming requests |
| `invites` | unique `code`, `server_id` | Invite lookup by code |
| `bans` | unique `(server_id, user_id)`, `user_id` | Ban checks on join |

SQLite does not index foreign key columns automatically, so every FK column
used for lookups or `ON DELETE` actions has its own index, or is the first
column of a composite one.

## Delete behaviour

- **Deleting a server** also deletes its channels, roles, members, invites, bans
  and, through channels, its messages with their attachments and reactions.
- **Deleting a user** deletes their memberships, role assignments, reactions,
  friendships, DM participation and owned servers. Their **messages stay**, with
  `user_id` set to NULL (shown as "Deleted User"). Invites they created and bans
  they issued also stay, with the user column set to NULL.
- **Deleting a message** sets `reply_to_id` on replies to NULL. The replies
  themselves stay.

## Invariants enforced in the app layer

A CHECK constraint or partial index would need raw SQL that only works on one
database, so these rules live in the app layer (validation, model events or policies):

- A message has **exactly one** of `channel_id` or `conversation_id`.
- `role_user`: the user must be a member of the role's server. Each server has
  exactly one `is_default` role.
- `friendships`: the reverse pair (B→A when A→B already exists) is not allowed.
  `user_id <> friend_id`.
- `conversations.type = 'direct'` has exactly two participants.
- Values in the `status` and `type` columns. These are plain strings, not
  `enum()`, because Laravel turns `enum()` into a CHECK constraint on SQLite,
  and changing it later would mean rebuilding the whole table.

## SQLite notes

- `DB_FOREIGN_KEYS=true` (the default in `config/database.php`, and set in
  `.env.example`) turns on `PRAGMA foreign_keys`. Without it SQLite ignores
  every FK and `ON DELETE` rule.
- `roles.permissions` is an integer bitmask. SQLite integers are signed
  64-bit, so permission flags must stay within bits 0–62.
- `json` columns are stored as `TEXT`. Use Eloquent's `array`/`json` casts.
- Write future migrations with SQLite's limited `ALTER TABLE` in mind. Adding
  nullable or defaulted columns and creating or dropping indexes is cheap. Changing or
  dropping columns makes Laravel rebuild the table, so avoid it on large tables.
