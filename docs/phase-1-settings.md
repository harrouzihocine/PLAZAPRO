# Phase 1 — Settings Backbone

**Ships:** roles, departments, users, ratings, reasons, and the **dynamic‑list structure every dropdown
uses**. Schema: [`database/01-settings.md`](database/01-settings.md).

**Why first:** every later module reads dynamic lists (payment methods, areas, sources…) and assigns
users/agents. Building Settings first means Phases 2–6 have real dropdowns and RBAC data to work with.

Build each item as a **vertical slice** — see
[`conventions/vertical-slice-recipe.md`](conventions/vertical-slice-recipe.md).

---

## Slices in this phase

### 1. Roles & permissions admin
- **API:** `GET/POST/PUT /api/v1/roles`, `GET /api/v1/permissions`, assign permissions to a role.
- **Rules:** `is_agent` flag editable; role is `Cancellable` (never deleted); changing a role is logged.
- **UI:** roles list + editor with a permission matrix; `is_agent` toggle.
- **Permissions:** `roles.manage`.

### 2. Departments
- **API:** resource CRUD (create/read/update/cancel) `/api/v1/departments`.
- **UI:** simple list + form. **Permissions:** `settings.manage`.

### 3. Users
- **API:** `/api/v1/users` — create/update/deactivate; **exactly one `role_id`**; assign department.
- **Rules:** password via `Password::defaults()`; deactivate (`is_active=false`) or cancel, never delete;
  only `is_agent` roles appear in agent pickers elsewhere.
- **UI:** users table (filter by role/department/active), create/edit drawer, deactivate action.
- **Permissions:** `users.manage`.

### 4. Dynamic lists (the backbone)
- **API:** `/api/v1/dynamic-lists` and `/api/v1/dynamic-lists/{key}/items` — CRUD items, reorder,
  activate/deactivate. Read endpoint `GET /api/v1/dynamic-lists/{key}` used by every dropdown app‑wide.
- **Rules:** `is_system` lists can't be renamed/removed; items referenced by live records are
  deactivated, not removed; reordering persists `sort_order`.
- **UI:** a generic "Lists" admin: pick a list → manage its items (label/value/order/active), supports
  hierarchical `parent_id` where used.
- **Seed:** `DynamicListSeeder` (payment_methods, floors, areas, sources, client_ratings,
  cancellation_reasons, unit_types, box_types, visit_outcomes).
- **Permissions:** `settings.manage`.

### 5. Ratings & reasons
- These are **seeded dynamic lists** (`client_ratings`, `cancellation_reasons`), managed through the
  same dynamic‑list UI — no separate screens or tables. Confirm the starting values with the client.

---

## Frontend

- Feature folder `src/features/settings/` with `views/` (Roles, Users, Departments, Lists),
  `components/`, `store.js`, `api.js`.
- A shared `useDynamicList(key)` composable fetches and caches a list for dropdowns everywhere — this is
  the single consumer other features reuse.

## Key rules to test

- [ ] A user cannot be given two roles.
- [ ] Cancelling a role/user/department keeps the row (no hard delete) and is logged.
- [ ] Adding a `payment_methods` item makes it appear in the versement form dropdown with no code change.
- [ ] `is_system` lists are protected from rename/removal.

## Definition of Done (gate)

Works end to end on phone + desktop in both themes; permissions enforced (`roles.manage`,
`users.manage`, `settings.manage`); every change logged; feature tests for the rules above pass; Pint/
ESLint clean; merged via reviewed PR. See [`00-overview-and-conventions.md`](00-overview-and-conventions.md) §6.

**Next:** [`phase-2-inventory-media.md`](phase-2-inventory-media.md)
