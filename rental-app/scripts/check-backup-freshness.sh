#!/usr/bin/env bash
#
# Say whether the rental-app backups are actually happening.
#
#   bash scripts/check-backup-freshness.sh
#   bash scripts/check-backup-freshness.sh --max-age-hours 30
#
# Exits 0 when the newest dump is fresh and the backup task last succeeded.
# Exits 1, listing each problem, when there are no dumps, the newest dump is
# too old, or "RentalApp DB Backup" is missing / last failed. Run daily by
# scripts/check-backup-freshness.cmd, which pops up a warning on failure.
set -euo pipefail

BACKUP_DIR="${BACKUP_DIR:-$HOME/Documents/rental-app-backups}"
OFFSITE_DIR="${OFFSITE_DIR:-$HOME/OneDrive/rental-app-backups}"
TASK_NAME="${TASK_NAME:-RentalApp DB Backup}"
MAX_AGE_HOURS="${MAX_AGE_HOURS:-30}"

usage() {
  cat <<'EOF'
usage: check-backup-freshness.sh [--max-age-hours HOURS]

  Verifies the newest dump in BACKUP_DIR is no older than HOURS (default 30)
  and that the "RentalApp DB Backup" scheduled task last finished successfully.

  --max-age-hours HOURS  allowed age of the newest dump (default 30)
EOF
}

while [ "$#" -gt 0 ]; do
  case "$1" in
    --max-age-hours)
      if [ "$#" -lt 2 ]; then
        echo "error: --max-age-hours needs a number" >&2
        exit 2
      fi
      MAX_AGE_HOURS="$2"
      shift
      ;;
    --max-age-hours=*) MAX_AGE_HOURS="${1#*=}" ;;
    -h|--help) usage; exit 0 ;;
    *) echo "error: unknown argument '$1'" >&2; usage >&2; exit 2 ;;
  esac
  shift
done

case "$MAX_AGE_HOURS" in
  ''|*[!0-9]*)
    echo "error: --max-age-hours must be a whole number of hours" >&2
    exit 2
    ;;
esac

PROBLEMS=0
problem() {
  PROBLEMS=$((PROBLEMS + 1))
  printf 'PROBLEM: %s\n' "$*"
}

NEWEST="$(ls -1t "$BACKUP_DIR"/*.gz 2>/dev/null | head -1 || true)"
if [ -z "$NEWEST" ]; then
  problem "no dumps found in $BACKUP_DIR"
else
  AGE_HOURS="$(awk -v now="$(date +%s)" -v then="$(date -r "$NEWEST" +%s)" \
    'BEGIN { printf "%.1f", (now - then) / 3600 }')"
  SIZE_KB=$(( $(wc -c < "$NEWEST") / 1024 ))
  if awk -v age="$AGE_HOURS" -v max="$MAX_AGE_HOURS" 'BEGIN { exit !(age > max) }'; then
    problem "newest dump is ${AGE_HOURS}h old (limit ${MAX_AGE_HOURS}h): $(basename "$NEWEST")"
  else
    echo "newest dump: $(basename "$NEWEST") — ${AGE_HOURS}h old, ${SIZE_KB} KB"
  fi

  OFFSITE_COPY="$OFFSITE_DIR/$(basename "$NEWEST").gpg"
  if [ -d "$OFFSITE_DIR" ]; then
    if [ -f "$OFFSITE_COPY" ]; then
      echo "off-site: $(basename "$OFFSITE_COPY") present in $OFFSITE_DIR"
    else
      problem "newest dump has no off-site encrypted copy ($OFFSITE_COPY)"
    fi
  else
    echo "note: off-site folder $OFFSITE_DIR not found; skipping the off-site check"
  fi
fi

if ! command -v schtasks >/dev/null 2>&1; then
  problem "schtasks not available; cannot check task '$TASK_NAME'"
else
  TASK_INFO="$(MSYS_NO_PATHCONV=1 schtasks /Query /TN "$TASK_NAME" /V /FO LIST 2>/dev/null | tr -d '\r' || true)"
  if [ -z "$TASK_INFO" ]; then
    problem "scheduled task '$TASK_NAME' not found"
  else
    LAST_RESULT="$(printf '%s\n' "$TASK_INFO" | grep -i '^Last Result:' | head -1 | sed 's/^[^:]*:[[:space:]]*//')"
    LAST_RUN="$(printf '%s\n' "$TASK_INFO" | grep -i '^Last Run Time:' | head -1 | sed 's/^[^:]*:[[:space:]]*//')"
    NEXT_RUN="$(printf '%s\n' "$TASK_INFO" | grep -i '^Next Run Time:' | head -1 | sed 's/^[^:]*:[[:space:]]*//')"
    if [ "$LAST_RESULT" != "0" ]; then
      problem "task '$TASK_NAME' last result was '${LAST_RESULT:-unknown}' (last run: ${LAST_RUN:-never})"
    else
      echo "task: last run ${LAST_RUN:-unknown}, result 0, next run ${NEXT_RUN:-unknown}"
    fi
  fi
fi

if [ "$PROBLEMS" -eq 0 ]; then
  echo "OK: backups are current"
  exit 0
fi

echo "FAILED: $PROBLEMS problem(s) — backups need attention"
exit 1
