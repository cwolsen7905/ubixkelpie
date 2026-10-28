# Changelog

## Unreleased

## v0.1.0-beta.2

Second beta. Issue intake and triage, the propose workflow with per-project modes, plus
the database, review tooling and planning docs along the way.

- Per-project mode is enforced (K-30 cutover, !58). New table `projects` (migration
  `20261022150000_create_projects`, apply it before the new image; it is created empty).
  The webhook, the worker, `kelpie:sweep`, `kelpie:triage-backfill`, `kelpie:fix` and
  `kelpie:respond` now read each project's row: no row or `off` means Kelpie does
  nothing there, `propose` can only comment, label and propose a diff, and only `push`
  lets a thumbs-up apply a proposal (the one push). `KELPIE_AUTOFIX_PROJECTS` is removed
  from the code and the manifests, with no fallback; `KELPIE_AUTOFIX_ENABLED` stays as the
  global kill switch. Until a row is inserted for a project it stays off, so enrol
  `ubixsys/ubixkelpie` (and any other) with an `INSERT` after the migration.
- Document how a project is enrolled in Kelpie, with Kelpie's own repo as the example (!57).
- Thumbs and `Triage:` on a proposal (K-32, !56): a 👍 from the MR author or a maintainer
  on Kelpie's proposed fix makes the worker apply the stored edits at the recorded head,
  run the gate and push `fix(review): ...` to the MR branch (a moved head gets a "stale"
  reply, a red gate pushes nothing); a 👎 declines it. A reply starting `Triage:` runs the
  thread again with the rest of the text as steering for the triage and propose models.
  Anyone else, and Kelpie itself, is ignored. New webhook handling for Emoji events
  (alongside the sign-off retry, which is unchanged) and `apply`/`decline` job sources;
  the proposal reply now says what the reactions do. Migration
  `20261022140000_autofix_jobs_steering` (apply it before the new image). Emoji events
  must be ticked on the project hook, as for the sign-off retry. No `bin/` change.
- Document the CI variables Kelpie relies on (!54).
- Propose mode (K-31, !53): for each unresolved thread `kelpie:work` now triages, and for
  a `fix` asks a second model for exact-text edits, applies them itself in a scratch
  checkout and replies on the thread with the diff git writes. It no longer pushes. A
  thread can also get a proposed dismissal or a proposed deferral (new `defer` triage
  class) with a reason. The triage and propose models (`KELPIE_TRIAGE_MODEL`,
  `KELPIE_PROPOSE_MODEL`, defaults `google/gemini-3-flash` and `openai/gpt-5.5`) must be
  from a different family than the review's (`AI_REVIEW_MODEL`), or the thread goes to a
  person. New table `thread_outcomes` (migration `20261022100000_create_thread_outcomes`,
  apply it before the new image) records each thread's verdict, outcome, models and cost.
  `docs/operations/worker.md` now also says when a running job is superseded.
- The webhook writes jobs and `kelpie:work` runs them (K-09, !52): MR comments, failed
  gate pipelines and finished `kelpie-review` jobs (new Job events handler) become rows
  in `autofix_jobs`; the worker leases a row with a conditional UPDATE, retries a failure
  after 1 then 5 minutes (three attempts) and runs the fixer. `kelpie:sweep` is now the
  backstop that only queues jobs. Migration `20261021124000_autofix_jobs_worker_columns`;
  see `docs/operations/worker.md`.
- Test image on the official PHP base with pcntl/posix via install-php-extensions (!51).
- Give the review's chat client the OpenRouter timeout too (!50).
- Go-live plan for enrolling the ubixsys group (!49).
- Give the OpenRouter client its own HTTP timeout, 150 s by default (!48).
- Show the container's logs in the kelpie-review job when the review exits non-zero; run
  it advisory in Kelpie's own pipeline (!47).
- Turn autofix off in every manifest until propose mode exists (!46).
- Triage status labels, summary note and 👀 show where triage is on a merge request (!45).
- `kelpie:review`: the AI merge request review as a command in Kelpie's image, running in
  shadow in Kelpie's own pipeline (!44).
- The `projects` table's reads: ProjectMode, Project, repository and mode service (K-30,
  !43).
- Issue triage worker: `kelpie:work` triages new issues, `kelpie:triage-backfill` queues
  issues that were open before triage (!42).
- Document why issue intake showed no 👀 and the path to triage (!41).
- Thin the kelpie commands into services with DI wiring (K-07 part 2, !40).
- Acknowledge accepted issues with a 👀 award emoji (!39).
- Rehome Kelpie classes into family folders (K-07 part 1, !38).
- Inventory of the Kelpie bot account (!37).
- Classify Issue Hook events at intake: triage, gate or ignored (!36).
- Approval retry falls back to `KELPIE_BOT_GITLAB_TOKEN` (!35).
- Plan for issue autofix, first step on ubixshepherd (!34).
- Remove planning documents from the repo (!33).
- Keep fixes out of the moved manifests and all of `sql/` (!32).
- Empty schema baseline for ubixkelpie so phpunit can rebuild the test database (!31).
- ubixkelpie database via the uBixCore CI template; uBixCore 0.47.0 (!30).
- Stamp model, effort, tokens and cost on every AI review summary (!29).
- Architecture audit of uBixCore standards vs Kelpie placement (!28).
- Scheduled autofix sweep and the failed-pipeline source, behind
  `KELPIE_AUTOFIX_ENABLED` (!27).
- Kelpie database setup runbook (!26).
- Resolution comment after a fix, resolve on already_fixed (!25).
- Fixer runner for fix-triaged threads, behind `KELPIE_AUTOFIX_ENABLED` (!24).
- Reply, needs-human label and claim marker for triaged threads (!23).
- Autofix job state: coalescing and caps, plus the `autofix_jobs` migration, job record
  model and SQL repository (!22).
- Classify a review thread via OpenRouter with the safety policy as code, plus a
  `kelpie:triage` dry-run command (!21).
- Filter Note and Pipeline hooks into autofix jobs (log only, !20).
- MR thread reader (Thread DTO + DiscussionReader service, !19).
- Record the eight MR thread autofix decisions (!18).
- Plan MR thread autofix: trigger, triage, fix, resolution (!17).

## v0.1.0-beta.1

First beta. Foundation plus the merge request workflow; issue fixing and code monitoring are
not built yet.

- AI merge request review (`ci/ai-review`, `docs/ai-review.md`): reviews a merge request and
  leaves a visible note when it finds nothing or is skipped.
- Approval webhook: a 👍 emoji on a merge request starts the sign-off job. Requires uBixCore
  ^0.46.1 (`ApprovalWebhookService` reads GitLab 19.4.2's top-level `event_type` for emoji
  events).
- `app/KelpieApi`: health, version, CORS, JSON errors and the GitLab webhook; deployed through
  the `dev` → `staging` → `main` pipeline.
- `app/HelloApi`: health.
