# Why Kelpie is not yet resolving threads and fixing findings by itself

Diagnosis only, 2026-11-19. Read-only: git on `origin/dev` (`f2a098e`), `glab api` GETs, the public `/health` and `/version`
endpoints. No cluster or database access from this lane (`kubectl` has no context here), so anything that lives only in the
cluster or the `projects` table is taken from `reports/live-check-2026-11-18.md` (a read-only look at both, a day old) and
marked as such. Times are UTC.

## Short answer

Item 290's code is merged and, on dev, it works: the bot resolved one of its own summaries on ubixkelpie !88. What stops
Kelpie from clearing these threads and fixing findings end to end is, ranked:

1. **Triage itself fails, so there is never a verdict to act on.** Every triage reply is still "Triage unavailable: the model
   gave no answer" (today, 18:33Z, on ubixshepherd !419). It classifies every thread `needs_human`, which is by design never
   resolved and never fixed. Settling (item 290) can only resolve what was fixed or dismissed, and nothing gets that far.
2. **No project is in `push` mode.** Both enrolled projects are `propose`: Kelpie may offer a diff, never push one. Fixes on
   MRs will come from lane agents until a row is set to `push`.
3. **The deployed image is not what `dev` says.** The worker manifests are not applied by the deploy; prod has not deployed
   since 2026-10-28; nothing is released since `v0.1.0-beta.1`. This is why "done" in the tracker is not "running".
4. **The summary note is a resolvable discussion by construction**, so a summary Kelpie leaves open (needs you) blocks
   auto-merge until someone resolves it.

## 1. Is item 290 on origin/dev, and is it released?

- On `origin/dev`: yes. `926c93d` "feat(autofix): resolve MR threads once they are settled" (2026-11-18 15:12 -0500),
  with the follow-up `38a3e4c fix(autofix): only the thread's author, the bot or an agent can settle a thread`,
  merged as `7e48827` (MR branch `feat/resolve-settled-threads`). Code: `php/Kelpie/Service/Autofix/SettledThreadService.php`
  (rules at lines 18-21, `settle()` at 69, `threadSettled()` at 217); doc `docs/autofix/settled-threads.md`.
- Changelog: `CHANGELOG.md:3` "## Unreleased", the item-290 entry is in that section (the second bullet).
- Tags: `git ls-remote --tags origin` shows one tag, `v0.1.0-beta.1` (`ff9e4b7`, 2026-10-14). `git merge-base --is-ancestor
  926c93d v0.1.0-beta.1` is false; `glab api projects/ubixsys%2Fubixkelpie/releases` and `/repository/tags` list only
  `v0.1.0-beta.1`. `CHANGELOG.md:70` has a `v0.1.0-beta.2` heading, but no such tag exists on origin.
  So item 290 is **unreleased**; it is in the branch heads only.
- It is on all three branches: `origin/dev` and `origin/staging` are at `f2a098e`, `origin/main` at `3496c0c`; 926c93d is an
  ancestor of each.

## 2. What version is running

Nothing in the stack pins a version: the manifests use mutable tags (`app/KelpieApi/dev-deploy.yaml:44`
`gitlab.brainchurts.com:5050/ubixsys/ubixkelpie:dev`, staging `:staging`, prod `:main`), all `imagePullPolicy: Always`, and
`/version` answers `"version":"unknown"` on dev, staging and prod (curl, today). So a running pod's code is "whatever
the tag pointed at when the pod last started". What can be shown:

| Piece | State | Evidence |
| --- | --- | --- |
| dev API deployment | applied today at `f2a098e`, includes 290 | pipeline 11159 (`ubixsys/ubixkelpie/-/pipelines/11159`): `build-dev`, `migrate-apply-dev`, `deploy-dev` success 2026-11-19 18:46Z |
| dev worker (`kelpie-worker`) and sweep | the sweep CronJob is applied by the deploy (`dev-cronjob.yaml`, matches `<env>-cronjob*.yaml`) and starts a pod per run, so it pulls the current `:dev`. The **worker Deployment is never applied** by the deploy: uBixCore v0.47.0 `ci/bin/deploy.sh` applies `<env>-deploy.yaml`, service, ingress and `<env>-cronjob*.yaml`, not `*-worker.yaml` (the manifest itself says "Not yet applied by uBixCore ci/bin/deploy.sh (it skips *-worker.yaml): apply by hand", `dev-worker.yaml:8`). It runs the image from its last restart | `dev-worker.yaml:8,43-45`; deploy.sh lines 66-88 |
| prod API | **last deployed 2026-10-28** (pipeline 9409, `deploy-prod` success 05:31Z, commit `0a5964d`, 85 commits before `origin/main`) | every `main` pipeline since (`glab api .../pipelines?ref=main`, 11028 at `3496c0c` and 15 earlier) stops at `migrate-apply-prod` "manual"; `deploy-prod` is "created" |
| prod worker | same as dev worker, applied by hand, image at its last restart | `main-worker.yaml:1-11` |
| staging | follows dev by fast-forward (`staging` = `f2a098e`); `promote-to-staging` succeeded in 11159 | pipeline 11159 |

Is that older than 290? Prod's API: yes, certainly (a month older). Dev worker: not provable from here. The worker
posts the new summary format (`<!-- kelpie:summary n=1 t1=<id> -->`, which `926c93d` introduced) on ubixshepherd !419
(dev bot, 18:33Z) and ubixkelpie !88 (prod bot, 2026-11-18 22:33Z), so both workers were running code from at least
`926c93d`; the older `<!-- kelpie:summary -->` shape is on !414 note 4bd42c52b4 (2026-11-18 21:00Z). So the image is not the
blocker for resolve; resolve ran (see below). The restart cadence is the open question: the live check saw the workers
restart about hourly (251 prod, 206 dev restarts in 8 days), which is why they keep picking up new `:dev`/`:main` images.

Evidence that resolve works: ubixkelpie !88, discussion `a7c1ebf5d2…`: a Kelpie summary (bot `group_223_bot_…`) with
`resolved_by` = the same bot at 2026-11-19 18:41:42Z. Its sibling `3edfdd0485…` was resolved by `cwolsen` at 18:42:21Z, 39 s
later, i.e. a person got there first. (Both of these summaries read "nothing to do … left for now", which is what the code
prints when the run recorded no result for the thread, `TriageStatusService.php:185-199`.)

## 3. Which modes are on, per project

What may happen on a project is its row in `ubixkelpie.projects` (`off` / `propose` / `push`), read on every pass;
`KELPIE_AUTOFIX_ENABLED=true` in the manifests is only the global kill switch (`docs/operations/projects.md`,
`docs/operations/autofix-flags.md`). The row lives in the database; the live check read it (dev and prod identical).

| Project | GitLab hook | Row (live check 2026-11-18, not re-read) | Mode in effect |
| --- | --- | --- | --- |
| ubixsys/ubixshepherd | project hook 5 -> `kelpie.dev` (MR, note, pipeline, issues, emoji) | `propose` | triage comments + labels + proposals, **no push**. Runs on the **dev** deployment |
| ubixsys/ubixkelpie | project hook 8 -> `kelpie.prod` (MR, note, issues, emoji; no pipeline events) | `propose` | same, on the **prod** deployment. Also `kelpie-review` in CI (`.gitlab-ci.yml:34-40`, advisory) |
| ubixsys/ubixcore | project hook 7 -> `kelpie.prod` (MR, note, issues, emoji) | no row seen | webhook arrives, ignored: no row is `off`. No `kelpie` include in its `.gitlab-ci.yml` |
| kitg/kitg | none to Kelpie (only the uBixOps hook, id 1) | none | **off**. No `kelpie` include in `.gitlab-ci.yml` on dev. Its CI variables hold `AI_REVIEW_GITLAB_TOKEN`, `OPENROUTER_API_KEY`, `KELPIE_REGISTRY_USER/TOKEN` (names only), so the CI review is prepared but not wired |

So: no project is on `push`. "Autofix with code changes and push" is on nowhere. Where the settings are kept:

- Mode, caps, `gate_command`, `protected_paths`, model overrides: `projects` table, per environment (set by `INSERT`, no
  CLI/seed: `docs/operations/go-live.md` row 5). Neither row overrides `triage_model`/`review_model` (live check).
- Kill switch, bot name, log path: env in the manifests (`KELPIE_AUTOFIX_ENABLED`, `KELPIE_BOT_USERNAME`, `LOGGER_PATH`).
- `KELPIE_TRIAGE_MODEL`: **not set anywhere**: not in any manifest (grep over `app/`), not in the container env or Vault
  per the live check. The code default applies: `google/gemini-3-flash` (`TriageService.php:64`, `docs/operations/worker.md:110`);
  `.env.example:58` suggests `anthropic/claude-haiku-5.5`, which no environment uses.
- Secrets (`OPENROUTER_API_KEY`, `KELPIE_BOT_GITLAB_TOKEN`, `GITLAB_API_URL`): uBixVault `kelpie/<env>/app`. Not read.
- CI variables relevant to Kelpie on the four projects (names only): kitg has the review ones; ubixkelpie, ubixshepherd and
  ubixcore have only the approval/vault/promote tokens at project level (group variables returned an empty list to this
  token, so group-level ones could not be checked).

## 4. Why summaries are resolvable discussions, not plain notes

- `TriageStatusService::start()` posts the summary with `DiscussionWriter::openNote()` (`TriageStatusService.php:105`), and
  `openNote()` does `POST …/merge_requests/<iid>/discussions` with just a body (`DiscussionWriter.php:267-275`). GitLab makes
  every discussion on an MR resolvable. A plain note (`POST …/notes`) is not. The bot then **edits** it into the outcome
  with `editNote()` (`DiscussionWriter.php:288`), which keeps it a discussion. Evidence: `type=DiscussionNote
  resolvable=true` on ubixkelpie !88 and ubixshepherd !414/!419 for the bot's summaries.
- It was done on purpose: the discussion is what `editNote()` needs (`discussionId` + `noteId`), and it is what item 290
  resolves (`SettledThreadService::isSummary`/`summarySettled`, lines 130-200). The design rested on "the bot will resolve it
  when the threads it covers are settled".
- That rests on the threads being settled, which they are not (section 5, gap A). A summary of "needs you" is left open by
  design (`docs/autofix/settled-threads.md`, "When it is not"). Open threads block auto-merge, so a summary that is never
  resolved blocks auto-merge exactly as seen: !414 (4bd42c52b4 with no thread list, from before the marker; b93f8e3c37) and
  !419 (e39fdd1d91, 2c389a965d, 15bd46b759), all `resolved=false`, all "needs you".
- Also: the covered thread has to hold a reply from Kelpie that says `Fixed`, or a "dismissed" with a reason, before it
  counts (`SettledThreadService.php:217-260`). Kelpie's reply on !419 was "Kelpie triage: **needs human** — Triage
  unavailable: the model gave no answer. I did not change any code.", then "Kelpie is done with this thread for now".
  Neither can settle a thread.

## 5. Gaps and the shortest path for each

**A. Triage returns nothing (blocks everything).**
- Evidence: all 36 triage comments on ubixshepherd in the live check said "the model gave no answer", and `triage_class` was
  `needs_human` on all 22 rows; it is still the case today (!419 notes of 18:33Z, thread 72395d28ff and d7058799848, both
  `class=needs_human`). Code: `Triage*Service` call `OpenRouterClient::chat()` with `max_tokens` 400 and
  `response_format: json_object` (`OpenRouterClient.php:62,80,93,99`) on `google/gemini-3-flash`. Likely cause, still
  unverified: the reasoning model spends the 400 tokens before any content, so the reply is empty (the live check's reading).
  I could not read an `OpenRouter:` log line to confirm; the CLI log path bug that hid it is fixed in the manifests
  (`LOGGER_PATH=/tmp/logs`, `dev-worker.yaml:105`, `main-worker.yaml:104`), so the dev worker's log now shows the
  actual error once the worker pod runs that manifest.
- Shortest path: (1) read the dev worker's `OpenRouter:` log line to confirm the cause (person or an ops lane: needs
  `kubectl logs`, which this lane lacks); (2) set `KELPIE_TRIAGE_MODEL` to a non-reasoning model in uBixVault
  `kelpie/dev/app` (no code, no deploy; the worker reads env at start, so restart it), or raise the 400 default in a code MR.
  **Needs the person for the Vault write (a secret store) and the restart.** Recommend the Vault setting first (reversible).
  Check the model is not in the review's family (`TriageService.php:22`).

**B. Triage can say needs-you but cannot fix, because `propose`.**
- Even with triage working, `propose` offers a diff and never pushes (`docs/operations/projects.md`; push only in
  `PushRoundBatchService.php:69,121` and `ProposalReactionService.php:77`, both need `ProjectMode::Push`). The push round is
  documented in `docs/autofix/push-mode.md`: no thumbs-up, one `fix(review)` commit per round, never forced.
- Shortest path: `UPDATE projects SET mode='push' WHERE path='ubixsys/ubixkelpie'` on prod, one project first, after A works
  and a week of propose output is judged good (`go-live.md` A8 sets the week). **Needs the person** (SQL on prod and the
  judgement). The bot also needs push rights on the repo (Developer, `go-live.md` around line 223) and the branch rule must
  allow its push; `protected_paths` and `max_fix_commits_per_mr` (3) already bound it.
- For the fixer to run at all, the image needs the `claude` CLI: `Dockerfile:14-24` has it since `44b672d`
  (2026-11-17), so it is in any image built after that; the `go-live.md` "known gap" text and the `dev-cronjob.yaml` comment
  still say it is missing and are stale. No fix run has been observed: triage never gets past `needs_human`, so the fixer has not been exercised live.

**C. What is running is not what is merged.**
- Prod API is a month old (last `deploy-prod` 2026-10-28, pipeline 9409); every later `main` pipeline waits on `migrate-apply-prod`.
  The prod webhook receiver therefore lacks every webhook-side change since (the worker is what does the work, but the
  worker is a different pod, see next point). 15 `main` pipelines sit at manual.
- Worker Deployments are outside the deploy (`deploy.sh` skips `*-worker.yaml`): a worker manifest change (for example
  `LOGGER_PATH`, flags) only lands when someone applies it. The cron sweep is applied.
- No release: only `v0.1.0-beta.1`; `CHANGELOG.md` keeps everything under "Unreleased", and `/version` reports `unknown`, so
  nobody can tell what is deployed. Mutable tags mean "which commit is running" is not recorded anywhere.
- Shortest path: (1) person clicks `migrate-apply-prod` on the latest `main` pipeline (a prod action: the person's);
  (2) person (or ops lane) applies `main-worker.yaml` and `dev-worker.yaml` once and restarts the workers; (3) upstream
  uBixCore change so `deploy.sh` applies `*-worker.yaml` (a framework change, so a lane for ubixcore, then bump the pin and
  `ci/ubixcore-pipeline.yml` ref together); (4) tag a release (the tag needs the person via `tag_reserve`; the lane may write
  the changelog), and stamp the commit into `/version` so a running pod can be identified.

**D. Summaries block auto-merge while open.**
- Cause: section 4. A "needs you" summary is open by design while its thread is open, and the thread is open because of A.
- Shortest path: after A and B the settle rules close summaries once their threads are fixed/dismissed, no extra work. To
  stop blocking auto-merge in the meantime or in the "needs you" case, make the summary non-resolvable: post it as a plain
  note (`POST …/notes`) and edit that note (`PUT …/notes/<id>`), which needs no discussion id. That is a small code change in
  `DiscussionWriter::openNote`/`editNote` plus `TriageStatusService`, and `SettledThreadService::isSummary` would no longer
  see summaries as threads (its summary rule becomes dead code). A design call: a summary that does not block merge also
  does not nag. **Needs the person to choose** (recommend the plain note; the label `kelpie::needs-you` already carries the signal).

**E. Other projects (ubixshepherd and the rest).**
- ubixshepherd runs on the dev deployment, with a dev bot; ubixkelpie, and ubixcore's hook, go to prod. Two environments
  mean two worker versions to keep current. kitg and ubixcore have no row and no CI include. To widen: an `INSERT` per project
  (propose first) and, for kitg, a webhook to `kelpie.prod` (hook secret) plus the `kelpie-review` include (`enrolment.md`).
  **Needs the person** (SQL, hook secret).

## What was not verified

- The `projects` rows and job tables were not re-read today (no database access here): they come from the 2026-11-18 live check.
- What image each pod is running right now, and the worker logs, need `kubectl`, which this lane does not have.
- Whether the model call fails for the reason in gap A: the cause is inferred, not read from a log line.
- Group-level CI variables could not be listed with this token.
