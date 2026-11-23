# Enrolling `ubixsys/ubixshepherd` on dev, now in `push` mode

Goal: an AI-review thread on a ubixshepherd merge request is picked up by Kelpie, which posts a fix
proposal (a diff in the reply), a dismissal or a needs-human flag. Since 2026-11-22 the project is on `push`
mode on dev: Kelpie also pushes automatic rounds under the round cap (`docs/autofix/push-mode.md`).
Dev only; production is not touched by this runbook. General background: `docs/operations/projects.md`
and `docs/operations/worker.md`.

## State on origin/dev (checked 2026-11-05, `7f17abc`)

| Piece | State |
| --- | --- |
| Note Hook enqueues the thread | In code: one job per (project, MR, thread), source `note`. |
| Gate | The `projects` table, not an env list. `KELPIE_AUTOFIX_PROJECTS` is gone. No row means off. |
| Kill switch | `KELPIE_AUTOFIX_ENABLED=true` in the dev deploy, worker and sweep manifests. |
| Sweep cronjob | `app/KelpieApi/dev-cronjob.yaml`, every 15 minutes, suspended until the token is in (see step 1). |
| Worker | `app/KelpieApi/dev-worker.yaml`; deploy.sh skips `*-worker.yaml`, so it is applied by hand. |
| `projects` row | Done on dev: `ubixsys/ubixshepherd`, mode `push` since 2026-11-22 (enrolled as `propose`), defaults for the rest (step 4). Staging and prod have none. |
| Dev webhook on ubixshepherd | Done 2026-11-05: hook 5 now points at dev (step 2). |

## Steps (each outside the repo, done by a person or Shepherd)

1. **Worker running in `kelpie-dev`.** The pod is up and idling (not restarting). The sweep only queues;
   nothing answers a thread without it. Checked 2026-11-05: the worker is in CrashLoopBackOff, because
   `KELPIE_BOT_GITLAB_TOKEN` is missing from uBixVault `kelpie/dev/app` (`GITLAB_API_URL` and
   `OPENROUTER_API_KEY` are there). Add it on vault.dev, then
   `kubectl -n kelpie-dev rollout restart deploy/kelpie-worker` and watch that it idles. The sweep needs the
   same token, so `dev-cronjob.yaml` stays `suspend: true`. Un-suspend it (a separate change) once the token is in
   and the worker idles, and confirm the image has the `claude` CLI and that Vault hydration runs for the
   command override.
   `dev-worker.yaml` is applied by hand: deploy.sh skips it until kelpie's ubixcore pin (v0.47.0) is bumped
   past ubixcore !227, so a later change to the file needs another by-hand apply.
2. **Repoint hook 5 to dev (decided; one hook, never two).** Hook 5 on `ubixsys/ubixshepherd` sent every
   event to prod. It is repointed, not joined by a second hook, so prod stops receiving ubixshepherd events
   and no two instances answer one thread. **Done 2026-11-05.** The token is the dev
   `GITLAB_WEBHOOK_SECRET` from uBixVault `kelpie/dev/app`, read with the dev CI token and never printed.
   Read back afterwards: URL `https://kelpie.dev.ubixsys.com/gitlab/webhook`, SSL verification on, events on:
   issues, merge requests, note, emoji, pipeline, job; the project still has exactly one hook.

   **Vault policy change (dev Vault only).** The CI read token's policy `ubixcore-ci-ro` gained one rule,
   read on `secret/data/kelpie/dev/app`, so it can read that path. It is now:
   `{"path":{"secret/data/ubixcore/*":{"capabilities":["read"]},"secret/data/kelpie/dev/app":{"capabilities":["read"]}}}`.
   Rollback: write the original back to `sys/policies/acl/ubixcore-ci-ro` on vault.dev, which was exactly
   `{"path":{"secret/data/ubixcore/*":{"capabilities":["read"]}}}`. The write used the dev root token for that
   one call; nothing else was written to Vault and prod Vault was not touched.
   The person chose this over a new policy. Consequence: whatever uses the dev CI token can now read the
   dev Kelpie app secrets. A dedicated read-only policy and token for the Kelpie path, bound to the deploy
   job, is the narrower follow-up if that is wanted.
3. **The hook's new shape.** URL `https://kelpie.dev.ubixsys.com/gitlab/webhook`, the dev secret as token,
   SSL verification on, events: comments (note), emoji, pipeline, job, and merge request kept on. Issue events
   stay on as they were.

   Prior state, for rollback (checked 2026-11-05): URL `https://kelpie.prod.ubixsys.com/gitlab/webhook`,
   SSL verification on; events on: issues, merge requests, comments (note), emoji; off: pipeline, job and the
   rest. The prior token is the prod secret: restore it from uBixVault `kelpie/prod/app` (prod vault), never
   from this repo.

   Roll back (`glab api -X PUT projects/ubixsys%2Fubixshepherd/hooks/5`, with `url` set to the prod URL,
   `token` read from vault into a variable, `pipeline_events=false`, `job_events=false`; leave the rest).
4. **Row, by hand, on the dev database only** (`ubixkelpie`; idempotent, read it back):

   ```sql
   INSERT IGNORE INTO ubixkelpie.projects (path, mode)
   VALUES ('ubixsys/ubixshepherd', 'propose');
   SELECT path, mode FROM ubixkelpie.projects WHERE path = 'ubixsys/ubixshepherd';
   ```

   Status 2026-11-05: **done on dev.** The `INSERT IGNORE`, run from the kelpie-dev API pod with the
   hydrated dev credentials (host and database checked first), inserted nothing because the row was already
   there (id 2, created 2026-10-28). Read back: `ubixsys/ubixshepherd`, `propose`, 30 issues a day, 10 per
   author, 2.00 USD a day, 3 fix commits per MR. `ubixsys/ubixkelpie` (id 1) is also `propose`. Staging and
   prod were not touched.

   Status 2026-11-22: **switched to push mode, on dev only.** The person ran, by hand on the dev database:

   ```sql
   UPDATE ubixkelpie.projects SET mode = 'push' WHERE path = 'ubixsys/ubixshepherd';
   ```

   1 row affected. Staging and prod were not touched. Rollback: `SET mode = 'propose'` (proposals only,
   nothing pushed), or `SET mode = 'off'`, with the same `WHERE`.

   **Gate: `ci`.** ubixshepherd is Go, and Kelpie's image has no Go toolchain, so `make check` cannot run in
   Kelpie's checkout. With `gate_command = 'ci'` a push round runs no gate here and leaves it to the MR
   pipeline (`go-check`); each `Fixed:` reply says so. Run by hand on the dev database:

   ```sql
   UPDATE ubixkelpie.projects SET gate_command = 'ci' WHERE path = 'ubixsys/ubixshepherd';
   ```

   Rollback: `SET gate_command = NULL` (rounds then try `make check` and go red, so nothing is pushed).

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
4. A commit from the bot appears only from a push round (`fix(review)`, with a `Thread:` line per thread) or
   after a thumbs-up, never from a thread that was only proposed.

## 👍 in push mode

Push mode pushes automatic rounds under the round cap (5 threads, 80 lines), see
`docs/autofix/push-mode.md`. A round over the cap pushes nothing and leaves its proposals open. A
thumbs-up on such a proposal pushes it alone, and that path is not capped, because a person asked for it.
Switching the project to `push` was the maintainer's decision (2026-11-22, dev only).

## Rollback

- Back to proposals only: `UPDATE ubixkelpie.projects SET mode = 'propose' WHERE path = 'ubixsys/ubixshepherd';`
- One project, fully: `UPDATE ubixkelpie.projects SET mode = 'off' WHERE path = 'ubixsys/ubixshepherd';`
- One item: the `triage:off` label.
- Everything on dev: `KELPIE_AUTOFIX_ENABLED=false` and redeploy, or `replicas: 0` on the worker.
- The sweep alone: `suspend: true`.

## Bot identity on dev

`KELPIE_BOT_USERNAME` is dev's own bot, `project_160_bot_6902be9519087b25c09cff5971a9002f` (the project token on
ubixsys/ubixshepherd), in `dev-deploy.yaml`, `dev-cronjob.yaml` and `dev-worker.yaml`, so the API, the sweep and
the worker agree on which notes are Kelpie's own. The token itself is `KELPIE_BOT_GITLAB_TOKEN` in uBixVault
`kelpie/dev/app`, read at run time, in no manifest. Staging and prod keep the prod group bot. `deploy.sh` does
not apply `dev-worker.yaml`, so a change to this value needs a by-hand apply of that file as well.
