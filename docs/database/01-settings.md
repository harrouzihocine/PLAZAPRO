# Database · 01 — Settings module

Roles, departments, users, and the **dynamic‑list structure that powers every dropdown**. Built in
[`../phase-1-settings.md`](../phase-1-settings.md). All tables carry the cross‑cutting base‑model
columns from [`00-schema-overview.md`](00-schema-overview.md) unless noted.

---

## `roles`

| Column | Type | Notes |
|--------|------|-------|
| `id` | PK | |
| `name` | string | "Agent", "Sales Manager", "Super Admin" |
| `slug` | string, unique | `agent`, `super-admin` — used by RBAC |
| `description` | string, nullable | |
| `is_agent` | boolean, default false | **drives visit‑assignment eligibility** |
| *(base columns)* | | `status`, `cancellation_reason`, `supersedes_id`, timestamps |

Index: `slug` unique, `is_agent`.

## `permissions`

| Column | Type | Notes |
|--------|------|-------|
| `id` | PK | |
| `name` | string | "Reserve units" |
| `slug` | string, unique | `units.reserve` (`resource.action`) |
| `group` | string, nullable | for grouping in the admin UI ("Inventory") |
| `description` | string, nullable | |
| timestamps | | (permissions are seeded config; no cancel needed, but keep timestamps) |

## `permission_role` (pivot)

| Column | Type |
|--------|------|
| `permission_id` | FK → permissions, cascade |
| `role_id` | FK → roles, cascade |

Primary key `(permission_id, role_id)`.

## `departments`

| Column | Type | Notes |
|--------|------|-------|
| `id` | PK | |
| `name` | string | "Sales", "Finance" |
| `slug` | string, unique | |
| *(base columns)* | | |

## `users`

**One role per user** — a single `role_id`, never a pivot.

| Column | Type | Notes |
|--------|------|-------|
| `id` | PK | |
| `name` | string | |
| `email` | string, unique | login identifier |
| `email_verified_at` | timestamp, nullable | |
| `password` | string | bcrypt/argon hash |
| `role_id` | FK → roles | **exactly one** |
| `department_id` | FK → departments, nullable | |
| `phone` | string, nullable | |
| `avatar_path` | string, nullable | private disk |
| `is_active` | boolean, default true | deactivate without deleting |
| `last_login_at` | timestamp, nullable | set on login |
| `remember_token` | string, nullable | |
| *(base columns)* | | `status`, `cancellation_reason`, `supersedes_id`, timestamps |

Indexes: `email` unique, `role_id`, `department_id`, `(status, is_active)`.

> `User` extends `Authenticatable` and uses `HasApiTokens` (Sanctum); it still carries the base‑model
> traceability columns and the no‑delete behaviour (deactivate/cancel instead of delete).

---

## Dynamic settings — the reusable dropdown backbone

**One structure powers every dropdown** in the app (payment methods, floors, areas, sources, client
ratings, cancellation reasons, visit outcomes, …). Two tables: a **list** and its **items**.

### `dynamic_lists`

| Column | Type | Notes |
|--------|------|-------|
| `id` | PK | |
| `key` | string, unique | machine key: `payment_methods`, `floors`, `areas`, `sources`, `client_ratings`, `cancellation_reasons`, `visit_outcomes`, `unit_types`, `box_types` |
| `name` | string | human label for the admin UI |
| `description` | string, nullable | |
| `is_system` | boolean, default false | system lists can't be renamed/removed by admins |
| *(base columns)* | | |

### `dynamic_list_items`

| Column | Type | Notes |
|--------|------|-------|
| `id` | PK | |
| `dynamic_list_id` | FK → dynamic_lists, cascade | |
| `label` | string | shown in the dropdown |
| `value` | string | stable machine value (referenced by other tables) |
| `sort_order` | int, default 0 | ordering in the UI |
| `parent_id` | FK → dynamic_list_items, nullable | hierarchical lists (e.g. area → sub‑area) |
| `is_active` | boolean, default true | hide without deleting |
| `meta` | json, nullable | optional extra (colour, weight for ratings, …) |
| *(base columns)* | | |

Indexes: `(dynamic_list_id, sort_order)`, `(dynamic_list_id, is_active)`, `parent_id`.

### How other tables reference a dynamic item

Foreign keys such as `clients.source_id`, `clients.rating_id`, `versements.method_id`,
`units.floor_id` point to `dynamic_list_items.id`. This is what lets an admin add a new payment method
or area **without a code change or migration**.

> **Ratings and reasons are seeded dynamic lists** (`client_ratings`, `cancellation_reasons`), per the
> guide's Phase 1 scope ("roles, departments, users, ratings, reasons, and the dynamic‑list
> structure"). They are not separate tables.

---

## Seeders (Phase 1)

- `RbacSeeder` — roles, permissions, first admin (see [`../phase-0-foundations/06-auth-and-rbac.md`](../phase-0-foundations/06-auth-and-rbac.md)).
- `DynamicListSeeder` — creates the system lists and their starting items:
  `payment_methods` (cash, cheque, bank transfer), `floors`, `areas`, `sources` (walk‑in, referral,
  Facebook, Instagram, portal), `client_ratings` (hot/warm/cold), `cancellation_reasons`,
  `unit_types` (studio, F2, F3, duplex…), `box_types` (parking, storage), `visit_outcomes`.

## Key rules to test

- A `user` has exactly one `role`; assigning a second role is impossible via the API.
- Deactivating/cancelling a `role` or `user` never deletes the row.
- A dynamic list item referenced by live records is deactivated, not hard‑removed.

**Next:** [`02-inventory.md`](02-inventory.md)
