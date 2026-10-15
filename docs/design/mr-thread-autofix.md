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
the `kelpie::needs-human` label, `not_an_issue` a reply; `fix` and `already_fixed` post nothing until
steps 6 and 7; release the claim and the working label. The job table is not touched yet: the worker
that ties jobs to this arrives with step 6. Writes go through `BotHttpClient` (GET/POST/PUT only,
`KELPIE_BOT_GITLAB_TOKEN`), and only for projects in `KELPIE_AUTOFIX_PROJECTS`.

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
