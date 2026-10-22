# CI variables

Every GitLab CI variable the Kelpie pipeline and the `kelpie-review` job read, where it is set and
who reads it. Values are never written here: examples show the format only. Real secrets live in
uBixVault; the GitLab copies are what the jobs see.

"Group" means the `ubixsys` group's CI/CD variables, so every project in the group gets it.
"Project" means this project's variables or the `variables:` block of `.gitlab-ci.yml`.

Why the group secrets are **not protected**: merge request pipelines run on unprotected branches,
and a protected variable never reaches them. The cost is that any MR author can read a masked
variable by changing CI, so keep the keys narrow (Reporter role, a spend limit on the OpenRouter
key) and review CI changes closely. See `docs/ai-review.md` (Secrets) and `docs/operations/go-live.md`.

## Group level: needed by `kelpie-review` in every enrolled project

| Variable | Purpose | Protected / masked | Example format | Read by |
|---|---|---|---|---|
| `OPENROUTER_API_KEY` | OpenRouter key the review's model calls run on | not protected, masked | `sk-or-v1-<64 hex>` | `kelpie-review`, `ai-review-mr` |
| `AI_REVIEW_GITLAB_TOKEN` | Group access token, Reporter role, `api` scope: posts threads and the summary note | not protected, masked | `glpat-<20 chars>` | `kelpie-review`, `ai-review-mr`, and `require-approval-mr` as its fallback token |

With either one missing, `kelpie-review` exits **75** ("could not run"), says UNREVIEWED in the
merge request when it can, and is allowed to fail: the pipeline stays green.

## Group level, optional

| Variable | Purpose | Protected / masked | Example format | Read by |
|---|---|---|---|---|
| `KELPIE_REGISTRY_USER` | Registry login name, for a project that pulls Kelpie's image from another project. Default: the job's own user | not protected, not masked | `kelpie-pull` | `kelpie-review` |
| `KELPIE_REGISTRY_TOKEN` | Registry password for the same login: a `read_registry` deploy token. Default: the job's token, which reaches only its own project | not protected, masked | `gldt-<20 chars>` | `kelpie-review` |

Without the pair, a project other than `ubixkelpie` cannot pull `KELPIE_REVIEW_IMAGE` and the job
exits 75. This is item 7 in `docs/operations/go-live.md`.

## Group or project level: `kelpie-review` tuning, all optional

Set at group level to apply everywhere, or per project to override. Unset means the default.
Anything not in the `-e` list of the job's `docker run` never reaches the review.

| Variable | Default | Meaning | Format |
|---|---|---|---|
| `AI_REVIEW_MODE` | `advisory` | `advisory`: never fails the job. `blocking`: fails on a standing blocker/major finding with high confidence. Any other value stops the review | `advisory` \| `blocking` |
| `AI_REVIEW_SHADOW` | off | `true`: post one labelled summary note only, no threads, never fail | `true` |
| `AI_REVIEW_MODEL` | `openrouter/anthropic/claude-haiku-5.5` | Model, as `openrouter/<id>` | `openrouter/anthropic/claude-haiku-5.5` |
| `AI_REVIEW_EFFORT` | empty (provider default) | Provider-specific reasoning effort | `high` |
| `AI_REVIEW_BASE_BRANCH` | `dev` | Only MRs targeting this branch are reviewed. Read by the job's `rules:` as well | `main` |
| `AI_REVIEW_MAX_LINES` | `3000` | Diff lines above which the review says human review is required | positive integer |
| `AI_REVIEW_MAX_BYTES` | `153600` | Same, in bytes | positive integer |
| `AI_REVIEW_MAX_ROUNDS` | `3` | Rounds per MR; a round is one pushed commit | positive integer |
| `AI_REVIEW_EXTRA_LABEL` | `review:extra` | MR label that allows more rounds | label name |
| `AI_REVIEW_MAX_TURNS` | `12` | Model calls per review, the last without tools | positive integer |
| `OPENROUTER_HTTP_TIMEOUT` | `150` | Total seconds allowed for one model call. The shared client's 5 s was too short for any model call; the job itself times out at 15 minutes | positive integer |
| `KELPIE_REVIEW_IMAGE` | `$CI_REGISTRY/ubixsys/ubixkelpie:dev` | Image the job runs. `ubixkelpie` sets its own to the image the same pipeline built | `registry.example/group/project:tag` |

A non-positive or non-numeric value for the numeric settings falls back to the default.

## Project level: this project's `.gitlab-ci.yml`

Set in the `variables:` block of the file, so they are versioned and not secret.

| Variable | Value here | Purpose | Read by |
|---|---|---|---|
| `UBIX_APPROVAL_OWNERS` | `cwolsen` | Comma-separated usernames whose Approve or thumbs-up counts as merge sign-off. Empty makes `require-approval-mr` fail with a message | `require-approval-mr` |
| `UBIX_VAULT_PREFIX` | `kelpie` | Vault path prefix: secrets are read from `secret/kelpie/...` | deploy, migrate, test jobs |
| `UBIX_STAGING_VAULT` | `dev` | Which vault the staging tier reads (dev and staging use vault.dev) | deploy, migrate jobs |
| `UBIX_RUNTIME_DOCKERFILE_STAGING`, `_PROD` | `Dockerfile` | Runtime Dockerfile for those tiers | `build-*` |
| `UBIX_APP_DB_HOST` | `%` | Host part of the `ubixkelpie` database account on all three servers | migrate jobs |
| `UBIX_PROD_DEPLOY_WHEN` | `on_success` | `migrate-apply-prod` stays the manual release click | prod jobs |
| `KELPIE_REVIEW_IMAGE` | `$CI_REGISTRY_IMAGE:$CI_COMMIT_REF_SLUG-$CI_COMMIT_SHA` | Review the MR with the image this pipeline just built | `kelpie-review` |
| `AI_REVIEW_MODE` | `advisory` | Live, but never red | `kelpie-review` |

The template (`ci/ubixcore-pipeline.yml`, pinned in `.gitlab-ci.yml`) has more `UBIX_*` switches,
all with defaults. They are documented in uBixCore's `ci/README.md`; this project sets only the
ones above.

## Read by the shared template, level not confirmed from the code

These are read by uBixCore's pipeline template, not by Kelpie's own files. The code shows that a
job needs them and how they must be set, but not whether they are set on the group or on this
project in GitLab. Check in Settings, CI/CD, Variables before relying on this table.

| Variable | Purpose | Expected setting | Read by |
|---|---|---|---|
| `UBIXVAULT_CI_TOKEN_DEV`, `UBIXVAULT_CI_TOKEN_PROD` | Read-only vault tokens (policy `kelpie-ci-ro`); dev and staging use the dev one, prod the prod one | masked; the prod one should be protected | jobs that read vault (`vault_kv`) |
| `GITLAB_PROMOTE_TOKEN` | Pushes the fast-forward to the next branch | masked, protected (it writes protected branches) | `promote-to-*` |
| `APPROVAL_GITLAB_TOKEN` | Optional. Token for `require-approval-mr`, ahead of `AI_REVIEW_GITLAB_TOKEN` | masked, not protected | `require-approval-mr` |
| `GEMINI_API_KEY` | Key for uBixCore's own Gemini review job, not Kelpie's | masked, not protected | `ai-review-mr` from the template |

## Older shell job (`ci/ai-review/ai-review.yml`)

Not used by `ubixkelpie` itself. A project that includes it instead of `ci/kelpie.yml` also reads
`AI_REVIEW_REF` (default `dev`, keep equal to the include ref), `AI_REVIEW_IMAGE` (default
`node:24-alpine`), `AI_REVIEW_OPENCODE_VERSION` (pinned npm version) and `AI_REVIEW_PROJECT`.
The variables above apply to it too. See `docs/ai-review.md`.

## Predefined GitLab variables

`kelpie-review` also reads `CI_REGISTRY`, `CI_REGISTRY_USER`, `CI_REGISTRY_PASSWORD`,
`CI_PIPELINE_SOURCE`, `CI_API_V4_URL`, `CI_PROJECT_ID`, `CI_PROJECT_DIR`, `CI_JOB_ID`,
`CI_COMMIT_SHA` and the `CI_MERGE_REQUEST_*` set (`IID`, `TITLE`, `LABELS`, `DIFF_BASE_SHA`,
`TARGET_BRANCH_NAME`). GitLab sets these; do not define them yourself.
