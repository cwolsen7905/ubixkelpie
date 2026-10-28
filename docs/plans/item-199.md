# Item 199 — issue triage: one comment, one label (step 2)

Goal: for each queued issue, Kelpie posts exactly one triage comment with the reason and sets one
`kelpie::*` label, idempotently; it never changes code.

## Scope
- `php/Kelpie/Service/Triage/IssueTriageService.php` (the comment + label, idempotent by marker)
- `php/Kelpie/Service/Triage/IssueTriageWorkerService.php` (claim, run, settle)
- `php/Kelpie/Service/Triage/WorkRunService.php` (the `kelpie:work` loop that runs it)
- `php/Kelpie/Prompt/IssueTriagePrompt.php`
- `tests/Service/Triage/IssueTriageServiceTest.php`, `IssueTriageWorkerServiceTest.php`, `WorkRunServiceTest.php`

## Depends on
Item 198 (the triage job is queued by intake); the `issue_iid` column
(`sql/migrations/20261021124000_autofix_jobs_worker_columns.sql`).

## Current state (dev, 3d820e9)
Built in code: `IssueTriageService` reads the issue, skips confidential / `kelpie:skip` / bot-authored
issues, posts one comment carrying a hidden marker and sets one `kelpie::*` label; a replay or retry
finds the marker and posts no second comment (a crash between the comment and the label is repaired by
the next run). The comment says "Fixing is not switched on yet." Nothing is deployed (the worker runs
nowhere, prod is not migrated).

## Steps
1. Read the tracker (item 199) for the exact acceptance criteria; the code matches the design.
2. Deploy the worker on dev (item 202's rollout) so the loop can claim the queued triage jobs.
3. Throwaway issue: confirm one comment and one label appear once; a replay adds nothing.
4. Confirm a skipped issue (confidential, `kelpie:skip`, bot-authored) gets nothing.

## Done when
A queued issue gets exactly one triage comment and one `kelpie::*` label, idempotently, with no code
changed; skipped issues get nothing. Gate green.

## Needs the person
Deploy and the e2e (a throwaway issue) — an operator/maintainer step, not code.
