Fresh legacy dump: database/data/crm_2026-07-13_22-00-41.sql (legacy CRM snapshot of 2026-07-13 22:00).
Goal: refresh the PLAZA database from this snapshot using our existing import tooling — but as a clean rebuild:
keep only settings/system data (users, roles, and the like), clear all business data, re-import everything
from the new dump. Rehearse the FULL procedure on a test database first; production only after my explicit
approval. Work in phases and STOP at every gate marked below.

Known facts about the new dump (verified externally — use as cross-checks, and re-verify yourself):
- Schema is byte-identical to the July 6 dump: PLAZA_MIGRATION_PLAN.md needs no mapping changes.
- Deltas vs July 6: clients +196 (=14,967), projects +193 (=11,298), call_reports +988 (=48,286),
  tasks +704 (=40,800), visit_reports +30 (=1,082), booked_estate +24 (=749, all visit-only),
  desires +60, client_interest +169, project_member +10. Unchanged: estates 192, estate_categories 14,
  bookings 19, payments 23, users 19, media 3, all lookup tables.
- Expected post-import pipeline: archive 7,774 / expected 1,866 / desires-fullfiled 1,605 / in-visit 29 /
  selling 20 / negotiating 4. Pending next_actions = 1,148 (cat1 1,138 + cat2 10). Open tasks = 3 (cat6).

════ PHASE 0 — DEEP SCAN (read-only, no writes anywhere) ════
1. Re-read PLAZA_MIGRATION_PLAN.md. Then read the import tooling AS IMPLEMENTED: config/legacy_import.php,
   the legacy_map migration, app/Services/LegacyImport/** and the legacy:import command. Note anywhere the
   implementation drifted from the plan (match rules, virtual synth keys, derivation pass, --load behavior).
2. Scan the CURRENT app schema/migrations for anything added since the plan (new tables/columns the wipe
   must account for).
3. Load the new dump into the crm_legacy staging schema (via the importer's --load or manually) and verify
   my numbers above: table list, row counts, no new enum/lookup values. Report any surprise.
4. SAFETY CHECK (critical): for every business table, count rows whose id is NOT in legacy_map (and not
   seed/demo-identifiable) — i.e. data created directly in PLAZA by real users. List them per table with
   samples. If any real PLAZA-native business data exists, STOP: wiping would destroy it, and we decide
   together per table.
5. Propose the exact table lists and WAIT for my approval:
   - KEEP (baseline, refine it): users, roles, permissions, permission_role, departments, dynamic_lists,
     dynamic_list_items, wilayas, communes, app_settings, migrations, sessions, password_reset_tokens,
     personal_access_tokens, cache, cache_locks, jobs, job_batches, failed_jobs, user_drafts.
   - WIPE (baseline): clients, client_projects, client_project_viewers, client_detail_grants,
     client_duplicate_requests, desires, desire_locations, calls, visits, next_actions, tasks, reminders,
     shortlist_items, deals, deal_items, reservations, versements, payment_schedules, locations, units,
     boxes, location_payment_methods.
   - PROPOSE with reasoning (they dangle onto wiped subjects): notifications, activity_log, conversations,
     conversation_user, messages, message_attachments, media (careful: any media uploaded via PLAZA to
     locations/units — flag before wiping rows and never touch files on disk), documents.
   - PROPOSE the legacy_map strategy: full clear is expected to be safe because users match by email,
     dynamic_list_items by (list, label), locations by name, units by (location, reference, floor) — but
     verify those match rules exist in the implemented code before asserting it.

════ PHASE 1 — BUILD THE REFRESH TOOLING (best practice: repeatable, not ad-hoc SQL) ════
Create an artisan command `legacy:refresh` (or extend legacy:import with --fresh) that performs, in order:
   a. Print the ACTIVE database connection + database name and require an interactive typed confirmation
      that names the database (e.g. "wipe plaza_test").
   b. Automatic timestamped mysqldump backup of the target DB to storage/app/backups/ before any write;
      abort if the dump file is empty or truncated.
   c. Clear the approved WIPE list in FK-safe order (DELETE or TRUNCATE with FK checks handled inside a
      controlled transaction/script). NEVER DROP tables. NEVER touch schema, migrations, models, seeders.
   d. Verify KEEP tables are untouched: row counts + CHECKSUM TABLE before/after must match exactly.
   e. Chain: legacy:import --load=crm_2026-07-13_22-00-41.sql → derivation pass → verification report +
      warnings ledger (plan §7–8).
All destructive steps behind --confirm; dry-run mode supported; everything logged.

════ PHASE 2 — TEST DB REHEARSAL ════
1. Create the test database as an EXACT CLONE OF PRODUCTION (schema + data), not of dev.
2. Run legacy:refresh against it end-to-end.
3. Verification must be green: plan §8 checks PLUS the expected numbers at the top of this prompt
   (14,967 clients / 11,298 client_projects with the stated stage distribution translated per the plan's
   stage map / 48,286 calls / 1,082 visits / 1,148 pending next_actions / 3 open tasks / 23 versements /
   21 reservations / zero orphans / KEEP tables byte-identical).
4. Idempotency proof: run legacy:import a second time on the same staging data → 100% skips.
5. Show me: the verification report, warnings ledger, before/after row-count table, and the exact command
   sequence you will replay on prod. ⛔ STOP — wait for my explicit "go prod".

════ PHASE 3 — PRODUCTION (only after my written approval) ════
1. php artisan down (maintenance mode).
2. Timestamped full prod backup; prove it is restorable by loading it into a scratch schema and comparing
   table counts. Keep it until I say otherwise.
3. Replay EXACTLY the Phase-2 command sequence — no improvisation, no manual SQL.
4. Run the full verification suite. Green → php artisan up. Any check red → STOP, restore the backup,
   report what differed between test and prod.
5. Deliver RUNBOOK.md documenting the whole procedure (commands, gates, rollback) so future weekly
   refreshes are one command + one approval.

Hard rules for the entire session: confirm the active DB connection before every destructive step; nothing
destructive without my explicit confirmation; raw query-builder only, no model events; never modify
existing app code or migrations — this work is purely additive (one command + docs).