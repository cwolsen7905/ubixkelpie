# Issue triage and the review triage rule

**Status:** Proposal (design only, no code). **Date:** 2026-10-14. **Owner:** the person decides the
open questions in section 8.

Kelpie's first product feature has two halves that meet in the middle:

- **Review triage.** The pipeline's AI code review decides, per finding, between *a thread in the
  MR* (resolve it now, it blocks the merge) and *an issue* (it does not block; file it and move on).
- **Issue triage and fixing.** Kelpie tracks Git issues, triages them, and optionally attempts a
  fix as a gated merge request.

The seam is the **issue format** the review creates, which is Kelpie's intake contract (section 5).

## 1. Today

Verified against a fresh `origin/dev` (79db7d2) of this repo and `origin/main` of `~/git/ubixcore`
(read-only).

### Kelpie (this repo)

Foundation only when this was written. There was no issue, triage or fixing feature and no
database (since 2026-10-17 there is one, `ubixkelpie`; see `docs/operations/database.md`), no
LLM client and no forge client of its own.

| What | Path | State |
| --- | --- | --- |
| Webhook receiver, `POST /gitlab/webhook`, `X-Gitlab-Token` checked with `hash_equals` | `php/Kelpie/Controller/Gitlab/GitlabWebhookController.php`, route in `app/KelpieApi/src/Routes.php` | Works. Dispatches on `X-Gitlab-Event`. |
| Issue Hook (open) handler | `php/Kelpie/Service/Gitlab/IssueOpenWebhookService.php` | **Logs only.** Returns `opened: issue #N in <path> by <user>`. |
| Note Hook (MR) handler | `php/Kelpie/Service/Gitlab/NoteOnMrWebhookService.php` | **Logs only** (length, not text). |
| MR and Emoji hooks | uBixCore `Ubix\Service\Ci\ApprovalWebhookService` | Re-runs `require-approval*` jobs. |
| HTTP guard | `php/Kelpie/HttpClient/ApprovalRetryHttpClient.php` | Refuses everything except `GET` MR, `GET` pipeline jobs, `POST` job `retry`/`play`. No write path to issues or threads exists. |
| Sign-off job | `.gitlab-ci.yml` `require-approval-mr` (`ci:requireApproval`), `docs/merge-signoff.md` | Active. |
| Config | `GITLAB_WEBHOOK_SECRET`, `GITLAB_API_URL`, `GITLAB_PROJECT_TOKENS`, `GITLAB_API_TOKEN` | Developer `api` bot token per project. |
| AI review job | `.gitlab-ci.yml` | **None.** Kelpie's own pipeline does not run `ci:aiReview` (kitg does). |

Note `php bin/ubix code:review` is *not* the AI review: it is phpcs + phpstan + phpunit
(`Ubix\Service\MachineCodeReviewService`).

### The pipeline's AI review (uBixCore, on `origin/main`, not in the local checkout)

| What | Path |
| --- | --- |
| Command `bin/ubix ci:aiReview` | `php/Ubix/Console/Command/Ci/AiReviewCommand.php` |
| Service (Gemini call, threads, fingerprint) | `php/Ubix/Service/Ci/AiReviewService.php` |
| Generic instructions | `php/Ubix/Service/Ci/ai-review-instructions.md` |
| Standard | `docs/standards/ai-review-in-ci.md` (v2.1) |
| Host wiring example | kitg `.gitlab-ci.yml`, job `ai-review-mr`, guide `bin/ci/ai-review-guide.md` |

How it posts findings today:

- Reads the MR diff (against `CI_MERGE_REQUEST_DIFF_BASE_SHA`, `docs/` and lockfiles excluded) on
  stdin, asks Gemini for structured JSON: a verdict and findings
  `{severity: bug|security|risk|test-gap|nit-worth-it, file, line, title, detail}`.
- **Every finding becomes a resolvable MR discussion**, inline at `file:line` when that line is in
  the diff, otherwise a general thread. Plus one summary thread, edited in place, auto-resolved
  when the review is clean.
- The merge waits on "All threads must be resolved". The job is `allow_failure`; a failed run
  posts "NOT reviewed". Agents never resolve these threads.
- **Dedup within one MR:** hidden marker `<!-- ubix-ai-review-finding:<fp> -->`, with
  `fp = substr(sha1(strtolower(file . '|' . normalizedTitle)), 0, 12)`. A repeat finding is not
  re-posted, a human-resolved thread is not reopened.

What it does **not** have: any issue path; any notion of "introduced by this diff" versus
"already there" (the model sees only the diff, so it can flag unchanged context lines); any
severity-based routing (a `nit-worth-it` blocks exactly like a `security`). That last point is
the gap this design fills.

## 2. Issue intake

**Decision: webhook-driven, with a reconciling poll as a backstop.**

- **Webhook (primary).** The receiver exists and is verified. `Issue Hook` events (open, reopen,
  update, close) go to a new intake service replacing the log-only `IssueOpenWebhookService`.
  Latency is seconds and costs nothing.
- **Poll (backstop).** GitLab webhooks are fire-and-forget; a Kelpie restart or a disabled hook
  loses events. A scheduled `bin/ubix` command lists, per enrolled project,
  `GET /projects/:id/issues?updated_after=<cursor>&labels=...` and feeds the same service. A
  `glab api` shell-out is not used: the command uses the HTTP client, so it is testable and needs
  no binary in the image.
- **Which projects.** An explicit allow-list, not "every project the token sees". A project is
  enrolled when it has a token in `GITLAB_PROJECT_TOKENS` (existing mechanism) *and* a row in
  Kelpie's project table (enabled flag, default branch, fix policy). Unenrolled projects are
  ignored with a 200.
- **Which issues.** Kelpie only *tracks* every issue of an enrolled project (cheap, read-only),
  but only *acts* on those that pass the gate in section 3.
- **Idempotency.** Key `(project_id, issue_iid)`: one row, upserted. A delivery is applied only
  if its `updated_at` is newer than the stored one, so replays and out-of-order deliveries are
  harmless. Side effects (labelling, comments) are decided from stored state, never from "this
  event arrived", and each action records itself (`triage_run` row with the `updated_at` it
  acted on), so a replayed event produces no second comment.
- **Loop guard.** Kelpie's own label and comment edits come back as Issue Hooks. Ignore events
  whose author is the bot user, and compare label sets before acting.

### Storage (uBixCore patterns)

Kelpie gets its first database; `VAULT_DB_STRATEGY=none` is flipped and a migration directory
(`sql/migrations/`) is used. Following CLAUDE.md conventions:

| Piece | Name (proposed) |
| --- | --- |
| Models/DataTypes | `Kelpie\Model\Issue`, `Kelpie\DataType\IssueIid`, `ProjectId`, `Fingerprint` |
| Enums (no `Enum` suffix) | `Kelpie\Enum\IssueState`, `TriageSeverity`, `TriageDecision`, `FixOutcome` |
| Repositories | `IssueReaderInterface` + `IssueWriterInterface`, realised by `IssueSqlRepository`; same for `Project`, `TriageRun`, `FixAttempt`. Criteria are DTOs (`IssueCriteria`). |
| Services | `IssueIntakeService`, `IssueTriageService`, `IssueFixService` (business logic; controllers thin) |
| Payloads | `IssueWebhookPayload` (GitLab in), later `IssueListPayload`/`IssueDetailPayload` for a read API |
| Controller | `GitlabWebhookController` stays; a new read-only `IssueController` is a later phase |

Tables: `project`, `issue` (project_id, iid, title, state, labels, author, source, fingerprint,
severity, component, assignee, gitlab_updated_at, triage_state), `triage_run`, `fix_attempt`.
Issue **bodies are not stored** beyond what triage needs (review text can carry code); fetch on
demand.

## 3. Triage

Output of triage is a *decision record* on the issue row and, on GitLab, labels plus at most one
Kelpie note. Deterministic rules run first; the LLM only fills gaps and is never the sole basis
for an action beyond labelling.

| Concern | Deterministic first | LLM role |
| --- | --- | --- |
| **Source** | Intake-contract marker in the body => `source=ai-review`, structured fields parsed, no LLM. No marker => `source=human`. | none |
| **Severity** | From the contract's `severity` for review issues. Human issues: existing `severity::*` or `priority::*` label wins. | Suggest for unlabelled human issues (`low|medium|high|critical`), stored as *suggested*, labelled `triage::needs-human` when it says critical. |
| **Duplicates** | Same `fingerprint` (review issues) or same normalised title in the same project, open => link and label `duplicate-candidate`. Never auto-close. | For human issues with no exact match: ask for nearest open issues among a short candidate list (title match / same component), propose, human confirms. |
| **Component** | Path rules: files named in the contract mapped through a per-project `component` table (path prefix => name, e.g. `php/Kelpie/Service/Gitlab/` => `gitlab`). | For human issues with no file hints: pick from the project's *known* component list only; "unknown" is allowed. |
| **Labels** | Kelpie owns the `triage::*` scope (`new`, `triaged`, `needs-human`, `fix-eligible`, `fix-in-progress`, `fix-proposed`) and `severity::*`, `component::*`. It never touches others. | none |
| **Assignee** | Component owner from the project table; else leave unassigned. Never assign on LLM output alone. | none |
| **Needs a human** | Any of: critical/security severity; no component and no file hint; duplicate candidate; touches a path on the project's protected list (migrations, CI, auth, secrets, deploy manifests); author asked a question; issue is a feature request/design (label `type::feature` or no stack trace/file ref and LLM says "not a defect"). | Classify defect vs request. |

Rules are data (`TriageRule` objects evaluated in order), so a decision can state *which rule*
fired. LLM calls go through one `TriageAdvisor` interface; the provider is configuration. The
advisor never sees secrets and gets only title, body and component list. Free-tier providers
that train on input (as `ai-review-in-ci.md` §2.1 notes for Gemini) are not acceptable for
private issue bodies without the person's decision (open question 5).

## 4. Fixing

**Opt-in, gated, and bounded.** Kelpie never merges, never approves, never gives a 👍, never
resolves a thread. The existing `ApprovalRetryHttpClient` posture is the model: a purpose-built
HTTP client allow-list for the fixer (read issue/repo; create branch; create MR; comment),
asserted by tests. The sign-off job (`require-approval-mr`) and all-threads-resolved still stand
in front of every Kelpie MR.

**Eligibility** (all must hold, evaluated deterministically):

1. Project has `fix.enabled` and the issue carries `triage::fix-eligible`, applied by a *person*
   (phase 4), or, later and per project, by rule for `source=ai-review` with `severity` in
   `risk|test-gap|nit-worth-it` and a single file (phase 5; open question 3).
2. Not `triage::needs-human`; no touched path on the protected list; severity not
   `security`/critical.
3. No open `fix_attempt` for the issue, and attempts < 2 total.
4. Scope budget: at most N files and M changed lines (start: 3 files, 80 lines); exceeded =>
   abandon the attempt, comment why, label `triage::needs-human`.

**Mechanics.** A `fix_attempt` row, a branch `kelpie/fix-<iid>-<slug>` off the default branch, a
single MR targeting it, titled and described with a link back (`Closes #iid`), labelled
`kelpie`. The MR runs the normal pipeline; nothing is skipped. Failure of any gate => the
attempt is closed and the issue returns to `needs-human` with the failing output summarised.

**Hand-off: who does the editing.** Options:

| Option | For | Against |
| --- | --- | --- |
| A. Kelpie dispatches to a Shepherd lane (a lane per issue, scope derived from the component paths) | Reuses scope-enforced lanes, push hooks, MR flow and ask_human; Kelpie stays a small control plane | Couples Kelpie to Shepherd's interface (not verified here; needs a lane API) |
| B. Kelpie's own runner (container job running an agent with a checkout) | Self-contained | Rebuilds sandboxing, scope enforcement and credentials Shepherd has; much larger blast radius |

**Recommendation: A.** Kelpie decides *whether* and *with what scope*; Shepherd lanes do the
work. This needs an answer from Shepherd on how a lane is requested programmatically
(question 4); it should be asked before phase 4.

**Limits.** One attempt in flight per project; daily attempt cap; no dependency upgrades or
schema changes; no fix for issues whose repro needs production data; tests must be added or
changed with the fix (an MR with a code change and no test change is abandoned); every attempt
records tokens/time for cost review.

## 5. The review triage rule

Goal: the AI review stops blocking MRs on things that do not belong to them, without letting a
real defect slip past the gate. The rule is deterministic and runs **after** the model returns
findings and **before** any post. The model supplies facts; code decides routing.

### Inputs per finding

From the model (extended schema, section 6): `severity`, `file`, `line`, `title`, `detail`, plus
two new fields: `introduced` (does the defect need lines this MR added or changed) and
`fix_size` (`trivial|small|large`, an estimate). Computed locally from the diff, not trusted from
the model: `on_changed_line` (the finding's line is in a hunk's added/changed lines),
`in_changed_file`, `mr_scope` (below).

### Rule (first match wins)

| # | Condition | Route | Why |
| --- | --- | --- | --- |
| 1 | `severity` is `security` or `bug` **and** (`on_changed_line` or `introduced`) | **Thread, blocking** | This MR would ship the defect. |
| 2 | `severity` is `security` (any origin) | **Thread, blocking** | Never defer a security finding silently; a pre-existing one may be *also* filed, see below. |
| 3 | `on_changed_line` and `fix_size` in `trivial|small` | **Thread** | Cheaper to fix in this MR than to track. |
| 4 | `on_changed_line` and `severity` in `bug|risk|test-gap` | **Thread** | It is this MR's code and a reviewer should see it. |
| 5 | not `on_changed_line` (pre-existing or outside the diff) | **Issue** | Not this MR's doing; must not hold it hostage. |
| 6 | `on_changed_line`, `severity` = `nit-worth-it`, `fix_size` = `large` | **Issue** | Real but out of scope for this MR. |
| 7 | anything else | **Thread** | Default is the safe, existing behaviour. |

`mr_scope`: a finding whose file is *outside* the MR's changed files is always rule 5, even if
it is a bug: it is not this MR's to fix. The reverse guard, "the model said pre-existing but the
line is changed", resolves in favour of the diff (rules 1-4 key on the computed
`on_changed_line`, not the model's claim). Rule 2 also files an issue (a pre-existing security
finding is blocking *and* tracked), so it is not lost if the MR is closed.

**Thread findings** behave as today: resolvable discussion, inline when possible, block until a
person resolves with `Fixed:`/`Dismissed:`/`Deferred:`. `Deferred:` replies should link an issue
(Kelpie can check later; not enforced).

**Issue findings** are *not* threads. They do not block. The summary thread still lists them
("Filed #123 — not blocking") so the reviewer sees the routing and can overrule by replying;
overruling means converting the item to a thread (a human reply `Block:` on the summary, phase
2.1; out of the first cut).

### Idempotency across pipeline runs

- Issue fingerprint is the existing finding fingerprint (file + normalised title, line left
  out). Stored in the issue body as the hidden marker (section 6) so it survives title edits.
- Before filing, the job lists the project's issues with the label `ai-review` (any state) and
  looks for the marker. Open match => do nothing (optionally add one "seen again in !N" comment,
  at most once per MR). Closed-as-fixed match => file a *new* issue that links the old one
  (regression). Closed-as-won't-fix (`resolution::wontfix` label) => do nothing.
- Filing is `search, then create`; a double run races, so the marker search runs again after
  create and the later of two duplicates is closed by Kelpie's intake (`duplicate-candidate`
  handling). Rare and recoverable.
- Where the finding is already a thread from an earlier push, routing is sticky: a finding once
  threaded in this MR is never re-routed to an issue, and vice versa, within the same MR.

### Where it runs

The rule is a small pure function over (findings, diff). Because it needs the diff and runs in
the MR job, it lives in **uBixCore's `AiReviewService`** (generic: every host benefits), as a
`FindingRouter`, with the issue-filing side effect behind an interface. Kelpie's role is the
*consumer*: it is not in the review job. Alternative (Kelpie routes after the fact via a
webhook) is rejected: Kelpie would need the diff and would duplicate the review's posting
logic.

## 6. Where the two parts meet: the issue contract

A review-created issue is plain GitLab markdown with a machine-readable header. Kelpie intake
parses *only* this contract for `source=ai-review`; a body without the marker is a human issue.

```markdown
<!-- ubix-ai-review-issue:v1 fp=3f9a1c07b2de -->
<!-- ubix-ai-review-meta: {"severity":"risk","file":"php/Kelpie/Service/X.php","line":42,"fix_size":"small","origin_mr":17,"origin_sha":"79db7d2","model":"gemini-flash-latest"} -->

## <title from the finding>

**Severity:** risk · **Found in:** !17 (not introduced by that MR) · **Where:**
[`php/Kelpie/Service/X.php:42`](https://gitlab.example/ubixsys/ubixkelpie/-/blob/79db7d2/php/Kelpie/Service/X.php#L42)

<detail from the finding>

---
Filed automatically by the AI review of !17. Reproduce, fix, or close with `resolution::wontfix`.
```

Rules:

- **Marker and meta first**, versioned (`v1`). Parsers ignore unknown JSON keys; a new required
  field bumps the version. Fingerprint in the marker is the single dedup key.
- **Permalink uses the commit SHA**, not a branch, so the line stays correct. Intake stores
  `file`, `line`, `origin_sha`.
- **Labels set at creation:** `ai-review` (the pickup label), `severity::<s>`, `triage::new`.
  No `triage::fix-eligible`: fixing is a separate decision.
- **Title:** `[review] <finding title>` (fixed prefix, searchable, stable for duplicate matching).
- **Link back:** the MR reference (`!17`) and, optionally, a related-link via the issue links API.
  The MR gets a note via the summary thread (section 5).
- **Author:** the review bot token's user, not a person, so intake can tell origin from author.
- **No secrets or large code in the body**: at most the finding's `detail` and a short snippet
  (<= 20 lines).

Kelpie intake treats this as a contract with tests on both sides: a fixture body rendered by
the uBixCore router is parsed by Kelpie's `ReviewIssueParser` in Kelpie's tests, and the format
version is checked.

## 7. Boundaries

Per CLAUDE.md "The uBixCore boundary": if explaining the code needs a Kelpie noun (issue fixer,
triage, fix attempt) it is in `Kelpie\`; generic plumbing goes upstream and the pin is bumped,
with no local shim.

| Goes upstream (uBixCore) | Stays in `Kelpie\` |
| --- | --- |
| A GitLab forge client: issues list/get/create/update, labels, notes, discussions, MR create, with an injectable HTTP client and an allow-list wrapper | Which projects are enrolled; which requests the fixer's allow-list permits |
| Webhook verification (`X-Gitlab-Token`, event dispatch, replay-safe parsing); today hand-rolled in `GitlabWebhookController` | The handlers' meaning: intake, triage, fix triggers |
| Finding fingerprint (exists), `FindingRouter` and the review-to-issue filing, issue-body *renderer* for the contract | The contract **parser**, triage rules, severity policy, component map, protected paths |
| A generic LLM client interface, retry/backoff (exists for Gemini) | `TriageAdvisor` prompts and what it may decide |
| Diff hunk/line-set utility | Fix budgets, eligibility, attempt accounting, Shepherd hand-off |

The contract's *renderer* upstream and its *parser* in Kelpie is deliberate: the review is
generic and must not know Kelpie exists; "ai-review issue" is a generic notion, "triage" is not.
The contract lives in the upstream standard (`ai-review-in-ci.md` gets a new section) and is
referenced from here.

## 8. Phases, each one MR

| # | Phase | Repo | Content | Gate |
| --- | --- | --- | --- | --- |
| 0 | This design | kelpie | `docs/design/issue-triage.md`, `docs/DECISIONS.md` | Person answers open questions |
| 1 | Review routing | uBixCore | `FindingRouter` + extended schema (`introduced`, `fix_size`), hunk utility, unit tests with fixtures; routes still all to threads behind `AI_REVIEW_ROUTE=off` | Pin bump later |
| 2 | Issue filing | uBixCore | Issue renderer, marker search, create; summary lists "Filed #N"; `AI_REVIEW_ROUTE=issues` | Try on kitg first |
| 3 | Kelpie enables review + DB | kelpie | `ai-review-mr` job in `.gitlab-ci.yml`; flip `VAULT_DB_STRATEGY`; first migration; project/issue tables, repositories | Needs DB provisioned (kubernetes repo) |
| 4 | Intake + triage | kelpie | Replace `IssueOpenWebhookService`; contract parser; upsert; deterministic triage; labels and one note; poll backstop; (no LLM yet) | Read-only first, labelling behind a flag |
| 5 | LLM advisor | kelpie | `TriageAdvisor` for severity/duplicates/component on human issues | Provider decision (Q5) |
| 6 | Fix hand-off | kelpie (+ Shepherd answer) | Eligibility, `fix_attempt`, dispatch to a Shepherd lane, MR linking, budgets, tests for the HTTP allow-list | Only person-applied `fix-eligible` |
| 7 | Rule-based eligibility, read API | kelpie | Per-project auto-eligibility for small review issues; `IssueController` | After 6 has run for a while |

**Recommended order:** 1 and 2 first (they are useful alone: the review stops blocking MRs on
pre-existing findings, in kitg and Kelpie both), then 3-4, then 6, with 5 after 4 only if human
issue volume warrants it. Do not start fixing before triage has labelled real issues for a few
weeks, so eligibility is tuned on data.

### Open questions for the person

1. **Rule 2 strictness.** Should a pre-existing `security` finding block the MR (as drafted) or
   only be filed? Blocking is safer; it can strand an unrelated MR.
2. **Who owns the review routing code?** Recommendation: uBixCore (section 5). The alternative,
   a Kelpie-side post-processor, means Kelpie needs the diff.
3. **Auto-eligibility.** May review-origin low-severity issues become `fix-eligible` without a
   person (phase 7), or must a person always label? Recommendation: person always, until phase 6
   has results.
4. **Hand-off to Shepherd.** Is there (or may there be) an API for Kelpie to request a lane
   with a scope and a task? Not verified in this design. Without it, option B (own runner) is
   far bigger.
5. **LLM provider for triage.** Gemini free tier trains on inputs (`ai-review-in-ci.md` §2.1);
   fine for MR diffs per the 2026-09-30 acceptance, but issue bodies may carry more. Which
   provider and tier for private issue text?
6. **Enrolled projects.** Which projects first? Recommendation: Kelpie itself, then kitg.
7. **Fix budget.** Are 3 files / 80 lines / 2 attempts / one in flight per project the right
   start?
8. **Bot identity.** Separate bot users for review (files issues), triage (labels) and fixer
   (MRs), or one? Recommendation: separate, so the audit trail says which did what.
9. **Database.** Kelpie has none today; the kubernetes repo must provision one per environment
   before phase 3. Is a new MySQL/MariaDB instance acceptable, and who creates it?
10. **GitLab tier.** Group webhooks are paid (`docs/merge-signoff.md`); confirm, since intake
    wants a hook on every enrolled project.

### Not verified here

- Kelpie `ApprovalWebhookService` behaviour on `Issue Hook` is unaffected (it ignores them).
- That `AiReviewService` on `origin/main` is the version kitg runs; read from git objects, the
  local ubixcore checkout is a feature branch.
- Shepherd's lane interface (question 4).
