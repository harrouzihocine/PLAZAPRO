#!/usr/bin/env bash
# scripts/backup-media.sh — nightly archive of uploaded files.
#
# Covers everything users upload: media galleries, generated documents, chat
# attachments/voice notes (backend/storage/app/{media,documents,chat,private,public}).
#
# Compression is zstd (LOSSLESS — photos/plans/PPTX come out bit-identical;
# "quality" is never touched). Level 6 multi-threaded: media formats are already
# internally compressed, so higher levels only burn CPU for ~0 gain.
# Retention: 14 nightly archives.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BACKUP_DIR="${PLAZA_BACKUP_DIR:-$HOME/backups/plaza}/media"
SRC="$ROOT/backend/storage/app"
KEEP=14

mkdir -p "$BACKUP_DIR"
file="$BACKUP_DIR/plaza-media-$(date +%Y%m%d-%H%M).tar.zst"

# Archive whichever upload dirs exist (fresh installs may lack some).
dirs=()
for d in media documents chat private public; do
    [[ -d "$SRC/$d" ]] && dirs+=("$d")
done
if (( ${#dirs[@]} == 0 )); then
    echo "Nothing to back up under $SRC" >&2
    exit 0
fi

tar -C "$SRC" -cf - "${dirs[@]}" | zstd -6 -q -T0 -o "$file"

if [[ ! -s "$file" ]]; then
    echo "ERROR: media backup $file is empty" >&2
    exit 1
fi

ls -1t "$BACKUP_DIR"/plaza-media-*.tar.zst 2>/dev/null | tail -n +"$((KEEP + 1))" | xargs -r rm --

echo "$(date '+%F %T') OK $file ($(du -h "$file" | cut -f1))"
