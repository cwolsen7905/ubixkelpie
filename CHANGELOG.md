# Changelog

## Unreleased

- Thumbs and `Triage:` on a proposal (K-32): a 👍 from the MR author or a maintainer on Kelpie's proposed fix makes the
  worker apply the stored edits at the recorded head, run the gate and push `fix(review): ...` to the MR branch (a
  moved head gets a "stale" reply, a red gate pushes nothing); a 👎 declines it. A reply starting `Triage:` runs the
  thread again with the rest of the text as steering for the triage and propose models. Anyone else, and Kelpie
  itself, is ignored. New webhook handling for Emoji events (alongside the sign-off retry, which is unchanged) and
  `apply`/`decline` job sources; the proposal reply now says what the reactions do. Migration
  `20261022140000_autofix_jobs_steering` (apply it before the new image). Emoji events must be ticked on the
  project hook, as for the sign-off retry. No `bin/` change.
- Propose mode (K-31): for each unresolved thread `kelpie:work` now triages, and for a `fix` asks a second model
  for exact-text edits, applies them itself in a scratch checkout and replies on the thread with the diff git
  writes. It no longer pushes. A thread can also get a proposed dismissal or a proposed deferral (new `defer`
  triage class) with a reason. The triage and propose models (`KELPIE_TRIAGE_MODEL`, `KELPIE_PROPOSE_MODEL`,
  defaults `google/gemini-3-flash` and `openai/gpt-5.5`) must be from a different family than the review's
  (`AI_REVIEW_MODEL`), or the thread goes to a person. New table `thread_outcomes` (migration
  `20261022100000_create_thread_outcomes`, apply it before the new image) records each thread's verdict, outcome,
  models and cost. `docs/operations/worker.md` now also says when a running job is superseded.
- The webhook writes jobs and `kelpie:work` runs them (K-09): MR comments, failed gate pipelines and
  finished `kelpie-review` jobs (new Job events handler) become rows in `autofix_jobs`; the worker leases a row
  with a conditional UPDATE, retries a failure after 1 then 5 minutes (three attempts) and runs the fixer.
  `kelpie:sweep` is now the backstop that only queues jobs. Migration
  `20261021124000_autofix_jobs_worker_columns`; see `docs/operations/worker.md`.

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
