# Go-live plan: enrolling the whole `ubixsys` group

State checked on 2026-10-20 (evening) against `origin/dev` = `origin/staging` = `origin/main` at
`788c941`, the GitLab group `ubixsys`, and the three Kelpie namespaces on the cluster (read only: `glab api`,
`kubectl get`, job logs). Nothing was changed. Re-check before acting on it: this file is a snapshot.

The aim: Kelpie on for every project in `ubixsys`, in **propose** mode, with Kelpie itself first.
Two separate things get switched on, and they have different risk:

- **Review through CI**: a project includes `ci/kelpie.yml` and its merge requests get an AI review.
  Needs no Kelpie server and no database; only group CI variables and an image the job can pull.
- **Events through the webhook**: one group webhook sends merge request, emoji, note, pipeline/job and
  issue events to `https://kelpie.prod.ubixsys.com/gitlab/webhook`. This is what lets Kelpie triage
  issues and answer review threads.

## 1. Where the path stands

| # | Step | State | Evidence |
| --- | --- | --- | --- |
| 1 | Prod migrate and deploy of the pipeline run on 2026-10-20 (8660) | **Not done** | [Pipeline 8660](https://gitlab.brainchurts.com/ubixsys/ubixkelpie/-/pipelines/8660) (`e90f66b`) is `manual`: build, tests and `migrate-verify-prod` passed; `migrate-apply-prod` and `deploy-prod` never ran. The same holds for every `main` pipeline since 8603; the newest, [8760](https://gitlab.brainchurts.com/ubixsys/ubixkelpie/-/pipelines/8760) (`788c941`), waits too. The last prod deploy was 8582 (`f5983e7`, 2026-10-19). `dev` and `staging` are green and current. The prod migrate job lists one migration, `20261015120000_create_autofix_jobs`, applied; the repo has no other. |
| 2 | Worker Deployment on any tier | **Not started** | `kubectl get deploy,cronjob` in `kelpie-dev`, `kelpie-staging` and `kelpie-prod`: only `kelpie-api` everywhere, plus a suspended `kelpie-autofix-sweep` CronJob in dev. No `kelpie-worker`. The dev deploy log applies `dev-deploy`, `-service`, `-ingress` and `-cronjob` and never `dev-worker.yaml`: uBixCore's `deploy.sh` does not apply `*-worker.yaml`. `main-worker.yaml` is `replicas: 0`, `dev-worker.yaml` is 1, and both are inert. |
| 3 | Vault keys | **Partly done**, not verifiable from here | No Vault access in this run. The prod API accepts the group-wide secret (200s with events processed) and reaches GitLab, so `GITLAB_WEBHOOK_SECRET` and a bot token exist in `kelpie/prod/app`. Group CI variables `OPENROUTER_API_KEY` and `AI_REVIEW_GITLAB_TOKEN` exist on `ubixsys` (masked, not protected). **Missing:** `KELPIE_REGISTRY_USER` / `KELPIE_REGISTRY_TOKEN` (see step 12) and the separate prod token and secret (#215). Whether the prod keys the worker needs are all there is unchecked. |
| 4 | K-30: projects table, migration, cutover | **Partly done** | Code is in: `ProjectMode`, `Project`, `ProjectSqlRepository`, `ProjectModeService` (`802436e`, `docs/operations/projects.md`). **No migration creates `projects`** (`sql/migrations` holds only `create_autofix_jobs`), and nothing consults the service except its own wiring and tests. `KELPIE_AUTOFIX_PROJECTS` is still what decides, read in eight places. The cutover is not started. The columns the triage worker needs on `autofix_jobs` also have no migration in the repo (only a snippet in `issue-triage-worker.md`). |
| 5 | #203 / K-09: thread jobs stored and consumed | **Partly done** | Stored: the prod log shows `GitLab webhook: autofix job` for `ubixsys/ubixshepherd!339` (source `note`). Issue-triage jobs: queue and worker code are on `dev`, deployed nowhere. Consumed: no. The worker (`kelpie:work`) takes issue-triage jobs only; thread fixes run only from the CLI (`kelpie:fix`, `respond`, `sweep`), and nothing schedules those in prod. There is no handler for Job events: they fall to "ignored". |
| 6 | K-31 propose mode | **Not started** | `ProjectMode::Propose` exists as an enum case and the `triage: proposed` label (`4e1f321`) as a label. No code applies an edit and offers a diff, proposes a dismissal or records outcomes, and nothing enforces "never push" per project. That is why autofix is switched off in every manifest (`a8cf12f`). |
| 7 | `kelpie-review` CI job | **Done for Kelpie, not started elsewhere** | No longer in shadow: `c4a8c1e` made it live and advisory in Kelpie's own pipeline, `ai-review-mr` (Gemini) still runs beside it. The timeout fix `6676b53` is merged (!48, `788c941`), not waiting to be pushed. Evidence: `kelpie-review` failed in 8747 and 8748 (before the fix) and passed in [8755](https://gitlab.brainchurts.com/ubixsys/ubixkelpie/-/pipelines/8755) (with it). |
| 8 | Triage status labels | Done | `f475eb0`, `4e1f321`. |
| 9 | Autofix off in the manifests | Done in git, **not on prod** | `a8cf12f` sets `KELPIE_AUTOFIX_ENABLED=false` in every manifest. The running prod pod still has `true` with `ubixsys/ubixkelpie,ubixsys/ubixshepherd`, because step 1 never ran. |

What prod does right now (image from 8582): a new issue on `ubixkelpie` or `ubixshepherd` gets a 👀;
thread notes on those two projects are stored as job rows that nothing consumes; approval and 👍
events retry the `require-approval` job. It cannot triage, fix or push.

## 2. The projects in `ubixsys` (15, none archived)

No group webhook exists (`groups/ubixsys/hooks` is empty). Five projects have their own hook to the prod
URL (issue, merge request and note events on); `ubixcore` also has a merge-request-only hook to uBixOps.

| Project | Default branch | Kelpie hook | `ci/kelpie.yml` | Review today |
| --- | --- | --- | --- | --- |
| `ubixkelpie` | `dev` | yes (own) | yes (local include) | `kelpie-review` + `ai-review-mr` |
| `ubixshepherd` | `dev` | yes (own) | no | shell `ai-review-mr` at `v0.1.0-beta.1` |
| `ubixcore` | `main` | yes (own, plus uBixOps hook) | no | none |
| `ubixvault` | `main` | yes (own) | no | none |
| `replikate` | `main` | yes (own) | no (no `.gitlab-ci.yml`) | none |
| `ubixshepherd-internal` | `dev` | no | no (no `.gitlab-ci.yml`) | none |
| `ubixcore-php` | `main` | no | no | none |
| `ubixcore-skeleton` | `main` | no | no | none |
| `ubixsys-web` | `dev` | no | no | none |
| `airdropt` | `dev` | no | no (no `.gitlab-ci.yml`) | none |
| `node-scripts` | `dev` | no | no (no `.gitlab-ci.yml`) | none |
| `ubcore` | `dev` | no | no | none |
| `phpmyadmin` | `dev` | no | no | none |
| `ubixsys-docs` | `master` | no | no | none |
| `ubixsys-manual-examples` | `dev` | no | no | none |

Facts that shape the plan:

- `ci/kelpie.yml` reviews only merge requests into `dev` unless `AI_REVIEW_BASE_BRANCH` is set. Six
  projects default to `main` or `master`; each needs the variable (or the right target) in its include.
- The job pulls Kelpie's image from the registry of a **different project**. `ubixkelpie` is private with
  the job-token inbound scope on and no other project allowed, so a job in another project cannot pull it
  with its own token. The group has no `KELPIE_REGISTRY_*` variables yet.
- Four projects have no `.gitlab-ci.yml` at all, so enrolling them for review means adding a pipeline.
- A project hook and a group hook on the same project deliver every event twice. Remove the five project
  hooks when the group hook goes in (keep `ubixcore`'s uBixOps hook).
- Any project that sends events to Kelpie gets approval retry: the receiver retries the
  `require-approval` job when someone approves or 👍s. That does not depend on enrolment.

## 3. The plan, in order

Owner: **agent** = an agent MR; **upstream** = a uBixCore change then a pin bump here; **person** = only
the maintainer can do it (prod jobs, Vault, tokens, group settings). Agents never approve or merge others'
MRs.

### A. Make prod match dev, and finish the foundation (no effect on other projects)

| Step | What | Owner |
| --- | --- | --- |
| A1 | Migration MR: create `projects` and add the triage columns to `autofix_jobs`. The existing runbooks say the maintainer writes migrations; an agent can draft it for review. dev and staging apply it by pipeline | agent (draft) / person (review) |
| A2 | `deploy.sh` applies `<tier>-worker.yaml`; bump the pin and CI template here (`ci/ubixcore-pipeline.yml` and `composer.lock` together) | upstream, then agent |
| A3 | K-30 cutover MR: webhook, worker, backfill and fix commands read `ProjectModeService`; the env list goes; `KELPIE_AUTOFIX_ENABLED` stays as the kill switch. A project with no row is off | agent |
| A4 | K-31 MR: propose mode, enforced in code per project (no push path reachable in `propose`), diff shown in the reply | agent |
| A5 | Safety items before the group: #214 (caps), #215 (prod identity) and #216 (injection scan), section 4 | agent (#214, #216), person (#215) |
| A6 | Run `migrate-apply-prod` once A1 is on `main`, seed `projects` rows on dev and prod (`ubixkelpie`, `ubixshepherd` as `propose`; every other project gets no row), then run `deploy-prod`. This also clears the stale prod flags. Run the pipeline for the latest `main`, not 8660 | person |
| A7 | Scale `kelpie-worker` on dev (after A2), watch it, then raise `main-worker.yaml` to one replica by MR | agent MR, person merges and watches |
| A8 | Let `ubixkelpie` and `ubixshepherd` run in `propose` for a week; look at the comments and labels before widening | person |

### B. Review through CI (can start before A3 to A8; see decision 1)

| Step | What | Owner |
| --- | --- | --- |
| B1 | Group variables on `ubixsys`: registry user and token that can pull Kelpie's image (`read_registry`), or allow the group in `ubixkelpie`'s job-token scope | person |
| B2 | Set a hard monthly limit on the OpenRouter key behind the group variable (provider side; no code) | person |
| B3 | One MR per repo adding the `include` of `ci/kelpie.yml` (with `AI_REVIEW_BASE_BRANCH` where the default branch is not `dev`). Order: `ubixshepherd` (replaces the shell job), `ubixvault`, `ubixcore`, `replikate`, then the other code repos. Leave `ubixsys-docs` and `ubixsys-manual-examples` out unless wanted | agent (one lane per repo) |

### C. The group webhook (after A3 to A6 and the gates in section 4)

| Step | What | Owner |
| --- | --- | --- |
| C1 | Create the group hook on `ubixsys` to `https://kelpie.prod.ubixsys.com/gitlab/webhook` with the prod-only secret: Merge request, Emoji, Comments, Pipeline/Job and Issues events, SSL verification on | person |
| C2 | Delete the five project hooks in the same sitting | person |
| C3 | Add a `projects` row, mode `propose`, per project as it earns it (`ubixcore`, `ubixvault`, `replikate`, then the rest). A project with no row is off, so the hook is safe on the whole group once A3 is live | person (SQL) or agent MR once a seeding command exists |
| C4 | Backfill old open issues per project with `kelpie:triage-backfill`, dry run first, a small `--limit` | person |

Do **not** put the hook on the group before A3: until the cutover, the env list is the only filter and a
group hook would depend on it alone.

## 4. The safety items

### #214 spend caps

- **Risk if enrolled first:** every issue costs a triage call, every merge request a review of up to twelve
  model turns, and a backfill multiplies that across 15 projects. Today the dollar cap in `projects` is
  stored and not enforced, and triage and fix runs do not record what they cost. One noisy project, a
  loop between hooks, or a leaked group variable (`OPENROUTER_API_KEY` is visible to every merge request
  pipeline in the group) turns into an unbounded OpenRouter bill. The review job alone is bounded by
  per-review line, byte and turn limits, not by a daily total.
- **Recommendation:** required before the group webhook (section C). Enforce the per-project and
  per-author daily counts and record cost per call, with a visible stop. For the CI review in B, the
  provider-side limit in B2 is enough to go ahead.

### #215 prod bot token and webhook secret

- **Risk if enrolled first:** the bot token and the webhook secret are the same on dev, staging and prod,
  and dev and staging read them from the weaker vault. Enrolling the group widens what that one token can
  reach from the two projects it covers today to the whole group (once it is a group member), and anyone
  who can read dev's Vault path could forge events to prod. The prod-Vault rule (prod holds production
  secrets only) is also not met.
- **Recommendation:** required before the group hook and before the bot joins the group. The new token
  should be Reporter on the group for `propose` (comment and label need no more); Developer only for the
  projects later switched to push. The group hook secret is created fresh for prod.

### #216 prompt-injection scan

- **Risk if enrolled first:** issue, merge request and thread text is untrusted input to a model. The
  design fences it as data and the triage and review runs have no write tools and no token, so in
  `propose` the realistic damage is a misleading comment or label, wasted spend, or private diff content
  sent to the model provider. A scan in front of the model lowers the first two. It matters more once a
  fixer produces diffs, and critically once anything pushes.
- **Recommendation:** not a blocker for `propose` on private, member-only projects, which all 15 are. Make
  it a hard requirement before any project moves to `push` or before a project with outside authors is
  enrolled. This is the one I would accept landing after the first enrolment; the person decides.

## 5. What "propose only" means today

K-30 and K-31 are unbuilt, so "propose" is not yet a thing the code enforces; it holds only because the
parts that could push have no trigger. On an enrolled repo, on current `dev`:

- **Review (CI include):** Kelpie posts an advisory AI review on merge requests: findings as threads, a
  stamped summary. It never fails the pipeline (an unrunnable review exits 75, shown as unreviewed) and
  never approves or merges.
- **Approval retry (hook):** on approve or 👍 it retries the `require-approval` job. It never approves.
- **Issues (hook):** with `KELPIE_AUTOFIX_ENABLED` off, as in the manifests after A6, nothing. With it on
  and the project on the env list, a 👀 and a queued triage job. The comment and `kelpie::*` label come
  only when the worker runs, which no tier does yet (step 2).
- **Merge request threads (hook):** a job row is stored. Nothing consumes it, so no reply, no diff, no
  commit. The fixer can push a gated fix to the merge request branch when run by hand
  (`kelpie:fix`), which is why the enabled flag is the only guard in `propose`.

So the honest label for an enrolled repo today is **review plus approval retry**. Triage comments arrive
with A2 and A6; the part of propose that offers a diff arrives only with K-31.

## 6. Decisions for the person

1. **Start CI review now?** Recommended yes, for `ubixshepherd`, `ubixvault`, `ubixcore`, `replikate`,
   after B1 and B2. It needs no Kelpie deploy.
2. **Gate for the group webhook:** #214 and #215 first, #216 after; or all three first.
3. **Who writes migration A1:** an agent drafts it for review, or the maintainer writes it.
4. **When to run `migrate-apply-prod` and `deploy-prod`:** after A1 (recommended), not on 8760 now. Running
   them first switches off the 👀 that is live on prod.
5. **Which projects to leave out:** recommended `ubixsys-docs`, `ubixsys-manual-examples`, and probably
   `ubcore` and `phpmyadmin`; with no row they stay off even under the group hook.
6. **Bot membership level:** Reporter at the group for `propose` (recommended), or Developer.
7. **Image pull for other projects:** a `read_registry` deploy token as group variables, or a
   job-token allowlist entry for the group.
8. **Base branches:** confirm each `main`/`master` project's review target (`AI_REVIEW_BASE_BRANCH`).
9. **How long the soak lasts** on `ubixkelpie` and `ubixshepherd` before widening (a week suggested).
