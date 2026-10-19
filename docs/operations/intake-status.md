# Issue intake status (2026-10-19)

A read-only check of why a new issue did not get a 👀 from Kelpie, and the shortest path from
here to Kelpie triaging issues. Nothing was changed to produce it: only `glab` reads and `kubectl`
get/logs against `kelpie-prod`. For the design see `docs/design/issue-autofix.md`; for the flags see
`docs/operations/autofix-flags.md`.

## Part 1: why there was no 👀

### Verdict

The issue was opened before the code that posts the 👀 was running. Nothing is misconfigured.

| Fact | Evidence |
| --- | --- |
| The only recent issue by `cwolsen` is `ubixsys/ubixshepherd#1`, opened **2026-10-18 00:13:25 UTC**, not confidential, no labels, no comments, no award emoji | `glab api` on the issue |
| The 👀 change (!39) merged to `dev` at 00:35:01 UTC as dd0bab8 | `glab api` on the merge request |
| It reached prod through pipeline 8460 (`dev`, `staging`, `main` all at dd0bab8, `deploy-prod` green at 00:44:23 UTC) | `glab api` on the pipeline jobs |
| The running pod started **00:44:11 UTC**, 31 minutes after the issue, from the `:main` image built from dd0bab8 | `kubectl get pod`, `imageID` and `startTime` |
| The pod has `KELPIE_AUTOFIX_ENABLED=true` and `KELPIE_AUTOFIX_PROJECTS=ubixsys/ubixkelpie,ubixsys/ubixshepherd`; `ubixsys/ubixshepherd` is listed | `kubectl get deploy` environment |
| `ubixshepherd` has a webhook (id 5) for Issue events (also Note and Merge Request events) to `https://kelpie.prod.ubixsys.com/gitlab/webhook`, SSL verification on, no failure state | `glab api` on the project hooks |

So the 👀 code is live in prod, the project is enrolled and the webhook points at the right place.
The delivery for issue #1 went to the previous pod, which ran the earlier code (log-only intake).
That pod is gone, so its log of the delivery cannot be read, and GitLab keeps only the latest 20
deliveries per hook, which no longer reach back to 00:13. What can be said from the live pod: since
it started, it has logged **no** Issue Hook at all (no intake, triage or emoji line, no emoji error).
The live path has not yet been exercised by a real issue, so the emoji POST itself (bot token
rights) is untested.

Only `open` events get a 👀. Editing, closing or relabelling the old issue will not add one.

### Fix and who does it

No code or config change is needed. Someone with an account on the project opens a new
(throwaway) issue on `ubixsys/ubixshepherd`, and then checks the pod log for
`GitLab webhook: issue intake` with `class: triage` and the issue on GitLab for the 👀.

- If the 👀 appears: done.
- If the log says `triage` but there is no 👀, the log also carries the emoji failure. Most likely
  `kelpie-bot` is not a member of the project, or its token lacks `api` scope. That is a token or
  membership change for the person (see section 4 of the design).
- If there is no intake line at all, check the hook's recent deliveries in GitLab.

The person must do this: opening the issue is a test of their own project, and a token change is
theirs to approve. An agent should not create issues on the live project.

## Part 2: shortest path to Kelpie triaging issues

### Where it stands

Intake (step 1 of the design's section 9) is live in prod: it classifies each Issue event, logs the
class and posts the 👀. That is all. There is **no triage yet**: no issue prompt, no verdict, no
comment, no `kelpie::*` label, no job for an issue. So even with the 👀 working, no label or
comment will appear until the steps below are done.

### Steps

| # | Step | What blocks it | Needs the person |
| --- | --- | --- | --- |
| 1 | **Prove intake live.** Open a throwaway issue on `ubixshepherd`; see the log line and the 👀 (Part 1). | Nothing. | Yes: opens the issue; approves any bot token or membership change. |
| 2 | **Finish and promote K-07 part 2** (thin commands). The rehome (part 1) is in prod; part 2 merged to `dev` on 2026-10-19 and its `main` pipeline is waiting at the manual `migrate-apply-prod` job. Triage code should land in the rehomed layout. | Part 2 is done on `dev`; only its prod promotion remains. | Yes: the manual production migration job. |
| 3 | **Settle where triage runs.** The design says the webhook does no AI work inline, so a job has to be picked up by something. Nothing runs jobs in prod today (no CronJob, the dev one is suspended). Options: a prod CronJob that sweeps issue jobs (existing pattern, slower, quick to build) or the K-09 worker (cleaner, bigger). Recommended: the CronJob first, the worker later. | A design call with a default only in this note. | Yes: confirm the choice. |
| 4 | **Check prod secrets and the image.** `kelpie/prod/app` in uBixVault must hold the bot token, the OpenRouter key and the GitLab API URL; the image must be able to run the triage call (the claude CLI is needed only for fixing, not triage). Not checked here: the vault was not read. | Unknown until someone reads the vault. | Yes: tokens and the prod vault are theirs. |
| 5 | **Build triage and comment (design step 2).** An `issue_iid` column on `autofix_jobs` (migration), an issue prompt for the cheap triage model, one comment and a `kelpie::*` label per new issue, a replay adding nothing. No fixing. | Step 3 for the runner; the migration is written by a person, not the fixer (design section 8). | Yes: writes the migration, reviews and approves the MR, runs `migrate-apply-prod` and promotion to prod. |
| 6 | **Prove it end to end** on a throwaway issue: comment and label appear once, a replay adds nothing, close the issue afterwards. | Step 5 in prod. | Yes: runs it and judges the result. |

### What is not on the shortest path

- **K-30, projects table.** It replaces the two env variables with per-project modes. The env
  list already covers `ubixkelpie` and `ubixshepherd`, which is all triage needs now. Do it before
  enrolling a third project, not before the first triage.
- **K-34, enrol every project.** It widens a working triage to more projects (one webhook each,
  or per group once the tier allows). It follows step 6, with Kelpie itself first.
- **Review-thread work.** The MR review-thread items are a separate path (merge request threads,
  not issues). They do not gate issue triage.
- **Fixing** (design steps 3 and 4): after triage, behind the gate label.

### Not checked

Shepherd's own board items were not readable from here; the steps above come from the design and
the K-series plan only. Vault contents, the bot's membership in `ubixshepherd` and the live emoji
POST were not checked, as that would have meant a write or a secret read.
