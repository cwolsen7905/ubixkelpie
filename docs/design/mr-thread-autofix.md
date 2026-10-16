# MR thread autofix

**Status:** Decided (design only, no code). **Date:** 2026-10-15. The person accepted all eight
recommendations on 2026-10-15; they are recorded in section 8.

Goal, in the person's words: Kelpie gets notified when there are issues that need resolution,
uses AI to triage whether it can safely fix them, and if so fixes them, pushes onto the same
branch and puts the resolution comment.

This builds on the receiver that already exists (`POST /gitlab/webhook`, `GitlabWebhookController`,
`NoteOnMrWebhookService`, which today only logs) and on the AI review template
(`ci/ai-review/ai-review.yml`, OpenRouter, `AI_REVIEW_GITLAB_TOKEN`). It is the fixing half of
`docs/design/issue-triage.md`, for MR threads rather than issues.

What needs resolution: unresolved AI review threads, unresolved human review threads, and a failed
MR pipeline.

## 1. Trigger

| Option | For | Against |
| --- | --- | --- |
| **Webhook into KelpieApi** (Note Hook on MRs, Pipeline Hook) | Works for any ubixsys MR with no per-repo CI change; sees human threads and failed pipelines; receiver exists | Needs a group webhook; needs a queue/state (see 3) |
| CI job after ai-review | No new service | Only sees AI threads, runs before humans comment, every repo must include it, runs inside the pipeline it would re-trigger |
| Schedule | Simple, self-healing | Latency; wasteful polling |

**Decision: the webhook**, with a scheduled sweep (every 15 min over open MRs with unresolved
threads) as a safety net for lost deliveries. Both feed the same entry point, so the sweep adds no
second code path.

The webhook handler does no AI work inline: it validates, filters (section 6 loop guards),
and enqueues one job per `(project, MR iid, head sha)`. Jobs for the same MR are serialised and
coalesced; a newer head sha supersedes a queued job.

## 2. Triage

Per unresolved thread, the model returns one class and a short reason:

| Class | Meaning | Action |
| --- | --- | --- |
| `fix` | A concrete, local change addresses it | go to section 3 |
| `needs_human` | Design call, ambiguous, large, or touches a forbidden path | section 5 |
| `not_an_issue` | Reviewer is mistaken or the point is a preference | reply with the reasoning; leave open for the reviewer |
| `already_fixed` | The code at head already satisfies it | reply with the commit; resolve the thread |

**Model sees:** the thread (all notes, author, file and line), the diff hunk and the full file at
head, the MR title/description and diff (truncated at a byte cap), the target branch's
`CLAUDE.md`/`AGENTS.md` if present, and for a failed pipeline the failing job's log tail. It never
sees secrets or CI variables. MR text and notes are untrusted input: the prompt says so, and tool
use is restricted to the checkout (no network, no token in the container environment).

**Model:** through OpenRouter, like ai-review. Triage uses a cheap model (default
`anthropic/claude-haiku-5.5`); the fix step uses a stronger one (default
`anthropic/claude-sonnet-5.5`). Both are env-configurable.

**Hard limits on "safe"** (enforced by code after the model answers, never by the prompt alone;
violating any one downgrades to `needs_human`):

- Diff of the fix: at most 80 changed lines across at most 5 files, no file deletions or renames.
- Never touch: `.gitlab-ci.yml`, `ci/`, `.env*`, anything matching secret patterns, `sql/migrations/`,
  `composer.lock`/lockfiles, `app/*/k8s/`, deploy scripts, or the repo's own gate config
  (`phpcs.xml*`, `phpstan*`, `phpunit.xml*`).
- No weakening of tests: only additive test changes, no deleted or skipped tests, no baseline growth.
- The repo's gate (phpcs, phpstan, phpunit; or the repo's declared commands) must pass in the runner.
- At most 2 fix attempts per thread and 3 fix commits per MR, then `needs_human`.
- Only MRs targeting `dev`/`staging`, in an allow-listed set of projects; not drafts.

## 3. Fix

- **Where:** an ephemeral container per job on the existing docker-capable runner (`shell` tag),
  using the repo's own CI image so the gate is the same one CI runs. No persistent workspace,
  no network except GitLab, OpenRouter and the package registry.
- **Checkout:** clone the MR source branch at the exact head sha the triage saw. If the branch
  has moved by push time, abort and re-enqueue (no rebase, no merge).
- **Agent:** an opencode run (as ai-review does) with edit access to the checkout only, given the
  triaged threads for that MR in one batch, so one MR gets one commit rather than one per thread.
- **Gate:** run the repo's gate; on red, one retry with the failure output, then give up to
  `needs_human`.
- **Push:** a normal fast-forward push of one commit to the same source branch. Never
  `--force`, never `--force-with-lease`; a rejected push means someone else pushed, so abort. The
  agent never touches the target branch, never approves, never merges.
- **Identity:** a dedicated bot user (`kelpie-bot`), commit author set to it, commit trailer
  `Kelpie-Autofix: <thread ids>` and the Developer role on ubixsys projects with an `api` scope
  project/group access token (needed to push and to reply on threads). It cannot be Maintainer
  and is not in the approvers, so protected-branch and approval rules still bind it. Where the
  source branch is protected, the push fails and the thread goes to `needs_human`.
- **Loops:** its own push re-triggers MR pipelines, ai-review and the Note/Pipeline hooks. Guards,
  all required: (1) ignore events authored by the bot user; (2) a job for a head sha whose commit
  has the `Kelpie-Autofix` trailer is only allowed to act on *new* threads opened by someone else
  after that commit; (3) the per-MR cap in section 2; (4) the skip-ci option is *not* used, since
  the gate must run on the new commit.

## 4. Resolution comment

Reply in the thread (never a top-level note), e.g. "Fixed in <commit link>: <one-line what changed>.
Gate: phpcs/phpstan/phpunit green." One reply per thread, naming the commit URL.

**Decided: do not resolve the thread** for `fix`; leave it for the reviewer. The AI is
unverified and the reviewer should confirm; an MR's "all threads resolved" gate then still means a
person looked. Exceptions the bot resolves itself: `already_fixed` (the thread is stale, nothing to
confirm). AI-authored (ai-review) threads on a `fix` are not auto-resolved either, for now (decision 3).

## 5. Not-safe path

For `needs_human`, `not_an_issue` and failed gates: reply on the thread with the class, the
reason and what the bot tried (if anything), add the `kelpie::needs-human` label on the MR, and do
not push. Once a chat channel is wired (not yet; see `docs/merge-signoff.md`), also notify the MR
author and assignee there. Until then GitLab's own notification of the thread reply is the
notice. A thread the bot has classified is not re-triaged until a new note arrives in it.

## 6. Secrets, state, and the boundary

- **uBixVault** (`kelpie/<env>/app`): `OPENROUTER_API_KEY`, `KELPIE_BOT_GITLAB_TOKEN`,
  `GITLAB_WEBHOOK_SECRET` (existing). The runner receives the token only for clone/push, via
  a credential helper, never in the agent's environment. Nothing in `.env`.
- **State:** a small table (job, MR, head sha, thread, class, attempt, commit) is needed for
  coalescing, idempotency and caps, which means Kelpie's first database and migrations. Decision 4
  accepts this.
- **Boundary (CLAUDE.md):** to uBixCore only generic primitives: a queue/job runner, a
  GitLab API client, an OpenRouter/LLM client, a sandboxed process runner. In `Kelpie\`: thread
  and triage models, the safety policy, the prompts, the bot's comment text, the orchestration.
  If a primitive is missing upstream, add it there and bump the pin; no local shim.

### Overlap with Shepherd

Shepherd already nudges its own agents about unresolved threads on MRs it opened. Both must not
fix the same thread. Rules: (1) **Kelpie skips MRs from `shepherd`-owned branches** (lane
branches it opened, identified by the author being the Shepherd bot/the lane's branch prefix);
Shepherd keeps ownership there. (2) For any other MR Shepherd does nothing, so Kelpie owns it.
(3) Belt and braces: before acting Kelpie posts/checks a claim marker, a hidden note
`<!-- kelpie:claim thread=<id> sha=<sha> -->` plus label `kelpie::working`, and skips a thread that
carries a fresh claim from anyone; Shepherd is asked (via ask_shepherd) to honour the same marker.

## 7. Steps (one MR each)

| # | MR | Scope | Depends on |
| --- | --- | --- | --- |
| 1 | Thread reader | GitLab client call to list MR discussions/unresolved threads; `Thread` DTO; no side effects; tests | - |
| 2 | Event filter and enqueue | Note/Pipeline hook to filtered job; loop guards; bot-author and Shepherd skips; still logs only | 1 |
| 3 | Database and job state | First migration, job/thread repository (reader/writer split), coalescing and caps | 2 |
| 4 | Triage service | OpenRouter client call, prompt, classes, safety policy as code, dry-run command `ubix kelpie:triage <mr>` | 1 |
| 5 | Reply and label | Thread reply, `needs_human` path, claim marker; no code changes yet | 3, 4 |
| 6 | Fixer runner | Sandbox image, checkout at sha, agent run, gate, push (non-force), one commit; behind `KELPIE_AUTOFIX_ENABLED=false` | 5 |
| 7 | Resolution comment and resolve | Commit-link reply, `already_fixed` resolve | 6 |
| 8 | Sweep and pipeline-failure source | Scheduled sweep; failed-pipeline log as a source | 7 |
| 9 | Rollout | Enable on one project in advisory mode (reply only, no push), then push mode; docs | 8 |

Steps 1-5 are safe to ship before any push capability exists.

**Step 5 as built** (`kelpie:respond <mr>`, run by hand; no worker calls it yet). Per thread: skip if
Kelpie already replied and nobody has written since; claim it (`kelpie:claim` note, 30 minute TTL,
oldest claim wins a race) and put `kelpie::working` on the MR; triage; `needs_human` gets a reply and
the `kelpie::needs-human` label, `not_an_issue` a reply; `already_fixed` a reply and the thread is resolved (step 7); `fix` posts
nothing here (step 6 owns it); release the claim and the working label. The job table is not touched yet: the worker
that ties jobs to this arrives with step 6. Writes go through `BotHttpClient` (GET/POST/PUT only,
`KELPIE_BOT_GITLAB_TOKEN`), and only for projects in `KELPIE_AUTOFIX_PROJECTS`.

**Step 6 as built** (`kelpie:fix <mr> <discussion>`, run by hand; no worker calls it yet). Does nothing
unless `KELPIE_AUTOFIX_ENABLED=true` (any other value is off). Per thread:

1. Job: `AutofixJobService` enqueues through the coalescer (so the caps apply) and takes the one pending
   job at the current head; otherwise it reports why not and stops. `markRunning` / `markFinished`
   (`done` or `failed`, with the triage class and the pushed commit) are wired through it.
2. Guards, read from GitLab: MR open, source branch in the same project (no forks), source branch not
   `dev`/`staging`/`main`/`master`, head still the sha the thread was read at, thread not already
   answered, claim won. Anything else supersedes or skips the job.
3. Triage again (`fix` only proceeds; other classes go down the section 5 path).
4. Sandbox: a temp dir per job (0700), `git clone --no-tags --branch <source>`, detached at the triaged
   sha. The bot token reaches git only through a `GIT_ASKPASS` script that lives for one network call;
   it is in no argv or environment. The agent (`claude` CLI, headless, `Read,Grep,Glob,Edit,Write`
   only) gets an exact environment: PATH, a throwaway HOME and the OpenRouter key; no GitLab or Vault
   value. The thread text is fenced as data.
5. The real diff is measured from git and put through `SafetyPolicy` (size, files, protected paths,
   deletes/renames, skipped tests, attempts, MR commit cap). A violation is `needs_human`, nothing
   is gated or pushed.
6. Gate: `composer install`, `phpcs`, `phpstan`, `phpunit` in the checkout. A red gate is fed back to
   the agent once; a second red is `needs_human` with the failed step, nothing pushed.
7. One commit with a `Kelpie-Autofix: thread=<id> sha=<sha>` trailer, then the head is read again; if it
   is not the triaged sha nothing is pushed. The push is `HEAD:refs/heads/<source>`: never
   `--force`, never a `+` refspec, so a branch that moved is rejected by the server.

What it does not do yet: no container-level isolation (the "sandbox image" of section 7 needs infra),
no worker or scheduler (step 8). The process runner is local
(`Kelpie\Sandbox\ProcOpenProcessRunner`); section 6 prefers a generic one in uBixCore, to be upstreamed.
The caps use the `AutofixCaps` defaults; the per-MR token budget and daily spend cap of decision 6 are
not enforced here. Database access in the web app is bound in `app/KelpieApi/src/Dependencies.php`; the
`kelpie` database and a CI migrate stage do not exist yet, so the migration is applied by hand.

**Step 7 as built** (inside `kelpie:fix`, and `kelpie:respond` for `already_fixed`). After a push the
runner replies on the thread: "Kelpie fixed this in <commit URL>: <summary>", the gate line, and that the
thread is left open for the reviewer, with a `class=fix` reply marker so step 5's answered check and the
step 2 skips still hold. The commit URL is the MR's `web_url` with `/-/merge_requests/<iid>` swapped for
`/-/commit/<sha>`; the short sha stands in if GitLab gave no URL. The summary is the triage reason, cleaned
(markers and @-mentions stripped as in step 5), one line, at most 200 characters; the diff itself is not
summarised. A failed reply is logged and does not undo the push; the job stays `done` with its commit.
`already_fixed` replies with the reason as evidence (the verdict carries no commit) and then resolves the
thread through `DiscussionWriter::resolve`; if the resolve fails the reply stays and its marker stops a
second one. Per decisions 2 and 3 nothing resolves a thread after a `fix`.

**Step 8 as built** (`kelpie:sweep`; the manifest `app/KelpieApi/k8s/dev-cronjob.yaml` is suspended).

- *Entry point.* The sweep adds no path: per open MR of `KELPIE_AUTOFIX_PROJECTS` it builds a synthetic
  Note Hook payload per unresolved thread and, when the head pipeline failed, a synthetic Pipeline Hook
  payload, and hands each to `EnqueueService::enqueue` (new; `handle` is a thin wrapper over it, so the
  webhook is unchanged). `EventFilter` and every loop guard decide, including the bot/Shepherd authors,
  Kelpie markers and the `Kelpie-Autofix` trailer. The one guard the filter cannot see is enforced by the
  sweep: on a head that is a Kelpie commit, threads older than that commit are left alone.
- *Failed pipeline.* Only a failed job that may not fail, in a gate stage (`EventFilter::GATE_STAGES`,
  decision 7), counts. `JobLogReader` takes the last 8 KB of its log, drops terminal codes, masks
  credentials (GitLab/OpenRouter tokens, `token=`/`password=`-style pairs, URL userinfo), and defuses
  Kelpie markers and @-mentions. The tail is opened by the bot as one MR discussion, marked
  `<!-- kelpie:pipeline job=<id> sha=<sha> -->` so it is opened once per job, and from then on it is an
  ordinary thread: triage (the prompt says a bot-opened log thread is a pipeline failure), safety policy,
  caps, claim, fixer, replies. A `fix` there is judged by the same gate as any other.
- *Dark by default.* With `KELPIE_AUTOFIX_ENABLED` not `true` the sweep only reads and prints what it
  would do (`would run`, `would open a thread`); it writes nothing to GitLab or the job table. Enabled, it
  runs at most `KELPIE_SWEEP_MAX_RUNS` (default 5, or `--max-runs`) threads per sweep and defers the rest
  to the next one.
- *Scheduling.* A Kubernetes CronJob every 15 minutes, `concurrencyPolicy: Forbid`, `suspend: true`,
  dev only, reusing the Deployment's namespace, Vault role and KV path. Not live until: the `kelpie`
  database and migration exist, Vault holds the bot and OpenRouter keys, the image carries the `claude`
  CLI, and `bin/deploy.sh` applies the file (it applies only deploy/service/ingress; `bin/` is outside
  this lane). The webhook does not enqueue to the table: the web app has no database yet, so the sweep is
  the only consumer of jobs.
- *Known gaps.* A pipeline thread whose run failed is retried by later sweeps only within the caps
  (2 attempts per thread). Pipelines of a Shepherd-owned branch are skipped by the same branch-prefix
  filter as threads (`KELPIE_SKIP_BRANCHES`, `KELPIE_SKIP_AUTHORS`). The per-MR token budget and daily
  spend cap of decision 6 are still not enforced. The sweep's inline AI work is bounded only by the run
  limit and the CronJob's deadline.

## 8. Decisions

All eight were put to the person with a recommendation; they took all eight, decided 2026-10-15.

| # | Question | Decision | Decided |
| --- | --- | --- | --- |
| 1 | Which projects first? | `ubixsys/ubixkelpie` only, then widen by allow-list | 2026-10-15 |
| 2 | Resolve threads after a fix? | No: leave it for the reviewer (section 4) | 2026-10-15 |
| 3 | Auto-resolve ai-review threads on a green fix? | No at first; revisit after a month of data | 2026-10-15 |
| 4 | Database for job state? | Yes (MariaDB like kitg); caps and idempotency need it | 2026-10-15 |
| 5 | Bot account and token? | New `kelpie-bot` user, Developer on the ubixsys group; `AI_REVIEW_GITLAB_TOKEN` (Reporter) is not reused | 2026-10-15 |
| 6 | Fix model cost ceiling? | Per-MR token budget and a daily spend cap with a hard stop | 2026-10-15 |
| 7 | Failed pipelines? | Fix gate failures only (lint, static analysis, tests), not deploy or infra jobs | 2026-10-15 |
| 8 | Chat channel for needs-human? | GitLab-only until a channel is wired | 2026-10-15 |

Still to settle at implementation time, not decisions here: the exact budget and cap figures (6),
and how a Shepherd-owned MR is recognised (section 6).
