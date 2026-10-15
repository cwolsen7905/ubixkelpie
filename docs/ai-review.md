# AI review for merge requests

A reusable GitLab CI job, `ai-review-mr`, that reads a merge request and opens one
discussion thread per finding. Other ubixsys repos include it; it lives in
`ci/ai-review/` until it becomes a `kelpie review` command, and including repos will not
need to change when it does.

## How it relates to `code:review`

| | uBixCore `code:review` | Kelpie AI review |
|---|---|---|
| Checks | lint, static types, tests, naming, layering, conventions | logic bugs, security, edge cases, design smells, missing tests for changed behaviour |
| Nature | deterministic, the gate | judgement, advisory first |
| Fails when | any tool is red | `blocking` mode and a high-confidence blocker/major finding stands |

They do not overlap. The review is told not to report what a linter, type checker or
`code:review` would catch, and to treat a repository's standards documents as context:
`CLAUDE.md`, `AGENTS.md`, `CONTRIBUTING.md`, `docs/standards/**/*.md` and an optional
`.ai-review.md` (repo-specific review notes: what to look hard at, what to ignore). They
are read from the **target branch**, so an MR cannot rewrite the rules it is judged by.

## Include it

```yaml
include:
  - project: ubixsys/ubixkelpie
    ref: dev                       # or a tag, to pin
    file: ci/ai-review/ai-review.yml

variables:
  AI_REVIEW_REF: dev               # the same ref: the scripts are fetched at it
  AI_REVIEW_MODE: advisory
```

If the repo's pipeline has its own `stages:` without `test`, put the job on one of them:

```yaml
ai-review-mr:
  stage: lint-and-test
```

Needs a runner tagged `shell` with docker (override `tags` the same way).

## Secrets

Two **masked, unprotected** group variables on `ubixsys` (MR branches are unprotected, so a
protected variable would never reach the job). The canonical copies live in uBixVault.

| Variable | What |
|---|---|
| `OPENROUTER_API_KEY` | OpenRouter key the review runs on |
| `AI_REVIEW_GITLAB_TOKEN` | group access token, Reporter role, `api` scope: posts the threads and fetches the scripts |

With either missing the job exits **75**, posts an UNREVIEWED note when it can, and is
allowed to fail. It never turns a pipeline red for being unconfigured.

## Variables

| Variable | Default | |
|---|---|---|
| `AI_REVIEW_MODE` | `advisory` | `advisory`: threads are posted, the job never fails. `blocking`: exit 1 on a standing blocker/major finding with high confidence |
| `AI_REVIEW_MODEL` | `openrouter/anthropic/claude-haiku-5.5` | any OpenRouter model as `openrouter/<id>` |
| `AI_REVIEW_BASE_BRANCH` | `dev` | only MRs targeting this branch are reviewed |
| `AI_REVIEW_REF` | `dev` | ref of `ubixsys/ubixkelpie` the scripts are fetched at; keep equal to the include `ref` |
| `AI_REVIEW_IMAGE` | `node:24-alpine` | container the review runs in; pin by digest for reproducibility |
| `AI_REVIEW_OPENCODE_VERSION` | `1.19.0` | pinned `opencode-ai` npm version |
| `AI_REVIEW_MAX_LINES` / `AI_REVIEW_MAX_BYTES` | `3000` / `153600` | above either: "too large, human review required" |
| `AI_REVIEW_MAX_ROUNDS` | `3` | rounds per MR; one round is one pushed commit |
| `AI_REVIEW_EXTRA_LABEL` | `review:extra` | the MR label that allows more rounds |

## What a run does

1. Skips drafts (`Draft:`/`WIP:`), docs-only changes (every changed file is `*.md`, `*.rst`, `*.txt`, `*.adoc`,
   `LICENSE*` or `CHANGELOG*`), and an empty diff.
2. Builds the diff without lockfiles, `vendor/`, `node_modules/`, `dist/`, minified,
   source-map, snapshot and generated files.
3. Over the size cap, or past the round cap without the label: posts a note and stops.
4. Runs OpenCode (`opencode run`) in a container with the repo mounted **read-only** and
   only `read`, `grep` and `glob` allowed (no shell, edit or web). The GitLab token is not
   in OpenCode's environment. The container runs as the runner's own uid/gid on a cached
   tools image (`ai-review-tools:<hash>`, built once per image + opencode version), so
   nothing it writes is root-owned and the job's workdir is removed by plain `rm`.
5. Validates the reply with `jq` (one retry on bad JSON). Two failures: UNREVIEWED, exit 75.
6. Posts one inline thread per new finding (a plain thread if GitLab refuses the position;
   the reason is in the job log) and one summary note, replaced on every push. A finding
   already open or dismissed is matched by its id or by file + title, so a reworded slug does
   not duplicate it. Open threads the run no longer reports are listed in the summary; they
   are never resolved automatically.

A review that finds nothing is not silent: the summary becomes a plain note (no thread to
resolve) saying the review ran with no findings, the model, the full commit sha and the files
covered. A skipped review (docs only, nothing reviewable) says so in the same note. Later
runs replace that note.

Output schema: `{verdict, summary, findings:[{severity, confidence, file, line, title,
why, fingerprint}]}`; see `ci/ai-review/prompt.md`.

## Overrides

- **Dismiss a finding:** resolve its thread with a reply starting `Dismissed: <reason>`.
  That finding is skipped from then on (its identity is the file plus the issue slug, not
  the line, so it survives edits). Resolving without that word means "fixed": if the same
  finding comes back it opens a new thread.
- **More rounds:** add the `review:extra` label.
- Agents never resolve these threads.

Exit codes: `0` reviewed or skipped, `1` blocking findings in `blocking` mode, `75` could
not run (configured as `allow_failure: {exit_codes: [75]}`).

## Models and cost

| Model | Price (in/out per 1M tokens) | |
|---|---|---|
| `openrouter/anthropic/claude-haiku-5.5` | $0.10 / $0.50 | default |
| `openrouter/meta/muse-spark-1.3-contributor` | $0.10 / $0.20 | cheap fallback |

The `-contributor` tier **trains on the prompts it receives**, including the code under
review. That is acceptable for the BSD open-source repos this is built for; do not point it
at private code. Ids and prices were checked against OpenRouter on 2026-10-14. A review of
a typical 500-line diff is on the order of 20-40k input tokens, i.e. cents or less. The cost
guards (skips, size cap, 3 rounds) bound the worst case.

## Try it locally

```bash
ci/ai-review/test/run-tests.sh                 # dry-run tests, no network or model

ci/ai-review/review.sh --dry-run --repo . --diff my.diff                       # real model
ci/ai-review/review.sh --dry-run --repo . --diff my.diff --canned reply.json   # canned reply
```

`--dry-run` prints the validated JSON and every comment it would post, and never calls
GitLab. Needs `bash`, `jq`, `curl`, `git` and, for a real model, `opencode` and
`OPENROUTER_API_KEY`.

## Notes

- **A job retry reuses the pipeline's old CI config.** After changing `ai-review.yml` (or
  the include ref), verify with a new push, not a retry; `review.sh` itself is fetched fresh
  at `AI_REVIEW_REF` on every run.

- Findings are only as good as the model. Advisory mode exists so a pilot can measure noise
  before anything can block a merge.
- The diff, MR title and standards are untrusted data to the model, and the prompt says so;
  the read-only, no-shell tool set is what actually bounds a prompt injection.
