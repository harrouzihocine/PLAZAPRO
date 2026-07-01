# Database · 04 — Payments & Documents module

Versements (instalments), payment schedules, cancel‑and‑duplicate corrections, and branded document
generation. Built in [`../phase-4-payments-documents.md`](../phase-4-payments-documents.md). Base‑model
columns per [`00-schema-overview.md`](00-schema-overview.md).

> **Money rule:** all amounts are `decimal(12,2)`. Do arithmetic with `bcmath`/integer cents in PHP,
> never floats. Store one currency; formatting/locale is a UI concern. Document the rounding rule
> (round half‑up to 2 dp) and test it — the guide's key rules include "versement rounding".

---

## `versements` (instalments — the guide's canonical no‑delete example)

| Column | Type | Notes |
|--------|------|-------|
| `id` | PK | |
| `client_project_id` | FK → client_projects | |
| `amount` | decimal(12,2) | |
| `paid_on` | date | |
| `method_id` | FK → dynamic_list_items (`payment_methods`) | cash/cheque/transfer |
| `reference` | string, nullable | cheque no. / transfer ref |
| `schedule_item_id` | FK → payment_schedules, nullable | the instalment it settles |
| `recorded_by` | FK → users | |
| `document_id` | FK → documents, nullable | generated receipt |
| `status` | string | `active` \| `cancelled` (base) |
| `cancellation_reason` | string, nullable | base |
| `supersedes_id` | FK → versements, nullable | **cancel‑and‑duplicate correction** |
| timestamps | | |

Indexes: `(client_project_id, status)`, `method_id`, `paid_on`, `supersedes_id`.

> **Correction pattern (`HasVersions`):** you never edit a recorded versement. To fix one, the
> `CorrectVersement` Action calls `supersedeWith()` — it cancels the original (with a reason) and
> inserts a corrected row linked via `supersedes_id`, inside a transaction. Both rows stay in history;
> the audit log records the `cancel` + `duplicate`. This is the guide's headline traceability example.

## `payment_schedules` (the instalment plan)

| Column | Type | Notes |
|--------|------|-------|
| `id` | PK | |
| `client_project_id` | FK → client_projects | |
| `installment_no` | int | 1..N |
| `due_date` | date | |
| `amount` | decimal(12,2) | planned amount |
| `state` | enum | `pending` \| `paid` \| `partial` \| `overdue` \| `cancelled` |
| `paid_amount` | decimal(12,2), default 0 | sum of allocated versements |
| *(base columns)* | | |

Indexes: `(client_project_id, installment_no)`, `(state, due_date)`.

> A schedule item's `state`/`paid_amount` is **derived** by the `AllocateVersement` Action when a
> versement is recorded against it; overdue is flipped by a scheduled sweep (queue worker) comparing
> `due_date` to today. Never let the frontend compute balances — the server is the source of truth.

## `documents` (branded, generated PDFs)

Polymorphic so any record (a versement receipt, a project contract, a quote) can own documents.

| Column | Type | Notes |
|--------|------|-------|
| `id` | PK | |
| `documentable_type` / `documentable_id` | morphs | `Versement`, `ClientProject`, … |
| `type` | enum | `receipt` \| `contract` \| `quote` \| `schedule` |
| `number` | string, unique | human document number (e.g. `REC-2026-000123`) |
| `template` | string | template key used to render |
| `disk` / `path` | string | generated PDF location (private disk) |
| `version` | int, default 1 | regeneration bumps version, keeps old |
| `generated_by` | FK → users | |
| `generated_at` | timestamp | |
| `meta` | json, nullable | snapshot of data rendered (immutability) |
| *(base columns)* | | |

Indexes: `(documentable_type, documentable_id)`, `number` unique, `type`.

> **Branded document generation** (Phase 4): a `GenerateVersementDocument` Action renders a Blade/HTML
> template to PDF (e.g. `barryvdh/laravel-dompdf` or a headless‑Chrome renderer), stores it on a private
> disk, and records the `documents` row with a stable `number`. `meta` snapshots the exact figures so a
> reprint is faithful even if related records later change. Generation runs on the **queue worker**.

## Business rules encoded here

- **Archive‑only‑without‑payments** (guide test rule): a `client_project` (or unit) may be archived
  only if it has **no active versements**. The archive Action checks this and refuses otherwise.
- **No‑delete on money:** versements are corrected via cancel‑and‑duplicate, never edited or removed.
- **Rounding:** amounts rounded half‑up to 2 dp; schedule totals must reconcile to the plan.

## Key rules to test

- Recording a versement allocates it to a schedule item and updates that item's `state`/`paid_amount`.
- Correcting a versement cancels the original and creates a linked replacement (`supersedes_id` set).
- Archiving a project with active versements is rejected; with none, it succeeds.
- Versement rounding is exact (no float drift) across a full schedule.
- Generating a receipt produces a `documents` row with a unique `number` and a private‑disk PDF.

**Next:** [`05-collaboration.md`](05-collaboration.md)
