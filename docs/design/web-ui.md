# Kelpie's own web UI (plan)

Status: plan only. No code, no migration and no route exists for it yet. It is a future version:
it starts after Shepherd's Kelpie stats panel, and each phase below is one merge request.

Today Kelpie answers four routes (`GET /health`, `GET /version`, the OPTIONS catch-all, and
`POST /gitlab/webhook`). Nothing reads state out, there is no sign-in, and projects are enrolled by
inserting SQL rows (`docs/operations/projects.md`). This plan adds a read surface, then a browser UI
with a few controls, without widening what Kelpie may do on its own.

## 1. What the UI shows

The starting point is the Shepherd panel's figures. Every one comes from tables Kelpie already fills.

| Figure | Source | Gap to settle |
| --- | --- | --- |
| Jobs per project | `autofix_jobs` by `project_path`, `source`, `status`, `date_created` | none; `idx_project_created` serves it |
| Proposals | `thread_outcomes` where `outcome` is proposed, with applied and declined beside it | none |
| Dismissals | `thread_outcomes.verdict` (not-an-issue, already-fixed) | no outcome is named "dismissal"; define it (open question 4) |
| Needs-you | `thread_outcomes.verdict` needs-human; the live count is the GitLab label on open MRs | the label is GitLab-side; define which one the UI shows (open question 4) |
| Fixes | `thread_outcomes` where `outcome` is pushed or applied | the commits themselves are on GitLab |
| Spend | `SUM(thread_outcomes.cost_usd)` per project and day, against `projects.max_cost_usd_day` and the global cap (`SpendCapService`) | issue triage and `kelpie:review` cost is not recorded, so spend understates (open question 5) |
| Mode and caps | `projects` | none |

Beyond the panel, the own UI adds:

- **Overview:** one row per enrolled project (mode, paused or not, today's jobs, proposals,
  dismissals, needs-you, fixes, spend against the cap), a Kelpie-wide spend line, and the global
  kill-switch state shown read-only.
- **Project page:** the same figures over a chosen window (today, 7 days, 30 days), the caps in force,
  and the recent jobs.
- **Job and thread lists:** ids, project, MR number, source, status, attempt, verdict, outcome,
  tokens, cost and times, each with a link to the thread or MR in GitLab. Kelpie's own pages show
  **no** issue or MR text, diffs, stored edits or model replies. That text stays in Kelpie and GitLab;
  the UI links out for it.
- **Unreachable or stale data:** a page that cannot read the database says so and shows no numbers
  rather than old ones.

## 2. What it controls

Controls are few, each is audited, and none lets the UI do more than a person already allowed.

| Control | Effect | What it needs |
| --- | --- | --- |
| Pause / resume a project | Kelpie stops taking new jobs for it; resume restores the earlier behaviour | a new `paused` flag on `projects`. Setting `mode` to `off` as the pause would lose the previous mode, so the flag is separate and the worker treats paused like off |
| Job caps | Edit `max_issues_per_day`, `max_issues_per_author_day`, `max_cost_usd_day`, `max_fix_commits_per_mr` per project | a ceiling set outside the UI (env or Vault) that the UI cannot raise; values above it are refused |
| Retry a job | Put a failed or deferred job back to pending (a fresh attempt count) | a writer method on the job repository; respects the one-open-job-per-thread rule and the caps |
| Dismiss a job | Mark a pending, deferred or failed job as superseded so it is never run | same writer; no GitLab side effects |
| Mode and enrolment | Add a project, change `off` / `propose` / `push` | last phase; see the rules below |

Rules that hold for every write:

1. **Never widen past what a person set.** A mode change toward more autonomy (`off` to `propose`,
   `propose` to `push`) needs a second, explicit confirmation step and is limited to projects on a
   maintainer-set allow-list; narrowing (pause, lower caps, `push` to `propose`) is one click. Push mode
   is not enabled from the UI at all until the person decides it should be (open question 3).
2. **The global kill switch stays env-only.** The UI shows it; it cannot flip it. It also cannot create
   or edit credentials.
3. **Every write is audited:** who (GitLab user), what, old and new value, when, in a new
   `ui_audit` table that is append-only.
4. **Writes go through a service**, not straight from a controller to SQL, so the CLI and the UI share
   the same validation (open question 2).
5. **CSRF protection** on every state-changing request, and no state change on GET.
6. The UI approves, merges and resolves nothing. Those stay with people in GitLab.

## 3. How it is served

- **Same image, one more app.** A new `app/KelpieWeb` (Slim, PHP-DI, `Routes.php`, `Middleware.php`)
  next to `KelpieApi`, using the same `Kelpie\` code (services, repositories). The Dockerfile already
  copies `app/`, `php/`, `templates/` and `sql/`, so nothing new is baked in. nginx and PHP-FPM are the
  base image's; `config/devops/nginx.conf` gains the routing for it.
- **Routing.** Preferred: the existing `KelpieApi` app mounts the UI under `/ui` and the stats API
  under `/api/v1/`, so one process, one Deployment and one hostname per tier
  (`kelpie.{dev,staging,prod}.ubixsys.com`) serve everything. The alternative is a second Deployment of
  the same image with `APP_NAME=KelpieWeb` behind its own ingress; that isolates the UI's sessions from
  the webhook path at the cost of more manifests (open question 6).
- **Rendering.** Server-rendered pages from `templates/` with the framework's template engine, and
  plain HTML forms. No JavaScript build step, no node in the runtime path. Small progressive
  enhancements (a refresh, a confirm dialog) may be hand-written scripts served as static files.
- **Layout.** Controllers stay thin; the stats come from a `StatsService` and per-table readers
  (`XReaderInterface`), criteria as DTOs, and a window as a DataType. Every concrete class gets a test
  under `tests/` as usual.
- **Anything missing in uBixCore** (OAuth client, sessions, CSRF helper) is added upstream and the pin is
  bumped, not shimmed here. Phase 2 starts by checking what the pinned release already ships.

## 4. Authentication

- **People: GitLab OAuth** against `gitlab.brainchurts.com`, so Kelpie reuses the identity the
  maintainers already have and holds no passwords. After sign-in, the user's GitLab login must be on a
  maintainer allow-list (an env or Vault setting per tier) or the session is refused. Sessions are
  short-lived, `HttpOnly`, `Secure`, `SameSite=Lax`, server-side.
- **Machines: bearer token** for the read-only stats API (Shepherd's panel, scripts). It is a token of
  its own in uBixVault, distinct from the GitLab webhook secret and from the OAuth client secret, and
  it can read only; it can never reach a write route.
- **Secrets** (OAuth client id and secret, session key, API token) live in uBixVault
  (`kelpie/<env>/app`), as the existing ones do; none is committed. A missing value turns the feature
  off and says so on `/health`-style readiness, not a crash.
- The UI is behind sign-in on every route except the OAuth callback and `/health`.

## 5. Data sources

- `projects`, `autofix_jobs`, `thread_outcomes`: read through the existing Reader interfaces, with new
  aggregate methods added (counts by window and group, no row text).
- New in later phases: `projects.paused` (phase 4), `ui_audit` (phase 4), and whatever the open
  questions below decide (a per-run cost ledger, a dismissal marker).
- GitLab is read only for links; live label counts are not fetched on page load unless open question 4
  decides it is needed, and then they are cached.
- Migrations follow the repo's rules (`sql/migrations/`, header, `AlterAck` for any ALTER).

## 6. Relation to the Shepherd panel

**Share one API; keep both.** The stats endpoint built in phase 1 is the single source: Shepherd's
panel reads it, and Kelpie's UI uses the same service in-process. The Shepherd panel stays read-only and
is not replaced: it is where the maintainer already looks at all lanes. It gains a link per project
into Kelpie's UI for the controls. Kelpie's UI shows the same numbers because it calls the same code,
so the two cannot drift. Retiring Shepherd's panel is a later choice, not part of this plan (open
question 7).

The API is versioned (`/api/v1/stats`), returns counts, ids, paths, modes, tokens and cost for a window
and never text, diffs or stored edits, and the panel treats a Kelpie that is unreachable or answering
5xx as "unreachable" with no numbers shown.

## 7. Phases

Each phase is one merge request, with its tests and docs, and ends green on the gate
(`vendor/bin/phpcs && vendor/bin/phpstan && vendor/bin/phpunit`). Checks are written before the code.

**Phase 1: read-only stats API.** A `StatsService`, aggregate reader methods, and
`GET /api/v1/stats?window=...` behind the stats bearer token. Settles the definitions of dismissal and
needs-you (open question 4) in `docs/`.
*Done when:* with the token, a project with 3 `thread_outcomes` rows today (one proposed, one applied,
one declined) and 2 jobs shows those counts; with no token, a wrong token, or the webhook secret it
answers 401; spend and cap match `SpendCapService` for a project with 1.50 spent of a 2.00 cap; no
response contains issue or MR text, a diff, or `edits_json` (a test greps the fixture output). Shepherd
can build its panel on it.

**Phase 2: sign-in and UI shell.** GitLab OAuth, the allow-list, sessions, CSRF middleware, the layout
template and an empty overview. Any uBixCore gap goes upstream first.
*Done when:* an unauthenticated request to any UI route redirects to sign-in; a GitLab user not on the
allow-list is refused after the callback; an allow-listed user sees the shell; a POST without a valid
CSRF token is refused; the API token cannot open a UI session.

**Phase 3: stats pages (read only).** Overview, project page with windows, job and thread lists with
GitLab links.
*Done when:* every enrolled project appears with its mode and today's numbers matching the phase 1
API for the same window; no page renders thread text, diffs or edits; with the database unreachable the
pages say so and show no numbers; no form or POST route exists yet.

**Phase 4: pause / resume and the audit trail.** `projects.paused` migration, the worker and webhook
honour it, `ui_audit` table, the first write routes.
*Done when:* pausing a project stops new jobs being taken for it (a worker test) without changing its
`mode`; resuming restores it; each action leaves one audit row with user, old and new value; a viewer
not on the allow-list cannot post; the GET of a write route changes nothing.

**Phase 5: caps, retry and dismiss.** Cap edits within the outside ceiling, job retry and job dismiss.
*Done when:* a cap above the ceiling is refused and a lower one takes effect on the next spend check; a
failed job retried becomes pending once and keeps the one-open-job-per-thread rule; a dismissed job is
never run; each change is audited.

**Phase 6: enrolment and modes.** Add a project, change its mode, with the widening rules of section 2.
*Done when:* a new project is created `off`; moving it toward more autonomy needs the confirmation step
and the allow-list; `push` cannot be selected from the UI unless the person has enabled it; narrowing is
immediate; the audit shows every change.

**Phase 7: lessons and thumbs.** Show what Kelpie learned and the 👍/👎 reactions on proposals, once the
learning feature exists. Left out until then; it will get its own short plan.

## 8. Open questions for the person

1. **Phase 1 as the next step?** It unblocks Shepherd's panel and the rest. *Recommendation:* yes;
   build it first and alone.
2. **Where do writes live?** Controllers straight to SQL, or a service that the CLI also uses.
   *Recommendation:* a service, so rules (ceilings, widening, audit) exist once.
3. **May the UI set `push` mode?** *Recommendation:* no, not in the first versions. Enrolment and
   `propose` only; push stays an operator step until a project has a track record.
4. **What are a "dismissal" and a "needs-you" for the UI?** *Recommendation:* dismissal is a
   `thread_outcomes` row with a not-an-issue or already-fixed verdict; needs-you is a row with a
   needs-human verdict that is still open. Both come from the database, with no live GitLab call; the
   GitLab label count is a later, cached addition.
5. **Add a per-run cost ledger and record issue-triage and review cost?** Spend understates without it.
   *Recommendation:* yes, as a separate small migration before phase 3, so the pages are honest from the
   start.
6. **Same pod or separate Deployment for the UI?** *Recommendation:* same pod, `/ui` and `/api/v1` under
   the existing hostname; split later only if the UI's load or exposure demands it.
7. **Retire Shepherd's panel eventually?** *Recommendation:* no; keep it as the cross-project view and
   link into Kelpie for controls. Revisit if nobody uses it.
8. **Allow-list source.** A fixed list of GitLab logins, or members of a GitLab group.
   *Recommendation:* a fixed list in Vault to start (smallest surface); a group later.
9. **Session and OAuth support in uBixCore.** If the pinned release lacks them, add upstream first.
   *Recommendation:* check in phase 2 before writing any code here; do not shim.
