# Enrolling a project in Kelpie

How a GitLab project gets Kelpie's help, and how far. State checked on 2026-10-22 against `origin/dev`
at `487497e` and GitLab (read only). Re-check before acting on it: the "today" notes are a snapshot.

Two things can be switched on, with different risk. They are independent:

- **Review through CI.** The project includes `ci/kelpie.yml` and its merge requests get an AI
  review. No Kelpie server, no database.
- **Events through the webhook.** GitLab sends issue, merge request, note and emoji events to Kelpie,
  which triages issues and answers review threads. This is what the mode below governs.

## Modes

| Mode | Kelpie may |
| --- | --- |
| `off` | Nothing. No row is `off` too. |
| `propose` | Comment and label. A fix is offered as a diff in the reply ("I have not pushed anything"); Kelpie never pushes. |
| `push` | Everything `propose` does, and push a fix that passed the gate to the merge request's branch. |

Start every project in `propose`. Columns, caps and labels: `docs/operations/projects.md`.

## Steps

1. **CI include (review).** In the project's `.gitlab-ci.yml`:

   ```yaml
   include:
     - project: ubixsys/ubixkelpie
       ref: dev
       file: ci/kelpie.yml
   ```

   Set `AI_REVIEW_BASE_BRANCH` if the default branch is not `dev`, and override the job's `stage` and
   `tags` if the project's differ. The group needs `OPENROUTER_API_KEY` and `AI_REVIEW_GITLAB_TOKEN`,
   and a project other than Kelpie also needs a registry login that can pull Kelpie's image. Every
   variable: `docs/operations/ci-variables.md`.
2. **Webhook (events).** A hook to `https://kelpie.prod.ubixsys.com/gitlab/webhook` with the shared
   secret, SSL verification on, and these events: issues, merge requests, comments (notes) and emoji.
   Use one hook per project or one on the group, never both: both deliver every event twice.
3. **Env list (today's gate).** Add the project path to `KELPIE_AUTOFIX_PROJECTS` in the manifests under
   `app/KelpieApi/`. An event for a project not in the list is ignored. This list is what decides today
   (see "What is still missing"); `KELPIE_AUTOFIX_ENABLED` is the global kill switch on top of it
   (`docs/operations/autofix-flags.md`).
4. **`projects` row (target gate).** A row in `ubixkelpie.projects` with the path and mode, on each
   environment that should act on the project. Once Kelpie reads the table, no row means off and the
   env list goes away.
5. **Check.** Open an issue: the bot adds a 👀, then a triage comment and a `triage:` label. Open a merge
   request: `kelpie-review` posts its summary. In `propose` no commit from the bot may appear on any
   branch. Turn a single item off with the `triage:off` label.

To stop: set `KELPIE_AUTOFIX_ENABLED=false` and redeploy (everything), set the row's mode to `off` (one
project), or remove the webhook.

## Worked example: `ubixsys/ubixkelpie`

| Step | State on 2026-10-22 |
| --- | --- |
| CI include | Done. `.gitlab-ci.yml` includes `ci/kelpie.yml` (local include); `kelpie-review` runs. |
| Webhook | Done. A project hook (id 8) to the prod URL, issue, merge request, note and emoji events on, SSL verification on. Pipeline and job events are off. |
| Env list | Done. `ubixsys/ubixkelpie` is in `KELPIE_AUTOFIX_PROJECTS` in the dev worker and prod manifests (prod also lists `ubixsys/ubixshepherd`). |
| `projects` row | **Not possible yet.** No migration creates the table. |
| Mode | **Not enforced.** See below. |

Autofix itself is off: `KELPIE_AUTOFIX_ENABLED=false` in every manifest, so the webhook acknowledges
events and nothing triages or fixes.

### What is still missing

- **The `projects` table.** The code to read it is in (`ProjectMode`, `ProjectSqlRepository`,
  `ProjectModeService`), but `sql/migrations` has no migration that creates it.
- **Nothing reads it.** The webhook, worker, backfill, fix and respond paths still read
  `KELPIE_AUTOFIX_PROJECTS`, so "propose" is not a per-project limit. Propose mode (`8e922ac`) is in the
  code: the reply offers a diff and pushes nothing. But an enrolled project is not held to it.
- **Operator step, after both land:** insert the row `ubixsys/ubixkelpie`, mode `propose`, on dev and
  then prod. Production data: an operator or the maintainer does it, not an agent.

Until the first two land and the row exists, "Kelpie's own repo in propose mode" means: enrolled,
review live, autofix switched off.

## Before enrolling any other project

These gate widening beyond Kelpie's own repo (full reasoning: `docs/operations/go-live.md`, section 4):

- **#214 spend caps.** Daily per-project and per-author counts enforced, and cost recorded per call. Today
  the dollar cap is stored, not enforced. Review alone is bounded per review, not per day.
- **#215 separate prod bot token and webhook secret.** Today they are shared with dev and staging. The new
  token should be Reporter for `propose`, Developer only for projects later set to `push`.
- **#216 prompt-injection scan.** Issue, merge request and thread text is untrusted input to a model. It
  matters more once a fixer produces diffs, and critically once anything pushes.

A project reaches `push` only after a period in `propose` where its comments and labels have been read.
