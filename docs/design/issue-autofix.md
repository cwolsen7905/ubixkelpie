# Issue autofix, first step

**Status:** Plan (design only, no code). **Date:** 2026-10-17.

Goal: someone reports an issue on `ubixsys/ubixshepherd` in GitLab. Kelpie is notified, triages
it, and if it can fix it, opens a fix branch and a merge request and asks the maintainer
(`cwolsen`) to review. Kelpie never merges and never approves.

## 1. What exists

The MR-thread autofix is on `dev`; this reuses it rather than starting over.

| Piece | Where | Use here |
| --- | --- | --- |
| Webhook receiver, secret checked | `Controller/Gitlab/GitlabWebhookController` | Same endpoint |
| Issue Hook handler | `Service/Gitlab/IssueOpenWebhookService` | Logs only today; becomes the intake |
| Bot HTTP client (GET, POST, PUT; no DELETE) | `HttpClient/BotHttpClient` | Comments, labels, branch, MR |
| Triage via OpenRouter | `Triage/TriageService`, `TriagePrompt` | New prompt for issues |
| Safety limits in code | `Triage/SafetyPolicy` | Same limits, paths extended |
| Throwaway checkout, push without force | `Sandbox/Checkout` | Clone, branch, push |
| Fixer: file-edit tools only, no GitLab token | `Autofix/ClaudeCliFixerAgent` | Same agent, issue prompt |
| Gate runner | `Autofix/GateRunner` | Runs the target repo's gate |
| Job state, caps, coalescing | `autofix_jobs` table, `Service/Autofix/*` | One more source |

## 2. Flow

1. GitLab sends an Issue event. The handler checks the secret and classifies the event (section 6):
   `triage` for a new issue on an enrolled project not authored by the bot (loop guard), `gate`
   for the gate label being added, `ignored` for everything else.
2. For `triage`, enqueue one job per `(project, issue iid)`. The webhook does no AI work inline.
3. Triage (cheap model) runs automatically and returns one class: `fix`, `needs_human` (unclear,
   design call, too big, protected path) or `not_a_defect`. Kelpie posts one triage comment with
   the reason and sets a `kelpie::*` label. Triage never changes code. Only `fix` can continue,
   and only once a person applies the gate label (section 6): the human is the gate.
4. Branch `kelpie/fix-<iid>-<slug>` from `dev`, fixer edits files, `SafetyPolicy` checks the
   result (size, paths), then the project's gate runs (`make check` for ubixshepherd).
5. Gate green: plain push, MR to `dev`, description with `Closes #N`, reviewer `cwolsen`, label
   `kelpie`. Comment on the issue with the MR link.
6. Gate red or policy violation: one attempt only. Comment with the failing output tail, label
   `kelpie::needs-human`, leave the issue to a person. No branch is left pushed.

## 3. Where the fixing agent runs

**Recommendation: in the Kelpie pod, in the existing sandbox.** Throwaway checkout, edit-only
tools, an exact environment holding only the OpenRouter key; the GitLab token reaches git only
through a one-call askpass script.

| Option | Security | Secrets | Cost |
| --- | --- | --- | --- |
| **Kelpie pod (recommended)** | Built and tested; agent has no shell, no GitLab token | Stay in uBixVault, one place | One spend cap in one place |
| GitLab CI job triggered by Kelpie | The job runs repo-controlled code (the gate) next to the model key and a write token | Model key and token become CI variables on ubixshepherd | Cap split between Kelpie and CI minutes |
| Separate runner service | Strongest isolation | New service, new secrets | New thing to build and run |

The catch: the gate runs the repo's own code (`make check`: Go, and Node for `web/`), so the pod
image needs those toolchains, and the gate must run with no secrets in its environment (as
`GateRunner` already does). If the image cost is too high, the fallback is to push the branch
(the push triggers the repo's own pipeline, which is the gate), open the MR as soon as the pipeline
is green, and skip the local gate. That is slower and leaves a pushed branch on failure; it is
the second choice, not the first.

## 4. Bot identity and tokens

Reuse `kelpie-bot` (Developer on the group, `api` scope, `KELPIE_BOT_GITLAB_TOKEN`): it cannot
push to protected branches or approve, so GitLab's rules bind it. Commits carry the
`Kelpie-Autofix` trailer and no AI attribution. The webhook secret is `GITLAB_WEBHOOK_SECRET`.
Both live in the `kelpie/<env>/app` uBixVault path the deploy manifests already name; the
OpenRouter key too.

Open questions (not guessed here):

- Is `kelpie-bot` already a member of `ubixsys/ubixshepherd` with Developer, and can it open MRs
  and set reviewers there?
- Is the token the same in all tiers (it is today), and does prod want its own before this goes live?
- Which model for triage and for the fix (defaults: Haiku 5.5 and Sonnet 5.5 via OpenRouter)?

## 5. What ubixshepherd needs

- A **project webhook** (group webhooks are paid-tier, so one per project): URL
  `https://kelpie.prod.ubixsys.com/gitlab/webhook`, secret token = Kelpie's
  `GITLAB_WEBHOOK_SECRET` from uBixVault, trigger **Issues events** only, SSL verification on.
  GitLab webhooks point at prod only. Other projects follow with the same steps.
- **Labels:** `kelpie::fix` (the gate, working name), `kelpie:skip` (opt out), plus Kelpie's own
  `kelpie::triaged`, `kelpie::fix-proposed`, `kelpie::needs-human`.
- `kelpie-bot` as Developer; `dev` protected (MR only), which it already is.
- **CI variables:** none. The gate runs in Kelpie; the repo's own pipeline runs on the MR as for
  anyone.

## 6. Automatic triage, human gate

Triage is automatic: Kelpie evaluates every new issue on an enrolled project and posts on it,
except issues authored by the bot. Fixing is not. It waits for the gate label (working name
`kelpie::fix`), applied by a person on the project.

- **Who counts:** Kelpie does not trust the event or the label alone. For a gate-label add it reads
  the acting user's access level on the project through the GitLab API (inherited access
  included) and proceeds only for Developer or higher. A lower level, a non-member, a bot, or an
  API failure is ignored (fail closed), and the actor and level are logged.
- **Intake classes**, logged by the Issue Hook handler: `triage`, `gate` (actor and verified
  level recorded) or `ignored` (with the reason). A replayed event gets the same class; the
  job keys (section 8) make a replay add nothing.
- `kelpie:skip` stops Kelpie at any time, including after a triage comment. Removing the gate
  label cancels a pending job.
- Labels, not issue text, are the control. Issue text is untrusted: intake reads only the project,
  issue number and acting user, and never logs the title or description.
- The whole path sits behind `KELPIE_AUTOFIX_ENABLED`; with it off every event is ignored.

## 7. Guard rails

All enforced in code (`SafetyPolicy`), never by the prompt.

- At most 5 files and 80 changed lines; no file deleted or renamed; tests added or changed with
  the fix.
- **One attempt**, then hand off to a person. A re-run needs a person to remove and re-add
  the gate label.
- **Spend cap:** a per-issue token budget and a daily cap with a hard stop (figures: decision 3).
- Plain push of a new branch only; no force push, no `--no-verify`, never to `dev`, `staging`
  or `main`.
- Never touches: `.gitlab-ci.yml`, `ci/`, `.env*`, secrets, keys, lock files, migrations,
  deploy manifests, tool configuration. Extended for ubixshepherd: `go.sum`,
  `package-lock.json`, `Makefile`, `Dockerfile*`, linter configs. The list is per project.
- Never merges, approves, gives a thumbs-up or resolves a thread.
- The issue text is untrusted: fenced as data, and the agent has no shell, no network and no token.
- Kelpie ignores events from its own account and label sets it wrote.

## 8. Database

No new table. `autofix_jobs` keys on `(project_path, mr_iid, thread_key)`; an issue job needs
`source = 'issue'` and an issue iid. Recommend adding one nullable column `issue_iid` and
reusing `mr_iid` as 0 until the MR exists, with a unique key on the open job per issue; the
caps and coalescing then work unchanged. A migration touches `sql/`, so a person writes it, not
the fixer.

## 9. Steps, each one MR

| # | MR | Content | Test |
| --- | --- | --- | --- |
| 1 | Intake | `IssueOpenWebhookService` classifies each Issue event as `triage` (enrolled, not the bot), `gate` (gate label added; actor's Developer-or-higher access verified through the API) or `ignored`, logs only, behind `KELPIE_AUTOFIX_ENABLED` | Unit tests; send a test event from the hook's Test button, see the class in the log |
| 2 | Triage and comment | Issue prompt, verdict, one comment and labels on every new issue; no fixing. Needs `issue_iid` migration | Throwaway issue: comment and label appear once; replay adds nothing |
| 3 | Fix and MR | Starts on the gate label from a Developer or higher. Branch, fixer, policy, gate, push, MR with `Closes #N`, reviewer, one-attempt hand-off, spend cap | Throwaway issue "fix a typo in a doc comment": MR opens, gate green, reviewer set, nothing merged |
| 4 | Failure paths | Too big, protected path, red gate: comment and label, no branch | Throwaway issues for each; check no branch is left |
| 5 | Widen | Per-project enrolment list; next project | Repeat on one more project |

End-to-end test, from step 2 on, on a throwaway issue in `ubixshepherd`: create the issue,
watch the triage comment, add the gate label as a Developer, watch the Kelpie logs and the issue; close the MR and issue afterwards. Do not
run the first live test with real work on the board.

## 10. Decisions for the person

| # | Question | Recommendation |
| --- | --- | --- |
| 1 | Where the agent runs | Kelpie pod (section 3) |
| 2 | Gate in the pod (needs Go and Node in the image) or in the repo's CI | In the pod; CI fallback only if the image is too heavy |
| 3 | Spend cap figures | Per issue: the thread-autofix budget; daily: low at first, raised on data |
| 4 | Gate | Triage is automatic; fixing waits for the gate label (`kelpie::fix`) from a user verified as Developer or higher |
| 5 | Triage comment on every new issue | Yes, except bot-authored issues; no code is changed without the gate |
| 6 | Bot | Reuse `kelpie-bot` for now; split later if audit needs it |
| 7 | Reviewer | `cwolsen` set as reviewer, not assignee; configurable per project |
| 8 | Limits | 5 files, 80 lines, one attempt |
| 9 | Webhook | Per-project hook to prod now; revisit if GitLab tier changes |
| 10 | Webhook secret | Same as today across tiers; give prod its own before enrolling more projects |
