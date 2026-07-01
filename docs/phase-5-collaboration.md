# Phase 5 — Collaboration

**Ships:** notifications, the tasks page, and chat with **text, images and voice notes**; visibility and
sharing. Schema: [`database/05-collaboration.md`](database/05-collaboration.md).

Build as vertical slices. This phase adds real‑time‑ish UX; keep it mobile‑first.

---

## Slices in this phase

### 1. Notifications
- **API:** `GET /api/v1/notifications` (paginated, unread count), `POST /notifications/{id}/read`,
  mark‑all‑read.
- **Delivery:** Laravel notifications via the database channel (+ optional broadcast for live badges);
  dispatched on the **queue worker**. Reuses reminders (Phase 3) and payment/visit events.
- **UI:** bell + dropdown in the `AppShell`; unread badge; deep‑links to the subject.
- **Permissions:** `notifications.view`.

### 2. Tasks page
- **API:** `/api/v1/tasks` — list (mine/team, by state/priority/due), create, complete, cancel.
  (`tasks` table lives in Pipeline schema.)
- **UI:** a tasks board/list (open/done), surfaces overdue next actions and reminders too; quick‑add.
- **Permissions:** `tasks.manage`.

### 3. Chat — text, images, voice notes
- **API:** `/api/v1/conversations` (list/create direct|group), `/conversations/{id}/messages`
  (list/send), attachments upload (image/voice/file), `last_read_at` updates.
- **Rules:** message media follows the same upload hardening (mime+size, private disk, UUID, signed
  URLs); voice notes capture `duration_ms`; a "deleted" message is **redacted (cancelled)**, not removed.
- **UI:** conversation list + thread; text composer; image attach; **voice‑note recorder** (mic
  permission already allowed via `Permissions-Policy`); mobile‑first thread that reflows.
- **Permissions:** `chat.use`.

### 4. Visibility & sharing
- **API:** add/remove participants; **share a record** (client/unit/project) into a conversation as a
  message with a `subject` reference.
- **Rules:** a user only sees conversations they participate in; a shared record is visible only to
  participants whose **RBAC** permits it.
- **UI:** participant management; "share to chat" from a client/unit; shared‑record cards in the thread.
- **Permissions:** `chat.use` (+ the subject's own view permission).

### Optional: real‑time transport

For live messages/notifications, add Laravel Echo + a broadcaster (Reverb/Pusher). Not required for a
first cut — polling the notifications/messages endpoints is acceptable initially. Document the choice
if added.

---

## Key rules to test

- [ ] A user only sees conversations they participate in.
- [ ] Sending a message updates `last_message_at` and other participants' unread counts.
- [ ] A shared record is visible only to participants whose RBAC permits it.
- [ ] A "deleted" message is redacted (cancelled), not physically removed.
- [ ] Voice‑note upload stores on a private disk with a UUID name and records duration.

## Definition of Done (gate)

End to end on phone + desktop, both themes; `chat.use`/`notifications.view`/`tasks.manage` permissions
enforced; actions logged where they matter; feature tests for visibility, sharing‑with‑RBAC, and media
handling pass; Pint/ESLint clean; reviewed PR.

**Next:** [`phase-6-analytics-audit.md`](phase-6-analytics-audit.md)
