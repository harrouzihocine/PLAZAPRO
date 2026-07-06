#!/usr/bin/env bash
# scripts/install-backup-cron.sh — install (or refresh) the backup crontab for
# THIS checkout. Idempotent: replaces any previous PLAZA-BACKUPS block, leaves
# unrelated crontab lines untouched.
#
# Cadence (see backup-db.sh for retention):
#   */30 07:00–18:30  DB, day period
#   hourly 19:00–06:00 DB, night period
#   21:30 nightly      media archive
#
# Logs append to $PLAZA_BACKUP_DIR/backup.log (default ~/backups/plaza/).

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BACKUP_ROOT="${PLAZA_BACKUP_DIR:-$HOME/backups/plaza}"
LOG="$BACKUP_ROOT/backup.log"
MARK_BEGIN="# >>> PLAZA-BACKUPS ($ROOT) >>>"
MARK_END="# <<< PLAZA-BACKUPS ($ROOT) <<<"

mkdir -p "$BACKUP_ROOT"

block=$(cat <<CRON
$MARK_BEGIN
# Business runs on Algeria time. TZ fixes the scripts' own date math wherever
# cron fires; CRON_TZ moves the schedule itself on crons that support it
# (harmless env var otherwise). Best set the HOST tz to Africa/Algiers too:
#   sudo timedatectl set-timezone Africa/Algiers
TZ=Africa/Algiers
CRON_TZ=Africa/Algiers
*/30 7-18 * * * PLAZA_BACKUP_DIR=$BACKUP_ROOT $ROOT/scripts/backup-db.sh >> $LOG 2>&1
0 19-23,0-6 * * * PLAZA_BACKUP_DIR=$BACKUP_ROOT $ROOT/scripts/backup-db.sh >> $LOG 2>&1
30 21 * * * PLAZA_BACKUP_DIR=$BACKUP_ROOT $ROOT/scripts/backup-media.sh >> $LOG 2>&1
$MARK_END
CRON
)

current=$(crontab -l 2>/dev/null || true)
# Strip a previous block for this checkout (exact marker lines), append the fresh one.
cleaned=$(printf '%s\n' "$current" \
    | awk -v b="$MARK_BEGIN" -v e="$MARK_END" '$0 == b {skip=1} !skip {print} $0 == e {skip=0}')
printf '%s\n%s\n' "$cleaned" "$block" | sed '/./,$!d' | crontab -

echo "Installed backup cron for $ROOT:"
crontab -l | awk -v b="$MARK_BEGIN" -v e="$MARK_END" '$0 == b {show=1} show {print} $0 == e {show=0}'
