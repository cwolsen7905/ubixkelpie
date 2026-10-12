#!/usr/bin/env bash
# promote.sh <staging|main> — fast-forward the next branch to this pipeline's commit
# (dev -> staging, staging -> main), after a green deploy of the source branch.
# Modelled on kitg's bin/promote.sh, minus the destructive-migration check
# (Kelpie has no migrations yet; add the check back with the first one).
#
#   - Backwards guard: if the target already contains the commit, nothing to do.
#   - Fast-forward only. Never forces. A non-ff means the target was written to
#     directly; reconcile by hand.
#   - Fails closed: if the target's tip cannot be resolved, nothing is promoted.
# Needs GITLAB_PROMOTE_TOKEN: a project access token (Maintainer, write_repository)
# that may push to the protected staging/main branches.
set -u

TARGET="${1:?usage: promote.sh <staging|main>}"
case "$TARGET" in staging|main) ;; *) echo "unknown target '$TARGET'" >&2; exit 1 ;; esac

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)"
SHA="${CI_COMMIT_SHA:-$(git -C "$SCRIPT_DIR" rev-parse HEAD)}"
SHORT="${CI_COMMIT_SHORT_SHA:-${SHA:0:8}}"
SRC="${CI_COMMIT_REF_NAME:-$(git -C "$SCRIPT_DIR" rev-parse --abbrev-ref HEAD)}"

git -C "$SCRIPT_DIR" fetch -q origin "$TARGET" 2>/dev/null || true
# `--verify`: plain `rev-parse FETCH_HEAD` echoes its argument when it cannot
# resolve it, which would look like a SHA.
TARGET_SHA="$(git -C "$SCRIPT_DIR" rev-parse -q --verify 'FETCH_HEAD^{commit}' 2>/dev/null || echo '')"
if [ -z "$TARGET_SHA" ]; then
    echo "Could not resolve origin/${TARGET}: refusing to promote. Does the branch exist?" >&2
    exit 1
fi

if git -C "$SCRIPT_DIR" merge-base --is-ancestor "$SHA" "$TARGET_SHA" 2>/dev/null; then
    echo "${TARGET} already contains ${SHORT}: nothing to promote."
    exit 0
fi

TOKEN="${GITLAB_PROMOTE_TOKEN:-}"
if [ -z "$TOKEN" ]; then
    echo "GITLAB_PROMOTE_TOKEN is not set: cannot promote." >&2
    exit 1
fi

URL="https://oauth2:${TOKEN}@${CI_SERVER_HOST:-gitlab.brainchurts.com}/${CI_PROJECT_PATH:-ubixsys/ubixkelpie}.git"
echo "Promoting ${SRC}@${SHORT} -> ${TARGET} (fast-forward only)..."
# No --force: a non-fast-forward is rejected by the server.
if OUT="$(git -C "$SCRIPT_DIR" push "$URL" "${SHA}:refs/heads/${TARGET}" 2>&1)"; then
    echo "$OUT" | sed "s#${TOKEN}#***#g"
    echo "Promoted to ${TARGET}."
else
    echo "$OUT" | sed "s#${TOKEN}#***#g"
    case "$OUT" in
        *401*|*"Authentication failed"*|*"Access denied"*) reason="token auth failed: refresh GITLAB_PROMOTE_TOKEN" ;;
        *"protected branch"*|*"pre-receive hook declined"*) reason="branch protection: allow Maintainers to push to ${TARGET}" ;;
        *"non-fast-forward"*|*"[rejected]"*|*"fetch first"*) reason="${TARGET} diverged from the promotion flow: reconcile manually (never forced)" ;;
        *) reason="see the log above" ;;
    esac
    echo "push to ${TARGET} FAILED: ${reason}" >&2
    exit 1
fi
