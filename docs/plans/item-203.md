# Item 203 — MR review threads on ubixshepherd (K-09)

Goal: notes on `ubixsys/ubixshepherd` merge requests are stored as autofix jobs and consumed by the
worker, so Kelpie answers review threads there; lost deliveries are recovered by the sweep.

## Scope
- `php/Kelpie/Service/Autofix/EnqueueService.php` (the Note Hook → job path)
- `php/Kelpie/Controller/Gitlab/GitlabWebhookController.php` (the `note hook` branch)
- `php/Kelpie/Service/Autofix/SweepRunService.php` (the backstop)
- `app/KelpieApi/` (a prod sweep CronJob; only `dev-cronjob.yaml` exists, `suspend: true`)

## Depends on
The K-30 cutover (the allow-list is the `projects` table, not the env list) and the worker
(`kelpie:work`) running on the tier.

## Current state (dev, 3d820e9)
Built: the Note Hook enqueues a job via `EnqueueService` (GitlabWebhookController.php:144) and logs it
via `NoteOnMrWebhookService`; the allow-list is the `projects` table. Not done: there is no prod sweep
CronJob (`app/KelpieApi/` has only `dev-cronjob.yaml`, suspended), and `ubixsys/ubixshepherd` has no
`projects` row yet.

## Steps
1. Read the tracker (item 203 / K-09) for the exact scope.
2. Add a prod sweep CronJob (mirror `dev-cronjob.yaml`, `kelpie-prod` namespace) and un-suspend the dev one.
3. Enrol `ubixsys/ubixshepherd` in `propose` (item 202) so its threads are claimed.
4. Verify: a note on a ubixshepherd MR stores a job; the worker answers the thread; a lost delivery is
   recovered by the next sweep.

## Done when
A note on a ubixshepherd MR is stored and answered by the worker, and the sweep recovers a lost
delivery, on the tier where the worker runs. Gate green.

## Needs the person
Enrolling ubixshepherd and running the prod CronJob are operator/maintainer steps.
