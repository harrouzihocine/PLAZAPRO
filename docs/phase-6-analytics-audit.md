# Phase 6 — Analytics & Audit

**Ships:** role dashboards, **source ROI**, **unit‑level intelligence**, and the **admin audit feed**.
Schema/strategy: [`database/06-analytics-audit.md`](database/06-analytics-audit.md).

Analytics is a **read side**: it computes over existing tables and never writes domain data. Build as
vertical slices (query service → resource → view).

---

## Slices in this phase

### 1. Role dashboards
- **API:** `GET /api/v1/dashboard` — returns KPIs scoped to the caller's role/agent (an agent sees their
  own book; a manager their team; admin everything). Scoping enforced **server‑side**.
- **UI:** role‑aware KPI cards + lists (my clients, upcoming visits, overdue actions, payments due).
- **Permissions:** `dashboard.view`.

### 2. Source ROI
- **API:** `GET /api/v1/analytics/source-roi` — group clients → visits → won deals → revenue by
  `source_id`.
- **UI:** funnel/table per source with conversion and revenue; date‑range filter.
- **Permissions:** manager/admin (`dashboard.view` + a report gate).

### 3. Unit‑level intelligence
- **API:** `GET /api/v1/analytics/units` — per‑unit interest (visits), holds, conversion; feeds the
  stacking‑plan "heat".
- **UI:** unit intelligence table + overlay on the stacking plan.
- **Permissions:** manager/admin.

### 4. Admin audit feed
- **API:** `GET /api/v1/audit` (built in [`phase-0-foundations/05-activity-log.md`](phase-0-foundations/05-activity-log.md))
  — paginated, filterable by user/date/entity/action; `GET /api/v1/audit/export` (CSV) logs an `export`.
- **UI:** searchable audit table with before→after diff view.
- **Permissions:** `audit.view`, `audit.export`.

### Performance

Add covering indexes for the group‑bys (`clients(source_id,status)`,
`versements(client_project_id,status,paid_on)`, `visits(unit_id,type)`). Consider read‑only DB views or
short‑TTL Redis caches **only if measured slow** — every figure must stay reproducible from base tables.

---

## Key rules to test

- [ ] An agent's dashboard shows only their assigned clients/visits (scoping enforced server‑side).
- [ ] Source ROI attributes a won sale to the correct `source_id`.
- [ ] The audit feed filters by user/date/entity/action and is read‑only.
- [ ] Exporting the audit writes an `export` entry to the log.
- [ ] No analytics endpoint can mutate domain data.

## Definition of Done (gate)

End to end on phone + desktop, both themes; `dashboard.view`/`audit.*` permissions enforced; scoping
verified; feature tests for scoping, ROI attribution, and audit filtering pass; Pint/ESLint clean;
reviewed PR.

**Next:** [`phase-7-hardening-launch.md`](phase-7-hardening-launch.md)
