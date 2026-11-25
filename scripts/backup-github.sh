#!/usr/bin/env bash
#
# Copy the newest encrypted dump into Backups/ on Screamarama/Freebuff.
#
#   bash scripts/backup-github.sh --dry-run   # build the commit, do not push
#   bash scripts/backup-github.sh
#
# The commit is built with git plumbing on top of the current remote main, so
# the app folder and the Rust demos are untouched and the push is a plain
# fast-forward. Only the newest GITHUB_KEEP copies stay in the tree (default 4);
# older ones are removed in the same commit.
#
# The repository is public. An encrypted dump pushed there stays in its public
# history even after the file is deleted, so the passphrase in
# ~/.rental-app-backup.key is the only thing protecting that data: treat the
# key accordingly, and prefer a private repo if this ever holds real customers.
set -euo pipefail

cd "$(dirname "$0")/.."

REMOTE_NAME="${GITHUB_REMOTE:-freebuff}"
REMOTE_URL="${GITHUB_URL:-https://github.com/Screamarama/Freebuff.git}"
REMOTE_BRANCH="${GITHUB_BRANCH:-main}"
PREFIX="${GITHUB_PREFIX:-Backups}"
PREFIX="${PREFIX%/}"
OFFSITE_DIR="${OFFSITE_DIR:-$HOME/OneDrive/rental-app-backups}"
KEEP="${GITHUB_KEEP:-4}"
DRY_RUN=0

usage() {
  cat <<'EOF'
usage: backup-github.sh [--dry-run]

  Pushes the newest encrypted dump to the Backups/ folder of Screamarama/Freebuff
  and keeps the newest 4 copies there.

  --dry-run  build the commit and show it, but do not push

  Env: GITHUB_URL, GITHUB_BRANCH, GITHUB_PREFIX, GITHUB_KEEP, OFFSITE_DIR
EOF
}

while [ "$#" -gt 0 ]; do
  case "$1" in
    --dry-run) DRY_RUN=1 ;;
    -h|--help) usage; exit 0 ;;
    *) echo "error: unknown argument '$1'" >&2; usage >&2; exit 2 ;;
  esac
  shift
done

case "$KEEP" in
  ''|*[!0-9]*|0)
    echo "error: GITHUB_KEEP must be a positive whole number" >&2
    exit 2
    ;;
esac

if ! git remote get-url "$REMOTE_NAME" >/dev/null 2>&1; then
  git remote add "$REMOTE_NAME" "$REMOTE_URL"
  echo "added remote '$REMOTE_NAME' -> $REMOTE_URL"
fi

UPSTREAM_REF="refs/remotes/$REMOTE_NAME/$REMOTE_BRANCH"
echo "fetching $REMOTE_NAME/$REMOTE_BRANCH..."
git fetch --quiet --no-tags "$REMOTE_NAME" "+refs/heads/$REMOTE_BRANCH:$UPSTREAM_REF"

NEWEST="$(ls -1t "$OFFSITE_DIR"/*.sql.gz.gpg 2>/dev/null | head -1 || true)"
if [ -z "$NEWEST" ]; then
  echo "error: no encrypted dump in $OFFSITE_DIR (run scripts/backup-offsite.sh first)" >&2
  exit 1
fi
NAME="$(basename "$NEWEST")"

INDEX_FILE="$(git rev-parse --git-dir)/github-backup.index"
trap 'rm -f "$INDEX_FILE"' EXIT
rm -f "$INDEX_FILE"
export GIT_INDEX_FILE="$INDEX_FILE"

git read-tree "$UPSTREAM_REF"
# A stray file called Backups would block the folder; replace it.
if [ "$(git cat-file -t "$UPSTREAM_REF:$PREFIX" 2>/dev/null || true)" = "blob" ]; then
  git update-index --force-remove "$PREFIX"
fi

EXISTING="$(git ls-tree -r --name-only "$UPSTREAM_REF" -- "$PREFIX/" 2>/dev/null || true)"
ALL_ENTRIES="$(printf '%s\n%s\n' "$EXISTING" "$PREFIX/$NAME" | grep -v '^$' | sort -u | sort -r)"
KEEP_ENTRIES="$(printf '%s\n' "$ALL_ENTRIES" | head -n "$KEEP")"
DROP_ENTRIES="$(printf '%s\n' "$ALL_ENTRIES" | tail -n "+$((KEEP + 1))")"

if [ -n "$DROP_ENTRIES" ]; then
  printf '%s\n' "$DROP_ENTRIES" | while IFS= read -r old; do
    [ -n "$old" ] || continue
    git update-index --force-remove "$old"
    echo "dropping: $old"
  done
fi

BLOB="$(git hash-object -w "$NEWEST")"
git update-index --add --cacheinfo "100644,$BLOB,$PREFIX/$NAME"
TREE="$(git write-tree)"
unset GIT_INDEX_FILE

if [ "$TREE" = "$(git rev-parse "$UPSTREAM_REF^{tree}")" ]; then
  echo "up to date: $PREFIX/$NAME is already on $REMOTE_NAME/$REMOTE_BRANCH"
  exit 0
fi

MESSAGE="Backup: $NAME

Encrypted with gpg AES256; decrypt with the key kept off this repository.
Files kept in $PREFIX/: $(printf '%s' "$KEEP_ENTRIES" | wc -l | tr -d ' ')

🤖 Generated with Codebuff
Co-Authored-By: Codebuff <noreply@codebuff.com>"

COMMIT="$(git commit-tree "$TREE" -p "$UPSTREAM_REF" -m "$MESSAGE")"

echo "publishing $PREFIX/$NAME as ${COMMIT:0:7}"
echo "kept in $PREFIX/:"
printf '%s\n' "$KEEP_ENTRIES" | sed 's/^/  /'

if [ "$DRY_RUN" -eq 1 ]; then
  git push --dry-run "$REMOTE_NAME" "$COMMIT:refs/heads/$REMOTE_BRANCH"
  echo "dry run: nothing was written to the remote"
  exit 0
fi

if ! git push "$REMOTE_NAME" "$COMMIT:refs/heads/$REMOTE_BRANCH"; then
  echo "error: push rejected; nothing local was modified, re-run to rebuild on the new tip" >&2
  exit 1
fi
git update-ref "$UPSTREAM_REF" "$COMMIT"

# Confirm the file really landed rather than trusting the push output.
git fetch --quiet --no-tags "$REMOTE_NAME" "+refs/heads/$REMOTE_BRANCH:$UPSTREAM_REF"
if ! git ls-tree -r --name-only "$UPSTREAM_REF" -- "$PREFIX/$NAME" | grep -q .; then
  echo "error: $PREFIX/$NAME is not on $REMOTE_NAME/$REMOTE_BRANCH after the push" >&2
  exit 1
fi

echo "done: $PREFIX/$NAME (${COMMIT:0:7}) on $(git remote get-url "$REMOTE_NAME") ($REMOTE_BRANCH)"
echo "note: that repository is public, so this encrypted dump is in its public history."
