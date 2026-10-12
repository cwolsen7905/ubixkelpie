# Merge sign-off: making Approve re-run the check

Every merge into Kelpie needs a human sign-off (an Approve or a 👍), enforced by a pipeline job
that runs uBixCore's `bin/ubix ci:requireApproval`. GitLab does not re-run a pipeline when
someone approves, so by itself the job stays red until someone retries it by hand.

**Kelpie does not implement the fix.** uBixCore ships it as **uBixOps**, a framework app that
turns the Approve click into a retry of that one job. Kelpie only needs to be wired to it. This
is deliberate: the sign-off check and its retry trigger are one CI-standard feature, useful to
any uBixCore host without Kelpie, so they ship together in the framework (see "The uBixCore
boundary" in `CLAUDE.md`). Reference: uBixCore `docs/architecture/ubix-ops.md` and
`docs/standards/branching-and-git-workflow.md` (Merge sign-off).

## What it does

1. GitLab sends a merge-request event to uBixOps on every MR change.
2. A wrong or missing `X-Gitlab-Token` is refused with 401. Anything other than an
   `approval` / `approved` / `unapproval` / `unapproved` action is answered 200 and ignored.
3. For an approval event it reads the MR's head pipeline, finds the newest job named in
   `APPROVAL_JOB_NAMES` (default `require-approval`, `require-approval-mr`) and retries that one
   job. A job that is already queued or running is left alone. It never creates a pipeline.
4. A GitLab failure is logged and still answered 200, so GitLab does not disable the hook.

## Wiring Kelpie to it

Per project, once:

1. **Pipeline job.** The project needs a last-stage MR job that runs `ci:requireApproval`,
   named one of the `APPROVAL_JOB_NAMES`. kitg's `require-approval-mr` is the reference.
   Kelpie's own `.gitlab-ci.yml` does not have one yet.
2. **Token.** uBixOps needs an API token for the project: a project access token, role
   **Developer**, scope **`api`** (retrying a job needs Developer; the Reporter token that
   `ci:requireApproval` reads with is not enough). Add it to uBixOps' `GITLAB_PROJECT_TOKENS` as
   `<projectId>=<token>`. Today tokens are per project; a project with no entry is ignored.
   Create it as a bot (the project access token's own user), not under a personal account, so
   the audit trail shows who retried the job.
3. **Webhook.** Project **Settings → Webhooks → Add new webhook**:
   - URL: uBixOps' `/gitlab/merge-request-hook` endpoint
   - Secret token: uBixOps' `GITLAB_WEBHOOK_SECRET`
    - Trigger: **Merge request events** and **Emoji events**
   - SSL verification on

   The hook's *Test → Merge request events* sends an `open` event, which uBixOps answers 200
   "ignored"; that proves the path and the secret.

### Group hook or project hooks

A group-level hook on `ubixsys` would cover every project with one entry, but group webhooks are
a paid-tier GitLab feature. Confirm the instance's tier; without it, add the hook per project.
Either way the per-project token in step 2 is still needed until uBixOps accepts one
group-scoped token (requested upstream).

## Where it runs

Not here. Deployment config for uBixOps lives in the `kubernetes` repo (`ubix-ops/`); see its
README for the host, the credentials Secret and the install command. GitLab must be able to
reach that URL. Kelpie holds none of these secrets and needs no environment variables for this.

## Limits

- **Emoji events.** Since ubixcore MR !212, uBixOps also handles `emoji` events: a 👍 on a
  merge request acts as an Approve and triggers a retry; removing a 👍 acts as an Unapprove
  and triggers an unapproval event.
- Jobs waiting on a manual play are not started by uBixOps; only retried.
- An already-successful job is retried too (harmless, noisy). Both are requested upstream.
