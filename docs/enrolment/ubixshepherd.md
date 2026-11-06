# Enrolling `ubixsys/ubixshepherd` on dev, in `propose` mode

Goal: an AI-review thread on a ubixshepherd merge request is picked up by Kelpie, which posts a fix
proposal (a diff in the reply), a dismissal or a needs-human flag. Kelpie pushes nothing in this mode.
Dev only; production is not touched by this runbook. General background: `docs/operations/projects.md`
and `docs/operations/worker.md`.

## State on origin/dev (checked 2026-11-05, `7f17abc`)

| Piece | State |
| --- | --- |
| Note Hook enqueues the thread | In code: one job per (project, MR, thread), source `note`. |
| Gate | The `projects` table, not an env list. `KELPIE_AUTOFIX_PROJECTS` is gone. No row means off. |
| Kill switch | `KELPIE_AUTOFIX_ENABLED=true` in the dev deploy, worker and sweep manifests. |
| Sweep cronjob | `app/KelpieApi/dev-cronjob.yaml`, every 15 minutes, un-suspended by this change. |
| Worker | `app/KelpieApi/dev-worker.yaml`; deploy.sh skips `*-worker.yaml`, so it is applied by hand. |
| `projects` row | Not there until an operator inserts it (below). |
| Dev webhook on ubixshepherd | Not there. Hook 5 on the project points at **prod**. |

## Steps (each outside the repo, done by a person or Shepherd)

1. **Worker running in `kelpie-dev`.** The pod is up and idling (not restarting). The sweep only queues;
   nothing answers a thread without it.
2. **Settle prod's hook first.** Hook 5 on `ubixsys/ubixshepherd` sends every event to prod. Two live hooks
   deliver each event to both instances, and two instances can answer one thread. Either repoint or
   pause hook 5, or confirm prod has autofix off and no ubixshepherd row, before step 3.
3. **Dev webhook.** On the project (or the group, never both): URL
   `https://kelpie.dev.ubixsys.com/gitlab/webhook`, the dev secret from uBixVault, SSL verification on,
   events: comments, emoji, pipeline, job (and merge request and issue if wanted).
4. **Row, by hand, on the dev database only** (`ubixkelpie`; idempotent, read it back):

   ```sql
   INSERT IGNORE INTO ubixkelpie.projects (path, mode)
   VALUES ('ubixsys/ubixshepherd', 'propose');
   SELECT path, mode FROM ubixkelpie.projects WHERE path = 'ubixsys/ubixshepherd';
   ```

   The other columns take their defaults (30 issues a day, 10 per author, 2.00 USD a day, 3 fix
   commits per MR). There is deliberately no migration: a migration would run on staging and prod too.
   The table is read on every pass, so no restart is needed.
5. **Review job.** ubixshepherd's `ai-review-mr` shell job is replaced by the `ci/kelpie.yml` include
   (an MR on ubixshepherd). Until then there are no Kelpie threads, only a person's or the old job's.

## Check

1. Reply in a thread on a ubixshepherd MR (not a draft). The webhook log shows `GitLab webhook: autofix job`
   and an `autofix_jobs` row appears with source `note`.
2. The worker claims it; the thread gets a proposal (diff), a dismissal or a needs-you flag, and the MR gets
   a `triage: proposed` or `triage: needs you` label.
3. Lost delivery: temporarily disable the hook, reply in a thread, run
   `kubectl -n kelpie-dev create job --from=cronjob/kelpie-autofix-sweep sweep-check`, and confirm the
   job row appears. Re-enable the hook.
4. No commit from the bot appears on any branch.

## 👍 in propose mode

A thumbs-up on a proposal does not push here. The reply says the project is not set to push, and no
commit is made. Only `push` mode applies a proposal. Setting `push` is the maintainer's separate decision
and is not part of this enrolment.

## Rollback

- One project: `UPDATE ubixkelpie.projects SET mode = 'off' WHERE path = 'ubixsys/ubixshepherd';`
- One item: the `triage:off` label.
- Everything on dev: `KELPIE_AUTOFIX_ENABLED=false` and redeploy, or `replicas: 0` on the worker.
- The sweep alone: `suspend: true`.
