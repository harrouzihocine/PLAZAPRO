# Phase 2 — Inventory & Media

**Ships:** locations, units and boxes; photo/video/PDF/PPTX media; the **visual stacking plan** and the
**48‑hour reservation hold**. Schema: [`database/02-inventory.md`](database/02-inventory.md).

Build as vertical slices. This phase introduces the media pipeline and the first timed business rule.

---

## Slices in this phase

### 1. Locations (projects)
- **API:** `/api/v1/locations` CRUD (create/read/update/cancel); `area_id` from the `areas` dynamic list.
- **UI:** locations list + detail; media gallery tab.
- **Permissions:** `units.manage` (or a dedicated `locations.manage`).

### 2. Units
- **API:** `/api/v1/units` CRUD + filters (location, type, floor, `sale_status`, price range).
- **Rules:** `price`/`sale_status` corrections use `HasVersions` (cancel‑and‑duplicate); stacking
  coordinates (`block`, `stack_floor`, `position`) set here.
- **UI:** units table with filters; unit detail (specs, media, reservation state).
- **Permissions:** `units.view`, `units.manage`.

### 3. Boxes (parking/storage)
- **API:** `/api/v1/boxes` CRUD; optional link to a `unit_id`.
- **UI:** boxes list under a location. **Permissions:** `units.manage`.

### 4. Media pipeline (foundation feature)
- **API:** `POST /api/v1/{location|unit}/{id}/media` (upload), list, reorder, replace (new version),
  cancel. Polymorphic `mediable`.
- **Rules (from [`phase-0-foundations/10-security-baseline.md`](phase-0-foundations/10-security-baseline.md)):**
  validate mime + size server‑side; private disk; UUID filenames; serve via signed URL/CDN; **replacing
  a file bumps `version` and keeps the old**; "removing" cancels the row — media is never deleted.
- **UI:** drag‑drop uploader, gallery with reorder, PDF/PPTX preview, video player, mobile‑friendly viewer.
- **Permissions:** `media.manage`.

### 5. Visual stacking plan
- **API:** `GET /api/v1/locations/{id}/stacking` → units grouped by `block`/`stack_floor`/`position`,
  each with `sale_status`.
- **UI:** a grid (floors × positions) colour‑coded by `sale_status` (available/reserved/sold); tap a
  cell → unit detail / reserve. Must reflow cleanly on phone (guide §5.4).
- **Permissions:** `units.view`.

### 6. Reservation hold (48‑hour auto‑expiry) — the key rule
- **Action:** `ReserveUnit` — checks the unit is `available`, creates a `reservation` with
  `expires_at = held_at + 48h`, flips `units.sale_status → reserved`, all in a transaction; logged.
- **Action:** `ExpireReservationHolds` — scheduled (queue worker via `schedule:run`) flips expired
  holds to `expired` and units back to `available`.
- **API:** `POST /api/v1/units/{unit}/reserve` (this is the guide's canonical lifecycle example),
  `POST /api/v1/reservations/{id}/release`, `POST /api/v1/reservations/{id}/convert`.
- **UI:** Reserve button (a few taps, per §5.4) with a live countdown to `expires_at`.
- **Permissions:** `units.reserve`.

### Scheduler wiring

```php
// routes/console.php (or app/Console) — runs on the queue/scheduler
Schedule::command('holds:expire')->everyFiveMinutes();   // ExpireReservationHolds
```

Ensure a scheduler is running (a `schedule:work` process, or cron calling `schedule:run`, inside the
container). Document which in [`phase-7-hardening-launch.md`](phase-7-hardening-launch.md).

---

## Key rules to test

- [ ] Reserving an available unit sets `expires_at = held_at + 48h` and flips it to `reserved`.
- [ ] After 48h with no conversion, the sweeper expires the hold and returns the unit to `available`.
- [ ] Reserving an already‑reserved/sold unit is rejected.
- [ ] Replacing media keeps the previous version (row not overwritten/deleted).
- [ ] The stacking plan reflects live `sale_status` and reflows on mobile.

## Definition of Done (gate)

End to end on phone + desktop, both themes; `units.*`/`media.manage` permissions enforced; every action
logged; feature tests for the 48‑hour expiry and media versioning pass; Pint/ESLint clean; reviewed PR.

**Next:** [`phase-3-clients-pipeline.md`](phase-3-clients-pipeline.md)
