# Database · 06 — Analytics & Audit

Role dashboards, source ROI, unit‑level intelligence, and the admin audit feed. Built in
[`../phase-6-analytics-audit.md`](../phase-6-analytics-audit.md). Analytics is mostly a **read side** —
it computes over existing tables and does **not** introduce writes that could bypass the audit trail.

---

## `activity_log` (reference — created in Phase 0)

Fully specified in [`../phase-0-foundations/05-activity-log.md`](../phase-0-foundations/05-activity-log.md).
Append‑only; columns: `user_id`, `role_at_time`, `action`, `subject_type`/`subject_id`, `changes`
(JSON), `ip_address`, `user_agent`, `created_at`. The **admin audit feed** is a paginated, filterable
read over this table (`can:audit.view`), with export (`can:audit.export`).

---

## Read‑model strategy (no denormalized writes)

Analytics is served by **query services** and, where useful, **database views** — never by maintaining
duplicate summary tables that a bug could desync from the source of truth.

| Report | Source | Shape |
|--------|--------|-------|
| **Role dashboards** | counts/sums over `clients`, `visits`, `versements`, `next_actions` filtered by the user's role/agent | per‑role KPI cards + lists |
| **Source ROI** | group `clients` (and won `client_projects`/`versements`) by `source_id` | leads → visits → sales → revenue per source |
| **Unit‑level intelligence** | join `units` ↔ `visits`/`reservations`/`client_projects` | interest, holds, conversion per unit; feeds the stacking plan heat |
| **Pipeline health** | `next_actions` by `state`/`due_at`, `visits` by outcome | overdue actions, stale clients |
| **Admin audit feed** | `activity_log` | who/what/when, filterable + exportable |

### Optional performance aids (add only if measured slow)

- **DB views** (`v_source_roi`, `v_unit_intelligence`) for complex joins, queried read‑only.
- **Cached aggregates** in Redis with a short TTL, recomputed on read — not authoritative storage.
- Add covering **indexes** to support the group‑bys (`clients(source_id, status)`,
  `versements(client_project_id, status, paid_on)`, `visits(unit_id, type)`).

> **Rule:** every number a dashboard shows must be reproducible by re‑querying the base tables. If a
> figure can only come from a summary table, that summary is a liability. Keep analytics derivable.

## Permissions

- `dashboard.view` — see role‑appropriate dashboards (scoped: an agent sees their own book; a manager
  sees their team; admin sees all).
- `audit.view` / `audit.export` — the admin audit feed and its export.
- ROI / unit intelligence gated behind manager/admin permissions as the product dictates.

## Key rules to test

- Source ROI attributes a won sale to the correct `source_id`.
- An agent's dashboard shows only their assigned clients/visits (scoping enforced server‑side).
- The audit feed is filterable by user/date/entity/action and is read‑only.
- No analytics endpoint can write to domain tables.

**Next:** back to the phases — [`../phase-1-settings.md`](../phase-1-settings.md)
