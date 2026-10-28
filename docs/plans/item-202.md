# Item 202 — per-project enrolment (step 5)

Goal: enrolling a project is a deliberate, repeatable step — a row in `ubixkelpie.projects` with a mode
(`propose` first) — and Kelpie acts only on enrolled projects.

## Scope
- `php/Kelpie/Service/Project/ProjectModeService.php` (reads the enrolled list)
- `php/Kelpie/Repository/Project/` (the projects reader/writer)
- `php/Kelpie/Console/Command/` (an optional enrol/seeding command)
- `docs/operations/enrolment.md`, `docs/operations/projects.md`

## Depends on
The `projects` table (`sql/migrations/20261022150000_create_projects.sql`) and the K-30 cutover (the
webhook, worker and the fix/respond/backfill commands read `ProjectModeService`, not the env list).

## Current state (dev, 3d820e9)
The `projects` table is the enrolment list and the code reads it: `ProjectModeService::enrolledPaths`
feeds the webhook allow-list (`app/KelpieApi/src/Dependencies.php`) and the worker claims only enrolled
projects. A project with no row is off. There is no enrol command: a row is added by an operator
`INSERT`. "Enrol the next project after ubixshepherd" is that operator step.

## Steps
1. Read the tracker (item 202) for the exact scope (a seeding command, or just the operator runbook).
2. If a command is wanted, add `kelpie:enrol` (and `kelpie:unenrol`) that writes a `projects` row.
3. Otherwise keep it an operator step and make `docs/operations/enrolment.md` the single runbook.
4. Enrol `ubixsys/ubixshepherd` in `propose` on dev, then prod, once the worker runs there.

## Done when
Enrolling a project is one documented step (a command or an operator `INSERT`), a project with no row
does nothing, and `ubixsys/ubixshepherd` is enrolled in `propose` on dev. Gate green.

## Needs the person
Enrolling a project in prod is an operator/maintainer step (production data).
