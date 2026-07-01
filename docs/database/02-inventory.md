# Database · 02 — Inventory module

Locations (projects), units, boxes, the reservation hold, and polymorphic media. Built in
[`../phase-2-inventory-media.md`](../phase-2-inventory-media.md). All tables carry the base‑model
columns from [`00-schema-overview.md`](00-schema-overview.md).

> **Naming:** `locations` = real‑estate **projects/buildings/sites** (they hold units and boxes). The
> geographic *dropdown* is the `areas` dynamic list, not this table.

---

## `locations` (projects / buildings / sites)

| Column | Type | Notes |
|--------|------|-------|
| `id` | PK | |
| `name` | string | "Résidence Les Oliviers" |
| `code` | string, unique | short reference |
| `area_id` | FK → dynamic_list_items (`areas`), nullable | geographic area |
| `address` | string, nullable | |
| `description` | text, nullable | |
| `latitude` / `longitude` | decimal, nullable | optional map pin |
| *(base columns)* | | |

## `units` (apartments / lots)

| Column | Type | Notes |
|--------|------|-------|
| `id` | PK | |
| `location_id` | FK → locations | |
| `reference` | string | unit code, unique within location |
| `type_id` | FK → dynamic_list_items (`unit_types`), nullable | studio/F2/F3/duplex |
| `floor_id` | FK → dynamic_list_items (`floors`), nullable | (or `floor` int) |
| `area_sqm` | decimal(8,2), nullable | surface |
| `rooms` | tinyint, nullable | |
| `price` | decimal(12,2) | list price |
| `sale_status` | enum | `available` \| `reserved` \| `sold` (distinct from the base `status`) |
| **stacking plan** | | |
| `block` | string, nullable | building/block label |
| `stack_floor` | int, nullable | numeric floor for the visual grid |
| `position` | int, nullable | position on the floor (column in the stacking grid) |
| *(base columns)* | | uses `HasVersions` — price/status corrections cancel‑and‑duplicate |

Indexes: `location_id`, `(location_id, reference)` unique, `(sale_status, status)`, `(block, stack_floor, position)`.

> **`sale_status` vs base `status`:** `sale_status` is the *commercial* state (available/reserved/sold);
> the base `status` is the *record* state (active/cancelled). Keep them separate — a cancelled record
> is a data correction, not a sale outcome.

### The visual stacking plan

The stacking plan (Phase 2) renders units as a grid per `block`/`stack_floor`/`position`, colour‑coded
by `sale_status`. No extra table — it's a **read view** over `units` grouped by these columns.

## `boxes` (parking / storage)

| Column | Type | Notes |
|--------|------|-------|
| `id` | PK | |
| `location_id` | FK → locations | |
| `reference` | string | |
| `type_id` | FK → dynamic_list_items (`box_types`) | parking / storage |
| `price` | decimal(12,2) | |
| `sale_status` | enum | `available` \| `reserved` \| `sold` |
| `unit_id` | FK → units, nullable | optional link (box sold with a unit) |
| *(base columns)* | | |

## `reservations` (the 48‑hour hold)

Encodes the reservation‑hold rule with **48‑hour auto‑expiry**.

| Column | Type | Notes |
|--------|------|-------|
| `id` | PK | |
| `unit_id` | FK → units | |
| `client_project_id` | FK → client_projects, nullable | who it's held for |
| `held_by` | FK → users | the agent placing the hold |
| `held_at` | timestamp | when placed |
| `expires_at` | timestamp | `held_at + 48h` |
| `hold_status` | enum | `active` \| `expired` \| `converted` \| `released` |
| *(base columns)* | | |

Indexes: `unit_id`, `(hold_status, expires_at)` (the expiry sweeper queries this).

> **48h expiry** is enforced by an `ExpireReservationHolds` Action run on a schedule (queue worker),
> flipping `hold_status → expired` and the unit's `sale_status → available`. This is a **key rule to
> test**. See [`../phase-2-inventory-media.md`](../phase-2-inventory-media.md).

## `media` (polymorphic, versioned, never deleted)

Attaches at **project and unit level** (and to any future model). Photos, video, PDF, PPTX in object
storage with a CDN.

| Column | Type | Notes |
|--------|------|-------|
| `id` | PK | |
| `mediable_type` / `mediable_id` | morphs | `Location`, `Unit`, … |
| `collection` | string, nullable | "gallery", "brochure", "floorplan" |
| `type` | enum | `photo` \| `video` \| `pdf` \| `pptx` |
| `disk` | string | storage disk (private by default) |
| `path` | string | randomised path/filename |
| `original_name` | string | as uploaded (metadata only) |
| `mime_type` | string | validated server‑side |
| `size_bytes` | bigint | |
| `cdn_url` | string, nullable | public delivery URL |
| `version` | int, default 1 | superseded versions kept, never overwritten |
| `sort_order` | int, default 0 | gallery ordering |
| `uploaded_by` | FK → users | |
| *(base columns)* | | never deleted — replaced files bump `version` / cancel old |

Indexes: `(mediable_type, mediable_id)`, `(mediable_type, mediable_id, collection, sort_order)`, `type`.

> **Upload rules** (from [`../phase-0-foundations/10-security-baseline.md`](../phase-0-foundations/10-security-baseline.md)):
> validate mime + size server‑side, store on a private disk with a UUID filename, serve public media via
> CDN/signed URLs, never trust the extension. New version = new row (bump `version`, keep the old);
> "removing" media cancels the row.

## Key rules to test

- Reserving an available unit creates a `reservation` with `expires_at = held_at + 48h` and flips the
  unit to `reserved`.
- After 48h with no conversion, the sweeper expires the hold and returns the unit to `available`.
- Uploading a replacement image keeps the previous version (row not overwritten/deleted).

**Next:** [`03-clients-pipeline.md`](03-clients-pipeline.md)
