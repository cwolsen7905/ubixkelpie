# Merge sign-off: making Approve re-run the check

Every merge into Kelpie needs a human sign-off (an Approve or a 👍), enforced by a pipeline job
that runs uBixCore's `bin/ubix ci:requireApproval`. GitLab does not re-run a pipeline when
someone approves, so by itself the job stays red until someone retries it by hand.

**Kelpie hosts the retry itself.** Kelpie's later features (fixing issues, fixing review
threads) need a GitLab webhook receiver anyway, and approval retry is its first real use. The
logic is not copied: it is uBixCore's `Ubix\Service\Ci\ApprovalWebhookService` (the same class
behind uBixOps), which Kelpie calls from its own endpoint. Kelpie adds the route, the wiring
and a guard (below); the framework keeps the find-and-retry logic, per "The uBixCore boundary"
in `CLAUDE.md`. Reference: uBixCore `docs/architecture/ubix-ops.md` and
`docs/standards/branching-and-git-workflow.md` (Merge sign-off).

## What it does

`POST /gitlab/webhook` (`app/KelpieApi/src/Routes.php`, `Kelpie\Controller\Gitlab\GitlabWebhookController`):

1. A wrong or missing `X-Gitlab-Token` is refused with 401. Everything else is answered 200,
   so GitLab never disables the hook.
2. `Merge Request Hook` with action `approval` / `approved` / `unapproval` / `unapproved`, and
   `Emoji Hook` with a `thumbsup` awarded or revoked on a merge request, go to
   `ApprovalWebhookService`. Anything else is answered 200 and ignored.
3. It reads the MR's head pipeline, finds the newest job named in `APPROVAL_JOB_NAMES` (default
   `require-approval`, `require-approval-mr`) and retries that one job (plays it if it is
   manual). A job already queued or running is left alone, and an already-passed job is left
   alone unless the sign-off was withdrawn. It never creates a pipeline.
4. A GitLab failure is logged and still answered 200.

The same endpoint also takes `Note Hook` (on merge requests) and `Issue Hook` (open); those
only log what they saw.

### Kelpie never approves or 👍s

The API token the receiver holds (Developer, `api`) *could* approve or award an emoji, so the
token's scope is no protection. The code is: the approval service is given
`Kelpie\HttpClient\ApprovalRetryHttpClient` as its HTTP client (`app/KelpieApi/src/Dependencies.php`),
which refuses, before anything is sent, every request except `GET` an MR, `GET` a pipeline's
jobs and `POST` a job's `retry`/`play`. There is no route from the webhook to `/approve`,
`/award_emoji`, `/merge` or `/pipeline`. A test asserts the refusals.

## Configuration

All from the environment (filled from uBixVault in deployment; nothing is committed, see
`.env.example`):

| Variable | Meaning |
| --- | --- |
| `GITLAB_WEBHOOK_SECRET` | The webhook's secret token; empty refuses everything |
| `GITLAB_API_URL` | GitLab API v4 base, e.g. `https://gitlab.example.com/api/v4` |
| `GITLAB_PROJECT_TOKENS` | `projectId=token,projectId=token`: Developer role, `api` scope |
| `GITLAB_API_TOKEN` | Optional fallback for a project with no entry above (e.g. a group token) |
| `KELPIE_BOT_GITLAB_TOKEN` | Last-resort fallback when neither of the above is set (the bot token the fix/respond/sweep commands use) |
| `APPROVAL_JOB_NAMES` | Optional, comma-separated sign-off job names |

A project with no token (and no fallback) is ignored. Create tokens as a bot (the project
access token's own user), not under a personal account, so the audit trail shows who retried.

## Wiring a project to Kelpie

Per project, once:

1. **Pipeline job.** A last-stage MR job running `ci:requireApproval`, named one of
   `APPROVAL_JOB_NAMES`. Kelpie's `.gitlab-ci.yml` has `require-approval-mr` (modelled on
   kitg's). It needs the CI variable `APPROVAL_GITLAB_TOKEN` (or `AI_REVIEW_GITLAB_TOKEN`; Reporter,
   `api`, masked) to read the sign-off. **Without it the job passes as "UNVERIFIED"**, so the
   gate enforces nothing until the variable is set. `MERGE_APPROVAL_OWNERS` is needed if an
   owner may sign off their own MRs.
2. **Token.** The Developer/`api` bot token above, in `GITLAB_PROJECT_TOKENS`. If the
   project has no entry, the retry falls back to `GITLAB_API_TOKEN`, then to
   `KELPIE_BOT_GITLAB_TOKEN`, so one Vault key can cover everything.
3. **Webhook.** Project **Settings → Webhooks → Add new webhook**:
   - URL: `https://kelpie.prod.ubixsys.com/gitlab/webhook` (staging: `kelpie.staging.…`, dev: `kelpie.dev.…`)
   - Secret token: Kelpie's `GITLAB_WEBHOOK_SECRET` for that environment (uBixVault, below)
   - Trigger: **Merge request events** and **Emoji events** (plus Comments / Issues events
     for the logging handlers)
   - SSL verification on

   The hook's *Test → Merge request events* sends an `open` event, answered 200 "ignored";
   that proves the path and the secret.

Do not leave the same project's hook pointed at uBixOps as well: both would fire. (Harmless,
since a running job is skipped, but noisy.) **Once Kelpie's prod is live and a test event has
been answered 200, remove the old uBixOps hook from every project that now points at Kelpie.**

### Group hook or project hooks

A group-level hook would cover every project with one entry, but group webhooks are a
paid-tier GitLab feature. Confirm the instance's tier; without it, add the hook per project.
`GITLAB_API_TOKEN` accepts one group-scoped token as the fallback; when it is unset,
`KELPIE_BOT_GITLAB_TOKEN` is used instead.

## Where it runs

KelpieApi deploys to three environments, one per branch, and promotes automatically:

| Branch | Namespace | Host | Vault path / role |
| --- | --- | --- | --- |
| `dev` | `kelpie-dev` | `kelpie.dev.ubixsys.com` | `kelpie/dev/app` / `kelpie-dev` |
| `staging` | `kelpie-staging` | `kelpie.staging.ubixsys.com` | `kelpie/staging/app` / `kelpie-staging` |
| `main` | `kelpie-prod` | `kelpie.prod.ubixsys.com` | `kelpie/prod/app` / `kelpie-prod` |

**Prod is the one GitLab webhooks point at**: `https://kelpie.prod.ubixsys.com/gitlab/webhook`.
Each hostname is covered by the `tls-wildcard-ubixsys-{dev,staging,prod}` wildcard certificates.

The four variables in *Configuration* live in uBixVault at `<vault path>` above (read-only for the
pod's `kelpie-<env>` role through `VAULT_APP_KV_PATH` in `app/KelpieApi/<env>-deploy.yaml`);
nothing is committed. uBixVault fails closed on a missing key, so the pod never becomes ready
without them. **Unverified until the first deploy:** that the pinned uBixCore exports every key
at that path as an environment variable (what `getenv()` in `Dependencies.php` needs), as it does
for kitg.

Pipeline (`.gitlab-ci.yml`, uBixCore's template): `build -> test -> migrate -> deploy -> promote`.
A green `deploy-dev` runs `promote-to-staging`; a green `deploy-staging` runs `promote-to-prod`,
and `main` then waits for the manual `migrate-apply-prod` before `deploy-prod`. uBixCore's
`ci/bin/promote.sh` fast-forwards only and never forces; it needs the project CI variable
`GITLAB_PROMOTE_TOKEN`. `ci/bin/deploy.sh` waits for the rollout, so a pod that cannot read its
secrets fails the deploy and stops promotion.

### Outside this repo (kubernetes repo / GitLab) before the first deploy

- Namespaces `kelpie-{dev,staging,prod}`, with the `regcred` pull secret and the
  `tls-wildcard-ubixsys-{dev,staging,prod}` secrets replicated into them.
- uBixVault roles `kelpie-{dev,staging,prod}` bound to those namespaces (`configure.sh` with
  `APP_NAMESPACES`) and the KV secrets at the paths above (Vault: dev and staging use
  `vault.dev`, prod uses `vault.prod`; prod holds production only).
- DNS for the three hostnames, if the wildcard record does not already cover them.
- GitLab: branches `staging` and `main` (they do not exist yet), protected, push allowed for
  the promote token's user; CI variable `GITLAB_PROMOTE_TOKEN`; `ubixsys/ubixkelpie` readable
  with the pull secret.
- The runner's `~/.kube/devstageconfig` (or `KELPIE_KUBECONFIG`) must be able to apply into
  the three namespaces.

## Requires

uBixCore v0.47.0 or later: its `ApprovalWebhookService` handles the 👍 emoji, the fallback
`apiToken` and skip-if-passed, and it ships the CI template and `ci/bin` scripts this pipeline
uses.

## Limits

- A job on a pipeline other than the MR's head pipeline is not touched.
- A 👍 (`thumbsup`) on the merge request starts the sign-off job, like an Approve. This needs
  uBixCore v0.46.1: GitLab 19.4.2 sends the emoji event's type at the top level
  (`event_type`), which v0.46.0 did not read.
