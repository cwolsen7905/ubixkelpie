# Changelog

## Unreleased

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
