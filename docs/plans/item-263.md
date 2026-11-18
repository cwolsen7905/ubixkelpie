# Item 263 — Kelpie takes over MR review threads on ubixcore, then the other ubixsys repos

Goal: an AI-review thread on a merge request in `ubixsys/ubixcore`, and afterwards in the other
ubixsys repos one at a time, is stored as an autofix job and answered by the worker (a fix proposal,
a dismissal or a needs-you flag). A lost delivery is recovered by the sweep. Everything runs in
`propose` mode: Kelpie pushes nothing.

## The item's wording is out of date
The item says "added to `KELPIE_AUTOFIX_PROJECTS`". That list is gone and nothing reads it. A project
is enrolled by a row in `ubixkelpie.projects` (mode `propose`) on each tier that should act on it; no
row means off. `KELPIE_AUTOFIX_ENABLED` is only the global kill switch. See `docs/operations/projects.md`.
Read "enrol" below as "add the row".

## Pattern (item 203)
Same mechanism as for ubixshepherd (`docs/plans/item-203.md`, `docs/enrolment/ubixshepherd.md`):
the Note Hook enqueues one job per (project, MR, thread) through `EnqueueService`; the worker
(`kelpie:work`) answers it; the sweep (`kelpie:sweep`, a CronJob every 15 minutes) queues threads whose
delivery was lost; the `projects` row lets all three act. No code change is needed per project.

## State to re-check before starting
Snapshot from the repo on 2026-11-18; verify each against the live tier, it may have moved.
- Mechanism is on `dev`. Enrolled: `ubixsys/ubixkelpie` and `ubixsys/ubixshepherd` only.
- Dev: sweep CronJob un-suspended (`app/KelpieApi/dev-cronjob.yaml`), worker manifest `dev-worker.yaml`.
- Prod: `main-worker.yaml` exists (one replica, `KELPIE_AUTOFIX_ENABLED=true`), but there is **no prod
  sweep CronJob** (`app/KelpieApi/` has only `dev-cronjob.yaml`).
- `deploy.sh` does not apply `*-worker.yaml`; worker changes are applied by hand until the pin moves.
- ubixcore `main` already includes `ci/kelpie.yml` (job `kelpie-review`, pinned to a ubixkelpie
  commit). It reviews only MRs into `AI_REVIEW_BASE_BRANCH` (default `dev`), and ubixcore's default
  branch is `main`.
- ubixcore has its own project hook to the prod Kelpie URL plus a merge-request-only hook to uBixOps.
- Go-live notes (`docs/operations/go-live.md`) list the safety items #214 (spend caps), #215 (separate
  prod bot token and secret) and #216 (injection scan) as gates before widening beyond Kelpie's own repo.

## Where to start: dev, then prod
Do the whole ubixcore sequence on dev first (the dev instance answers threads, as it does for
ubixshepherd), and repeat on prod only after the dev check passes and the prod preconditions hold.
One hook per project, always: a project's hook points at exactly one Kelpie instance.

## Part 1 — ubixcore
1. **Preconditions on the tier** (outside this repo): worker Deployment idling, not restarting; Vault
   `kelpie/<env>/app` has `KELPIE_BOT_GITLAB_TOKEN`, `OPENROUTER_API_KEY`, `GITLAB_API_URL`; sweep
   CronJob exists and is not suspended (prod: needs a new `main-cronjob.yaml`, a Kelpie MR in its own
   lane, mirroring `dev-cronjob.yaml`).
2. **Bot access.** The Kelpie bot must be a member of `ubixsys/ubixcore` (Reporter is enough for
   `propose`: comment and label). Check it; do not widen beyond that.
3. **Review job present.** Confirm `kelpie-review` runs on a ubixcore MR and that
   `AI_REVIEW_BASE_BRANCH=main` is set (in ubixcore's CI or project variables), otherwise no thread is
   ever created for the MRs that matter. Group variables `OPENROUTER_API_KEY`, `AI_REVIEW_GITLAB_TOKEN`
   and the registry pull login (`KELPIE_REGISTRY_USER`/`KELPIE_REGISTRY_TOKEN`) must exist, or the job
   exits 75 (unreviewed).
4. **Follow-through owner.** Name who reads Kelpie's proposals and dismissals on ubixcore (a person on
   the ubixcore side). Kelpie's replies need a human to apply or close them.
5. **Webhook.** Point ubixcore's Kelpie hook at the target tier (dev: `https://kelpie.dev.ubixsys.com/gitlab/webhook`
   with the dev secret; prod: the prod URL and secret). Events: merge requests, comments (note), emoji, issues,
   pipeline, job; SSL verification on. Repoint, never add a second hook. Leave the uBixOps hook alone.
   Record the prior state first, for rollback.
6. **Row** (SQL on that tier's `ubixkelpie`, idempotent, read it back):
   ```sql
   INSERT IGNORE INTO ubixkelpie.projects (path, mode) VALUES ('ubixsys/ubixcore', 'propose');
   SELECT path, mode FROM ubixkelpie.projects WHERE path = 'ubixsys/ubixcore';
   ```
   The table is read on every pass; no restart needed.
7. **Check** (same as ubixshepherd): reply in a thread on a non-draft ubixcore MR; the webhook log shows
   `GitLab webhook: autofix job` and an `autofix_jobs` row has source `note`; the worker answers and the
   MR gets `triage: proposed` or `triage: needs you`; disable the hook, reply, run a one-off job from the
   sweep CronJob and confirm the row appears; no commit from the bot appears on any branch.
8. **Soak** one week in `propose`; read the comments and labels before enrolling the next repo.

**Done when (ubixcore):** a note on a ubixcore MR is stored and answered by the worker on the tier,
the sweep recovers a lost delivery, no bot commit exists on any branch, and the soak was read by the
named owner. Recorded in a short `docs/enrolment/ubixcore.md` (a docs MR in this repo), in the style
of `docs/enrolment/ubixshepherd.md`.

## Part 2 — the other repos, one at a time
Order (code repos that benefit first, rarely changed or docs-like last); each waits for the
previous repo's soak:

1. `ubixsys/ubixvault`
2. `ubixsys/replikate` (has no `.gitlab-ci.yml`: add one with the include first)
3. `ubixsys/ubixsys-web`
4. `ubixsys/ubixcore-php`, then `ubixsys/ubixcore-skeleton`
5. `ubixsys/ubcore`
6. `ubixsys/phpmyadmin` (low value; the person may drop it)

Left out unless the person asks: `ubixsys-docs`, `ubixsys-manual-examples`, and projects with no
pipeline and little code (`ubixshepherd-internal`, `airdropt`, `node-scripts`).

Per-repo checks before the row goes in (all must be yes):
- **AI review job present:** the repo's `.gitlab-ci.yml` includes `ci/kelpie.yml`, with
  `AI_REVIEW_BASE_BRANCH` for a `main`/`master` default, and a recent MR shows a `kelpie-review` run that
  was not exit 75. No review job means no Kelpie threads; skip the repo until fixed.
- **Follow-through owner named** for the repo.
- **One hook,** pointing at the right tier; no group hook plus project hook duplicate.
- **Bot is a member** at Reporter level.
- **Safety gates** #214, #215 and #216 are settled as the person decided in go-live; if not, stop.
- **Prior soak** of the previous repo was read and found acceptable.

Then run steps 5 to 8 of Part 1 for that repo.

**Done when (each repo):** the Part 1 check passes for that repo on the tier, its soak was read by its
owner, and its section is added to the enrolment docs.

## Rollback
- One project: `UPDATE ubixkelpie.projects SET mode = 'off' WHERE path = '<path>';` (takes effect on the next pass).
- One item: the `triage:off` label.
- Whole tier: `KELPIE_AUTOFIX_ENABLED=false` and redeploy, or `replicas: 0` on the worker.
- The sweep alone: `suspend: true` on the CronJob.
- The hook: put the recorded prior URL, token (from Vault, never from this repo) and events back.
- Deleting the row is not needed; `off` is the same as no row.

## Needs a separate lane or the person (not in this repo)
| Step | Who |
| --- | --- |
| Hook repoint on each GitLab project; bot membership | person or Shepherd (GitLab settings) |
| `projects` rows by SQL, especially on prod | person (production data) |
| Vault keys, group CI variables, registry pull access, OpenRouter limit | person |
| Prod sweep CronJob `main-cronjob.yaml` | separate Kelpie lane (MR here) |
| Applying `*-worker.yaml` by hand until the pin moves | person or a deploy lane |
| `include` of `ci/kelpie.yml`, `AI_REVIEW_BASE_BRANCH`, a pipeline where none exists | one lane per repo, in that repo |
| Safety items #214, #215, #216 | per the go-live decisions |
| Enrolment docs per repo (`docs/enrolment/<repo>.md`) | docs lane in this repo |

In this repo only the plan and the later enrolment notes change; no code.
