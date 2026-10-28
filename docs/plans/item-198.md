# Item 198 — issue intake (step 1)

Goal: when a new issue opens on an enrolled project, Kelpie classifies the event, logs the class,
puts a 👀 on an accepted issue and queues one triage job — and does none of it while
`KELPIE_AUTOFIX_ENABLED` is off.

## Scope
- `php/Kelpie/Service/Gitlab/IssueOpenWebhookService.php` (classify, log, enqueue)
- `php/Kelpie/Service/Gitlab/IssueAwardEmojiService.php` (the 👀)
- `php/Kelpie/Service/Gitlab/MemberAccessReaderService.php` (the gate-actor access check)
- `php/Kelpie/Controller/Gitlab/GitlabWebhookController.php` (the `issue hook` branch)
- `app/KelpieApi/src/Dependencies.php` (wiring; the enrolled list from `projects`)
- `tests/Service/Gitlab/IssueOpenWebhookServiceTest.php` and the two service tests

## Depends on
The `projects` table and `ProjectModeService` (K-30) for the enrolled list; `KELPIE_AUTOFIX_ENABLED`
as the kill switch.

## Current state (dev, 3d820e9)
Built in code: `IssueOpenWebhookService::classify` returns `triage` / `gate` / `ignored`; the class,
actor, project and reason are logged; a triaged issue gets a 👀 (once, best-effort) and one queued
triage job; a gate label is accepted only when the actor's API-read access is Developer or higher; the
issue title and description are never read. None of it is deployed (the worker runs nowhere, prod is
not migrated).

## Steps
1. Confirm the acceptance criteria against the tracker (item 198); the code matches the design.
2. Verify on dev: send a test Issue event (the hook's Test button), see the class in the log and the 👀.
3. Verify the gate path: add the gate label as a Developer and as a lower user; only the former is `gate`.
4. Confirm a replay (the same event twice) queues nothing new.

## Done when
A new issue on an enrolled project logs its class, gets one 👀 and one queued triage job; a replay adds
nothing; with `KELPIE_AUTOFIX_ENABLED` off every event is `ignored`. Gate green.

## Needs the person
Deploy and the e2e (a throwaway issue) — an operator/maintainer step, not code.

The full chain design is in `docs/design/issue-autofix.md`; operational detail lives in
`docs/operations/go-live.md` and `docs/operations/enrolment.md`.
