#!/usr/bin/env bash
#
# Publish this app into the `rental-app/` folder of Screamarama/Freebuff.
#
# Instead of cloning the remote and re-exporting the project every time, this
# builds the upstream commit from the local index (git read-tree / write-tree /
# commit-tree) and pushes that single commit object. No second working tree.
#
#   bash scripts/push-to-freebuff.sh            # publish HEAD
#   bash scripts/push-to-freebuff.sh --dry-run  # show what would be pushed
#
# Only committed content is published; uncommitted changes are ignored (with a
# warning). One upstream commit is created per publish and it squashes whatever
# local commits are new since the last publish into a single state.
#
# Override via env: FREEBUFF_URL, FREEBUFF_BRANCH, FREEBUFF_PREFIX,
# FREEBUFF_REMOTE.
set -euo pipefail

REMOTE_NAME="${FREEBUFF_REMOTE:-freebuff}"
REMOTE_URL="${FREEBUFF_URL:-https://github.com/Screamarama/Freebuff.git}"
REMOTE_BRANCH="${FREEBUFF_BRANCH:-main}"
PREFIX="${FREEBUFF_PREFIX:-rental-app}"

DRY_RUN=0
for arg in "$@"; do
  case "$arg" in
    --dry-run) DRY_RUN=1 ;;
    -h|--help) sed -n '2,18p' "$0"; exit 0 ;;
    *) echo "error: unknown argument '$arg'" >&2; exit 2 ;;
  esac
done

cd "$(git rev-parse --show-toplevel)"

if [ -n "$(git status --porcelain)" ]; then
  echo "note: uncommitted changes are not published; commit them first." >&2
fi

if ! git remote get-url "$REMOTE_NAME" >/dev/null 2>&1; then
  git remote add "$REMOTE_NAME" "$REMOTE_URL"
  echo "added remote '$REMOTE_NAME' -> $REMOTE_URL"
fi

UPSTREAM_REF="refs/remotes/$REMOTE_NAME/$REMOTE_BRANCH"
LOCAL_SHA="$(git rev-parse --short HEAD)"

echo "fetching $REMOTE_NAME/$REMOTE_BRANCH..."
git fetch --quiet --no-tags "$REMOTE_NAME" "+refs/heads/$REMOTE_BRANCH:$UPSTREAM_REF"

# Build the upstream tree in a throwaway index: remote tree + our HEAD as $PREFIX/.
GITDIR="$(git rev-parse --git-dir)"
INDEX_FILE="$GITDIR/freebuff-publish.index"
trap 'rm -f "$INDEX_FILE"' EXIT
rm -f "$INDEX_FILE"
export GIT_INDEX_FILE="$INDEX_FILE"

git read-tree "$UPSTREAM_REF"
# Drop the published folder so files we deleted locally are really removed.
git rm -r -q --cached --ignore-unmatch "$PREFIX" >/dev/null 2>&1 || true
git read-tree --prefix="$PREFIX/" HEAD
TREE="$(git write-tree)"
unset GIT_INDEX_FILE

if [ "$TREE" = "$(git rev-parse "$UPSTREAM_REF^{tree}")" ]; then
  echo "up to date: $PREFIX/ on $REMOTE_NAME/$REMOTE_BRANCH already matches HEAD ($LOCAL_SHA)"
  exit 0
fi

MESSAGE="Publish $PREFIX/ from local commit $LOCAL_SHA

Source: $(git log -1 --pretty='%h %s' HEAD)

🤖 Generated with Codebuff
Co-Authored-By: Codebuff <noreply@codebuff.com>"

COMMIT="$(git commit-tree "$TREE" -p "$UPSTREAM_REF" -m "$MESSAGE")"

echo "publishing HEAD $LOCAL_SHA -> $REMOTE_NAME/$REMOTE_BRANCH:$PREFIX/ as ${COMMIT:0:7}"

if [ "$DRY_RUN" -eq 1 ]; then
  git push --dry-run "$REMOTE_NAME" "$COMMIT:refs/heads/$REMOTE_BRANCH"
  echo "dry run: nothing was written to the remote"
  exit 0
fi

git push "$REMOTE_NAME" "$COMMIT:refs/heads/$REMOTE_BRANCH"
git update-ref "$UPSTREAM_REF" "$COMMIT"
echo "done: ${COMMIT:0:7} pushed to $REMOTE_URL ($REMOTE_BRANCH)"
