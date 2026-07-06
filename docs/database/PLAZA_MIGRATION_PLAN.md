# PLAZA PRO — Legacy Data Migration Plan (v2, final)

**Handoff document for the implementing agent (Claude Code).**
Source: legacy CRM dump `crm_2026-07-05.sql` (MySQL/MariaDB, 43 tables, live data Oct 2022 → Jul 2026, still in production).
Target: the current PLAZA PRO database `plaza` (50 tables, schema of 2026-07-06).
This plan was produced by loading **both real databases** side-by-side and profiling every table. Every mapping below is grounded in actual rows, actual enum values, actual unique constraints and actual morph strings found in the dumps — not in assumptions.

---

## 0. Mission & operating constraints

1. **Zero data loss.** Every legacy fact must land somewhere queryable in the new DB. When a fact has no natural column, it goes into a designated `notes` field or an `activity_log` entry — never dropped silently.
2. **Re-runnable.** The old CRM stays live during parallel-run. The importer will be executed repeatedly against fresh dumps (dropped into `database/data/`) until cutover. Re-runs must **insert new rows, update changed rows, skip unchanged rows, and never delete or duplicate.**
3. **Never destructive.** The importer never deletes target rows (PLAZA's zero-deletion philosophy) and never mutates rows it did not create, except via explicit match rules defined below (e.g. pre-existing `PERLA` location).
4. **Runs inside the Laravel app** as an Artisan command (`php artisan legacy:import`) using a second DB connection — so it benefits from models being *bypassed* (raw query builder writes; we do NOT fire model events, observers, notifications, or activity logging during import) while still living in the repo, versioned and testable.
5. Timezone: legacy stores bare `date`s; the app runs **Africa/Algiers**. All legacy dates become timestamps at `00:00:00 Africa/Algiers` unless stated.

---

## 1. The two databases in one breath — and the naming trap

| Concept | Legacy table | New table |
|---|---|---|
| A residence / development (Aqua, PERLA, EL MOURDJAN…) | `estate_categories` (14) | `locations` |
| A sellable apartment / local | `estates` (192) | `units` |
| **A client's buying journey / pipeline card** | **`projects` (11,105)** | **`client_projects`** |
| A person | `clients` (14,771) | `clients` |
| Phone call log | `call_reports` (47,298) | `calls` |
| Visit log | `visit_reports` (1,052) | `visits` |
| Planned next step | `tasks` cat 1–2 (40,050) | `next_actions` |
| Booking / deposit hold | `booking_reports` (19) + `booked_estate` (with `booking_report_id`) | `reservations` (+ synthesized `deals`/`deal_items`) |
| Units shown to a client | `booked_estate` (`booking_report_id IS NULL`, 710) | `shortlist_items` |
| Payment received | `payment_reports` (23) | `versements` |
| Deadline extension | `extending_reports` (2) | `activity_log` on the reservation |
| Demand spec | `desires` (2,240) + `client_interest` (10,671) | `desires` |
| All 10+ legacy lookup tables | `sources`, `ratings`, `estate_floors`, `estate_rooms_numbers`, … | `dynamic_list_items` |

⚠️ **Legacy `projects` are NOT new `locations`.** A legacy "project" is named after the client and carries the pipeline enum. Anyone who maps `projects → locations` destroys the migration. The residences live in `estate_categories`.

Empty legacy tables (import logic specified but no rows today): `box_locals`, `client_reason`, `project_reason`, `cancel_booking_reports`, `cancel_payment_reports`, `employee_source`, `member_project`.

---

## 2. Architecture (how the importer works)

### 2.1 Pipeline

```
database/data/*.sql  ──(newest)──▶  staging DB `crm_legacy`  ──ETL──▶  DB `plaza`
        ▲                                   ▲                              │
   scp from old server            mysql load via artisan flag      legacy_map ledger
```

- `php artisan legacy:import --load` loads the newest dump from `database/data/` into the `crm_legacy` schema (drop & recreate), then syncs. `--load=file.sql` picks a specific dump. Without `--load`, it syncs from whatever is already staged.
- Second Laravel connection in `config/database.php` named **`legacy`** (same MySQL server, database `crm_legacy`, `'strict' => false` — the legacy dump contains zero-dates and enum quirks).

### 2.2 The `legacy_map` ledger (idempotency core)

New migration creates:

```
legacy_map:  id, source_table (idx), source_id (idx), target_table, target_id,
             row_hash (md5 of the transformed payload), unique(source_table, source_id)
legacy_import_runs: id, started_at, finished_at, dump_file, stats json, warnings json
```

Sync algorithm per source row:
1. Build the transformed target payload (per the maps in §5).
2. `hash = md5(json of payload)`.
3. No map entry → **insert**, record map + hash.
4. Map entry, hash differs → **update** the mapped target row (only the mapped columns — never touch columns the app may have edited outside the mapped set… see §2.5), store new hash.
5. Hash equal → **skip** (counter only).

Every legacy FK is translated through the map (`mapId('clients', 123) → new id`). A missing prerequisite mapping = ordered-run bug → abort with clear error.

**Synthesized rows** (rows we create that have no 1:1 legacy source — e.g. a deal built from a booking, an aggregate desire from `client_interest`) also go through the map using virtual source tables: `synth:deal_from_booking`, `synth:deal_from_payment`, `synth:desire_interests`, `synth:reservation_from_payment`, `synth:shortlist_from_booked`. This keeps re-runs from duplicating them.

### 2.3 Order of import (hard dependency order)

1. `system_user` (find-or-create, §4.1)
2. `wilayas` (match only) → 3. `dynamic_list_items` reconciliation (§4.3) → 4. `departments`/`roles` (match only) → 5. `users` → 6. `locations` → 7. `units` → 8. `clients` → 9. `client_projects` → 10. `desires` (+ interests aggregate) → 11. `calls` → 12. `visits` → 13. `next_actions` (from tasks cat 1–2) → 14. `tasks` (cat 3/5/6) → 15. `shortlist_items` → 16. `reservations` + `deals` + `deal_items` (from bookings) → 17. `versements` (+ deals from direct payments) → 18. `media` → 19. **derivation pass** (§6) → 20. verification report (§8).

### 2.4 Morphs — use the app's morph aliases

Confirmed from live data — two conventions coexist:
- Business morphs use **snake aliases**: `client_project`, `client`, `call`, `visit`, `unit`, `box`, `versement` (seen in `next_actions.subject_type`, `shortlist_items.shortlistable_type`, `conversations.subject_type`, `tasks.subject_type`, `documents.documentable_type`).
- `activity_log.subject_type` and `notifications.notifiable_type` use **FQCNs** like `App\Modules\Inventory\Models\Reservation`.

The importer must write aliases for business morphs and FQCNs for `activity_log`. Verify against `Relation::enforceMorphMap` in the codebase before coding; do not invent new strings.

### 2.5 What "update" may touch

On re-runs the importer updates **only the columns it maps**, and only when the *source* changed (hash diff). Columns that the new app owns after import — e.g. `units.sale_status`, `client_projects.stage`, anything users edited in PLAZA — are written **on insert and by the derivation pass**, and the derivation pass only *upgrades* facts derived from legacy transactions (details in §6). Between refreshes, **legacy wins for legacy-mapped columns**: warn Hocine that hand-edits in PLAZA to imported rows can be overwritten by the next sync if the legacy source row also changed. This is the documented parallel-run contract.

### 2.6 Command surface

```
php artisan legacy:import
    {--load= : load newest (or named) dump from database/data into crm_legacy first}
    {--only= : comma list of phases, e.g. clients,calls}
    {--dry-run : full transform + counters + warnings, zero writes}
    {--chunk=500}
```

Chunked reads (`orderBy id`), transactions per chunk, in-memory map cache preloaded per phase. 47k calls + 40k tasks ≈ 100k rows total: minutes, not hours.

---

## 3. Ground truths discovered in the data (drive the rules below)

- Phones: 99.3% stored as `tel:+213-540-78-26-88`; 1,011 phone numbers shared by 2–5 client rows; **no unique index on `clients.phone`/`phone_nsn` in the new DB** → duplicates import safely; the app's `client_duplicate_requests` workflow handles them later.
- New DB phone convention (from live rows): `phone` = `0550 111 111`, `phone_nsn` = `550111111` (9 digits, no leading zero).
- Legacy prices are in **"millions de centimes"**: `850` for a 60.78 m² F2 ⇒ ×10,000 ⇒ 8,500,000 DZD. New `units.price` demo rows are full DZD in the same magnitude. Multiplier `10000` is a config constant — confirm with Hocine before the production run.
- Legacy unit identity lives in `estates.note` (`F2/bB`, 61 distinct, 53 NULL); `estates.name` is a **FK to `estate_categories`**; `estates.direction` is 100% NULL (dead); `estate_categories.direction` is 100% `'D'` (dead default) — both dropped, noted in §5.6.
- `projects.last_status` reveals the pre-archive stage: of 7,517 archived → 7,304 `expected`, 210 `in visit`, 1 `selling`, 2 NULL.
- Every one of the 17 booking-linked payments has `estate_id = 0` (meaningless); the unit comes from `booked_estate`. The 6 recent direct payments (2025–2026) carry a real `estate_id` and `booking_report_id = NULL`. Bookings #22, #23, #38, #43 have **zero** `booked_estate` rows → their sold unit is unknown → warning ledger.
- `visit_reports.has_booked = 1` on 657 / 1,052 rows (a soft "interested/committed" flag); `has_visited` is 100% `0` (dead).
- New unique constraints that shape the import: `locations.code`, `users.email`, `users.username`, `dynamic_list_items (dynamic_list_id, value)`, `wilayas.code`, `roles.slug`, `departments.slug`, `desire_locations (desire,location)`, `location_payment_methods (location,item)`.
- The new dev DB already contains **demo/seed rows** (locations 1–14, demo clients/users) *and* two real, hand-entered locations — `PERLA` (id 16) and `EL MOURDJAN` (id 17) with 11 real units. Match rules in §5.5/§5.6 make the importer converge onto these instead of duplicating them. **Recommended:** run the production import on a freshly migrated DB seeded only with system seeders (roles, permissions, dynamic lists, wilayas/communes, admin user); the match rules make either path safe.
- The 19 legacy user emails (`plazapro0X@gmail.com`, `harrouzihocine@gmail.com`, …) do **not** overlap the 15 current PLAZA accounts → all legacy users are inserted as new accounts, bcrypt `$2y$` hashes carried verbatim (same algorithm — everyone keeps their password).

---

## 4. Global rules & shared transforms

### 4.1 System user
Find-or-create `users` row: name `Legacy Import`, email `legacy-import@plaza-pro.dz`, username `legacy-import`, role `admin` (slug match), `is_active = 0`, random password. Used **only** where the target column is NOT NULL and legacy has no author (e.g. `media.uploaded_by`).

### 4.2 Transform helpers (single `Transform` class)

- `phone(raw)`: strip everything but digits and leading `+`; `+213…`/`213…` → `0` + rest; 9 digits not starting with 0 → prefix `0`; format DZ 10-digit as `0XXX XX XX XX`? — **No: match the app.** Live rows show `0550 111 111` (4-3-3). Format 10-digit numbers as `#### ### ###`; return `['phone' => formatted, 'nsn' => digits[1..9] when 10-digit DZ else NULL]`. Non-DZ (`+33…`) → keep `+E164` raw, `nsn = NULL`.
- `priceDa(x)`: `x === null ? null : round(x * config('legacy_import.price_multiplier'), 2)` — multiplier default **10000**.
- `splitName(name)`: trim/collapse spaces; first token → `first_name`, remainder → `last_name` (NULL when single token). Arabic names split the same way; full original always preserved in `clients.notes` line `Nom (legacy): …` **only when** the split is lossy (3+ tokens or single token).
- `ts(date)`: `date 00:00:00` Africa/Algiers; NULL-safe. `created_at`/`updated_at` are copied from legacy when present, else the business date.
- `noteBlock(lines[])`: joins non-empty `label: value` lines with `\n`, prefixed by `— Legacy #<id> —` on its own first line. Every table that receives displaced facts uses this.

### 4.3 Dynamic-list reconciliation (the lookup backbone)

One generic resolver: `dli(listKey, legacyLabel) → item id`, resolving by **normalized label** (trim, collapse spaces, casefold, strip Arabic diacritics) against `dynamic_list_items` of the list with `dynamic_lists.key = listKey`; on miss → **create** the item (`label` = original legacy label, `value` = ASCII slug, unique-suffixed on collision, `is_active = 1`, `meta = {"legacy": true}`). Cache per list. Never create when an **explicit pin** below exists.

Pinned maps (verified against the live 129 items — pins are by `value`, not id, so they survive reseeding):

| Legacy | → list `key` | → item `value` |
|---|---|---|
| ratings ⭐⭐⭐ / ⭐⭐ / ⭐ | `client_ratings` | `hot` / `warm` / `cold` |
| source `Oued Kniss` | `sources` | `ouedkniss` |
| source `Facebook Page` | `sources` | `facebook` |
| source `Instagram` / `TikTok` | `sources` | `instagram` / `tiktok` |
| sources `Market Place`, `مصدر خارجي`, `Youtube`, `FaceBook Group`, `Archive` | `sources` | *create* (keep distinct; do not fold Facebook Group into Facebook) |
| floors `RDC`, `1`…`9` | `floors` | `ground`, `floor_1`…`floor_9` |
| floors `10`, `11` | `floors` | *create* `10th Floor`/`floor_10`, `11th Floor`/`floor_11` |
| floors `En s1`, `En s2` | `floors` | *create* `Sous-sol 1`/`sous_sol_1`, `Sous-sol 2`/`sous_sol_2` |
| rooms `Studio`,`F2`,`F3`,`F4`,`F5`,`Dublex` | `room_numbers` | `studio`,`f2`,`f3`,`f4`,`f5`,`dublex` |
| rooms `F2 + T`,`F3 + T`,`F4 + T` | `room_numbers` | `f2_t`,`f3_t`,`f4_t` |
| rooms `F6`,`Tréplex`,`F2+C`,`F3+C`,`F4+C`,`LOCO`,`LOCO DUBLX` | `room_numbers` | *create* (⚠️ `+C` ≠ `+T`; never merge) |
| residence types `إقامة مفتوحة` / `إقامة مغلقة` / `اقامة شبه مغلقة` | `project_types` | `akam_mftoh` / `akam_mghlk` / `akam_shbh_mghlk` (already exist, ids 94–96) |
| contract types `حصة في الأرض`/`دفتر عقاري`/`وعد بالبيع`/`بيع على التصميم` | `contract_types` | `hs_fy_alard`/`dftr_aakary`/`oaad_balbyaa`/`byaa_aal_altsmym` (exist, 97–100) |
| residence payment methods (4 Arabic) | `project_payment_methods` | `alkrd_albnky_ghyr_mtofr`/`alkrd_albnky_mtofr`/`amkany_altksyt`/`aldfaa_kash` (exist, 105–108) |
| payment `دفع كاش` | `payment_methods` | `cash` |
| payment `عربون` | `payment_methods` | *create* `عربون (acompte)`/`arboun` |
| payment `50% دفع` | `payment_methods` | *create* `Non précisé (legacy)`/`legacy_unspecified` |
| reasons `type='archive'` (5 Arabic rows) | `archive_reasons` | *create*, `meta {"legacy":true}` |
| reasons `type='desire'` (6 rows) | `archive_reasons` | *create*, `meta {"legacy":true,"legacy_type":"desire"}` |
| categories (call/visit/…) | — | not a list: drives `next_actions.type` / `tasks` routing (§5.11) |

Legacy `estate_delivery_methods` (`جاهزة` / `نصف جاهزة` / `جاهزة بنسبة 90%`) have no new home → written into `locations.description` (§5.5), not into any list.

### 4.4 Geography

- `wilayas`: **match only**, by `code` (legacy tinyint ↔ new varchar, compare as int). Never create; unmatched → warn.
- `communes`: legacy has none as data, but legacy `estate_locations` are Algiers commune names in Arabic. Fixed map (config, match by new `communes.name`, wilaya 16): `برج الكيفان→Bordj El Kiffan`, `دالي ابراهيم→Dely Ibrahim`, `برج البحري→Bordj El Bahri`, `باب الزوار→Bab Ezzouar`, `قايدي→Bordj El Kiffan` *(Kaïdi is a BEK neighbourhood)*, `شراقة→Cheraga`, `المرادية→El Mouradia`, `عين طاية→Ain Taya`, `درارية→Draria`, `بير خادم→Birkhadem`, `واد السمار→Oued Smar`, `العاشور→El Achour`, `اماكن اخرى→NULL`. All verified present in the new `communes` table.

---

## 5. Table-by-table mapping specifications

Notation: `←` legacy column. `map(t,id)` = translate through `legacy_map`. Unlisted target columns: leave NULL / DB default. All rows get `status='active'` unless stated. `created_at`/`updated_at` ← legacy same columns (fallback: the business date, else now).

### 5.1 `users` ← `users` (19)
Match by **email** (unique) before insert — protects against overlap with existing accounts.
`name`←name · `email`←email · `username` = email local-part, lowercased, unique-suffixed (`hocine`, `hocine2`) · `password`←password **verbatim** (bcrypt `$2y$` — compatible) · `phone`←`Transform::phone` · `is_active` = `is_active && !leave` (legacy `leave`=departed) · `role_id` by slug: legacy role `admin`→`admin`, `semi-admin`→`manager`, `employee`→`sales-agent`, `x`/unknown→`sales-agent` + warn (roles/permissions themselves are **never** imported — the new RBAC is authoritative; legacy role `x` and dept `x` are junk rows, skipped) · `department_id` by slug: `admins`→`administration`, `المسؤولين`→`direction`, `فريق الاتصال`→`ventes`, `فريق الزيارات`→`ventes`, `قسم الحجوزات`→`finance`, else NULL.

### 5.2 `clients` ← `clients` (14,771)
`first_name`/`last_name` = `splitName(name)` · `phone`+`phone_nsn` = `Transform::phone(phone1)` (phone1 is 100% non-empty — verified) · `source_id` = `dli('sources', …)` · `rating_id` = pinned map · `assigned_agent_id` = map(users, assigned_to) · `created_by` = same · `notes` = noteBlock: `Tél 2: <phone(phone2)>` when phone2, `Wilaya: <name>` when wilaya_id (new clients have no wilaya column), `Nom (legacy): <name>` when split lossy.
**Duplicates:** import all (no unique on phone). After the phase, emit `storage/app/legacy-import/duplicate-clients.csv` (nsn, count, new ids, names) — resolved later inside PLAZA via `client_duplicate_requests`. `merge_duplicate_clients` config flag exists but stays **false** (if ever enabled: keep the oldest row canonical, alias newer legacy ids to it in `legacy_map`, do not create extra rows).

### 5.3 `wilayas` — match-only (§4.4). ### 5.4 dynamic lists — §4.3.

### 5.5 `locations` ← `estate_categories` (14)
Match order: `legacy_map` → **normalized name** against existing `locations` (case/space-insensitive; converges onto hand-entered `PERLA`, `EL MOURDJAN` instead of duplicating) → create.
`name`←name · `code`: keep existing on match; else uppercase ASCII slug of name (`AQUA`, `EL-YASSAMINE-2`), unique-suffixed (`locations.code` is UNIQUE) · `type_id` = dli(`project_types`, residence_type label) · `contract_type_id` = dli(`contract_types`, …) · `commune_id`+`wilaya_id` via §4.4 from `estate_locations` (wilaya 16 when commune found) · `address` = raw Arabic location label · `description` = legacy description + noteBlock(`Livraison: <delivery method>`, `Boxes: oui/non` when has_box, `Locaux: oui/non` when has_local, `Plan (legacy): <map>` when map path) · pivot `location_payment_methods` find-or-insert (unique pair) with dli(`project_payment_methods`, payment_method label) · `gtm_priority`='medium'. Dead: `direction` (100% `'D'`).
On **match** of a hand-entered location: only fill NULL columns + add pivot; never overwrite Hocine's values (one-way enrich; note this exception to §2.5 in code).

### 5.6 `units` ← `estates` (192)
Match order: `legacy_map` → `(location_id, lower(reference), floor_id)` against pre-existing units (PERLA's 11) → create.
`location_id` = map(estate_categories, name — **name is the category FK**) · `reference` = trim(note) when present else `<rooms label>-<legacy id>` (e.g. `F2-297`; no unique constraint, duplicates like `F2/A` on several floors are fine — the live app itself has duplicate references) · `room_number_id` = dli(`room_numbers`, rooms label) · `floor_id` = dli(`floors`, floor label) · `stack_floor` = int(floor label) with `RDC→0`, `En s1→-1`, `En s2→-2` · `position` = 1-based sequence within (location, floor) ordered by legacy id · `block` = substring of note after `/` when it matches `X/Y`, uppercased, else NULL · `area_sqm`←area · `price` = priceDa(price) (NOT NULL: fallback `0.00` + warn — none needed today, 0 NULL prices verified) · `sale_status` = `'available'` on insert; the derivation pass (§6) owns it afterwards · `gtm_priority`='medium'.
Keep `is_available` in the transform payload (hash) so legacy toggles trigger re-derivation.

### 5.7 `boxes` ← `box_locals` (0 today; future-proof)
`box_or_local='box'` → `boxes`: `location_id` = map(name FK) · `reference` = `BOX-<number>` · `price` = priceDa · `type_id` = dli(`box_types`,'Parking') default · area → no column: prepend to reference? **No** — put `Surface: X m²` in nothing… boxes have no notes: append ` (X m²)` to `reference`. `box_or_local='local'` → `units` with `room_number_id` = dli(`room_numbers`,'LOCO'), reference `LOCAL-<number>`, note→reference rule as units.

### 5.8 `client_projects` ← `projects` (11,105)
`client_id` = map · `created_by` = map(users, created_by) · `created_at`←created_at (fallback start_date) · `hidden_from_owner`=0 · **stage & status**:

| legacy `status` | → `stage` | → `status` | extras |
|---|---|---|---|
| `expected` | `lead` | `active` | |
| `in visit` | `lead` | `active` | the visit rows carry the signal |
| `negotiating` | `negotiating` | `active` | |
| `selling` | `reserved` | `active` | §6 upgrades to `won` when a payment/won deal exists |
| `desires fullfiled` *(sic — waiting list)* | `lead` | `active` | `closed_to_desire_at` ← updated_at |
| `archive` | stageMap(`last_status` ?? `expected`) but **`lost` when that resolves to `lead`** | `archived` | `archive_reason_id` = archive_reasons item `other` |

Rationale: 7,304 of the archived were `expected` — dead leads → funnel-wise `lost`; the 210 archived `in visit` → also `lost`; the 1 archived `selling` → `reserved`+`archived` (leave for §6/manual). Config flag `archived_lead_stage` = `lost` (alternative `lead`) so Hocine can flip.
`location_id`/`unit_id`/`total_price`: NULL/0 on insert — §6 sets them from transactions.

### 5.9 `desires` ← `desires` (2,240) + interests aggregate
Per legacy desire row: `client_id` = map · `client_project_id` = the client's most recent **active** mapped project, else most recent, else NULL · `type_id` = dli(`project_types`, residence_type) · `room_number_id` = dli(`room_numbers`, rooms_number) · `floor_id` = dli(`floors`, floor); `floor_pref` = raw label when unmapped · `contract_type_id` = dli(`contract_types`, …) · `budget_max` = priceDa(price) · `area_min`←area · `commune_id` when the free-text `location` matches the §4.4 table or a commune name; always keep raw in notes · `notes` = noteBlock(`Lieu souhaité: …`, `Livraison: <delivery_method>`, `Paiement: <payment_method>`).
**`client_interest` (10,671 rows / 9,066 clients — client × room-type):** one synthetic desire per client (virtual source `synth:desire_interests` / client_id): `room_number_id` set when exactly one interest, `notes` = `Intérêts (legacy): F2, F3+C, …`, same project attachment. Do not explode into one desire per interest.

### 5.10 `calls` ← `call_reports` (47,298) and `visits` ← `visit_reports` (1,052)
Calls: `client_id` = client of the mapped project (calls.client_id is NOT NULL) · `client_project_id` = map · `agent_id` = map(created_by) · `direction`='outbound' (call-team CRM) · `called_at` = ts(call_date) · `notes`←description (raw Darija — leave verbatim) · `outcome_id`/`topics`/`objections` = NULL.
Visits: same client/project/agent pattern · `type` = config `legacy_visit_type` default **`office`** (units were *presented* at these visits — matches office-visit semantics and lets `shortlist_items.office_visit_id` attribution work; Hocine can flip to `in_site`) · `scheduled_at` = `completed_at` = ts(visit_date) · `outcome_id` = `visit_outcomes:interested` when `has_booked=1` else NULL (657/1,052 carry the flag; `has_visited` is dead) · `notes`←description.

### 5.11 `next_actions` ← `tasks` cat 1–2 (40,050) and `tasks` ← `tasks` cat 3/5/6 (46)
Legacy tasks ARE the materialized next-action pipeline (each report spawns a dated task of the next category).
Cat 1 (call) / cat 2 (visit) → `next_actions`: `subject_type`='client_project', `subject_id` = map(project_id) · `type`: 1→`call`, 2→`office_visit` (config `legacy_visit_type` keeps this consistent with §5.10) · `due_at` = ts(due_date) (NOT NULL — satisfied) · `assigned_to` = map · `state` = is_complete ? `done` : `pending` · `completed_at`←completed_at · `source_*` = NULL (the spawning report is not recoverable reliably — reports point at the task they *fulfil*, not the one they create).
Cat 3 booking / 5 extending / 6 payment → **`tasks`** (no such next_action types): `title` = `Legacy: <category name> / <arabe_name>` · `subject` morph = client_project · `assigned_to` map · `due_at` = ts(due_date) · `priority`='normal' · `state` = is_complete ? `done` : `open` (⚠️ verify the Task state enum in `app/Modules/Pipeline/Enums`; live data only shows `open`).
`assigned_by` → no column: skip (recoverable from dump if ever needed). Reports' `task_id`/`next_action` columns: redundant with the task rows — not migrated.

### 5.12 Bookings → `reservations` + `deals` + `deal_items` (19 bookings, 15 booked units)
For each `booking_reports` row B (project P, `booked_estate` rows with `booking_report_id=B.id` = its units; linked payments = `payment_reports.booking_report_id=B.id`):
- Per booked unit → **reservation**: `unit_id` = map(estate) · `client_project_id` = map(P) · `held_by` = map(B.created_by) · `held_at` = ts(booking_date) · `expires_at` = NULL · `hold_status`: B accepted → `converted` (all 17 accepted have a payment — verified); B pending → `expired` (both pendings are from 2024, long stale). Source key = the `booked_estate` row id.
- B accepted → **deal** (`synth:deal_from_booking`/B.id): `client_project_id` = map(P) · `state`='won' · `created_by` = map · `notes` = `Réservation legacy #B.id du <date>` · **deal_items** per unit: `state`='won', `agreed_price` = unit price, `closed_at` = payment date; `total_price` = Σ agreed. If B has **zero** booked units (#22 #23 #38 #43): create the deal with no items + ⚠️ warning "booking without unit — fix in app".
- `extending_reports` (2) → `activity_log`: `subject_type` = FQCN `App\Modules\Inventory\Models\Reservation`, `subject_id` = the reservation, `user_id` = map(created_by), `action`='legacy.reservation_extended', `changes` = `{"extending_date":…,"description":…}`, `created_at` preserved.
- `booked_estate` **with** booking also gets a `shortlist_items` row (`synth:shortlist_from_booked`/row id): state `won` (converted) / `lost` (expired pending) — keeps the app's shortlist→deal narrative coherent.

### 5.13 `shortlist_items` ← `booked_estate` where `booking_report_id IS NULL` (710)
`client_project_id` = project of the mapped visit · `shortlistable_type`='unit', `shortlistable_id` = map(estate) · `office_visit_id` = map(visit_reports, visit_report_id) · `state`='shortlisted' on insert; §6 upgrades to `won`/`lost` when the same (project, unit) appears in a closed deal item · `note` = NULL. `box_local_id` always NULL in data; if future dumps fill it → shortlistable 'box'.

### 5.14 `versements` ← `payment_reports` (23) (+ deals for the 6 direct ones)
`client_project_id` = map(project_id) · `unit_id`: booking-linked → the booking's single unit (NULL + ⚠️ when the booking had none); direct → map(estate_id) · `amount` = **0.00** + `reference` = `LEGACY <method> — montant à saisir` (legacy v1 never stored amounts; `amount` is NOT NULL; ledger lists all 23 for manual backfill in-app) · `paid_on` = payment_date · `method_id` = §4.3 pins (`cash` / `arboun` / `legacy_unspecified`) · `recorded_by` = map(created_by) · `schedule_item_id`/`document_id` = NULL (no legacy schedules; `payment_schedules` receives **nothing**) · description → `activity_log` (FQCN `…Payments\Models\Versement`, action `legacy.payment_note`, changes `{description}`).
Direct payments (booking NULL, estate set — the 6 of 2025-26) additionally synthesize, keyed on the payment id: a `converted` reservation (`held_at`=payment_date, `held_by`=recorded_by) and a won deal + item as §5.12 (`synth:deal_from_payment`).
`cancel_booking_reports`/`cancel_payment_reports` (empty): if future dumps contain rows → set that reservation `hold_status='released'` / create a superseding cancelled versement row (`supersedes_id` chain) + warn, mirroring the app's cancel-and-duplicate pattern.

### 5.15 `media` ← `media` (3 rows, Spatie, all photos of EstateCategory #70)
`mediable_type`='location' (alias), `mediable_id` = map · `collection`='photos', `type`='photo' (⚠️ verify Media enums) · `disk`='public' · `path` = `legacy/<legacy media id>/<file_name>` · `original_name`←name · `mime_type`, `size_bytes`←size · `sort_order`←order_column · `uploaded_by` = system user · `version`=1.
**Files are not in the SQL dump.** One-time rsync from the old server: `storage/app/public/<media id>/<file_name>` → new `storage/app/public/legacy/<media id>/…`, then re-generate previews via the app's pipeline (or set `preview_status` per its enum). The importer only writes rows and verifies file existence (warn when missing).

---

## 6. Derivation pass (after all phases, every run)

Order matters; each step only *upgrades* — never downgrades — and only touches rows whose inputs are legacy-mapped:
1. **Units:** `sale_status` = `sold` when a mapped won deal_item or versement targets it → else `reserved` when a mapped reservation `hold_status IN (active)` → else `available` when legacy `is_available=1` → else config `legacy_unavailable_means` (**default `sold`**, alternative `available`): 4 years of sales vs 23 recorded payments means `is_available=0` almost always = sold in reality; every unit set sold by this fallback goes to the review ledger.
2. **Client projects with a won deal:** `stage`='won', `unit_id` = the deal's unit (single) / first, `location_id` = that unit's location, `total_price` = deal total.
3. **Shortlist upgrade** (§5.13) from closed deal items on the same (project, unit).
4. Projects in `reserved` stage whose only reservation `expired` → leave `reserved` + ledger (human decision).

---

## 7. Warning ledger (per-run JSON + printed table)

Written to `legacy_import_runs.warnings` and `storage/app/legacy-import/report-<run>.md`: bookings without units (4) · versements needing amounts (23) · units force-marked sold by fallback · unmapped lookup labels auto-created · unmatched wilayas · phone parse failures/foreign numbers · duplicate-clients CSV pointer · missing media files · users mapped with junk role `x`.

## 8. Verification checklist (run after every sync; ship as `--verify` or a md report)

- Counts: legacy vs `legacy_map` per source table vs target-table deltas — all equal.
- Pipeline snapshot equality, transformed: legacy `expected 1,989 / in visit 29 / negotiating 4 / selling 20 / desires fullfiled 1,546 / archive 7,517` must land as `lead(active) = 1,989+29+1,546 (of which closed_to_desire_at IS NOT NULL = 1,546)`, `negotiating = 4`, `reserved+won = 20+1`, `archived = 7,517`.
- Open next_actions = 1,276 (cat 1: 1,265 + cat 2: 11); open tasks = 4 (cat 6).
- Reservations = 15 unit-holds from bookings + 6 synthesized = 21; converted = 19; expired = 2. Deals won = 15 + 6 = 21 minus… *(count exactly: 17 accepted bookings → 17 deals; 6 direct-payment deals; total 23 won deals, 4 item-less)*. Versements = 23.
- Zero orphans: every new FK written by the importer resolves; every `legacy_map.target_id` exists.
- Spot-check 5 named clients end-to-end (client → project → calls/visits/next_actions → shortlist → deal → reservation → versement) against the old UI.

## 9. Locked decisions & flags for Hocine (config `legacy_import.php`)

`price_multiplier = 10000` (**confirm before prod**) · `legacy_visit_type = office` · `archived_lead_stage = lost` · `legacy_unavailable_means = sold` · `merge_duplicate_clients = false` · staging db `crm_legacy` · dumps dir `database/data` · **not migrated by design:** legacy roles/permissions matrix, report `task_id`/`next_action` back-links, dead columns (`estates.direction`, `estate_categories.direction`, `visit_reports.has_visited`), Laravel plumbing tables. Old & new run in parallel: legacy wins for mapped columns between refreshes (§2.5).

## 10. Build order for Claude Code (with the repo open)

0. **Read first:** `Relation::enforceMorphMap` (aliases), Enums in `app/Modules/*/Enums` (Stage, SaleStatus, HoldStatus, Deal/Item/Shortlist/NextAction/Task states, Media type/collection, Visit type, Call direction) — reconcile any mismatch with §5 *before* coding; the plan's values come from live data and the schema doc.
1. `config/legacy_import.php` (flags above + pinned maps §4.3/§4.4 + phase list) · `legacy` connection in `config/database.php` (strict=false).
2. Migration: `legacy_map` + `legacy_import_runs` (§2.2).
3. Services: `ImportContext` (map cache, `mapId`, counters, warnings, dry-run), `Transform` (§4.2), `DynamicListResolver` (§4.3), `BaseImporter` (chunked hash-diff sync engine, §2.2).
4. Importers in §2.3 order — one class per phase, `map()` per §5; synthesized rows via virtual source keys (§2.2).
5. `LegacyImportCommand` (§2.6) + derivation pass (§6) + report/verify (§7–8).
6. Test loop: fresh dev DB → seed system data → `--dry-run` → real run → §8 checks → run **twice** (second run must be 100% skips) → edit one legacy row in staging, run again (exactly one update).
7. A reference skeleton of this architecture (built against assumed names, superseded by this plan's §5) exists in `plaza-legacy-import.zip` from the previous session — reuse the engine, rewrite the `map()`s.

**Acceptance:** dry-run clean on the real dump · all §8 checks green · double-run idempotent · warnings ledger produced · no model events fired · runtime < 10 min.
