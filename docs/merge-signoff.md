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
| `APPROVAL_JOB_NAMES` | Optional, comma-separated sign-off job names |

A project with no token (and no fallback) is ignored. Create tokens as a bot (the project
access token's own user), not under a personal account, so the audit trail shows who retried.

## Wiring a project to Kelpie

Per project, once:

1. **Pipeline job.** A last-stage MR job running `ci:requireApproval`, named one of
   `APPROVAL_JOB_NAMES`. Kelpie's `.gitlab-ci.yml` has `require-approval-mr` (modelled on
   kitg's). It needs the CI variable `AI_REVIEW_GITLAB_TOKEN` (Reporter, `api`, masked) to read
   the sign-off, and `MERGE_APPROVAL_OWNERS` if an owner may sign off their own MRs.
2. **Token.** The Developer/`api` bot token above, in `GITLAB_PROJECT_TOKENS`.
3. **Webhook.** Project **Settings → Webhooks → Add new webhook**:
   - URL: Kelpie's `/gitlab/webhook`
   - Secret token: Kelpie's `GITLAB_WEBHOOK_SECRET`
   - Trigger: **Merge request events** and **Emoji events** (plus Comments / Issues events
     for the logging handlers)
   - SSL verification on

   The hook's *Test → Merge request events* sends an `open` event, answered 200 "ignored";
   that proves the path and the secret.

Do not leave the same project's hook pointed at uBixOps as well: both would fire. (Harmless,
since a running job is skipped, but noisy.)

### Group hook or project hooks

A group-level hook would cover every project with one entry, but group webhooks are a
paid-tier GitLab feature. Confirm the instance's tier; without it, add the hook per project.
`GITLAB_API_TOKEN` accepts one group-scoped token as the fallback.

## Where it runs

Kelpie has no deployment yet (`.gitlab-ci.yml` stops at the gate); the hostname, namespace and
Vault paths for it are not decided. GitLab must be able to reach the webhook URL.

## Requires

uBixCore with emoji, fallback-token and skip-if-passed support in `ApprovalWebhookService`
(merged to uBixCore `main` after v0.45.0). Kelpie needs the release that carries it.

## Limits

- Needs that uBixCore release; on v0.45.0 the service has no emoji handling.
- A job on a pipeline other than the MR's head pipeline is not touched.
