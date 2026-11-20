# Review rollout audit

Which repos have the Kelpie pieces and which do not, taken on 2026-11-19 with read-only GitLab API
calls (`glab api --hostname gitlab.brainchurts.com`). Nothing was changed in GitLab or in any repo.
The pieces are the ones in `docs/operations/enrolment.md`: the CI include of `ci/kelpie.yml` (the
`kelpie-review` job), the webhook, the bot's membership, and the labels.

Scope: every repo checked out under `~/git` whose origin is on gitlab.brainchurts.com (29 projects,
below). Checkouts on GitHub or on git.lan.vsmedia.net are at the end: this does not apply to them.

## How each column was read

- **Review job**: the default branch's `.gitlab-ci.yml` mentions `kelpie-review` or `ci/kelpie.yml`.
  `none` is a file without it; `no CI` is no `.gitlab-ci.yml` at all. An include of another project's
  template could still pull it in, so a repo marked `none` that has includes was only read at the top
  level (marked with *).
- **Hook**: a project hook to the prod Kelpie webhook. Group-level hooks were not read.
- **Bot**: the Kelpie Bot's role on the project, from the members listing with inherited members.
  `Dev` is Developer (30), the role Kelpie needs. Empty means not a member.
- **Labels**: the `kelpie::` labels exist on the project or inherited from its group. Kelpie creates
  its status labels on first use, so their absence on an unenrolled repo is expected and is not a
  blocker; it is listed for completeness.

## Table

| Repo | Default branch | Review job | Hook | Bot | `kelpie::` labels | State |
| --- | --- | --- | --- | --- | --- | --- |
| `ubixsys/ubixkelpie` | dev | yes | yes | Dev | no (old `triage:` labels only) | complete |
| `ubixsys/ubixshepherd` | dev | yes | yes (dev and prod) | Dev | yes | complete |
| `ubixsys/ubixcore` | main | yes | yes (plus the ops hook) | Dev | no | complete |
| `ubixsys/ubixvault` | main | yes | yes | Dev | no | complete |
| `ubixsys/ubixsys-docs` | master | yes | yes | Dev | no | complete |
| `ubixsys/ubixgate` | dev | none | yes | Dev | no | needs include |
| `ubixsys/replikate` | main | none * | yes | Dev | no | needs include |
| `kitg/kitg` | dev | none * | yes (plus the ops hook) | Dev | no | needs include |
| `ubixsys/phpmyadmin` | dev | none | no | Dev | no | needs include and hook |
| `ubixsys/ubixsys-web` | dev | none | no | Dev | no | needs include and hook |
| `ubixsys/ubixsys-manual-examples` | dev | none | no | Dev | no | needs include and hook |
| `ubixsys/ubixshepherd-internal` | dev | no CI | no | Dev | no | needs CI file, include and hook |
| `ubixos/ubixos-packages` | master | no CI | yes | none | no | needs CI file and bot |
| `ubixos/ubixos` | master | none * | yes | none | no | needs include and bot |
| `the-8-bit-machine` (`cwolsen/`) | dev | no CI | yes | none | no | needs CI file and bot |
| `cwolsen/my-person` | dev | no CI | no | none | no | not enrolled |
| `cwolsen/planet-merge` | dev | no CI | no | none | no | not enrolled |
| `cwolsen/resume` | dev | none | no | none | no | not enrolled |
| `cwolsen/airdropt-web` | dev | none | no | none | no | not enrolled |
| `cwolsen/brainchurts-web` | dev | none | no | none | no | not enrolled |
| `cwolsen/eink` | dev | none | no | none | no | not enrolled |
| `cwolsen/laravel-poc` | dev | none | no | none | no | not enrolled |
| `cwolsen/the8bitmachine-website` | dev | none | no | none | no | not enrolled |
| `ubixos/ubixos-web` | dev | none | no | none | no | not enrolled |
| `k8s/baseimages` | dev | none | no | none | no | not enrolled |
| `kube-stuff/kubernetes` | master | no CI | no | none | no | not enrolled |
| `empower-group/landing-sites` | dev | none | no | none | no | not enrolled |
| `myfollowers/mf-api` | dev | none | no | none | no | not enrolled |
| `spherepbx/spherefax-portal` | master | none | no | none | no | not enrolled |

`the-8-bit-machine` is `cwolsen/the-8-bit-machine`. Where the default branch is not `dev` (master or
main), set `AI_REVIEW_BASE_BRANCH`, because the review job assumes `dev`.

## Not applicable

| Checkout | Host | Why |
| --- | --- | --- |
| `ubixcore-php` | github.com | GitHub-hosted: the GitLab job, hook and labels do not apply |
| `QEMU-osdev`, `qemu` | github.com | GitHub upstream clones |
| `JatRR` | Git.BrainChurts.com (old host) | not on gitlab.brainchurts.com |
| `fanclub-user-python`, `project-neptune`, `vsm-labs-web` | git.lan.vsmedia.net | another GitLab: this host's bot and webhook do not reach it |

## Rollout order

Missing repos only. Each line is one merge request (MR) or one ops step; the include MR is always the
same change as step 1 of `docs/operations/enrolment.md`.

1. **`ubixsys/ubixgate`, `ubixsys/replikate`, `kitg/kitg`**: one MR each, adding the include. Hook and bot
   are already in place, so the review starts on the next MR. Do replikate and kitg after reading
   their existing includes (they may already pull a template). Start with ubixgate.
2. **`ubixsys/phpmyadmin`, `ubixsys/ubixsys-web`, `ubixsys/ubixsys-manual-examples`**: one MR each for the
   include, plus one ops step each for the hook. The bot is already a member.
3. **`ubixsys/ubixshepherd-internal`**: one MR that adds a minimal `.gitlab-ci.yml` with the include,
   plus the hook.
4. **`ubixos/ubixos-packages`, `ubixos/ubixos`, `the-8-bit-machine`**: one ops step to add the bot as
   Developer (the hook is there), then one MR each for the include (a CI file for the packages and
   the-8-bit-machine). Set `AI_REVIEW_BASE_BRANCH=master` for the first two.
5. **The remaining `not enrolled` repos**: one ops step per repo (bot, hook) and one MR (include), but
   only after the person decides which belong. That is not a technical call: enrolling sends diffs to
   an outside model service, and `empower-group`, `myfollowers` and `spherepbx` are other parties'
   code. Personal repos with no CI at all gain little from a review gate.

Whichever order: the group variables (`OPENROUTER_API_KEY`, `AI_REVIEW_GITLAB_TOKEN`) and a registry
login that can pull Kelpie's image must reach each repo's group first (`docs/operations/ci-variables.md`),
and the repo should be in `KELPIE_AUTOFIX_PROJECTS` only if it also gets triage, not for review alone.
These were not checked: group variables were not read.
