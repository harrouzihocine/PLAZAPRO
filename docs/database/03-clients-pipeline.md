# Database · 03 — Clients & Pipeline modules

Clients and their desire, plus the interaction chain (call → office‑visit → apartment‑visit) with the
**enforced next action** and auto‑reminders. Built in
[`../phase-3-clients-pipeline.md`](../phase-3-clients-pipeline.md). All tables carry the base‑model
columns from [`00-schema-overview.md`](00-schema-overview.md).

---

## `clients`

| Column | Type | Notes |
|--------|------|-------|
| `id` | PK | |
| `first_name` / `last_name` | string | |
| `phone` | string, indexed | primary contact |
| `email` | string, nullable | |
| `source_id` | FK → dynamic_list_items (`sources`), nullable | lead source (for ROI) |
| `rating_id` | FK → dynamic_list_items (`client_ratings`), nullable | hot/warm/cold |
| `assigned_agent_id` | FK → users, nullable | must be an **agent** (`is_agent`) |
| `notes` | text, nullable | |
| *(base columns)* | | |

Indexes: `phone`, `assigned_agent_id`, `source_id`, `(status, rating_id)`.

## `client_projects` (the deal / opportunity)

A client's buying intent; parent of payments, schedule, desire and documents. (You may collapse this
into `clients` for a lean v1, but the guide's `versements.client_project_id` implies it exists.)

| Column | Type | Notes |
|--------|------|-------|
| `id` | PK | |
| `client_id` | FK → clients | |
| `location_id` | FK → locations, nullable | project of interest |
| `unit_id` | FK → units, nullable | the chosen unit once known |
| `stage` | enum | `lead` \| `negotiating` \| `reserved` \| `won` \| `lost` |
| `total_price` | decimal(12,2), nullable | agreed price |
| *(base columns)* | | |

## `desires` (what the client wants → matched to inventory)

| Column | Type | Notes |
|--------|------|-------|
| `id` | PK | |
| `client_id` | FK → clients | |
| `client_project_id` | FK → client_projects, nullable | |
| `floor_pref` | string, nullable | legacy free text, e.g. "high floor" |
| `area_min` / `area_max` | decimal(10,2), nullable | surface range (m²) |
| `rooms_min` | tinyint, nullable | |
| `budget_min` / `budget_max` | decimal(12,2), nullable | budget range |
| `notes` | text | required — the story behind the numbers |
| *(base columns)* | | |

Every structured criterion is **multi-valued** ("F2 OR F3", "Hydra OR Kouba") and lives in a
pivot with "no rows = no preference" semantics:

| Pivot | Columns | Criterion |
|-------|---------|-----------|
| `desire_wilayas` | `desire_id`, `wilaya_id` | acceptable wilayas |
| `desire_communes` | `desire_id`, `commune_id` | acceptable communes |
| `desire_list_items` | `desire_id`, `item_id` (FK dynamic_list_items), `field` | `type` / `room_number` / `contract_type` / `floor` |
| `desire_locations` | `desire_id`, `location_id` | preferred sites (projects) |

> **Desire matching** (Phase 3): the `MatchDesireToInventory` Action queries `units` that are
> not sold and whose value for every SET criterion is ANY of the picked ones (`price BETWEEN
> budget_min/max`, `area_sqm` in range) — ranked by closeness to budget. This is a **key rule
> to test** (next‑action match).

## Pipeline — the interaction chain

### `calls`

| Column | Type | Notes |
|--------|------|-------|
| `id` | PK | |
| `client_id` | FK → clients | |
| `client_project_id` | FK → client_projects, nullable | |
| `agent_id` | FK → users | who called |
| `direction` | enum | `inbound` \| `outbound` |
| `outcome_id` | FK → dynamic_list_items (`visit_outcomes`/`call_outcomes`), nullable | |
| `notes` | text, nullable | |
| `called_at` | timestamp | |
| *(base columns)* | | |

### `visits`

Covers both **office visits** and **apartment visits**.

| Column | Type | Notes |
|--------|------|-------|
| `id` | PK | |
| `client_id` | FK → clients | |
| `client_project_id` | FK → client_projects, nullable | |
| `type` | enum | `office` \| `apartment` |
| `unit_id` | FK → units, nullable | required when `type = apartment` |
| `agent_id` | FK → users | **must be an agent** (`is_agent`) |
| `scheduled_at` | timestamp | |
| `completed_at` | timestamp, nullable | |
| `outcome_id` | FK → dynamic_list_items (`visit_outcomes`), nullable | |
| `notes` | text, nullable | |
| *(base columns)* | | |

Indexes: `(agent_id, scheduled_at)`, `client_id`, `type`, `unit_id`.

> **Agent‑only assignment** is enforced by the `AssignVisit` Action and by the `is_agent` query in
> [`../phase-0-foundations/06-auth-and-rbac.md`](../phase-0-foundations/06-auth-and-rbac.md).

### `next_actions` (the enforced next step)

Every interaction (call/visit) must leave a **next action** — the pipeline can never go "cold" silently.

| Column | Type | Notes |
|--------|------|-------|
| `id` | PK | |
| `subject_type` / `subject_id` | morphs | the client/project the action belongs to |
| `source_type` / `source_id` | morphs, nullable | the call/visit that created it |
| `type` | enum | `call` \| `office_visit` \| `apartment_visit` \| `follow_up` \| `send_docs` |
| `due_at` | timestamp | when it must happen |
| `assigned_to` | FK → users | usually the agent |
| `state` | enum | `pending` \| `done` \| `cancelled` |
| `completed_at` | timestamp, nullable | |
| *(base columns)* | | |

Indexes: `(state, due_at)`, `(subject_type, subject_id)`, `assigned_to`.

> **Enforcement rule:** completing a call/visit **requires** creating the next action (validated in the
> FormRequest + Action). A client/project should always have exactly one open `pending` next action.

### `reminders` (auto)

Auto‑reminders for upcoming/overdue next actions and scheduled visits.

| Column | Type | Notes |
|--------|------|-------|
| `id` | PK | |
| `next_action_id` | FK → next_actions, nullable | |
| `task_id` | FK → tasks, nullable | |
| `remind_at` | timestamp | |
| `channel` | enum | `in_app` \| `email` (extensible) |
| `sent_at` | timestamp, nullable | |
| `state` | enum | `pending` \| `sent` \| `cancelled` |
| *(base columns)* | | |

Index: `(state, remind_at)` — the reminder dispatcher (queue worker) scans this.

### `tasks`

General to‑dos (also surfaced on the Phase 5 tasks page).

| Column | Type | Notes |
|--------|------|-------|
| `id` | PK | |
| `title` | string | |
| `description` | text, nullable | |
| `assigned_to` | FK → users | |
| `subject_type` / `subject_id` | morphs, nullable | related client/unit/project |
| `due_at` | timestamp, nullable | |
| `priority` | enum | `low` \| `normal` \| `high` |
| `state` | enum | `open` \| `done` \| `cancelled` |
| *(base columns)* | | |

Indexes: `(assigned_to, state, due_at)`.

## Key rules to test

- Completing a call/visit **without** a next action is rejected.
- Desire matching returns only `available` units that fit area/type/budget/rooms.
- A visit can only be assigned to a user whose role has `is_agent = true`.
- An overdue `next_action` produces a reminder for the assigned agent.

**Next:** [`04-payments.md`](04-payments.md)
