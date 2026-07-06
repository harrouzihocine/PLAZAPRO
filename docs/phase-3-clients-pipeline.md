# Phase 3 — Clients & Pipeline

**Ships:** clients and **Desire**; the **call → office‑visit → apartment‑visit** chain; the **enforced
next action** with **auto‑reminders**. Schema: [`database/03-clients-pipeline.md`](database/03-clients-pipeline.md).

This is the sales heart of the CRM. Build as vertical slices.

---

## Slices in this phase

### 1. Clients
- **API:** `/api/v1/clients` CRUD + filters (agent, source, rating, stage); assign agent.
- **Rules:** `assigned_agent_id` must be an **agent** (`is_agent`); `source_id`/`rating_id` from dynamic
  lists; cancel, never delete.
- **UI:** clients table (searchable by phone/name), client file (profile, desire, timeline, payments).
- **Permissions (the client record only):** `clients.view`, `clients.create`, `clients.manage`
  (edit/reassign/archive the client). Project and deal work has its own grants — see §2.

### 2. Client projects (deals)
- **API:** `/api/v1/clients/{id}/projects` — open a project; `/projects/{id}` edit/archive/reactivate/
  shift/remove; `/projects/{id}/advance` moves the stage (`lead→…→won/lost`); deal closure under
  `/deals/*` and `/shortlist-items/{id}/outcome`.
- **UI:** deal panel on the client file.
- **Permissions — split into three groups so client editing can be handed out without project closing:**
  - *Client projects (the project record & lifecycle):* `projects.create` (open a project),
    `projects.manage` (edit/archive/reactivate/shift/remove), plus the existing `projects.view_all`,
    `projects.contributors`, `projects.freeze`.
  - *Client project details (work inside one project's file):* `projects.advance` (move the stage),
    `deals.direct` (open a deal without a visit log), `deals.manage` (close a deal/apartment won/lost,
    release a won apartment, add boxes, record shortlist outcomes).
  - Legacy note: `projects.create` was split out of `clients.create`; `projects.manage`,
    `projects.advance` and `deals.manage` were split out of `clients.manage`. The seeder backfills the
    new grants onto any role that held the legacy one, so nothing loses access on upgrade.

### 3. Desire + matching
- **API:** `/api/v1/clients/{id}/desire` (upsert); `GET /api/v1/clients/{id}/matches` →
  `MatchDesireToInventory` returns available units fitting area/type/budget/rooms, ranked.
- **UI:** desire form; a "matching units" panel showing candidates with a one‑tap "present"/"reserve".
- **Permissions:** `clients.view`, `units.view`.

### 4. The interaction chain (calls & visits)
- **Calls:** `POST /api/v1/clients/{id}/calls` — log direction/outcome/notes; **must set a next action**.
- **Office visit / apartment visit:** `POST /api/v1/visits` — `type` office|apartment; apartment visits
  require a `unit_id`; `agent_id` must be an agent; **completing requires a next action**.
- **Actions:** `LogCall`, `ScheduleVisit`, `AssignVisit` (agent‑only), `CompleteInteraction`
  (validates the next action).
- **UI:** timeline on the client file (calls, visits, outcomes); "log call" / "schedule visit" flows
  that work in a few taps (§5.4); visit assignment picker lists **only agents**.
- **Permissions:** `calls.log`, `visits.assign`, `visits.conduct`.

### 5. Enforced next action + reminders — the key rule
- **Rule:** every completed call/visit **must** produce a `next_action` (validated in the FormRequest
  *and* the Action). A client/project always has exactly one open `pending` next action.
- **Reminders:** `next_actions` due/overdue generate `reminders`; a scheduled `DispatchReminders`
  Action (queue worker) sends in‑app (and optional email) reminders to the assigned agent.
- **UI:** a "needs action" indicator on clients; the next‑action due date surfaced on dashboards and the
  tasks page (Phase 5).
- **Permissions:** `tasks.manage` (for acting on the reminder).

### Scheduler wiring

```php
Schedule::command('reminders:dispatch')->everyMinute();   // DispatchReminders
Schedule::command('actions:mark-overdue')->hourly();      // flip next_actions/schedules to overdue
```

---

## Key rules to test

- [ ] Completing a call/visit **without** a next action is rejected.
- [ ] `MatchDesireToInventory` returns only `available` units fitting area/type/budget/rooms.
- [ ] A visit can only be assigned to a user whose role has `is_agent = true`.
- [ ] An overdue next action produces a reminder for the assigned agent.
- [ ] Cancelling a client keeps the record and its history (no delete).

## Definition of Done (gate)

End to end on phone + desktop, both themes; `clients.*`/`calls.*`/`visits.*` permissions enforced;
every action logged; feature tests for enforced‑next‑action, agent‑only assignment, and desire matching
pass; Pint/ESLint clean; reviewed PR.

**Next:** [`phase-4-payments-documents.md`](phase-4-payments-documents.md)
