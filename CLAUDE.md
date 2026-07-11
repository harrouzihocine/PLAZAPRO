# PLAZA PRO — agent ground rules

Real-estate CRM: Laravel (`backend/`) + Vue 3 (`frontend/`), MySQL, Docker.
**Two stacks run on this machine — always know which one you are touching:**

|                 | dev                                  | prod                                       |
| --------------- | ------------------------------------ | ------------------------------------------ |
| compose project | `plaza` (from `/home/plazapro/www`)  | `plaza-prod` (from `/home/plazapro/plaza-prod`) |
| containers      | `plaza-*`                            | `plaza-prod-*`                             |
| database        | demo/import data — safe to reset     | **REAL company data, live users**          |

## Production database — hard rules

- **NEVER** run destructive or mutating operations against the prod DB
  (`plaza-prod-mysql-1`): no `DROP`, `TRUNCATE`, `DELETE`, `UPDATE`, `INSERT`,
  no `migrate:fresh`, `migrate:rollback`, `db:wipe`, `db:seed`.
  Safe mode only: read-only access (`SELECT`, `SHOW`, `EXPLAIN`, `mysqldump`) is fine.
- Schema changes reach prod **only** via `php artisan migrate --force` inside a
  deploy the user explicitly asked for (`scripts/deploy.sh` — it snapshots the DB first).
- New permissions ship as idempotent one-shot seeders registered in
  `Database\Seeders\ProductionSeeder` (deploy.sh runs it after migrate on every
  deploy) — never as manual post-deploy steps. Follow its docblock rules
  (updateOrCreate, backfills gated on `wasRecentlyCreated`, no factories).
- Anything experimental — tests, seeders, verification scripts, data fixes you
  want to try — runs against the **dev** stack (`docker compose exec -T app …`
  from `/home/plazapro/www`, DB `plaza` on `plaza-mysql-1`) or the `plaza_test`
  database (phpunit). Never point a test at prod: it is real.
- Unsure which stack a command hits? Check `COMPOSE_PROJECT_NAME` in that
  directory's `.env` **before** running it.

## Backups — do not break these

- Cron (host crontab) dumps the **prod** DB every 30 min by day / hourly by
  night into `~/backups/plaza-prod/db/{day,night,manual,archive}`, and archives
  uploaded media nightly at 21:30. Same scheme for dev under `~/backups/plaza/`.
  Log: `~/backups/plaza-prod/backup.log`. Scripts: `scripts/backup-db.sh`,
  `scripts/backup-media.sh` (installed by `scripts/install-backup-cron.sh`).
- Never delete, rewrite, or "clean up" anything under `~/backups/` — retention
  is handled by the scripts themselves.
- Before any deploy or risky migration, take a manual snapshot:
  `scripts/backup-db.sh manual`.

## Working habits

- `git add` explicit paths only — parallel agent sessions leave in-flight work
  in this tree; never `git add -A` / `git add .`.
- Frontend must use SweetAlert2 (`useConfirm` composable) — never native
  `alert`/`confirm`/`prompt`.
- App timezone is Africa/Algiers end-to-end; frontend date prefills use the
  local-date helpers in `frontend/src/utils/format.js`, never
  `toISOString().slice(0, 10)`.
