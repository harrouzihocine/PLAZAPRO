#!/usr/bin/env bash
# scripts/backup-db.sh — MySQL backup with day/night cadence and retention.
#
# Schedule (installed by scripts/install-backup-cron.sh):
#   day   07:00–18:30  every 30 min → keep the last 25  (the full working day)
#   night 19:00–06:00  every hour   → keep the last 23  (~two nights)
# Together: every moment of the last 24h is recoverable to ≤30 min (day) / ≤1 h (night).
#
#   Usage:  backup-db.sh          cadence run (cron) — period picked by clock
#           backup-db.sh manual   pre-deploy/ad-hoc snapshot → manual/, keep 10
#
# The dump runs inside the mysql container with credentials from the container
# environment — no passwords in this script or the crontab. Compression is
# zstd -9 (lossless, ~5–10× smaller than raw SQL, fast to restore).

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BACKUP_ROOT="${PLAZA_BACKUP_DIR:-$HOME/backups/plaza}/db"

cd "$ROOT" # docker compose reads .env here (COMPOSE_FILE selects dev/prod stack)

if [[ "${1:-}" == "manual" ]]; then
    period="manual"; keep=10
else
    hour=$((10#$(date +%H)))
    if (( hour >= 7 && hour < 19 )); then period="day"; keep=25; else period="night"; keep=23; fi
fi

dir="$BACKUP_ROOT/$period"
mkdir -p "$dir"
file="$dir/plaza-$(date +%Y%m%d-%H%M).sql.zst"

# --single-transaction: consistent InnoDB snapshot without locking the app out.
docker compose exec -T mysql sh -c \
    'exec mysqldump --single-transaction --quick --routines --triggers --events \
        -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"' \
    | zstd -9 -q -T0 -o "$file"

# A dump that produced (almost) nothing is a failure, not a backup.
if [[ ! -s "$file" ]] || (( $(stat -c%s "$file") < 1024 )); then
    echo "ERROR: backup $file is missing or suspiciously small" >&2
    exit 1
fi

# Retention: newest $keep stay, the rest go.
ls -1t "$dir"/plaza-*.sql.zst 2>/dev/null | tail -n +"$((keep + 1))" | xargs -r rm --

echo "$(date '+%F %T') OK $file ($(du -h "$file" | cut -f1))"
