#!/usr/bin/env bash
#
# Restore drill: load the newest dump into a throwaway database and compare it
# with the live rental_app database, then drop the scratch database.
#
#   bash scripts/restore-drill.sh
#   bash scripts/restore-drill.sh --dump ~/Documents/rental-app-backups/x.sql.gz
#   bash scripts/restore-drill.sh --strict      # any row difference fails
#   bash scripts/restore-drill.sh --keep-scratch
#
# Fails when the dump will not load, when the table set differs from live, or
# when a table cannot be counted. Row-count differences are reported as drift
# since the dump was taken (the live database keeps changing); pass --strict to
# treat them as failures instead. Run monthly by scripts/restore-drill.cmd.
set -euo pipefail

cd "$(dirname "$0")/.."

LIVE_DB="${LIVE_DB:-rental_app}"
SCRATCH_DB="${SCRATCH_DB:-${LIVE_DB}_restore_check}"
BACKUP_DIR="${BACKUP_DIR:-$HOME/Documents/rental-app-backups}"
DUMP=""
STRICT=0
KEEP_SCRATCH=0

usage() {
  cat <<'EOF'
usage: restore-drill.sh [--dump FILE] [--strict] [--keep-scratch]

  Restores a dump into a scratch database and diffs it against the live one.

  --dump FILE      dump to restore (default: newest in BACKUP_DIR)
  --strict         fail on row-count differences, not just schema ones
  --keep-scratch   leave the scratch database in place for inspection
EOF
}

while [ "$#" -gt 0 ]; do
  case "$1" in
    --dump)
      if [ "$#" -lt 2 ]; then echo "error: --dump needs a file" >&2; exit 2; fi
      DUMP="$2"; shift ;;
    --dump=*) DUMP="${1#*=}" ;;
    --strict) STRICT=1 ;;
    --keep-scratch) KEEP_SCRATCH=1 ;;
    -h|--help) usage; exit 0 ;;
    *) echo "error: unknown argument '$1'" >&2; usage >&2; exit 2 ;;
  esac
  shift
done

if [ "$SCRATCH_DB" = "$LIVE_DB" ]; then
  echo "error: SCRATCH_DB must not be the live database ($LIVE_DB)" >&2
  exit 2
fi

if [ ! -f ".env" ]; then
  echo "error: .env not found in $(pwd)" >&2
  exit 1
fi

env_value() {
  local key="$1" line
  line="$(grep -E "^${key}=" .env | tail -n 1 || true)"
  [ -n "$line" ] || return 0
  line="${line#*=}"
  line="${line%\"}"; line="${line#\"}"
  line="${line%\'}"; line="${line#\'}"
  printf '%s' "$line"
}

MYSQL="${MYSQL:-$(command -v mysql || true)}"
if [ -z "$MYSQL" ]; then
  for candidate in /c/laragon/bin/mysql/*/bin/mysql.exe; do
    if [ -x "$candidate" ]; then MYSQL="$candidate"; break; fi
  done
fi
if [ -z "$MYSQL" ]; then
  echo "error: mysql client not found; set MYSQL=/path/to/mysql" >&2
  exit 1
fi

DB_HOST="$(env_value DB_HOST)"; DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="$(env_value DB_PORT)"; DB_PORT="${DB_PORT:-3306}"
DB_USERNAME="$(env_value DB_USERNAME)"; DB_USERNAME="${DB_USERNAME:-root}"
DB_PASSWORD="$(env_value DB_PASSWORD)"

OPTIONS_FILE="$(mktemp "${TMPDIR:-/tmp}/rental-drill.XXXXXX")"
chmod 600 "$OPTIONS_FILE" 2>/dev/null || true
{
  echo "[client]"
  echo "user=\"$DB_USERNAME\""
  echo "password=\"$DB_PASSWORD\""
  echo "host=\"$DB_HOST\""
  echo "port=$DB_PORT"
} > "$OPTIONS_FILE"

q() {
  "$MYSQL" --defaults-extra-file="$OPTIONS_FILE" -N -B -e "$1" 2>&1 | tr -d '\r'
}

drop_scratch() {
  "$MYSQL" --defaults-extra-file="$OPTIONS_FILE" \
    -e "DROP DATABASE IF EXISTS \`$SCRATCH_DB\`" >/dev/null 2>&1 || true
}

cleanup() {
  if [ "$KEEP_SCRATCH" -eq 1 ]; then
    echo "kept scratch database: $SCRATCH_DB" >&2
  else
    drop_scratch
  fi
  rm -f "$OPTIONS_FILE"
}
trap cleanup EXIT

if [ -z "$DUMP" ]; then
  DUMP="$(ls -1t "$BACKUP_DIR"/*.sql.gz 2>/dev/null | head -1 || true)"
  if [ -z "$DUMP" ]; then
    echo "error: no .sql.gz dump found in $BACKUP_DIR" >&2
    exit 1
  fi
fi
if [ ! -f "$DUMP" ]; then
  echo "error: dump not found: $DUMP" >&2
  exit 1
fi

AGE_HOURS="$(awk -v now="$(date +%s)" -v then="$(date -r "$DUMP" +%s)" \
  'BEGIN { printf "%.1f", (now - then) / 3600 }')"
echo "dump: $(basename "$DUMP") (${AGE_HOURS}h old)"

echo "creating scratch database $SCRATCH_DB..."
q "DROP DATABASE IF EXISTS \`$SCRATCH_DB\`; CREATE DATABASE \`$SCRATCH_DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" >/dev/null

echo "loading dump..."
LOAD_ERRORS="$(mktemp "${TMPDIR:-/tmp}/rental-drill-load.XXXXXX")"
set +e
gzip -dc "$DUMP" | sed -E '/^CREATE DATABASE /d; /^USE /d' \
  | "$MYSQL" --defaults-extra-file="$OPTIONS_FILE" "$SCRATCH_DB" 2> "$LOAD_ERRORS"
LOAD_STATUS="${PIPESTATUS[2]}"
set -e
if [ "$LOAD_STATUS" -ne 0 ] || [ -s "$LOAD_ERRORS" ]; then
  echo "FAIL: the dump did not load cleanly (mysql exit $LOAD_STATUS)" >&2
  sed -n '1,10p' "$LOAD_ERRORS" >&2
  rm -f "$LOAD_ERRORS"
  exit 1
fi
rm -f "$LOAD_ERRORS"

tables() {
  q "SELECT table_name FROM information_schema.tables WHERE table_schema='$1' AND table_type='BASE TABLE' ORDER BY table_name"
}
columns() {
  q "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='$1'"
}

LIVE_TABLES="$(tables "$LIVE_DB")"
SCRATCH_TABLES="$(tables "$SCRATCH_DB")"
LIVE_COLUMNS="$(columns "$LIVE_DB")"
SCRATCH_COLUMNS="$(columns "$SCRATCH_DB")"

FAILURES=0
if [ "$LIVE_TABLES" != "$SCRATCH_TABLES" ]; then
  FAILURES=$((FAILURES + 1))
  echo "FAIL: table sets differ" >&2
  diff <(printf '%s\n' "$LIVE_TABLES") <(printf '%s\n' "$SCRATCH_TABLES") \
    | sed 's/^/  /' >&2
fi
if [ "$LIVE_COLUMNS" != "$SCRATCH_COLUMNS" ]; then
  FAILURES=$((FAILURES + 1))
  echo "FAIL: column count differs (live $LIVE_COLUMNS, restored $SCRATCH_COLUMNS)" >&2
fi

LIVE_ROWS=0
SCRATCH_ROWS=0
DRIFT=0
DRIFT_DETAIL=""
while IFS= read -r table_name; do
  [ -n "$table_name" ] || continue
  live_count="$(q "SELECT COUNT(*) FROM \`$LIVE_DB\`.\`$table_name\`")"
  scratch_count="$(q "SELECT COUNT(*) FROM \`$SCRATCH_DB\`.\`$table_name\`")"
  case "$live_count$scratch_count" in
    *[!0-9]*)
      FAILURES=$((FAILURES + 1))
      echo "FAIL: could not count rows in $table_name (live '$live_count', restored '$scratch_count')" >&2
      continue
      ;;
  esac
  LIVE_ROWS=$((LIVE_ROWS + live_count))
  SCRATCH_ROWS=$((SCRATCH_ROWS + scratch_count))
  if [ "$live_count" != "$scratch_count" ]; then
    DRIFT=$((DRIFT + 1))
    DRIFT_DETAIL="${DRIFT_DETAIL}  ${table_name}: live ${live_count}, restored ${scratch_count}
"
  fi
done <<< "$LIVE_TABLES"

TABLE_COUNT="$(printf '%s\n' "$LIVE_TABLES" | grep -c . || true)"
echo "tables : live $TABLE_COUNT / restored $(printf '%s\n' "$SCRATCH_TABLES" | grep -c . || true)"
echo "columns: live $LIVE_COLUMNS / restored $SCRATCH_COLUMNS"
echo "rows   : live $LIVE_ROWS / restored $SCRATCH_ROWS"

if [ "$DRIFT" -gt 0 ]; then
  if [ "$STRICT" -eq 1 ]; then
    FAILURES=$((FAILURES + 1))
    echo "FAIL: $DRIFT table(s) differ in row count" >&2
  else
    echo "note: $DRIFT table(s) differ, expected when rows changed since the dump"
  fi
  printf '%s' "$DRIFT_DETAIL" >&2
fi

if [ "$FAILURES" -gt 0 ]; then
  echo "FAILED: restore drill found $FAILURES problem(s)"
  exit 1
fi

echo "PASS: the dump restores with the same schema as $LIVE_DB"
exit 0
