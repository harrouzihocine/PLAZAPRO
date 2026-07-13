# RUNBOOK — weekly legacy-CRM refresh of the PLAZA database

One command + one approval. First executed on production 2026-07-14 00:04
(deploy `bb32813`, dump `crm_2026-07-13_22-00-41.sql`, 35/35 verification
checks green, ~2 min inside the maintenance window).

The command is `php artisan legacy:refresh` (see
`app/Console/Commands/LegacyRefreshCommand.php`). It wipes the approved
business-table list and rebuilds it from a fresh legacy dump via
`legacy:import`. The approved WIPE/KEEP lists and every design decision live
in `PLAZA_MIGRATION_PLAN 2.md` (procedure) and `PLAZA_MIGRATION_PLAN.md`
(field-level mapping spec).

## What the command does (in order, all logged)

1. Prints the active DB connection and requires the typed phrase
   `wipe <database>` plus `--confirm`. `--dry-run` walks everything with zero
   writes and prints per-table wipe counts.
2. Backs up the target DB (`mariadb-dump --skip-ssl --no-tablespaces
   --single-transaction`) to `storage/app/backups/refresh-<db>-<ts>.sql`;
   aborts unless the `Dump completed` trailer is present.
3. Archives every PLAZA-native row of the wiped tables (rows NOT in
   `legacy_map`) to `storage/app/legacy-import/native-archive-<ts>.json` —
   the manual re-entry source if staff created real data since the last dump.
4. Snapshots KEEP tables (row count + `CHECKSUM TABLE`).
5. Wipes in ONE transaction (DELETE only, FK checks off): the approved list,
   plus partial rules — `media` keeps `website_space` rows, `activity_log`
   keeps non-business subjects, `web_leads` survives with `location_id` /
   `unit_id` / `converted_client_id` nulled, `legacy_map` keeps entries whose
   target table is KEPT (users / wilayas / dynamic_list_items) and clears the
   rest.
6. Re-verifies the KEEP snapshot — any non-volatile drift aborts BEFORE the
   import (volatile plumbing like sessions/cache/jobs only warns).
7. Chains `legacy:import --load=<dump> --force`: staging reload → sync →
   derivation pass → §8 verification. Non-zero exit = verification failed.

## The procedure (production)

```bash
# 0. Get the fresh dump from the old server into the dev tree, then prod tree
cp ~/www/backend/database/data/crm_<DATE>.sql ~/plaza-prod/backend/database/data/

# 1. REHEARSE on an exact prod clone (dev MySQL server) — required gate
cd ~/plaza-prod && P=$(grep ^DB_PASSWORD= backend/.env | cut -d= -f2-) \
  && docker exec -i plaza-prod-mysql-1 sh -c "MYSQL_PWD='$P' mysqldump -uplaza \
     --single-transaction --quick --no-tablespaces --routines --triggers plaza" > /tmp/prod.sql
cd ~/www   # dev stack; load /tmp/prod.sql into plaza_test as root, grant to 'plaza'
echo "wipe plaza_test" | docker compose exec -T -e DB_DATABASE=plaza_test app \
  php artisan legacy:refresh --confirm --load=crm_<DATE>.sql
# idempotency proof — must be 100% skips:
docker compose exec -T -e DB_DATABASE=plaza_test app php artisan legacy:import --force

# 2. OWNER APPROVAL of the rehearsal report — do not continue without it.

# 3. Freeze production
cd ~/plaza-prod
docker compose exec -T app php artisan down
docker compose stop queue media-queue scheduler

# 4. Quiescent backup + restore-proof (load into a scratch schema on the DEV
#    server, compare all table counts — must be identical)
PLAZA_BACKUP_DIR=$HOME/backups/plaza-prod ./scripts/backup-db.sh manual

# 5. The refresh (typed phrase: "wipe plaza")
docker compose exec app php artisan legacy:refresh --confirm --load=crm_<DATE>.sql

# 6. GREEN → restore service; ANY RED → rollback (below), never continue.
docker compose start queue media-queue scheduler
docker compose exec -T app php artisan up
curl -fsSk https://127.0.0.1/up && curl -fsSk https://127.0.0.1/api/v1/ping
```

## Rollback

The command's own pre-wipe backup is
`~/plaza-prod/backend/storage/app/backups/refresh-plaza-<ts>.sql`
(the `scripts/backup-db.sh manual` snapshot is the belt-and-braces copy in
`~/backups/plaza-prod/db/manual/`). To restore:

```bash
cd ~/plaza-prod   # app must be in maintenance mode, workers stopped
P=$(grep ^DB_PASSWORD= backend/.env | cut -d= -f2-)
docker exec -i plaza-prod-mysql-1 sh -c "MYSQL_PWD='$P' mysql -uplaza plaza" \
  < backend/storage/app/backups/refresh-plaza-<ts>.sql
docker compose exec -T app php artisan up && docker compose start queue media-queue scheduler
```

The dump restores every wiped table AND the pre-refresh `legacy_map`, so the
system returns exactly to the pre-refresh parallel-run state.

## Post-refresh follow-ups (every run)

- **Website re-curation**: the WIPE list includes `locations`, `units` and
  their `media` rows — every refresh resets `is_published`, covers and
  location/unit photos (files stay on disk under `storage/app/public/`; only
  DB rows are wiped). Hero/about photos on `website_space` are kept.
  Re-publish + re-upload after each refresh, or descope inventory from the
  wipe first (owner decision of 2026-07-13 was full inventory wipe).
- **Native rows**: check `native-archive-<ts>.json` for real clients staff
  created in PLAZA since the dump (2026-07-14 run: Nawel + Bassem to
  re-enter by hand; Hocine's two test clients discarded; Djamila returned
  via the dump itself).
- **Versement amounts**: legacy never stored amounts — all 23 versements are
  `0.00` with reference `LEGACY … montant à saisir`; backfill in-app.
- **KPI snapshots** were wiped; `kpi:snapshot` rebuilds nightly from now on.

## Caveats / gotchas

- **Staff emails**: after the July import the owner renamed imported accounts
  `…@gmail.com → …@plaza-pro.com`. The refresh therefore KEEPS the `users`
  map entries — a full `legacy_map` clear would re-adopt by email, miss 16
  accounts and duplicate them. If a legacy `users` row ever changes between
  dumps, the sync will overwrite the renamed email with the legacy one
  (§2.5 "legacy wins") — re-rename afterwards or pin it first.
- The old CRM deletes rows: expect small drifts in the warnings ledger
  (unattributable `booked_estate` rows, deleted estates on direct payments —
  the doc's "21 reservations" is actually 20 for exactly this reason).
- Verification expectations are computed live from the staged dump — they
  keep working for every future dump without editing checks.
- Run order matters on the machine: the dev stack hosts `crm_legacy` staging
  for rehearsals; production has its own `crm_legacy` on `plaza-prod-mysql-1`.
  Confirm `COMPOSE_PROJECT_NAME` before every command (dev=`plaza`,
  prod=`plaza-prod`).
