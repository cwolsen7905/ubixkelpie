# The job worker (`kelpie:work`)

Kelpie does its slow work (a model call, a checkout, the gate) in one place: the `kelpie-worker`
Deployment running `php bin/ubix kelpie:work`. The web app and the sweep only write rows to
`ubixkelpie.autofix_jobs`; the worker takes them off.

## Who writes jobs

| Source | When | Row |
|---|---|---|
| Webhook, **Comment events** (Note Hook on an MR) | a person comments in an MR thread | one job per (project, MR, discussion), source `note` |
| Webhook, **Pipeline events** | the MR pipeline failed in a gate stage | one MR-level job, source `pipeline` |
| Webhook, **Job events** | a `kelpie-review` job finishes (success or failed) | one MR-less job per head sha, source `review` |
| Webhook, Issue events | a new issue | an issue triage job (unchanged) |
| `kelpie:sweep` (every 15 minutes) | an open MR has an unresolved, unanswered thread, or a failed gate job | one job per thread, source `note` or `pipeline` |

The webhook filters (loop guards, allow-list, drafts, forks, Shepherd branches) and inserts. It makes
no GitLab or model call. With `KELPIE_AUTOFIX_ENABLED` not `true` it writes nothing.

The GitLab hook on each enrolled project must have **Comments**, **Pipeline events** and **Job events**
ticked; Job events is the one that is new. The review job name is `kelpie-review`; set
`KELPIE_REVIEW_JOBS` (comma separated) on the web app to change it.

## Coalescing and caps

One open (pending or running) job per (project, MR, thread): the unique index `uq_open_job` enforces
it, so two events for one thread, or a webhook racing the sweep, make one row. A newer head supersedes a
pending job; a running job is left alone. The caps (commits per MR, attempts per thread, jobs per day)
bind thread jobs. The MR-level rows (`pipeline` without a thread, `review`) run nothing themselves, so
they are not capped and not counted.

## What the worker does with a row

1. **Claim.** A conditional `UPDATE` sets `status = running`, `locked_by` and `locked_until` (30 minutes) on a
   row that is pending, due (`next_attempt_at`) and of an enrolled project, or whose lease has expired. Only
   one worker's `UPDATE` matches; the worker reads the row back by `locked_by` and goes on only if it won.
2. **Run.**
   - A **thread job** runs the fixer runner: it re-reads the MR and the thread, and ends the job itself when
     the MR is closed, the head moved, the thread is answered or resolved, or the job is done. Every step is
     guarded by the GitLab markers and the head sha, so running a job twice does nothing twice.
   - A **fan-out job** (`review`, or `pipeline` without a thread) finds the open MR at its head and queues one
     thread job per thread that needs resolution, using the same per-MR code as the sweep.
3. **Settle.** Done, or put back with an error. Attempt 1 failing retries after 1 minute, attempt 2 after
   5 minutes, attempt 3 is final (`failed`, with `last_error`). A worker that dies leaves a lease that expires;
   the job is then taken again, and one that had already used its third attempt is ended as `failed`.

The loop ends after `--max-jobs` (200) or `--max-seconds` (3600) and the Deployment restarts it. Merge
request jobs are taken before issue triage jobs.

## The sweep is the backstop

`kelpie:sweep` no longer runs fixes. It walks the open MRs, applies the same filter as the webhook, skips
threads Kelpie has already answered, and queues what is left (at most `KELPIE_SWEEP_MAX_RUNS` new jobs per
sweep, default 5). A job the webhook already wrote is coalesced, not duplicated. Lost deliveries are recovered
at the next sweep, with the worker as the only runner, so the two cannot race.

## Kill switches and checks

- `KELPIE_AUTOFIX_ENABLED` not `true`: the worker claims nothing, the webhook and the sweep write nothing.
- `replicas: 0` on `kelpie-worker`: rows stay queued and run when it is scaled up (stale ones end as
  "head moved" or "MR closed").
- Stuck or failing rows: `SELECT id, project_path, mr_iid, source, status, attempt, last_error FROM
  ubixkelpie.autofix_jobs WHERE status IN ('running', 'failed') ORDER BY id DESC LIMIT 20;`

## Before it runs

The migration `20261021124000_autofix_jobs_worker_columns` adds the lease and retry columns
(`next_attempt_at`, `locked_until`, `locked_by`, `last_error`) and the issue columns the triage worker
already writes. It must be applied before the new image reaches an environment. The Deployment manifest is
`app/KelpieApi/dev-worker.yaml` (dev only for MR jobs; `main-worker.yaml` stays at zero replicas).
