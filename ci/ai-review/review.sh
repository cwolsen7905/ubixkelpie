#!/usr/bin/env bash
# Kelpie AI review for one GitLab merge request.
#
#   review.sh [--dry-run] [--diff FILE] [--canned FILE] [--existing FILE] [--repo DIR]
#
# Exit codes: 0 reviewed (or deliberately skipped), 1 blocking findings in `blocking`
# mode, 75 could not run (UNREVIEWED). See docs/ai-review.md.
#
# --dry-run   never calls GitLab; prints the validated JSON and the comments it would post
# --diff      review this diff file instead of `git diff` (no MR needed)
# --canned    use this file as the model's reply instead of calling OpenCode
# --existing  JSON array of MR discussions to dedupe against (dry-run stand-in for the API)
# --repo      repository root (default $AI_REVIEW_REPO or /repo)
#
# Needs: bash, jq, curl, git, sha1sum, and `opencode` unless --canned is given.

set -uo pipefail

SELF_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
readonly EX_UNREVIEWED=75

DRY=0 DIFF_FILE="" CANNED="" EXISTING_FILE=""
REPO=${AI_REVIEW_REPO:-/repo}
while [ $# -gt 0 ]; do
  case $1 in
    --dry-run) DRY=1 ;;
    --diff) DIFF_FILE=$2; shift ;;
    --canned) CANNED=$2; shift ;;
    --existing) EXISTING_FILE=$2; shift ;;
    --repo) REPO=$2; shift ;;
    *) echo "ai-review: unknown argument $1" >&2; exit 2 ;;
  esac
  shift
done

MODEL=${AI_REVIEW_MODEL:-openrouter/anthropic/claude-haiku-5.5}
EFFORT=${AI_REVIEW_EFFORT:-}
MODE=${AI_REVIEW_MODE:-advisory}
MAX_LINES=${AI_REVIEW_MAX_LINES:-3000}
MAX_BYTES=${AI_REVIEW_MAX_BYTES:-153600}
MAX_ROUNDS=${AI_REVIEW_MAX_ROUNDS:-3}
EXTRA_LABEL=${AI_REVIEW_EXTRA_LABEL:-review:extra}
TIMEOUT=${AI_REVIEW_OPENCODE_TIMEOUT:-420}
WORK=${AI_REVIEW_WORK:-$(mktemp -d)}
BASE_SHA=${CI_MERGE_REQUEST_DIFF_BASE_SHA:-}
HEAD_SHA=${CI_COMMIT_SHA:-HEAD}
TITLE=${CI_MERGE_REQUEST_TITLE:-}
API="${CI_API_V4_URL:-}/projects/${CI_PROJECT_ID:-0}/merge_requests/${CI_MERGE_REQUEST_IID:-0}"
LABELS=${CI_MERGE_REQUEST_LABELS:-}
TOKEN=${AI_REVIEW_GITLAB_TOKEN:-}

mkdir -p "$WORK"
log() { echo "ai-review: $*" >&2; }

MODEL_CALLED=0 # set once the model section is reached; summaries before that report no model call
USAGE_IN=0 USAGE_OUT=0 USAGE_COST=0 # input/output tokens and micro-dollars, summed over the run's step-finish events

# Formats a token count for the stamp: 999 stays, 12300 becomes 12.3k.
kfmt() {
  local n=$1
  if [[ $n =~ ^[0-9]+$ ]] && [ "$n" -ge 1000 ]; then
    printf '%d.%dk' $((n / 1000)) $(((n % 1000) / 100))
  else
    printf '%s' "$1"
  fi
}

# One line appended to every summary: model, effort and summed tokens/cost when a model
# call ran, or "no model call" when none did (skipped, too large, early failure).
stamp_line() {
  if [ "$MODEL_CALLED" = 1 ]; then
    printf '_Model `%s`, effort %s, %s in / %s out, $%d.%04d (OpenCode estimate), on `%s`._' \
      "$MODEL" "${EFFORT:-default}" "$(kfmt "$USAGE_IN")" "$(kfmt "$USAGE_OUT")" \
      $((USAGE_COST / 1000000)) $(((USAGE_COST % 1000000) / 100)) "${HEAD_SHA:0:8}"
  else
    printf '_No model call on `%s`: no model, effort, tokens or cost to report._' "${HEAD_SHA:0:8}"
  fi
}

case $MODE in advisory | blocking) ;; *) log "AI_REVIEW_MODE must be advisory or blocking, got '$MODE'"; exit 2 ;; esac

# --- GitLab -------------------------------------------------------------------------

# gl METHOD PATH [JSON]  -> response body on stdout; non-zero on HTTP error.
gl() {
  local method=$1 path=$2 data=${3:-}
  if [ "$DRY" = 1 ]; then
    log "(dry-run) $method $path"
    echo '{}'
    return 0
  fi
  local args=(-sS --fail-with-body --retry 2 --max-time 30 -X "$method" -H "PRIVATE-TOKEN: $TOKEN")
  [ -n "$data" ] && args+=(-H 'Content-Type: application/json' --data "$data")
  # /user is not under the MR.
  local url="$API$path"
  [ "$path" = /user ] && url="${CI_API_V4_URL:-}/user"
  curl "${args[@]}" "$url"
}

# Guards against GitLab quick actions in model- or diff-derived text: a line that starts
# with `/` is a command (`/merge`, `/approve`), so the slash becomes a character reference.
neutralize() { sed -e 's#^\([[:space:]]*\)/#\1\&\#47;#'; }

discussions() {
  if [ -n "$EXISTING_FILE" ]; then cat "$EXISTING_FILE"; return; fi
  [ "$DRY" = 1 ] && { echo '[]'; return; }
  local page out='[]' chunk
  for page in $(seq 1 20); do
    chunk=$(gl GET "/discussions?per_page=100&page=$page") || return 1
    out=$(jq -s 'add' <(echo "$out") <(echo "$chunk"))
    [ "$(jq length <<<"$chunk")" -lt 100 ] && break
  done
  echo "$out"
}

SUMMARY_ID="" # id of our summary note, when one exists

# Posts or replaces the one summary note. $1 body, $2 round. The usage stamp is
# appended to every summary: model/effort/tokens/cost after a model call, or a
# "no model call" line when none ran (skipped, too large, early failure).
put_summary() {
  local body=$1 round=$2 marker
  marker="<!-- ai-review:summary round=$round sha=$HEAD_SHA -->"
  body="$marker"$'\n'"$body"$'\n\n'"$(stamp_line)"
  if [ "$DRY" = 1 ]; then
    printf '\n--- would %s the summary note ---\n%s\n' "$([ -n "$SUMMARY_ID" ] && echo replace || echo post)" "$body"
    return 0
  fi
  local payload
  payload=$(jq -n --arg b "$body" '{body: $b}')
  if [ -n "$SUMMARY_ID" ]; then
    gl PUT "/notes/$SUMMARY_ID" "$payload" >/dev/null
  else
    gl POST "/notes" "$payload" >/dev/null
  fi
}

unreviewed() {
  log "UNREVIEWED: $1"
  if [ -n "$TOKEN" ] || [ "$DRY" = 1 ]; then
    put_summary "**AI review: UNREVIEWED.** $1"$'\n\n'"No automated review ran on this commit; a person must review it." "${ROUND:-0}" || true
  fi
  exit "$EX_UNREVIEWED"
}

# --- Gates before any model call ------------------------------------------------------

shopt -s nocasematch
if [[ $TITLE =~ ^[[:space:]]*(draft|wip)[:\ ] ]]; then log "draft MR, skipped"; exit 0; fi
shopt -u nocasematch

if [ "$DRY" = 0 ]; then
  [ -n "$TOKEN" ] || unreviewed "AI_REVIEW_GITLAB_TOKEN is not set."
  [ -n "${OPENROUTER_API_KEY:-}" ] || [ -n "$CANNED" ] || unreviewed "OPENROUTER_API_KEY is not set."
  [ -n "${CI_MERGE_REQUEST_IID:-}" ] || unreviewed "not a merge request pipeline."
fi

# Paths that are never sent: lockfiles, vendored and generated files.
EXCLUDES=(
  ':(exclude,glob)**/composer.lock' ':(exclude,glob)**/package-lock.json' ':(exclude,glob)**/yarn.lock'
  ':(exclude,glob)**/pnpm-lock.yaml' ':(exclude,glob)**/Cargo.lock' ':(exclude,glob)**/poetry.lock'
  ':(exclude,glob)**/Gemfile.lock' ':(exclude,glob)**/go.sum' ':(exclude,glob)**/uv.lock'
  ':(exclude,glob)**/vendor/**' ':(exclude,glob)**/node_modules/**' ':(exclude,glob)**/dist/**'
  ':(exclude,glob)**/*.min.js' ':(exclude,glob)**/*.min.css' ':(exclude,glob)**/*.map'
  ':(exclude,glob)**/*.pb.go' ':(exclude,glob)**/*.generated.*' ':(exclude,glob)**/*.snap'
)
DOCS_RE='(\.(md|markdown|rst|txt|adoc)|(^|/)LICENSE[^/]*|(^|/)CHANGELOG[^/]*)$'

git_r() { git -c safe.directory='*' -C "$REPO" "$@"; }

CHANGED="$WORK/changed.txt"
DIFF="$WORK/change.diff"
if [ -n "$DIFF_FILE" ]; then
  cp "$DIFF_FILE" "$DIFF"
  grep -E '^diff --git ' "$DIFF" | sed -E 's#^diff --git a/.* b/##' >"$CHANGED"
else
  [ -n "$BASE_SHA" ] || unreviewed "CI_MERGE_REQUEST_DIFF_BASE_SHA is not set."
  git_r diff --name-only "$BASE_SHA" "$HEAD_SHA" -- . "${EXCLUDES[@]}" >"$CHANGED" ||
    unreviewed "could not diff against $BASE_SHA (is the base commit fetched?)."
  git_r diff --no-color --unified=3 "$BASE_SHA" "$HEAD_SHA" -- . "${EXCLUDES[@]}" >"$DIFF" ||
    unreviewed "could not diff against $BASE_SHA."
fi

# A skip is reported too, but only once the existing summary note is known, so a rerun
# replaces it instead of adding another.
SKIP_REASON=""
if [ ! -s "$CHANGED" ]; then
  SKIP_REASON="nothing reviewable changed (lockfiles, vendored and generated files are not sent)"
elif ! grep -Evq "$DOCS_RE" "$CHANGED"; then
  SKIP_REASON="only docs changed"
fi

# Files covered, for the notes below: first 20, then a count.
files_covered() {
  local n; n=$(wc -l <"$CHANGED" | tr -d ' ')
  head -n 20 "$CHANGED" | awk '{ printf "- %c%s%c\n", 96, $0, 96 }'
  [ "$n" -le 20 ] || printf -- '- ... and %s more\n' "$((n - 20))"
}

# --- Existing threads: round counter, dedupe, dismissals ------------------------------

ROUND=1
DISC='[]'
if [ "$DRY" = 0 ] || [ -n "$EXISTING_FILE" ]; then
  DISC=$(discussions) || unreviewed "could not read the MR's discussions."
fi
BOT_ID=0
[ "$DRY" = 0 ] && BOT_ID=$(gl GET /user 2>/dev/null | jq -r '.id // 0' 2>/dev/null || echo 0)
# --argjson needs one JSON number: an error body, a retry's repeated body or an empty reply
# must not reach it.
BOT_ID=$(head -n1 <<<"$BOT_ID")
[[ $BOT_ID =~ ^[0-9]+$ ]] || BOT_ID=0

# Our summary note, as "id round sha"; empty when there is none yet.
find_summary() {
  jq -r '[.[].notes[]? | select(.body | startswith("<!-- ai-review:summary"))][0] // empty |
    [.id, (.body | capture("round=(?<r>[0-9]+) sha=(?<s>[0-9a-f]+)") | .r + " " + .s)] | join(" ")' <<<"$DISC"
}
prev=$(find_summary)
if [ -n "$prev" ]; then
  read -r SUMMARY_ID prev_round prev_sha <<<"$prev"
  ROUND=$prev_round
  # A pipeline retry of the same commit is the same round.
  [ "$prev_sha" = "$HEAD_SHA" ] || ROUND=$((prev_round + 1))
fi

if [ -n "$SKIP_REASON" ]; then
  log "$SKIP_REASON, skipped"
  put_summary "**AI review: skipped.** No model call was made: $SKIP_REASON."$'\n\n'"_On \`${HEAD_SHA:0:8}\`._" "$ROUND" || true
  exit 0
fi

if [ "$ROUND" -gt "$MAX_ROUNDS" ] && [[ ",${LABELS// /}," != *",$EXTRA_LABEL,"* ]]; then
  put_summary "**AI review: round limit reached.** This merge request has had $MAX_ROUNDS automated review rounds. Add the \`$EXTRA_LABEL\` label to allow more; a person should review it either way." "$((ROUND - 1))"
  exit 0
fi

# Finding threads already on the MR. Marker format on a finding thread:
# <!-- ai-review:fp=<12 hex> sha=<head sha when posted> -->. Rows are
# "fp<TAB>file<TAB>title<TAB>line<TAB>sha", parsed back out of the thread so the model can be
# told what was raised in words, not hashes. A thread from before the sha was in the marker
# falls back to its diff position's head_sha; a plain thread has none, and the sha is empty.
# thread_rows open|dismissed
# Open means no resolved note; dismissed means resolved with a person's `Dismissed:` reply.
thread_rows() {
  jq -r --arg want "$1" --argjson bot "$BOT_ID" '
    def state:
      if ([.notes[]? | .resolved] | all(. != true)) then "open"
      elif ([.notes[1:][]? | select((.author.id // 0) != $bot) | .body | test("^\\s*Dismissed:"; "i")] | any)
      then "dismissed" else "resolved" end;
    [.[] | select(.notes[0].body | contains("<!-- ai-review:fp=")) | select(state == $want)
      | .notes[0] as $n | $n.body as $b
      | ($b | capture("ai-review:fp=(?<f>[0-9a-f]+)").f) as $fp
      | [ $fp,
          ([$b | capture("`(?<p>[^`:]+):[0-9]+`").p][0] // "-"),
          ([$b | capture("\\): (?<t>[^\n]+)").t][0] // $fp),
          ([$b | capture("`[^`:]+:(?<l>[0-9]+)`").l][0] // "0"),
          ([$b | capture("ai-review:fp=[0-9a-f]+ sha=(?<s>[0-9a-f]+)").s][0] // $n.position.head_sha // "") ]
      | join("\t")]
    | unique | .[]' <<<"$DISC"
}
# file|lowercased title, for matching when the model words the issue's slug differently.
row_keys() { awk -F'\t' 'NF >= 3 && $3 != "" { print $2 "|" tolower($3) }' <<<"$1"; }
# file|line, for a new finding that lands on a line an open thread already covers.
row_lines() { awk -F'\t' 'NF >= 4 && $4 > 0 { print $2 "|" $4 }' <<<"$1"; }

load_threads() {
  OPEN_ROWS=$(thread_rows open)
  DISMISSED_ROWS=$(thread_rows dismissed)
  OPEN_FPS=$(cut -f1 <<<"$OPEN_ROWS")
  DISMISSED_FPS=$(cut -f1 <<<"$DISMISSED_ROWS")
  OPEN_KEYS=$(row_keys "$OPEN_ROWS")
  DISMISSED_KEYS=$(row_keys "$DISMISSED_ROWS")
  OPEN_LINES=$(row_lines "$OPEN_ROWS")
}
load_threads

# Did lines near $2 of file $1 change between the commit $3 a thread was posted on and
# this one? Unknown (no sha, sha not fetched, no such file) answers no: an open thread is
# only called possibly-fixed on evidence that its code moved.
thread_changed() {
  local file=$1 line=$2 sha=$3
  [[ $line =~ ^[0-9]+$ ]] && [ "$line" -gt 0 ] && [ -n "$sha" ] && [ "$sha" != "$HEAD_SHA" ] || return 1
  git_r cat-file -e "$sha^{commit}" 2>/dev/null || return 1
  git_r diff -U0 --no-color "$sha" "$HEAD_SHA" -- "$file" 2>/dev/null | awk -v l="$line" '
    /^@@ / {
      split($2, a, ","); s = substr(a[1], 2) + 0; c = (a[2] == "" ? 1 : a[2] + 0)
      e = s + (c > 0 ? c : 1) - 1
      if (s <= l + 3 && e >= l - 3) hit = 1
    }
    END { exit hit ? 0 : 1 }'
}

# --- Size guard -----------------------------------------------------------------------

lines=$(wc -l <"$DIFF" | tr -d ' ')
bytes=$(wc -c <"$DIFF" | tr -d ' ')
if [ "$lines" -gt "$MAX_LINES" ] || [ "$bytes" -gt "$MAX_BYTES" ]; then
  put_summary "**AI review: too large, human review required.** The reviewable diff is $lines lines / $bytes bytes (limit $MAX_LINES lines / $MAX_BYTES bytes)." "$ROUND"
  exit 0
fi

# --- Context for the model ------------------------------------------------------------

# Standards come from the target branch, not the MR's head, so an MR cannot rewrite the
# rules it is judged by. Without a base sha (local runs) the working tree is used.
repo_ls() {
  if [ -n "$BASE_SHA" ] && [ -z "$DIFF_FILE" ]; then
    git_r ls-tree -r --name-only "$BASE_SHA" 2>/dev/null
  else
    (cd "$REPO" && find . -type f -not -path './.git/*' -not -path './vendor/*' -not -path './node_modules/*' | sed 's#^\./##')
  fi
}
repo_cat() {
  if [ -n "$BASE_SHA" ] && [ -z "$DIFF_FILE" ]; then git_r show "$BASE_SHA:$1" 2>/dev/null; else cat "$REPO/$1"; fi
}

CONTEXT="$WORK/context.md"
{
  printf '# MR title\n\n%s\n\n' "$TITLE"
  printf '# Repository standards\n\n'
  printf 'Context only. Do not re-report what these say a tool already enforces.\n\n'
  budget=61440
  found=0
  while IFS= read -r f; do
    [ -n "$f" ] || continue
    body=$(repo_cat "$f" | head -c 20480) || continue
    [ -n "$body" ] || continue
    [ "$budget" -gt 0 ] || break
    budget=$((budget - ${#body}))
    found=1
    printf '## %s\n\n%s\n\n' "$f" "$body"
  done < <(repo_ls | grep -E '^(CLAUDE\.md|AGENTS\.md|CONTRIBUTING\.md|\.ai-review\.md|docs/standards/.+\.md)$' | sort)
  [ "$found" = 1 ] || printf '(none found)\n\n'
  printf '# Already raised on this MR (do not report again)\n\n'
  { awk -F'\t' 'NF { printf "- %s: %s (open)\n", $2, ($3 != "" ? $3 : $1) }' <<<"$OPEN_ROWS"
    awk -F'\t' 'NF { printf "- %s: %s (dismissed by a person; do not raise again)\n", $2, ($3 != "" ? $3 : $1) }' <<<"$DISMISSED_ROWS"
  } || true
  printf '\n# Diff\n\n```diff\n'
  cat "$DIFF"
  printf '\n```\n'
} >"$CONTEXT"

# --- Model ----------------------------------------------------------------------------

# Read-only tools only; the key never leaves the opencode process's environment.
OPENCODE_CONFIG_CONTENT=$(jq -nc '{
  "$schema": "https://opencode.ai/config.json",
  permission: {"*": "deny", read: "allow", grep: "allow", glob: "allow"},
  autoupdate: false, share: "disabled"}')
export OPENCODE_CONFIG_CONTENT

validate() {
  jq -e '
    def sev: . as $s | ["blocker","major","minor","nit"] | index($s) != null;
    def conf: . as $s | ["high","medium","low"] | index($s) != null;
    type == "object"
    and (.verdict | IN("pass","concerns","block"))
    and (.summary | type == "string")
    and (.findings | type == "array")
    and all(.findings[]; type == "object"
      and (.severity | type == "string" and sev) and (.confidence | type == "string" and conf)
      and (.file | type == "string" and length > 0) and (.line | type == "number" or . == null)
      and (.title | type == "string" and length > 0) and (.why | type == "string")
      and (.fingerprint | type == "string" and length > 0))' >/dev/null
}

# Emits each top-level balanced {...} span of the input that parses as JSON, as one
# line of compact JSON, outermost (earliest-starting) first. A reply wrapped in a
# ```json fence or surrounded by prose still yields its object; unbalanced braces and
# braces inside strings are ignored.
extract_candidates() {
  jq -Rsr '
    (explode) as $c
    | (reduce range(0; $c | length) as $i
        ({depth: 0, instr: false, esc: false, start: -1, spans: []};
          if .instr then
            if .esc then .esc = false
            elif $c[$i] == 92 then .esc = true
            elif $c[$i] == 34 then .instr = false
            else . end
          elif $c[$i] == 34 then .instr = true
          elif $c[$i] == 123 then (if .depth == 0 then .start = $i else . end) | .depth += 1
          elif $c[$i] == 125 and .depth > 0 then
            .depth -= 1 | (if .depth == 0 then .spans += [{s: .start, e: $i}] else . end)
          else . end))
    | [.spans | sort_by(.s)[] | . as $sp | $c[$sp.s:$sp.e + 1] | implode
       | try (fromjson | tojson) catch empty] | .[]' 2>/dev/null
}

# Names the first schema problem in a parsed reply, so the retry can say what was wrong.
validation_problem() {
  jq -r '
    def sev: . as $s | ["blocker","major","minor","nit"] | index($s) != null;
    def conf: . as $s | ["high","medium","low"] | index($s) != null;
    if type != "object" then "the top level must be a JSON object"
    elif (.verdict | IN("pass","concerns","block") | not) then "verdict must be one of pass, concerns, block"
    elif (.summary | type) != "string" then "summary must be a string"
    elif (.findings | type) != "array" then "findings must be an array"
    else ([.findings | to_entries[] | .value as $f | .key as $k |
      (if ($f | type) != "object" then "findings[\($k)] must be an object"
       elif ($f.severity | sev | not) then "findings[\($k)].severity must be one of blocker, major, minor, nit"
       elif ($f.confidence | conf | not) then "findings[\($k)].confidence must be one of high, medium, low"
       elif ($f.file | type) != "string" or ($f.file | length) == 0 then "findings[\($k)].file must be a non-empty string"
       elif $f.line != null and (($f.line | type) != "number") then "findings[\($k)].line must be a number or null"
       elif ($f.title | type) != "string" or ($f.title | length) == 0 then "findings[\($k)].title must be a non-empty string"
       elif ($f.why | type) != "string" then "findings[\($k)].why must be a string"
       elif ($f.fingerprint | type) != "string" or ($f.fingerprint | length) == 0 then "findings[\($k)].fingerprint must be a non-empty string"
       else empty end)] | first // "valid") end'
}

# Sums input/output tokens and cost from an opencode --format json event stream into
# USAGE_IN/USAGE_OUT/USAGE_COST. A step-finish part carries numeric tokens and cost;
# anything unparsable is ignored and contributes nothing.
accumulate_usage() { # $1 = stream file
  local u
  u=$(jq -n -c '
    def num: if type == "number" then . elif type == "object" then (.total // .value // 0) else 0 end;
    reduce inputs as $e ({i: 0, o: 0, c: 0};
      if ($e.type == "step-finish" or $e.type == "step_finish") then
        ($e.part // $e) as $p
        | .i += (($p.tokens.input // 0) | num)
        | .o += (($p.tokens.output // 0) | num)
        | .c += (($p.cost // 0) | num)
      else . end)' "$1" 2>/dev/null) || return 0
  USAGE_IN=$((USAGE_IN + $(jq -r '.i | floor' <<<"$u")))
  USAGE_OUT=$((USAGE_OUT + $(jq -r '.o | floor' <<<"$u")))
  # Cost is kept as integer micro-dollars to avoid float drift.
  USAGE_COST=$((USAGE_COST + $(jq -r '(.c * 1000000) | round' <<<"$u")))
}

# The reply text of an opencode --format json event stream: all text parts joined.
reply_text() { # $1 = stream file
  jq -rs '[.[] | select(.type == "text") | (.part.text // .text // "")] | join("")' "$1" 2>/dev/null
}

MODEL_CALLED=1 # summaries from here on follow a model call and carry the usage stamp
RESULT=""
retry=""
for attempt in 1 2; do
  STREAM="$WORK/stream-$attempt.jsonl"
  prompt=$(cat "$SELF_DIR/prompt.md")$'\n\n'"$retry"
  if [ -n "$CANNED" ]; then
    # A canned file is the model's reply text, unless it is an opencode event stream
    # (JSON lines with text/step-finish events), which is parsed like a live run so a
    # canned stream with step costs gives the same stamp.
    cp "$CANNED" "$STREAM"
    if jq -e 'select(type == "object")
        | select(.type == "text" or .type == "step-finish" or .type == "step_finish")' \
        "$STREAM" >/dev/null 2>&1; then
      accumulate_usage "$STREAM"
      raw=$(reply_text "$STREAM") || raw=$(cat "$STREAM")
    else
      raw=$(cat "$CANNED")
    fi
  else
    # The GitLab token is deliberately not in the environment OpenCode runs in. --file is
    # an array option and swallows any positional after it, so the prompt goes first.
    # --variant is opencode's effort setting (provider-specific reasoning effort); empty
    # means the provider default and the stamp then says "default".
    if ! (cd "$REPO" && env -u AI_REVIEW_GITLAB_TOKEN timeout "$TIMEOUT" \
      opencode run --format json --model "$MODEL" ${EFFORT:+--variant "$EFFORT"} \
      "$prompt" --file "$CONTEXT" >"$STREAM"); then
      log "model run failed (attempt $attempt)"
      retry="Your previous run failed before producing a reply. Try again."
      continue
    fi
    accumulate_usage "$STREAM"
    raw=$(reply_text "$STREAM") || raw=$(cat "$STREAM")
  fi
  cands=$(extract_candidates <<<"$raw")
  if [ -z "$cands" ]; then
    log "model output contained no JSON object (attempt $attempt)"
    retry="Your previous reply contained no JSON object I could parse. Reply with ONLY the JSON object described in the schema: no prose, no Markdown fence, no commentary before or after."
    continue
  fi
  json=""
  while IFS= read -r cand; do
    [ -n "$cand" ] && validate <<<"$cand" && { json=$cand; break; }
  done <<<"$cands"
  if [ -n "$json" ]; then RESULT=$json; break; fi
  problem=$(validation_problem <<<"$(head -n1 <<<"$cands")")
  log "model output was not valid review JSON (attempt $attempt): $problem"
  retry="Your previous reply was JSON but did not match the schema: $problem. Reply with ONLY the corrected JSON object, no prose and no fence."
done
[ -n "$RESULT" ] || unreviewed "the model did not return a valid review after a retry."

# Stable per-finding id: file + the model's issue slug, never the line or the wording.
RESULT=$(jq -c '.findings |= map(.line |= (if type == "number" then floor else 0 end))' <<<"$RESULT")
FINDINGS="$WORK/findings.ndjson"
jq -c '.findings[]' <<<"$RESULT" | while IFS= read -r f; do
  key=$(jq -r '.file + "|" + (.fingerprint | ascii_downcase)' <<<"$f")
  id=$(printf '%s' "$key" | sha1sum | cut -c1-12)
  jq -c --arg id "$id" '. + {id: $id}' <<<"$f"
done >"$FINDINGS"

echo "=== review JSON ==="
jq . <<<"$RESULT"

# --- Post -----------------------------------------------------------------------------

POSITION_JSON='null'
if [ "$DRY" = 0 ]; then
  # The versions API names these base_commit_sha / start_commit_sha / head_commit_sha.
  POSITION_JSON=$(gl GET "/versions" 2>/dev/null | jq -ce '.[0] |
    {base_sha: .base_commit_sha, start_sha: .start_commit_sha, head_sha: .head_commit_sha} |
    select(all(.[]; type == "string" and length > 0))' 2>/dev/null | head -n1)
  [[ $POSITION_JSON == \{* ]] || { log "could not read the MR diff version; threads will not be inline"; POSITION_JSON=null; }
fi

# The model run takes minutes; another job may have posted since the first read. Reread, so
# a thread that appeared meanwhile is deduped rather than posted twice.
if [ "$DRY" = 0 ] && [ -z "$EXISTING_FILE" ]; then
  if fresh=$(discussions); then
    DISC=$fresh
    load_threads
    if [ -z "$SUMMARY_ID" ]; then
      prev=$(find_summary)
      [ -n "$prev" ] && read -r SUMMARY_ID _ <<<"$prev"
    fi
  else
    log "could not reread the MR's discussions; deduping against the earlier read"
  fi
fi

SEEN_IDS="$WORK/seen-ids.txt" SEEN_KEYS="$WORK/seen-keys.txt"
: >"$SEEN_IDS" && : >"$SEEN_KEYS"
posted=0 skipped_open=0 skipped_dismissed=0 failed=0 blocking=0
FILE_LEVEL="" SAME_LINE=""
while IFS= read -r f; do
  [ -n "$f" ] || continue
  id=$(jq -r .id <<<"$f")
  sev=$(jq -r .severity <<<"$f")
  # The same issue under another slug on a rerun is matched by file and title.
  key=$(jq -r '.file + "|" + (.title | ascii_downcase)' <<<"$f")
  printf '%s\n' "$id" >>"$SEEN_IDS"
  if grep -qx "$id" <<<"$DISMISSED_FPS" || grep -qxF -- "$key" <<<"$DISMISSED_KEYS"; then
    skipped_dismissed=$((skipped_dismissed + 1)); continue
  fi
  if [ "$sev" = blocker ] || [ "$sev" = major ]; then
    [ "$(jq -r .confidence <<<"$f")" = high ] && blocking=$((blocking + 1))
  fi
  if grep -qx "$id" <<<"$OPEN_FPS" || grep -qxF -- "$key" <<<"$OPEN_KEYS"; then
    printf '%s\n' "$key" >>"$SEEN_KEYS"
    skipped_open=$((skipped_open + 1)); continue
  fi
  line_no=$(jq -r '(.line // 0) | floor | tostring' <<<"$f")
  title=$(jq -r .title <<<"$f" | neutralize)
  file=$(jq -r .file <<<"$f")
  # Not about one line (a whole file or package): it goes in the summary, which is replaced
  # on every push, rather than in a thread pinned to a line that happens to be near.
  if ! [[ $line_no =~ ^[0-9]+$ ]] || [ "$line_no" -le 0 ]; then
    FILE_LEVEL+="- \`$file\`: **$sev** ($(jq -r .confidence <<<"$f")): $title. $(jq -r .why <<<"$f" | tr '\n' ' ' | neutralize)"$'\n'
    continue
  fi
  # The same line as an open thread is the same defect, reworded; a blocker is still posted.
  if [ "$sev" != blocker ] && grep -qxF -- "$file|$line_no" <<<"$OPEN_LINES"; then
    SAME_LINE+="- \`$file:$line_no\`: $title"$'\n'
    skipped_open=$((skipped_open + 1)); continue
  fi

  body=$(jq -r '"**" + .severity + "** (" + .confidence + " confidence): " + .title +
    "\n\n`" + .file + ":" + (.line | tostring) + "`\n\n" + .why +
    "\n\n_Fix and resolve this thread, or resolve it with a reply starting `Dismissed: <reason>`._\n" +
    "<!-- ai-review:fp=" + .id + " sha=" + $sha + " -->"' --arg sha "$HEAD_SHA" <<<"$f" | neutralize)
  if [ "$DRY" = 1 ]; then
    printf '\n--- would open a thread ---\n%s\n' "$body"
    posted=$((posted + 1))
    continue
  fi
  line=$line_no
  payload=$(jq -n --arg b "$body" --arg file "$file" --argjson line "$line" --argjson pos "$POSITION_JSON" '
    {body: $b} + (if $pos != null and $line > 0 then
      {position: ($pos + {position_type: "text", new_path: $file, old_path: $file, new_line: $line})} else {} end)')
  # A line outside the diff is refused by GitLab; fall back to a plain thread, and say why.
  if resp=$(gl POST /discussions "$payload" 2>&1); then
    ok=1
  else
    log "inline thread for $file:$line refused, posting a plain thread: $(head -c 300 <<<"$resp")"
    gl POST /discussions "$(jq -n --arg b "$body" '{body: $b}')" >/dev/null && ok=1 || ok=0
  fi
  if [ "$ok" = 1 ]; then
    posted=$((posted + 1))
  else
    failed=$((failed + 1))
  fi
done <"$FINDINGS"

# Open threads of ours the model did not re-raise. Not re-raising is what it was told to do
# for an issue already open, so it says nothing about a fix: a thread is only listed as
# possibly fixed when the lines it points at changed since it was posted. Listed, never
# resolved: a person decides.
STALE="" stale_n=0
while IFS=$'\t' read -r sfp sfile stitle sline ssha; do
  [ -n "$sfp" ] || continue
  grep -qx "$sfp" "$SEEN_IDS" && continue
  grep -qxF -- "$sfile|$(tr '[:upper:]' '[:lower:]' <<<"$stitle")" "$SEEN_KEYS" && continue
  thread_changed "$sfile" "$sline" "$ssha" || continue
  STALE+="- \`$sfile\`: $stitle"$'\n'
  stale_n=$((stale_n + 1))
done <<<"$OPEN_ROWS"
STALE=${STALE%$'\n'}
# Every open thread that is not listed above is still open, re-raised or not.
open_total=$(awk -F'\t' 'NF >= 3' <<<"$OPEN_ROWS" | wc -l | tr -d ' ')
still_open=$((open_total - stale_n))

CLEAN=0
[ ! -s "$FINDINGS" ] && [ -z "$STALE" ] && CLEAN=1

counts=$(jq -r '[.findings[].severity] | group_by(.) | map("\(length) \(.[0])") | join(", ")' <<<"$RESULT")
if [ "$CLEAN" = 1 ]; then
  # A plain note, not a thread: nothing to resolve.
  {
    echo "**AI review: ran, no findings**, round $ROUND of $MAX_ROUNDS (\`$MODE\` mode)"
    echo
    jq -r .summary <<<"$RESULT" | neutralize
    echo
    echo "Model \`$MODEL\` reviewed commit \`$HEAD_SHA\`. Files covered:"
    files_covered | neutralize
    echo
    echo "_Beside \`code:review\`, not instead of it. This note is updated on each push._"
  } >"$WORK/summary.md"
else
{
  echo "**AI review: $(jq -r .verdict <<<"$RESULT")**, round $ROUND of $MAX_ROUNDS (\`$MODE\` mode)"
  echo
  jq -r .summary <<<"$RESULT" | neutralize
  echo
  echo "Findings: ${counts:-none}. New threads: $posted. Already open: $still_open. Dismissed: $skipped_dismissed."
  if [ -n "$FILE_LEVEL" ]; then
    echo
    echo "Not tied to one line (no thread; this note is updated on each push):"
    printf '%s' "$FILE_LEVEL"
  fi
  if [ -n "$SAME_LINE" ]; then
    echo
    echo "Not opened as new threads (the line already has an open thread; see it):"
    printf '%s' "$SAME_LINE"
  fi
  if [ -n "$STALE" ]; then
    echo
    echo "Open threads whose lines changed since they were posted and that this run no longer reports (fixed? resolve them if so):"
    printf '%s\n' "$STALE" | neutralize
  fi
  echo
  echo "_Model \`$MODEL\` on \`${HEAD_SHA:0:8}\`. Beside \`code:review\`, not instead of it. Resolve a thread with \`Dismissed: <reason>\` to override a finding._"
} >"$WORK/summary.md"
fi
put_summary "$(cat "$WORK/summary.md")" "$ROUND" || failed=$((failed + 1))

if [ "$failed" -gt 0 ]; then
  log "$failed comment(s) could not be posted"
  exit "$EX_UNREVIEWED"
fi
if [ "$blocking" -gt 0 ]; then
  log "$blocking blocking finding(s) (mode: $MODE)"
  [ "$MODE" = blocking ] && exit 1
fi
exit 0
