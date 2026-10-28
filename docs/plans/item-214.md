# Item 214 — spend caps (K-14)

Goal: the per-project and per-author daily caps in `projects` are enforced, run cost is recorded per
call, and a visible stop fires when a cap is hit — before the group webhook widens Kelpie's reach.

## Scope
- `php/Kelpie/Service/Project/ProjectModeService.php` (or a new caps service)
- `php/Kelpie/Service/Triage/WorkRunService.php` and the triage/fix run paths (where calls are made)
- `php/Kelpie/Repository/ThreadOutcome/` (cost is already recorded in `thread_outcomes.cost_usd`)
- `tests/Service/Project/` and the run-path tests

## Depends on
The `projects` table (the cap columns `max_issues_per_day`, `max_issues_per_author_day` and
`max_cost_usd_day` already exist) and `thread_outcomes.cost_usd` (already written by the propose runner).

## Current state (dev, 3d820e9)
Not enforced. The cap columns are stored in `projects` and carried in the `Project` DTO, but
`ProjectModeService` reads only `mode`; nothing counts issues per day or per author, and nothing stops a
run when the daily cost cap is reached. Cost is recorded per thread in `thread_outcomes.cost_usd`.

## Steps
1. Read the tracker (item 214 / K-14) for the cap figures and the stop behaviour.
2. Add a caps check to the run paths: count today's issues (per project, per author) and today's cost
   against the `projects` caps before a triage or fix call.
3. On a cap hit, stop the run with a visible log/label; do not fail the webhook.
4. Record cost per call where it is not already (issue triage).
5. Add a test per cap.

## Done when
A project over its daily issue or cost cap stops with a visible stop, per-author caps are enforced, and
each call's cost is recorded. Gate green.

## Needs the person
The cap figures (per issue, daily) — a spend decision.
