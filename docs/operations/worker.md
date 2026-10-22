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
pending job; the coalescer never touches a running one.

## When a running job is superseded

A job's status becomes `superseded` for one of two reasons, and only these:

1. **A newer head, and the job is still pending.** The coalescer replaces it with a job for the new head. A
   job that is already running is never replaced this way: it keeps the lease and finishes, and the event for
   the newer head is picked up by the next webhook or sweep.
2. **The runner ends the job it holds.** When a worker has leased a job (status `running`) and the runner then
   finds that its MR is closed (or is a fork, a protected branch, a draft, or has `triage:off`), that the head
   moved off the sha the job was made at, or that the thread is already answered, it sets that one job to
   `superseded`, clears its lease and does nothing else. This is the only way a running job is superseded, it
   concerns only the job the runner was handed, and no GitLab write happens on that path. The worker sees the
   job is no longer `running` and does not retry it.

A job that has already finished (`done`, `failed`) is never changed by either path. Because the lease is
cleared in the same statement, `markDone`, `markFinished` and `markRetry` on a superseded job match no row and
do nothing, which is the intent: the work was dropped on purpose, not lost.

The caps (commits per MR, attempts per thread, jobs per day)
bind thread jobs. The MR-level rows (`pipeline` without a thread, `review`) run nothing themselves, so
they are not capped and not counted.

## What the worker does with a row

1. **Claim.** A conditional `UPDATE` sets `status = running`, `locked_by` and `locked_until` (30 minutes) on a
   row that is pending, due (`next_attempt_at`) and of an enrolled project, or whose lease has expired. Only
   one worker's `UPDATE` matches; the worker reads the row back by `locked_by` and goes on only if it won.
2. **Run.**
   - A **thread job** runs the fixer runner in propose mode (below): it re-reads the MR and the thread, and
     supersedes its own job when the MR is closed, the head moved or the thread is answered (see "When a running
     job is superseded"). Every step is guarded by the GitLab markers and the head sha, so running a job twice
     does nothing twice.
   - A **fan-out job** (`review`, or `pipeline` without a thread) finds the open MR at its head and queues one
     thread job per thread that needs resolution, using the same per-MR code as the sweep.
3. **Settle.** Done, or put back with an error. Attempt 1 failing retries after 1 minute, attempt 2 after
   5 minutes, attempt 3 is final (`failed`, with `last_error`). A worker that dies leaves a lease that expires;
   the job is then taken again, and one that had already used its third attempt is ended as `failed`.

The loop ends after `--max-jobs` (200) or `--max-seconds` (3600) and the Deployment restarts it. Merge
request jobs are taken before issue triage jobs.

## Propose mode

The runner never pushes. For a thread it:

1. asks the **triage model** for a class: `fix`, `needs_human`, `not_an_issue`, `already_fixed` or `defer`;
2. for `fix`, makes a scratch checkout at the job's head sha and asks the **propose model** for exact-text edits
   to the file the thread is on (`file`, `old` text that occurs once, `new` text). Kelpie applies them itself:
   an edit whose old text does not occur exactly once, or whose path is outside the checkout, under `.git` or
   not a plain file, refuses all of them. The safety limits then run on the real diff (protected paths, 80
   lines, 5 files, no deletes, no weakened tests, attempts). A thread that is not on a file has no text to edit
   and goes to a person;
3. replies on the thread with the diff git wrote (it applies with `git apply` to that sha), the head it was made
   at, and "I have not pushed anything". `not_an_issue` is posted as a proposal to dismiss and `defer` as a
   proposal to defer, each with its reason. Neither resolves the thread; `already_fixed` still resolves it and
   `needs_human` still labels the MR;
4. writes one row to `ubixkelpie.thread_outcomes` for the thread (see below).

The reply promises no reaction handling yet: 👍/👎 on a proposal and push mode are later work.

### Models

| Variable | Default | Meaning |
|---|---|---|
| `KELPIE_TRIAGE_MODEL` | `google/gemini-3-flash` | classifies the thread |
| `KELPIE_PROPOSE_MODEL` | `openai/gpt-5.5` | writes the edits |
| `AI_REVIEW_MODEL` | the review's own default | the model that wrote the review; set it on the worker to the same value as the `kelpie-review` job |

A model that fixes or judges a finding must not be from the family that wrote it. The family is the vendor
part of the OpenRouter id (`anthropic/claude-sonnet-5.5` is `anthropic`; a leading `openrouter/` is ignored). If
the triage or propose model is in the review's family, or an id has no vendor part and cannot be placed, Kelpie
makes no call and the thread goes to a person with the reason. The defaults above are not Anthropic because the
review default is.

### Outcome record

`thread_outcomes` has one row per (project, MR, discussion), replaced by a later run on the same thread. It holds
the head sha, the verdict (the triage class), the mode (`propose`), the outcome, the triage and propose model ids,
the review's family, tokens and cost summed over both calls (`cost_usd` is NULL when OpenRouter reported none),
the proposal note id, and the edits and the diff that were posted. Outcomes written by a run are `proposed` (a
fix, dismissal or deferral is waiting), `answered` (needs human or already fixed) and `failed` (no reply could
be posted or no checkout made); `applied`, `declined`, `overridden`, `reverted` and `reopened` are for what
happens to a proposal afterwards and are written by later work. Useful checks:

```sql
SELECT verdict, outcome, COUNT(*), SUM(cost_usd) FROM ubixkelpie.thread_outcomes
WHERE project_path = 'ubixsys/ubixkelpie' GROUP BY verdict, outcome;
```

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
already writes. `20261022100000_create_thread_outcomes` creates the outcome table the propose-mode runner
writes. Both must be applied before the new image reaches an environment. The Deployment manifest is
`app/KelpieApi/dev-worker.yaml` (dev only for MR jobs; `main-worker.yaml` stays at zero replicas).
