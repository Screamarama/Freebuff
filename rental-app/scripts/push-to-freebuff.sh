#!/usr/bin/env bash
#
# Publish committed HEAD of this app into the `rental-app/` folder of
# Screamarama/Freebuff without cloning the remote or exporting by hand.
#
#   bash scripts/push-to-freebuff.sh                publish HEAD
#   bash scripts/push-to-freebuff.sh --dry-run      build the commit, skip the push
#   bash scripts/push-to-freebuff.sh --allow-dirty  publish despite uncommitted changes
#
# Each publish creates one upstream commit on top of the current remote branch
# (a squash of local commits since the last publish), so the Rust demos and
# other folders are preserved. The app's real history is mirrored to a separate
# branch on every run, so the squashed snapshots are never the only copy.
# Env overrides: FREEBUFF_URL, FREEBUFF_BRANCH, FREEBUFF_PREFIX, FREEBUFF_REMOTE,
# FREEBUFF_HISTORY_REF.
set -euo pipefail

REMOTE_NAME="${FREEBUFF_REMOTE:-freebuff}"
REMOTE_URL="${FREEBUFF_URL:-https://github.com/Screamarama/Freebuff.git}"
REMOTE_BRANCH="${FREEBUFF_BRANCH:-main}"
PREFIX="${FREEBUFF_PREFIX:-rental-app}"
PREFIX="${PREFIX%/}"

DRY_RUN=0
ALLOW_DIRTY=0

usage() {
  cat <<'EOF'
usage: push-to-freebuff.sh [--dry-run] [--allow-dirty]

  Publishes committed HEAD into the rental-app/ folder of Screamarama/Freebuff.

  --dry-run      build the upstream commit but do not push it
  --allow-dirty  publish HEAD even if the working tree has uncommitted changes

Also mirrors the app's full local history to the rental-app-history branch,
so recovery does not depend on the squashed publish commits.
EOF
}

while [ "$#" -gt 0 ]; do
  case "$1" in
    --dry-run) DRY_RUN=1 ;;
    --allow-dirty) ALLOW_DIRTY=1 ;;
    -h|--help) usage; exit 0 ;;
    *) echo "error: unknown argument '$1'" >&2; usage >&2; exit 2 ;;
  esac
  shift
done

case "$PREFIX" in
  ""|/*|*..*)
    echo "error: invalid publish prefix '$PREFIX' (expected a relative folder name)" >&2
    exit 2
    ;;
esac

if ! git rev-parse --git-dir >/dev/null 2>&1; then
  echo "error: $(pwd) is not a git repository" >&2
  exit 1
fi
cd "$(git rev-parse --show-toplevel)"

if [ -n "$(git status --porcelain)" ] && [ "$ALLOW_DIRTY" -eq 0 ]; then
  echo "error: working tree has uncommitted changes; they would not be published." >&2
  echo "       commit them first, or pass --allow-dirty to publish HEAD as it is." >&2
  echo "--- uncommitted ---" >&2
  git status --short >&2
  exit 1
fi

if ! git remote get-url "$REMOTE_NAME" >/dev/null 2>&1; then
  git remote add "$REMOTE_NAME" "$REMOTE_URL"
  echo "added remote '$REMOTE_NAME' -> $REMOTE_URL"
fi

UPSTREAM_REF="refs/remotes/$REMOTE_NAME/$REMOTE_BRANCH"
LOCAL_SHA="$(git rev-parse --short HEAD)"
BASE=""
HISTORY_REF="${FREEBUFF_HISTORY_REF-rental-app-history}"

# The publish commit is a snapshot with no link back to local commits, so mirror
# HEAD (and therefore its whole ancestry) to its own branch as a real backup.
mirror_history() {
  [ -n "$HISTORY_REF" ] || return 0
  if [ "$DRY_RUN" -eq 1 ]; then
    echo "history: would mirror HEAD ($LOCAL_SHA) -> $REMOTE_NAME/$HISTORY_REF"
    return 0
  fi
  if ! git push --quiet --force "$REMOTE_NAME" "HEAD:refs/heads/$HISTORY_REF"; then
    echo "error: could not mirror local history to $REMOTE_NAME/$HISTORY_REF" >&2
    exit 1
  fi
  echo "history: mirrored $LOCAL_SHA -> $REMOTE_NAME/$HISTORY_REF"
}

echo "checking $REMOTE_NAME/$REMOTE_BRANCH..."
ref_status=0
git ls-remote --exit-code "$REMOTE_NAME" "refs/heads/$REMOTE_BRANCH" >/dev/null 2>&1 || ref_status=$?

if [ "$ref_status" -eq 0 ]; then
  git fetch --quiet --no-tags "$REMOTE_NAME" "+refs/heads/$REMOTE_BRANCH:$UPSTREAM_REF"
  BASE="$UPSTREAM_REF"
elif [ "$ref_status" -eq 2 ]; then
  echo "note: $REMOTE_NAME has no '$REMOTE_BRANCH' branch yet; the publish will create it"
else
  echo "error: cannot reach $REMOTE_NAME ($(git remote get-url "$REMOTE_NAME")); not publishing" >&2
  exit 1
fi

# Build the upstream tree in a throwaway index: remote tree + HEAD as $PREFIX/.
INDEX_FILE="$(git rev-parse --git-dir)/freebuff-publish.index"
trap 'rm -f "$INDEX_FILE"' EXIT
rm -f "$INDEX_FILE"
export GIT_INDEX_FILE="$INDEX_FILE"

if [ -n "$BASE" ]; then
  git read-tree "$BASE"
  # Clear the published folder first, so local deletions and renames propagate
  # upstream instead of surviving in the remote copy.
  git rm -r -q --cached --ignore-unmatch "$PREFIX"
else
  git read-tree --empty
fi
git read-tree --prefix="$PREFIX/" HEAD
TREE="$(git write-tree)"
unset GIT_INDEX_FILE

if [ -n "$BASE" ] && [ "$TREE" = "$(git rev-parse "$BASE^{tree}")" ]; then
  mirror_history
  echo "up to date: $PREFIX/ on $REMOTE_NAME/$REMOTE_BRANCH already matches HEAD ($LOCAL_SHA)"
  exit 0
fi

MESSAGE="Publish $PREFIX/ from local commit $LOCAL_SHA

Source: $(git log -1 --pretty='%h %s' HEAD)

🤖 Generated with Codebuff
Co-Authored-By: Codebuff <noreply@codebuff.com>"

if [ -n "$BASE" ]; then
  COMMIT="$(git commit-tree "$TREE" -p "$BASE" -m "$MESSAGE")"
else
  COMMIT="$(git commit-tree "$TREE" -m "$MESSAGE")"
fi

echo "publishing HEAD $LOCAL_SHA -> $REMOTE_NAME/$REMOTE_BRANCH:$PREFIX/ as ${COMMIT:0:7}"

if [ "$DRY_RUN" -eq 1 ]; then
  git push --dry-run "$REMOTE_NAME" "$COMMIT:refs/heads/$REMOTE_BRANCH"
  echo "dry run: nothing was written to the remote (built ${COMMIT:0:7})"
  exit 0
fi

if ! git push "$REMOTE_NAME" "$COMMIT:refs/heads/$REMOTE_BRANCH"; then
  echo "error: the push was rejected: $REMOTE_NAME/$REMOTE_BRANCH moved after it was read," >&2
  echo "       or the remote declined the commit (hook, protected branch, permissions)." >&2
  echo "       nothing local was modified; re-run to rebuild on the current tip." >&2
  exit 1
fi

git update-ref "$UPSTREAM_REF" "$COMMIT"
mirror_history
echo "done: ${COMMIT:0:7} pushed to $(git remote get-url "$REMOTE_NAME") ($REMOTE_BRANCH)"
