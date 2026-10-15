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

# A clean review leaves a visible plain note, replaced on a rerun; a skip is reported too.
printf '{"verdict":"pass","summary":"Looks right.","findings":[]}\n' >"$here/.clean.json"
run --diff "$sample" --canned "$here/.clean.json"
[ "$rc" = 0 ] && has 'ran, no findings'; expect "clean review posts a no-findings note" $?
has 'would post the summary'; expect "clean note is one plain note, not a thread" $?
! has 'would open a thread'; expect "clean review opens no thread" $?
has 'src/Transfer.php'; expect "clean note lists the files covered" $?
has 'Model `openrouter/'; expect "clean note names the model" $?
CI_COMMIT_SHA=cafe1234 run --diff "$sample" --canned "$here/.clean.json" --existing "$existing"
has 'would replace the summary'; expect "clean note replaces the earlier summary" $?
has 'cafe1234'; expect "clean note names the reviewed sha" $?
rm -f "$here/.clean.json"
printf 'diff --git a/README.md b/README.md\n--- a/README.md\n+++ b/README.md\n@@ -1 +1 @@\n-a\n+b\n' >"$here/.docs.diff"
run --diff "$here/.docs.diff" --canned "$ok"
rm -f "$here/.docs.diff"
has 'AI review: skipped'; expect "docs-only skip is reported in a note" $?

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

# Dedupe: the same issue under a different slug is matched by file and title. An open
# thread of unknown age that the model did not re-raise is still open, not "fixed".
existing_open=$here/existing-open.json
run --diff "$sample" --canned "$ok" --existing "$existing_open"
expect_that "slug drift: the already-open finding is not posted again" [ "$(grep -c 'would open a thread' <<<"$out")" = 2 ]
has 'Already open: 2'; expect "open threads are all counted as already open" $?
! has 'no longer reports'; expect "thread of unknown age is not called possibly fixed" $?

# A repo with two commits: the thread at src/Fee.php:3 was posted on the first.
g=$(mktemp -d)
git -C "$g" init -q && git -C "$g" config user.email t@t && git -C "$g" config user.name t
mkdir -p "$g/src" && seq 1 12 >"$g/src/Fee.php" && git -C "$g" add -A && git -C "$g" commit -qm one
sha1=$(git -C "$g" rev-parse HEAD)
sed "s/ai-review:fp=bbbbbbbbbbbb/ai-review:fp=bbbbbbbbbbbb sha=$sha1/" "$existing_open" >"$g/open.json"
sed -i.bak 's/^12$/twelve/' "$g/src/Fee.php" && rm -f "$g/src/Fee.php.bak"
git -C "$g" commit -qam far
sha_far=$(git -C "$g" rev-parse HEAD)
rerun() { out=$(AI_REVIEW_WORK=$(mktemp -d) CI_COMMIT_SHA=$1 "$here/../review.sh" --dry-run --repo "$g" \
  --diff "$sample" --canned "$ok" --existing "$g/open.json" 2>&1); rc=$?; }
rerun "$sha_far"
! has 'no longer reports'; expect "unchanged lines: the unreported thread stays open" $?
has 'Already open: 2'; expect "unchanged lines: counted as already open" $?
sed -i.bak 's/^3$/three/' "$g/src/Fee.php" && rm -f "$g/src/Fee.php.bak"
git -C "$g" commit -qam near
rerun "$(git -C "$g" rev-parse HEAD)"
has 'no longer reports'; expect "changed lines: the unreported thread is listed" $?
has 'Magic number in fee calculation'; expect "stale thread is named by title" $?
has 'Already open: 1'; expect "changed lines: the listed thread is not counted as open" $?
rerun "$sha1"
! has 'no longer reports'; expect "same commit: nothing changed, nothing stale" $?
sed "s/ai-review:fp=bbbbbbbbbbbb/ai-review:fp=bbbbbbbbbbbb sha=0000000000000000000000000000000000000000/" \
  "$existing_open" >"$g/open.json"
rerun "$(git -C "$g" rev-parse HEAD)"
! has 'no longer reports'; expect "unreachable sha: thread stays open" $?
rm -rf "$g"

# A new finding on a line an open thread already covers is not a second thread.
w=$(mktemp -d)
printf '{"verdict":"concerns","summary":"s","findings":[
 {"severity":"major","confidence":"medium","file":"src/Fee.php","line":3,"title":"Fee constant is unexplained","why":"w","fingerprint":"fee-const"},
 {"severity":"blocker","confidence":"high","file":"src/Fee.php","line":3,"title":"Fee can be negative","why":"w","fingerprint":"fee-negative"}]}\n' >"$w/same.json"
run --diff "$sample" --canned "$w/same.json" --existing "$existing_open"
expect_that "same line as an open thread: only the blocker opens a thread" [ "$(grep -c 'would open a thread' <<<"$out")" = 1 ]
has 'Fee constant is unexplained'; expect "same-line finding is mentioned in the summary" $?

# A finding with no line is in the summary, not in a thread.
printf '{"verdict":"concerns","summary":"s","findings":[
 {"severity":"major","confidence":"high","file":"src/Fee.php","line":0,"title":"Fee has no tests","why":"Add some.","fingerprint":"fee-untested"},
 {"severity":"minor","confidence":"high","file":"src/Fee.php","line":null,"title":"Package lacks docs","why":"w","fingerprint":"fee-docs"}]}\n' >"$w/file.json"
run --diff "$sample" --canned "$w/file.json"
! has 'would open a thread'; expect "line 0 or null: no thread is opened" $?
has 'Not tied to one line'; expect "file-level findings are listed in the summary" $?
has 'Fee has no tests'; expect "file-level finding is named" $?
AI_REVIEW_MODE=blocking run --diff "$sample" --canned "$w/file.json"
expect_that "file-level high-confidence major still blocks" [ "$rc" = 1 ]
rm -rf "$w"

# The model is told what was raised in words, not hashes.
w=$(mktemp -d)
AI_REVIEW_WORK=$w "$here/../review.sh" --dry-run --repo "$here" --diff "$sample" --canned "$ok" \
  --existing "$existing_open" >/dev/null 2>&1
grep -q -- '- src/Fee.php: Magic number in fee calculation (open)' "$w/context.md"
expect "context lists open findings by file and title" $?
rm -rf "$w"

# Concurrency guard: the discussions are read again after the model ran, so a thread another
# job posted in between is not posted a second time.
export CURL_LOG; CURL_LOG=$(mktemp)
DISC_LATER=$existing_open PATH="$here/bin:$PATH" AI_REVIEW_GITLAB_TOKEN=t CI_API_V4_URL=http://gl/api \
  CI_PROJECT_ID=1 CI_MERGE_REQUEST_IID=2 CI_COMMIT_SHA=hhh AI_REVIEW_WORK=$(mktemp -d) \
  "$here/../review.sh" --diff "$sample" --canned "$ok" --repo "$here" >/dev/null 2>&1
expect_that "guard: discussions are read before and after the model" \
  [ "$(grep -c '^GET .*/discussions' "$CURL_LOG")" = 2 ]
expect_that "guard: a thread posted meanwhile is not posted again" \
  [ "$(grep -c '^POST .*/discussions' "$CURL_LOG")" = 2 ]
rm -f "$CURL_LOG"

exit $fail
