#!/usr/bin/env bash
#
# Restore drill: load dumps into a throwaway database, compare each with the live
# rental_app database, then drop the scratch database.
#
#   bash scripts/restore-drill.sh                   # local dump + off-site copy
#   bash scripts/restore-drill.sh --source local    # only the local dump
#   bash scripts/restore-drill.sh --source offsite  # only the encrypted copy
#   bash scripts/restore-drill.sh --dump FILE       # one specific dump
#   bash scripts/restore-drill.sh --strict          # row drift is a failure
#
# Off-site sources are the gpg copies in OFFSITE_DIR. They are decrypted into
# DRILL_TMP_DIR (default: a folder under %TEMP% used only by this script), which
# is mode 700 and swept at the start of every run, so a killed run cannot leave
# customer data behind; the exit trap clears it again on the way out.
#
# Fails when a source will not load, when the table set or column count differs
# from live, or when a table cannot be counted. Row-count differences are
# reported as drift since the dump was taken; --strict makes them failures.
# Run monthly by scripts/restore-drill.cmd.
set -euo pipefail

cd "$(dirname "$0")/.."

LIVE_DB="${LIVE_DB:-rental_app}"
SCRATCH_DB="${SCRATCH_DB:-${LIVE_DB}_restore_check}"
BACKUP_DIR="${BACKUP_DIR:-$HOME/Documents/rental-app-backups}"
OFFSITE_DIR="${OFFSITE_DIR:-$HOME/OneDrive/rental-app-backups}"
KEY_FILE="${BACKUP_KEY_FILE:-$HOME/.rental-app-backup.key}"
SOURCE="${SOURCE:-both}"
DUMP=""
STRICT=0
KEEP_SCRATCH=0

usage() {
  cat <<'EOF'
usage: restore-drill.sh [--source local|offsite|both] [--dump FILE]
                        [--strict] [--keep-scratch]

  Restores dump(s) into a scratch database and diffs them against the live one.

  --source WHICH   local dump, encrypted off-site copy, or both (default: both)
  --dump FILE      restore this specific local dump instead
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
    --source)
      if [ "$#" -lt 2 ]; then echo "error: --source needs local, offsite or both" >&2; exit 2; fi
      SOURCE="$2"; shift ;;
    --source=*) SOURCE="${1#*=}" ;;
    --local) SOURCE=local ;;
    --offsite) SOURCE=offsite ;;
    --strict) STRICT=1 ;;
    --keep-scratch) KEEP_SCRATCH=1 ;;
    -h|--help) usage; exit 0 ;;
    *) echo "error: unknown argument '$1'" >&2; usage >&2; exit 2 ;;
  esac
  shift
done

case "$SOURCE" in
  local|offsite|both) ;;
  *) echo "error: --source must be local, offsite or both (got '$SOURCE')" >&2; exit 2 ;;
esac

# All decrypted material lives in this one folder, so leftovers from a crashed
# or killed run are found and removed by the next run instead of accumulating.
DRILL_TMP_DIR="${DRILL_TMP_DIR:-${TMPDIR:-/tmp}/rental-app-drill}"
mkdir -p "$DRILL_TMP_DIR"
chmod 700 "$DRILL_TMP_DIR" 2>/dev/null || true
STALE_COUNT="$(find "$DRILL_TMP_DIR" -mindepth 1 -maxdepth 1 -type f 2>/dev/null | wc -l | tr -d ' ')"
find "$DRILL_TMP_DIR" -mindepth 1 -maxdepth 1 -type f -delete 2>/dev/null || true
if [ "$STALE_COUNT" -gt 0 ]; then
  echo "swept $STALE_COUNT stale temp file(s) from $DRILL_TMP_DIR"
fi

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

OPTIONS_FILE="$(mktemp "$DRILL_TMP_DIR/options.XXXXXX")"
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

TEMP_FILES=()
cleanup() {
  if [ "$KEEP_SCRATCH" -eq 1 ]; then
    echo "kept scratch database: $SCRATCH_DB" >&2
  else
    drop_scratch
  fi
  rm -f "$OPTIONS_FILE"
  if [ "${#TEMP_FILES[@]}" -gt 0 ]; then
    rm -f "${TEMP_FILES[@]}"
  fi
}
trap cleanup EXIT

TOTAL_FAILURES=0

tables() {
  q "SELECT table_name FROM information_schema.tables WHERE table_schema='$1' AND table_type='BASE TABLE' ORDER BY table_name"
}
columns() {
  q "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='$1'"
}

# drill_one LABEL ARCHIVE(.sql.gz) DISPLAY_NAME [AGE_REFERENCE]
drill_one() {
  local label="$1" archive="$2" display="$3" age_ref="${4:-$2}"
  local failures=0 age_hours load_errors load_status
  local live_tables restored_tables live_columns restored_columns
  local live_rows=0 restored_rows=0 drift=0 drift_detail=""
  local table_name live_count restored_count table_count

  age_hours="$(awk -v now="$(date +%s)" -v then="$(date -r "$age_ref" +%s)" \
    'BEGIN { printf "%.1f", (now - then) / 3600 }')"
  echo
  echo "=== $label: $display (${age_hours}h old) ==="

  echo "creating scratch database $SCRATCH_DB..."
  q "DROP DATABASE IF EXISTS \`$SCRATCH_DB\`; CREATE DATABASE \`$SCRATCH_DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" >/dev/null

  echo "loading..."
  load_errors="$(mktemp "$DRILL_TMP_DIR/load-errors.XXXXXX")"
  set +e
  gzip -dc "$archive" | sed -E '/^CREATE DATABASE /d; /^USE /d' \
    | "$MYSQL" --defaults-extra-file="$OPTIONS_FILE" "$SCRATCH_DB" 2> "$load_errors"
  load_status="${PIPESTATUS[2]}"
  set -e
  if [ "$load_status" -ne 0 ] || [ -s "$load_errors" ]; then
    echo "FAIL: $label dump did not load cleanly (mysql exit $load_status)" >&2
    sed -n '1,10p' "$load_errors" >&2
    rm -f "$load_errors"
    drop_scratch
    TOTAL_FAILURES=$((TOTAL_FAILURES + 1))
    return 0
  fi
  rm -f "$load_errors"

  live_tables="$(tables "$LIVE_DB")"
  restored_tables="$(tables "$SCRATCH_DB")"
  live_columns="$(columns "$LIVE_DB")"
  restored_columns="$(columns "$SCRATCH_DB")"

  if [ "$live_tables" != "$restored_tables" ]; then
    failures=$((failures + 1))
    echo "FAIL: table sets differ" >&2
    diff <(printf '%s\n' "$live_tables") <(printf '%s\n' "$restored_tables") \
      | sed 's/^/  /' >&2
  fi
  if [ "$live_columns" != "$restored_columns" ]; then
    failures=$((failures + 1))
    echo "FAIL: column count differs (live $live_columns, restored $restored_columns)" >&2
  fi

  while IFS= read -r table_name; do
    [ -n "$table_name" ] || continue
    live_count="$(q "SELECT COUNT(*) FROM \`$LIVE_DB\`.\`$table_name\`")"
    restored_count="$(q "SELECT COUNT(*) FROM \`$SCRATCH_DB\`.\`$table_name\`")"
    case "$live_count$restored_count" in
      *[!0-9]*)
        failures=$((failures + 1))
        echo "FAIL: could not count rows in $table_name (live '$live_count', restored '$restored_count')" >&2
        continue
        ;;
    esac
    live_rows=$((live_rows + live_count))
    restored_rows=$((restored_rows + restored_count))
    if [ "$live_count" != "$restored_count" ]; then
      drift=$((drift + 1))
      drift_detail="${drift_detail}  ${table_name}: live ${live_count}, restored ${restored_count}
"
    fi
  done <<< "$live_tables"

  table_count="$(printf '%s\n' "$live_tables" | grep -c . || true)"
  echo "tables : live $table_count / restored $(printf '%s\n' "$restored_tables" | grep -c . || true)"
  echo "columns: live $live_columns / restored $restored_columns"
  echo "rows   : live $live_rows / restored $restored_rows"

  if [ "$drift" -gt 0 ]; then
    if [ "$STRICT" -eq 1 ]; then
      failures=$((failures + 1))
      echo "FAIL: $drift table(s) differ in row count" >&2
    else
      echo "note: $drift table(s) differ, expected when rows changed since the dump"
    fi
    printf '%s' "$drift_detail" >&2
  fi

  if [ "$failures" -gt 0 ]; then
    echo "FAILED: $label found $failures problem(s)"
    TOTAL_FAILURES=$((TOTAL_FAILURES + failures))
  else
    echo "PASS: $label restores with the same schema as $LIVE_DB"
  fi
}

drill_local() {
  local newest
  newest="$(ls -1t "$BACKUP_DIR"/*.sql.gz 2>/dev/null | head -1 || true)"
  if [ -z "$newest" ]; then
    echo "FAIL: no local dump in $BACKUP_DIR" >&2
    TOTAL_FAILURES=$((TOTAL_FAILURES + 1))
    return 0
  fi
  drill_one local "$newest" "$(basename "$newest")"
}

drill_offsite() {
  local newest plain
  newest="$(ls -1t "$OFFSITE_DIR"/*.sql.gz.gpg 2>/dev/null | head -1 || true)"
  if [ -z "$newest" ]; then
    echo "FAIL: no encrypted off-site copy in $OFFSITE_DIR" >&2
    TOTAL_FAILURES=$((TOTAL_FAILURES + 1))
    return 0
  fi
  if [ ! -f "$KEY_FILE" ]; then
    echo "FAIL: encryption key $KEY_FILE not found; cannot decrypt the off-site copy" >&2
    TOTAL_FAILURES=$((TOTAL_FAILURES + 1))
    return 0
  fi

  plain="$(mktemp "$DRILL_TMP_DIR/plain.XXXXXX")"
  TEMP_FILES+=("$plain")
  if ! gpg --batch --quiet --pinentry-mode loopback --passphrase-file "$KEY_FILE" \
       --decrypt "$newest" > "$plain" 2>/dev/null; then
    echo "FAIL: could not decrypt $(basename "$newest") with $KEY_FILE" >&2
    TOTAL_FAILURES=$((TOTAL_FAILURES + 1))
    return 0
  fi
  if ! gzip -t "$plain" 2>/dev/null; then
    echo "FAIL: decrypted $(basename "$newest") is not a valid gzip dump" >&2
    TOTAL_FAILURES=$((TOTAL_FAILURES + 1))
    return 0
  fi

  drill_one offsite "$plain" "$(basename "$newest")" "$newest"
}

if [ -n "$DUMP" ]; then
  if [ ! -f "$DUMP" ]; then
    echo "error: dump not found: $DUMP" >&2
    exit 1
  fi
  drill_one local "$DUMP" "$(basename "$DUMP")"
else
  case "$SOURCE" in
    local) drill_local ;;
    offsite) drill_offsite ;;
    both) drill_local; drill_offsite ;;
  esac
fi

if [ "$TOTAL_FAILURES" -gt 0 ]; then
  echo
  echo "FAILED: restore drill found $TOTAL_FAILURES problem(s)"
  exit 1
fi

echo
echo "PASS: every source restores with the same schema as $LIVE_DB"
exit 0
