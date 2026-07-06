# Phase 4 — Payments & Documents

**Ships:** versements and schedules; **cancel‑and‑duplicate** corrections; **branded document
generation**. Schema: [`database/04-payments.md`](database/04-payments.md).

This phase handles money, so the traceability foundations matter most here. Build as vertical slices.

---

## Slices in this phase

### 1. Payment schedules
- **API:** `/api/v1/projects/{id}/schedule` — create/adjust the instalment plan (installments, due
  dates, amounts).
- **Rules:** schedule totals reconcile to the agreed `total_price`; state derived, never client‑set.
- **UI:** schedule table with per‑instalment state (pending/paid/partial/overdue).
- **Permissions:** `versements.view`, `versements.record`.

### 2. Record a versement (instalment)
- **Action:** `RecordVersement` — validates amount/method/date, allocates to a `schedule` item
  (`AllocateVersement`), updates the item's `paid_amount`/`state`, in a transaction; logged.
- **API:** `POST /api/v1/projects/{id}/versements`.
- **Rules:** money is `decimal(12,2)`; arithmetic via `bcmath`; **round half‑up to 2 dp**.
- **UI:** "record payment" form (method from `payment_methods` dynamic list); running balance.
- **Permissions:** `versements.record`.

### 3. Cancel‑and‑duplicate correction — the key rule
- **Action:** `CorrectVersement` → `supersedeWith()` cancels the original (with reason) and inserts a
  corrected row linked by `supersedes_id`, in one transaction; audit records `cancel` + `duplicate`.
- **API:** `POST /api/v1/versements/{id}/correct`.
- **Rules:** a recorded versement is **never edited or deleted** — only superseded. Both rows stay in
  history and reconcile.
- **UI:** "correct" action that captures a reason and shows the correction chain.
- **Permissions:** `versements.cancel`.

### 4. Branded document generation
- **Action:** `GenerateVersementDocument` (and contract/quote generators) — renders a branded template
  to PDF, stores it on a **private disk**, records a `documents` row with a stable `number`; `meta`
  snapshots the figures so reprints are faithful. Runs on the **queue worker**.
- **API:** `POST /api/v1/versements/{id}/document`, `GET /api/v1/documents/{id}` (signed download).
- **Rules:** documents are versioned (regeneration bumps `version`); numbers are unique/sequential.
- **UI:** "generate receipt" / download; document list on the client file.
- **Permissions:** `documents.generate`.

### 5. Archive‑only‑without‑payments — the key rule
- **Rule:** a `client_project` (or unit) may be archived only if it has **no active versements**; the
  archive Action checks and refuses otherwise. (One of the guide's named test rules.)
- **Permissions:** `projects.manage` (project archive/reactivate; split out of `clients.manage`).

---

## Key rules to test

- [ ] Recording a versement allocates to a schedule item and updates its `state`/`paid_amount`.
- [ ] Correcting a versement cancels the original and creates a linked replacement (`supersedes_id` set); both persist.
- [ ] Archiving a project **with** active versements is rejected; with none, it succeeds.
- [ ] Versement rounding is exact across a full schedule (no float drift).
- [ ] Generating a receipt yields a `documents` row with a unique number and a private‑disk PDF.

## Definition of Done (gate)

End to end on phone + desktop, both themes; `versements.*`/`documents.generate` permissions enforced;
every action logged; feature tests for correction, rounding, archive rule, and document generation pass;
Pint/ESLint clean; reviewed PR.

**Next:** [`phase-5-collaboration.md`](phase-5-collaboration.md)
