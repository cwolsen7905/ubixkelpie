# Enrolling ubixsys/ubixcore for Kelpie issue autofix

2026-11-19. Follows `docs/notes/why-no-resolve.md`, which found a webhook on ubixcore but no `projects` row. Reference
project: ubixsys/ubixkelpie. GitLab was read with `glab api` GETs; no secret value was printed. No database access from
this lane (no kubectl context, no Vault), so the row is not inserted.

## Summary

| Step | State |
| --- | --- |
| Webhook | Done already, no gap. Nothing changed. |
| Bot membership | Done already. Nothing changed. |
| CI variables | Nothing project-level is missing. Group-level ones could not be read: left for the person. |
| `projects` row (prod, `propose`) | **Left for the person**: SQL below. |
| CI include in ubixcore | Already present on `main`; nothing to add for autofix. |

## Webhook

ubixcore hook 7 and ubixkelpie hook 8 are the same on every field that matters: URL
`https://kelpie.prod.ubixsys.com/gitlab/webhook`, name "Kelpie Automation", events merge request, note, issues and
emoji on; push, pipeline, job, tag and the rest off; SSL verification on; secret token set on both; state executable.
No change. ubixcore also has the unrelated uBixOps hook 2.

## Bot membership

The Kelpie bot (the group access token bot of group 223, user id 37) is an active member of ubixcore as Developer (30),
the same role it has in ubixkelpie. No change.

## CI variables

- ubixkelpie's project variables: `APPROVAL_GITLAB_TOKEN`, `MERGE_APPROVAL_OWNERS`, `UBIXVAULT_CI_TOKEN_DEV`,
  `UBIXVAULT_CI_TOKEN_PROD`. They are for sign-off and Vault, not for Kelpie's autofix.
- ubixcore has `APPROVAL_GITLAB_TOKEN`, `UBIXVAULT_CI_TOKEN_DEV`, `UBIXVAULT_CI_TOKEN_PROD` already, plus
  `GITLAB_PROMOTE_TOKEN` and `GITLAB_SKELETON_TOKEN`.
- `MERGE_APPROVAL_OWNERS` is absent at project level, but ubixcore's `.gitlab-ci.yml` on `main` sets it as a job
  variable on the sign-off job, so a project variable is not needed. I did not create one.
- The webhook-driven autofix runs in Kelpie's own pods and reads GitLab with the bot's token from Vault; it needs no CI
  variable in ubixcore.
- The advisory CI review job needs group-level `OPENROUTER_API_KEY` and `AI_REVIEW_GITLAB_TOKEN`, and optionally
  `KELPIE_REGISTRY_USER` / `KELPIE_REGISTRY_TOKEN` (docs/operations/ci-variables.md). **Not checked:** reading group
  variables was refused for this lane, so whether the group already holds them is unknown. Until they exist the review
  job exits 75, is allowed to fail and posts UNREVIEWED. Left for the person to confirm.

## `projects` row (left for the person)

Table `ubixkelpie.projects` (migration `20261022150000_create_projects`). Other columns take their defaults
(30 issues/day, 10 per author, 2.00 USD/day, 3 fix commits per MR). Run on **prod** the same way the two existing rows
were made (an idempotent `INSERT IGNORE` from a pod, as in `docs/operations/go-live.md`, step 5):

```sql
INSERT IGNORE INTO ubixkelpie.projects (path, mode) VALUES ('ubixsys/ubixcore', 'propose');

SELECT id, path, mode, triage_model, review_model, date_created
FROM ubixkelpie.projects
WHERE path = 'ubixsys/ubixcore';
```

`path` is unique, so a second run changes nothing. Expect one row, mode `propose`. To undo: set `mode = 'off'`.
Dev is not needed: the webhook goes to the prod deployment only.

## CI include in ubixcore

ubixcore's `.gitlab-ci.yml` on `main` already includes `ci/kelpie.yml` from ubixsys/ubixkelpie, pinned to a commit,
with `AI_REVIEW_BASE_BRANCH: main`. (`why-no-resolve.md` recorded "no include"; that was out of date or read from
another branch.) Webhook autofix does not need it at all; it only drives the advisory CI review. I made no change in the
ubixcore repo. Optional later: move the pin from a commit to a release tag once Kelpie has one.

## What is left

1. Person: run the SQL above on prod and read the row back.
2. Person: confirm the group variables for the CI review exist (see above).
3. Triage still answers nothing until `KELPIE_TRIAGE_MODEL` is set (another lane), so enrolment alone will not make
   Kelpie act on ubixcore threads. Moving to `push` is a separate decision (`docs/autofix/push-mode.md`).
