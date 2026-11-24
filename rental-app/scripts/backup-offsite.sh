#!/usr/bin/env bash
#
# Encrypted off-site copy of the rental-app dumps.
#
#   bash scripts/backup-offsite.sh
#   OFFSITE_DIR=/d/backups bash scripts/backup-offsite.sh
#
# Encrypts each local dump with gpg (AES256) and writes <dump>.sql.gz.gpg to
# OFFSITE_DIR, which defaults to ~/OneDrive/rental-app-backups so OneDrive
# carries it off this machine. Dumps already copied are skipped, and every copy
# is decrypted again and compared by SHA-256 before it counts as done.
#
# The passphrase lives in BACKUP_KEY_FILE (default ~/.rental-app-backup.key,
# created on first run, mode 600). It is deliberately NOT inside the sync
# folder: losing it means the off-site copies cannot be decrypted, so keep a
# copy in a password manager.
set -euo pipefail

cd "$(dirname "$0")/.."

BACKUP_DIR="${BACKUP_DIR:-$HOME/Documents/rental-app-backups}"
OFFSITE_DIR="${OFFSITE_DIR:-$HOME/OneDrive/rental-app-backups}"
KEY_FILE="${BACKUP_KEY_FILE:-$HOME/.rental-app-backup.key}"
OFFSITE_KEEP_DAYS="${OFFSITE_KEEP_DAYS:-30}"

usage() {
  cat <<'EOF'
usage: backup-offsite.sh

  Encrypts every dump in BACKUP_DIR into OFFSITE_DIR (default: the OneDrive
  folder) as <dump>.gpg, then prunes off-site copies older than 30 days.

  Env: BACKUP_DIR, OFFSITE_DIR, BACKUP_KEY_FILE, OFFSITE_KEEP_DAYS
EOF
}

case "${1:-}" in
  -h|--help) usage; exit 0 ;;
  "") ;;
  *) echo "error: unknown argument '$1'" >&2; usage >&2; exit 2 ;;
esac

if ! command -v gpg >/dev/null 2>&1; then
  echo "error: gpg not found; it ships with Git for Windows" >&2
  exit 1
fi

case "$OFFSITE_DIR" in
  "$(pwd)"|"$(pwd)"/*)
    echo "error: OFFSITE_DIR ($OFFSITE_DIR) is inside the repository" >&2
    exit 1
    ;;
esac

if [ "$OFFSITE_DIR" = "$BACKUP_DIR" ]; then
  echo "error: OFFSITE_DIR must differ from BACKUP_DIR" >&2
  exit 1
fi

if [ ! -d "$BACKUP_DIR" ]; then
  echo "error: backup folder $BACKUP_DIR not found" >&2
  exit 1
fi

if [ ! -f "$KEY_FILE" ]; then
  (umask 077; openssl rand -base64 48 > "$KEY_FILE")
  chmod 600 "$KEY_FILE" 2>/dev/null || true
  echo "generated backup key: $KEY_FILE" >&2
  echo "WARNING: keep a copy of this key in a password manager; without it the" >&2
  echo "         off-site dumps cannot be decrypted." >&2
fi
chmod 600 "$KEY_FILE" 2>/dev/null || true

mkdir -p "$OFFSITE_DIR"

encrypt() {
  gpg --batch --yes --quiet --pinentry-mode loopback \
    --passphrase-file "$KEY_FILE" \
    --symmetric --cipher-algo AES256 \
    --output "$2" "$1"
}

decrypt_to() {
  gpg --batch --quiet --pinentry-mode loopback \
    --passphrase-file "$KEY_FILE" --decrypt "$1"
}

# Decrypt the sealed copy and compare it with the original. Reading the file back
# immediately after writing it can come up short while OneDrive or an antivirus
# scanner still holds it, so retry before calling a copy broken.
# Hash from stdin: sha256sum prefixes a backslash when the filename contains one,
# which happens when the task runs with a Windows-style HOME, and that prefix
# would make two identical files look different.
hash_of() {
  sha256sum < "$1" | cut -d' ' -f1
}

verify_copy() {
  local dump="$1" target="$2" attempt plain err expected actual
  expected="$(hash_of "$dump")"
  for attempt in 1 2 3; do
    plain="$(mktemp "${TMPDIR:-/tmp}/rental-offsite.XXXXXX")"
    err="$(mktemp "${TMPDIR:-/tmp}/rental-offsite-err.XXXXXX")"
    if decrypt_to "$target" > "$plain" 2> "$err"; then
      actual="$(hash_of "$plain")"
      if [ "$actual" = "$expected" ]; then
        rm -f "$plain" "$err"
        return 0
      fi
      echo "  verify $attempt/3: mismatch (expected ${expected:0:12}, got ${actual:0:12}, $(wc -c < "$plain") bytes vs $(wc -c < "$dump"))" >&2
    else
      echo "  verify $attempt/3: gpg failed: $(head -1 "$err" 2>/dev/null)" >&2
    fi
    rm -f "$plain" "$err"
    if [ "$attempt" -lt 3 ]; then sleep 2; fi
  done
  return 1
}

COPIED=0
SKIPPED=0
shopt -s nullglob
for dump in "$BACKUP_DIR"/*.sql.gz; do
  name="$(basename "$dump")"
  target="$OFFSITE_DIR/$name.gpg"
  if [ -f "$target" ] && [ "$target" -nt "$dump" ]; then
    SKIPPED=$((SKIPPED + 1))
    continue
  fi

  if ! encrypt "$dump" "$target" 2>/dev/null; then
    rm -f "$target"
    echo "error: could not encrypt $name" >&2
    exit 1
  fi

  if ! verify_copy "$dump" "$target"; then
    rm -f "$target"
    echo "error: $name.gpg did not verify against the original (3 attempts)" >&2
    exit 1
  fi
  COPIED=$((COPIED + 1))
  echo "encrypted: $name -> $OFFSITE_DIR/$name.gpg"
done
shopt -u nullglob

if [ "$COPIED" -eq 0 ] && [ "$SKIPPED" -eq 0 ]; then
  echo "off-site: no dumps in $BACKUP_DIR to copy"
  exit 0
fi

# Off-site copies are kept longer than local ones; they are the last resort.
if [ "$OFFSITE_KEEP_DAYS" -gt 0 ]; then
  OLD_COPIES="$(find "$OFFSITE_DIR" -maxdepth 1 -type f -name '*.gpg' -mtime +"$OFFSITE_KEEP_DAYS" || true)"
  if [ -n "$OLD_COPIES" ]; then
    printf '%s\n' "$OLD_COPIES" | while IFS= read -r old; do rm -f "$old"; done
    echo "pruned: $(printf '%s\n' "$OLD_COPIES" | wc -l | tr -d ' ') off-site copy/copies older than ${OFFSITE_KEEP_DAYS} days"
  fi
fi

TOTAL="$(find "$OFFSITE_DIR" -maxdepth 1 -type f -name '*.gpg' | wc -l | tr -d ' ')"
echo "off-site: copied $COPIED, already present $SKIPPED, $TOTAL encrypted copy/copies in $OFFSITE_DIR"
