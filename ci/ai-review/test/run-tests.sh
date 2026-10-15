#!/usr/bin/env bash
# Dry-run tests for review.sh: no network, no model. Run: ci/ai-review/test/run-tests.sh
set -uo pipefail
here=$(cd "$(dirname "$0")" && pwd)
sample=$here/sample.diff ok=$here/canned-ok.json existing=$here/existing-discussions.json
rc=0 out=""
run() { out=$(AI_REVIEW_WORK=$(mktemp -d) "$here/../review.sh" --dry-run --repo "$here" "$@" 2>&1); rc=$?; }
fail=0
expect() { # name, ok? (0|1)
  if [ "$2" = 0 ]; then echo "ok   $1"; else echo "FAIL $1"; fail=1; fi
}
expect_that() { # name, test command...
  local name=$1; shift
  if "$@"; then expect "$name" 0; else expect "$name" 1; fi
}
has() { grep -q -- "$1" <<<"$out"; }

run --diff "$sample" --canned "$ok"
expect "advisory: blocking finding does not fail" "$rc"
has 'would open a thread'; expect "advisory: posts threads" $?

AI_REVIEW_MODE=blocking run --diff "$sample" --canned "$ok"
expect_that "blocking: high-confidence blocker exits 1" [ "$rc" = 1 ]

AI_REVIEW_MODE=blocking run --diff "$sample" --canned "$ok" --existing "$existing"
has 'Dismissed: 1'; expect "dismissed fingerprint is skipped" $?
has 'would replace the summary'; expect "summary note is replaced, not added" $?
has 'round 2 of 3'; expect "round counter advances" $?

CI_COMMIT_SHA=def AI_REVIEW_MAX_ROUNDS=1 run --diff "$sample" --canned "$ok" --existing "$existing"
has 'round limit reached'; expect "round cap stops the review" $?
CI_MERGE_REQUEST_LABELS="a,review:extra" CI_COMMIT_SHA=def AI_REVIEW_MAX_ROUNDS=1 \
  run --diff "$sample" --canned "$ok" --existing "$existing"
! has 'round limit reached'; expect "review:extra label lifts the cap" $?

# The real model path, with a stub that parses arguments like opencode (prompt vs --file).
PATH="$here/bin:$PATH" STUB_REPLY="$ok" run --diff "$sample"
[ "$rc" = 0 ] && has 'would open a thread'; expect "opencode call: prompt and --file arrive as the CLI parses them" $?

run --diff "$sample" --canned "$here/canned-bad.txt"
expect_that "bad model JSON exits 75" [ "$rc" = 75 ]
has 'UNREVIEWED'; expect "bad model JSON posts UNREVIEWED" $?

printf 'diff --git a/README.md b/README.md\n--- a/README.md\n+++ b/README.md\n@@ -1 +1 @@\n-a\n+b\n' >"$here/.docs.diff"
run --diff "$here/.docs.diff" --canned "$ok"
rm -f "$here/.docs.diff"
[ "$rc" = 0 ] && has 'only docs changed'; expect "docs-only change is skipped" $?

AI_REVIEW_MAX_LINES=5 run --diff "$sample" --canned "$ok"
has 'too large, human review required'; expect "oversize diff is not reviewed" $?

CI_MERGE_REQUEST_TITLE="Draft: wip" run --diff "$sample" --canned "$ok"
has 'draft MR'; expect "draft MR is skipped" $?

# Inline threads: the position comes from /versions' *_commit_sha fields.
export CURL_LOG; CURL_LOG=$(mktemp)
PATH="$here/bin:$PATH" AI_REVIEW_GITLAB_TOKEN=t CI_API_V4_URL=http://gl/api CI_PROJECT_ID=1 \
  CI_MERGE_REQUEST_IID=2 CI_COMMIT_SHA=hhh AI_REVIEW_WORK=$(mktemp -d) \
  "$here/../review.sh" --diff "$sample" --canned "$ok" --repo "$here" >/dev/null 2>"$CURL_LOG.err"
rc=$?
expect_that "live path with stubbed GitLab exits 0" [ "$rc" = 0 ]
n=$(grep -c '"base_sha":"bbb"' "$CURL_LOG")
grep -q '^GET http://gl/api/user' "$CURL_LOG"; expect "bot identity is read from /user, not under the MR" $?
expect_that "every finding thread is posted inline with the version's shas" [ "$n" = 3 ]
! grep -q 'refused' "$CURL_LOG.err"; expect "no inline thread is refused" $?
rm -f "$CURL_LOG" "$CURL_LOG.err"

# Dedupe: the same issue under a different slug is matched by file and title; an open
# thread no longer reported is listed, never resolved.
existing_open=$here/existing-open.json
run --diff "$sample" --canned "$ok" --existing "$existing_open"
expect_that "slug drift: the already-open finding is not posted again" [ "$(grep -c 'would open a thread' <<<"$out")" = 2 ]
has 'Already open: 1'; expect "slug drift: counted as already open" $?
has 'no longer reports'; expect "stale open thread is listed in the summary" $?
has 'Magic number in fee calculation'; expect "stale thread is named by title" $?

# The model is told what was raised in words, not hashes.
w=$(mktemp -d)
AI_REVIEW_WORK=$w "$here/../review.sh" --dry-run --repo "$here" --diff "$sample" --canned "$ok" \
  --existing "$existing_open" >/dev/null 2>&1
grep -q -- '- src/Fee.php: Magic number in fee calculation (open)' "$w/context.md"
expect "context lists open findings by file and title" $?
rm -rf "$w"

exit $fail
