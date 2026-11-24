#!/usr/bin/env bash
#
# Dump the rental-app database into a dated file outside the repository.
#
#   bash scripts/backup-db.sh
#   BACKUP_DIR=/d/backups bash scripts/backup-db.sh
#
# Connection settings come from .env. The password is only ever written to a
# temporary option file (mode 600) that is deleted on exit, so it never shows
# up in the process list. Defaults to ~/Documents/rental-app-backups and
# refuses to write inside the repo.
#
# Dumps older than KEEP_DAYS (default 14) are pruned after a successful dump;
# --keep N overrides it and --keep 0 keeps everything. The "RentalApp DB Backup"
# scheduled task runs this nightly through scripts/backup-db.cmd.
set -euo pipefail

KEEP_DAYS="${BACKUP_KEEP_DAYS:-14}"

usage() {
  cat <<'EOF'
usage: backup-db.sh [--keep DAYS]

  Dumps the rental-app database into a dated file in BACKUP_DIR.

  --keep DAYS  remove dumps in BACKUP_DIR older than DAYS (default 14, 0 = keep all)
EOF
}

while [ "$#" -gt 0 ]; do
  case "$1" in
    --keep)
      if [ "$#" -lt 2 ]; then
        echo "error: --keep needs a number of days" >&2
        exit 2
      fi
      KEEP_DAYS="$2"
      shift
      ;;
    --keep=*) KEEP_DAYS="${1#*=}" ;;
    -h|--help) usage; exit 0 ;;
    *) echo "error: unknown argument '$1'" >&2; usage >&2; exit 2 ;;
  esac
  shift
done

case "$KEEP_DAYS" in
  ''|*[!0-9]*)
    echo "error: --keep must be a whole number of days (0 keeps everything)" >&2
    exit 2
    ;;
esac

cd "$(dirname "$0")/.."

ENV_FILE=".env"
if [ ! -f "$ENV_FILE" ]; then
  echo "error: $ENV_FILE not found in $(pwd)" >&2
  exit 1
fi

env_value() {
  local key="$1" line
  line="$(grep -E "^${key}=" "$ENV_FILE" | tail -n 1 || true)"
  [ -n "$line" ] || return 0
  line="${line#*=}"
  line="${line%\"}"; line="${line#\"}"
  line="${line%\'}"; line="${line#\'}"
  printf '%s' "$line"
}

DB_CONNECTION="$(env_value DB_CONNECTION)"; DB_CONNECTION="${DB_CONNECTION:-mysql}"
DB_DATABASE="$(env_value DB_DATABASE)"; DB_DATABASE="${DB_DATABASE:-laravel}"
BACKUP_DIR="${BACKUP_DIR:-$HOME/Documents/rental-app-backups}"
STAMP="$(date +%Y-%m-%d_%H%M%S)"

case "$BACKUP_DIR" in
  "$(pwd)"|"$(pwd)"/*)
    echo "error: BACKUP_DIR ($BACKUP_DIR) is inside the repository; choose a path outside it" >&2
    exit 1
    ;;
esac

mkdir -p "$BACKUP_DIR"

if [ "$DB_CONNECTION" = "sqlite" ]; then
  DB_FILE="$(env_value DB_DATABASE)"
  [ -n "$DB_FILE" ] || DB_FILE="database/database.sqlite"
  if [ ! -f "$DB_FILE" ]; then
    echo "error: sqlite database $DB_FILE not found" >&2
    exit 1
  fi
  OUT="$BACKUP_DIR/${DB_DATABASE}-${STAMP}.sqlite.gz"
  echo "compressing $DB_FILE..."
  gzip -9 -c "$DB_FILE" > "$OUT"
else
  MYSQLDUMP="${MYSQLDUMP:-$(command -v mysqldump || true)}"
  if [ -z "$MYSQLDUMP" ]; then
    for candidate in /c/laragon/bin/mysql/*/bin/mysqldump.exe; do
      if [ -x "$candidate" ]; then MYSQLDUMP="$candidate"; break; fi
    done
  fi
  if [ -z "$MYSQLDUMP" ]; then
    echo "error: mysqldump not found; set MYSQLDUMP=/path/to/mysqldump" >&2
    exit 1
  fi

  DB_HOST="$(env_value DB_HOST)"; DB_HOST="${DB_HOST:-127.0.0.1}"
  DB_PORT="$(env_value DB_PORT)"; DB_PORT="${DB_PORT:-3306}"
  DB_USERNAME="$(env_value DB_USERNAME)"; DB_USERNAME="${DB_USERNAME:-root}"
  DB_PASSWORD="$(env_value DB_PASSWORD)"

  OPTIONS_FILE="$(mktemp "${TMPDIR:-/tmp}/rental-app-dump.XXXXXX")"
  trap 'rm -f "$OPTIONS_FILE"' EXIT
  chmod 600 "$OPTIONS_FILE" 2>/dev/null || true
  {
    echo "[client]"
    echo "user=\"$DB_USERNAME\""
    echo "password=\"$DB_PASSWORD\""
    echo "host=\"$DB_HOST\""
    echo "port=$DB_PORT"
  } > "$OPTIONS_FILE"

  OUT="$BACKUP_DIR/${DB_DATABASE}-${STAMP}.sql.gz"
  echo "dumping $DB_DATABASE from $DB_HOST:$DB_PORT..."
  if ! "$MYSQLDUMP" --defaults-extra-file="$OPTIONS_FILE" \
        --single-transaction --quick --skip-lock-tables --no-tablespaces \
        --routines --triggers --events --default-character-set=utf8mb4 \
        --databases "$DB_DATABASE" | gzip -9 > "$OUT"; then
    rm -f "$OUT"
    echo "error: mysqldump failed; no backup was kept" >&2
    exit 1
  fi
fi

SIZE="$(wc -c < "$OUT" | tr -d ' ')"
if [ "$SIZE" -lt 1024 ]; then
  rm -f "$OUT"
  echo "error: dump is only ${SIZE} bytes; treating it as failed" >&2
  exit 1
fi
if ! gzip -t "$OUT" 2>/dev/null; then
  rm -f "$OUT"
  echo "error: $OUT is not a valid gzip file" >&2
  exit 1
fi
TABLES="$(gzip -dc "$OUT" | grep -c 'CREATE TABLE' || true)"
if [ "$TABLES" -eq 0 ]; then
  echo "warning: dump contains no CREATE TABLE statements (empty database?)" >&2
fi

echo "backup: $OUT ($((SIZE / 1024)) KB, $TABLES tables)"

# Prune only after a good dump, so a failed run never deletes older copies.
if [ "$KEEP_DAYS" -gt 0 ]; then
  PRUNED=0
  while IFS= read -r old; do
    [ -n "$old" ] || continue
    rm -f "$old"
    PRUNED=$((PRUNED + 1))
  done < <(find "$BACKUP_DIR" -maxdepth 1 -type f -name "${DB_DATABASE}-*.gz" -mtime +"$KEEP_DAYS")
  if [ "$PRUNED" -gt 0 ]; then
    echo "pruned: $PRUNED dump(s) older than $KEEP_DAYS days"
  fi
fi
