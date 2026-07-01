# Database · 05 — Collaboration module

Notifications, the tasks feed (tasks table lives in Pipeline — see
[`03-clients-pipeline.md`](03-clients-pipeline.md)), and chat with text, images and voice notes, plus
visibility and sharing. Built in [`../phase-5-collaboration.md`](../phase-5-collaboration.md).
Base‑model columns per [`00-schema-overview.md`](00-schema-overview.md).

---

## `notifications`

Use Laravel's built‑in notifications table shape (UUID id, `type`, `notifiable`, `data`, `read_at`),
extended with what the product needs. If you prefer a custom table:

| Column | Type | Notes |
|--------|------|-------|
| `id` | uuid PK | Laravel default |
| `type` | string | notification class |
| `notifiable_type` / `notifiable_id` | morphs | the recipient (usually `User`) |
| `data` | json | payload (title, body, link, subject ref) |
| `read_at` | timestamp, nullable | |
| `created_at` / `updated_at` | timestamps | |

Index: `(notifiable_type, notifiable_id, read_at)`.

> Notifications are transient UX records, not audited domain data, so they use Laravel's own table
> rather than `BaseModel`. Domain actions that *matter* are still captured in the `activity_log`.
> Delivery is via the database channel (+ optional broadcast) and dispatched on the **queue worker**.

## `conversations`

| Column | Type | Notes |
|--------|------|-------|
| `id` | PK | |
| `type` | enum | `direct` \| `group` |
| `title` | string, nullable | group name |
| `subject_type` / `subject_id` | morphs, nullable | optional link to a client/unit/project (contextual chat) |
| `created_by` | FK → users | |
| `last_message_at` | timestamp, nullable | for sorting the inbox |
| *(base columns)* | | |

## `conversation_user` (participants + visibility/sharing)

| Column | Type | Notes |
|--------|------|-------|
| `conversation_id` | FK → conversations, cascade | |
| `user_id` | FK → users, cascade | |
| `role` | enum | `member` \| `admin` (group admin) |
| `joined_at` | timestamp | |
| `last_read_at` | timestamp, nullable | unread badge |
| `muted` | boolean, default false | |

Primary key `(conversation_id, user_id)`.

> **Visibility & sharing:** who can see a conversation is defined by its participant rows. Sharing a
> record (client/unit) into a chat creates a message with a `subject` reference; access still respects
> RBAC — a participant only sees a shared record if their permissions allow it.

## `messages`

| Column | Type | Notes |
|--------|------|-------|
| `id` | PK | |
| `conversation_id` | FK → conversations | |
| `user_id` | FK → users | author |
| `type` | enum | `text` \| `image` \| `voice` \| `file` \| `system` |
| `body` | text, nullable | text content (null for pure media) |
| `subject_type` / `subject_id` | morphs, nullable | a shared record reference |
| `edited_at` | timestamp, nullable | |
| *(base columns)* | | messages are cancellable (redacted), never hard‑deleted |

Indexes: `(conversation_id, id)`, `user_id`.

## `message_attachments`

Images and **voice notes** (and generic files).

| Column | Type | Notes |
|--------|------|-------|
| `id` | PK | |
| `message_id` | FK → messages, cascade | |
| `kind` | enum | `image` \| `voice` \| `file` |
| `disk` / `path` | string | private disk, UUID filename |
| `mime_type` | string | validated server‑side |
| `size_bytes` | bigint | |
| `duration_ms` | int, nullable | voice‑note length |
| `width` / `height` | int, nullable | image dimensions |
| `meta` | json, nullable | waveform, thumbnail ref |
| *(base columns)* | | |

Index: `message_id`.

> **Media rules** are the same as inventory media
> ([`../phase-0-foundations/10-security-baseline.md`](../phase-0-foundations/10-security-baseline.md)):
> validate mime + size, private disk, UUID filenames, serve via signed URLs/CDN. Voice notes need mic
> permission — the `Permissions-Policy` header already allows `microphone=(self)`.

## Key rules to test

- A user only sees conversations they participate in.
- Sending a message updates `conversations.last_message_at` and unread counts for other participants.
- A shared record is only visible to participants whose RBAC permits it.
- A "deleted" message is redacted (cancelled), not physically removed.

**Next:** [`06-analytics-audit.md`](06-analytics-audit.md)
