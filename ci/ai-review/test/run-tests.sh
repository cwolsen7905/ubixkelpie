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
has() { grep -q -- "$1" <<<"$out"; }

run --diff "$sample" --canned "$ok"
expect "advisory: blocking finding does not fail" "$rc"
has 'would open a thread'; expect "advisory: posts threads" $?

AI_REVIEW_MODE=blocking run --diff "$sample" --canned "$ok"
[ "$rc" = 1 ]; expect "blocking: high-confidence blocker exits 1" $?

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
[ "$rc" = 75 ]; expect "bad model JSON exits 75" $?
has 'UNREVIEWED'; expect "bad model JSON posts UNREVIEWED" $?

printf 'diff --git a/README.md b/README.md\n--- a/README.md\n+++ b/README.md\n@@ -1 +1 @@\n-a\n+b\n' >"$here/.docs.diff"
run --diff "$here/.docs.diff" --canned "$ok"
rm -f "$here/.docs.diff"
[ "$rc" = 0 ] && has 'only docs changed'; expect "docs-only change is skipped" $?

AI_REVIEW_MAX_LINES=5 run --diff "$sample" --canned "$ok"
has 'too large, human review required'; expect "oversize diff is not reviewed" $?

CI_MERGE_REQUEST_TITLE="Draft: wip" run --diff "$sample" --canned "$ok"
has 'draft MR'; expect "draft MR is skipped" $?

exit $fail
