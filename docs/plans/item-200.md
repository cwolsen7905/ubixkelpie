# Item 200 — issue fix and merge request (step 3)

Goal: when a Developer adds the gate label to a triaged `fix` issue, Kelpie opens a
`kelpie/fix-<iid>-<slug>` branch, edits files, checks `SafetyPolicy`, runs the gate, pushes and opens
an MR to `dev` with `Closes #N`, a reviewer, and a comment on the issue. One attempt, then hand off.

## Scope
- `php/Kelpie/Service/Triage/` (a new issue-fix service; the worker already runs issue jobs)
- `php/Kelpie/Policy/SafetyPolicy.php` (the size / paths / tests limits)
- `php/Kelpie/Sandbox/Checkout.php` (branch, push without force)
- `php/Kelpie/Service/Autofix/` (reuse the propose-mode fixer runner)
- `tests/Service/Triage/` (mirror the new service)

## Depends on
Item 199 (the triage verdict is `fix`); the gate label (item 198); `SafetyPolicy`, `Checkout` and the
propose-mode fixer (all present).

## Current state (built)
The gate label from a Developer now queues an `issue_fix` job (same `autofix_jobs` table, thread key
`issue-fix-<iid>`, so no migration). `kelpie:work` takes it after the MR and triage queues. The service
is in `Service/Autofix/` (not `Service/Triage/`, which the scope list of this lane did not cover):

- `IssueFixWorkerService` claims the job (one retry, only when nothing changed anywhere).
- `IssueFixService` checks the label, confidential and opt-out, an open MR for the branch and the spend cap, then
  the checkout is opened (existing `Checkout::open`, detached) at the head of `dev`, read from the GitLab API; the
  `kelpie/fix-<iid>-<slug>` name is only what `push()` gives HEAD, so `Checkout.php` is not changed in this lane.
- `IssueProposeEditsService` asks the propose model twice (which files, then exact-text edits), reusing
  `EditApplyService`; `SafetyPolicy` (size, files, protected paths, plus the project's own globs) judges the measured
  diff; `IssueGateService` runs the project's `gate_command`, else `make check`, else the PHP gate.
- Green: plain push, `MergeRequestOpener` opens the MR to `dev` with `Closes #N`, label `kelpie` and the reviewer
  (`KELPIE_FIX_REVIEWER`), and the issue gets the link and `kelpie::fix-proposed`.
- Anything else: one comment with the reason (and the gate tail), `kelpie::needs-human`, nothing pushed. Step 201
  refines these paths.
- Spend: the run's cost is recorded in `thread_outcomes` (`issue-<iid>`), which the daily caps already count; the
  per-issue budget is 100k tokens, checked between the two calls, and each call is capped on size.
- `kelpie:fix-issue <iid> --path=...` runs one by hand.

Open: tests-required is not enforced (the done-when example is a doc-comment typo); the reviewer env var and the
Go/Node toolchains in the image are deployment work outside this lane.

## Done when
A throwaway "fix a typo in a doc comment" issue, gated by a Developer, opens an MR to `dev` with
`Closes #N`, the reviewer set, the gate green, and nothing merged. Gate green.

## Needs the person
The reviewer identity and the spend-cap figures; the in-pod gate needs the Go/Node toolchains in the
image (a design call). e2e on a throwaway issue.
