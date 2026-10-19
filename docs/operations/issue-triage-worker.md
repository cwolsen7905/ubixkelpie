# Issue triage worker

Step 5 of `docs/operations/intake-status.md`: for each newly accepted issue Kelpie posts **one
comment** and adds **one `kelpie::*` label**, exactly once, and a redelivered webhook repeats
neither. No code is changed.

## How it runs

1. The webhook (`IssueOpenWebhookService`) classifies an Issue event. For `triage` it adds the 👀 and
   queues one `autofix_jobs` row (`source = 'issue_triage'`). It does no AI work, and a queue failure
   is logged and still answers 200.
2. The worker, `php bin/ubix kelpie:work` (Deployment `kelpie-worker`), takes the oldest due row,
   reads the issue by `GET`, asks the triage model (`KELPIE_TRIAGE_MODEL`, default
   `anthropic/claude-haiku-5.5`) and posts the comment and the label as `kelpie-bot`. The issue's
   title and description are read at run time and never stored or logged.
3. The loop ends by itself after 200 jobs or an hour (`--max-jobs`, `--max-seconds`) and the
   Deployment restarts it.

## Exactly once

- `UNIQUE (project_path, issue_iid, source)`: a redelivery cannot add a second job.
- The comment ends with `<!-- kelpie:triage issue=N class=<class> -->`. Before posting, the worker
  lists the bot's own notes and skips the comment and the model call if the marker is there. A
  crash between the comment and the label is repaired by the next attempt, which adds the label only.
- A worker takes a job with one conditional `UPDATE` on the attempt it read, then reads back that
  its `locked_by` is on the row. A lock lasts 10 minutes; a crashed worker's job is taken again after.

## Retries and giving up

Three attempts. A failed attempt (GitLab unreachable, no usable model answer, a post refused) is
retried after 1 minute, then 5. After the third the job is `failed` with `last_error` set; nothing
is posted, because a failed model call never becomes a made-up verdict.

## Skipped without a comment or a model call

A confidential issue (its text would go to OpenRouter), an issue labelled `kelpie:skip`, and an
issue opened by the bot.

## Labels and comment

| Class | Label | Heading |
|---|---|---|
| `fix` | `kelpie::fixable` | Kelpie could attempt a fix |
| `needs_human` | `kelpie::needs-human` | needs a human |
| `not_a_defect` | `kelpie::not-a-defect` | not a defect |

`kelpie::fixable` is a verdict, not the gate: the gate label stays `kelpie::fix` and fixing is a later step.

## Kill switch

`KELPIE_AUTOFIX_ENABLED` not `true`: the worker idles and claims nothing; rows stay pending. A project
not in `KELPIE_AUTOFIX_PROJECTS` is not claimed. `replicas: 0` stops the worker; the webhook then
keeps queueing, and the backlog is triaged when it is scaled up.

## Before it can run

- **Migration** (a person writes it; the code expects exactly these columns):

  ```sql
  ALTER TABLE ubixkelpie.autofix_jobs
      ADD COLUMN issue_iid       INT UNSIGNED NULL AFTER mr_iid,
      ADD COLUMN next_attempt_at DATETIME     NULL AFTER attempt,
      ADD COLUMN locked_until    DATETIME     NULL AFTER next_attempt_at,
      ADD COLUMN locked_by       VARCHAR(64)  NULL AFTER locked_until,
      ADD COLUMN last_error      VARCHAR(255) NULL AFTER locked_by,
      ADD UNIQUE KEY uq_issue_job (project_path, issue_iid, source),
      ADD KEY idx_claim (status, next_attempt_at);
  ```

  MR rows keep `issue_iid` NULL, so the unique key never touches them. Issue rows use `mr_iid = 0`,
  `head_sha = ''` and `thread_key = 'issue-<iid>'` (so the existing one-open-job key holds per issue).
  Apply it before the new image reaches an environment: the webhook enqueue writes to these columns.
- **uBixVault** `kelpie/<env>/app` holds `KELPIE_BOT_GITLAB_TOKEN` (`api` scope, Developer on the
  project), `OPENROUTER_API_KEY` and `GITLAB_API_URL`. Without one the worker exits with an error line.
- **Manifests:** `app/KelpieApi/dev-worker.yaml` (1 replica) and `main-worker.yaml` (**0 replicas**:
  scale it in git once the migration and the secrets are in place and dev has run).
- **Not confirmed:** that the image's Vault hydration runs for a command override. Dev is where to see it.

## Proving it on a throwaway issue

On `ubixsys/ubixshepherd`, a person opens an issue, then:

1. The 👀 appears, then one comment ending in the marker and one `kelpie::*` label.
2. Resend the delivery from the hook's Recent deliveries: no second comment, label or job row.
3. Edit or relabel the issue: nothing new.
4. The worker log has one `Issue triage: done` line for the issue. Close the issue.
