# Live check of Kelpie, 2026-11-18

Read-only. Looked at: the three namespaces (`kubectl get/logs/exec`, no changes), `SELECT`s on the
`ubixkelpie` DB through the read-only DB user, GitLab `GET`s. Nothing deployed, restarted or written.
Times are UTC unless noted.

## 1. Who sends webhooks, who is enrolled

| Project | Webhook (GitLab hook id) | Events | `projects` row (dev and prod identical) |
| --- | --- | --- | --- |
| `ubixsys/ubixshepherd` | **kelpie.dev**.ubixsys.com (5) | MR, issues, note, pipeline, job, emoji | mode `propose` |
| `ubixsys/ubixkelpie` | **kelpie.prod**.ubixsys.com (8) | MR, issues, note, emoji | mode `propose` |

- `propose` is the middle mode: triage comments and labels, and the fixer offers a diff but never pushes.
  No project is on `push`, so **no repo is enrolled for auto-fix pushes**; both are enrolled for triage and proposals.
- Neither row overrides `triage_model` or `review_model` (both NULL).
- Only these two projects' hooks and rows were checked. I did not sweep groups for other hooks (GitLab rate-limited me).
- Shepherd's triage therefore runs on **kelpie-dev**, not prod.

## 2. Recent comments and fixes

- ubixshepherd (dev): 46 `review` jobs and 28 `note` jobs, all `done` over five days; the last at 14:41 local.
  Kelpie's own triage comments on its MRs: 10 on 11-17 and 26 on 11-18, plus 18 "done with this thread" releases.
  **Every one of the 36 says "Triage unavailable: the model gave no answer."**
- Dev `autofix_jobs.triage_class`: 22 rows since 11-05 (the table's first row), **all `needs_human`**. Not one thread has ever been classified. No fix was proposed or pushed.
- ubixkelpie (prod): last 5 days, 20 `note` jobs, 16 `failed` with "the worker died on the last attempt", 4 `superseded`; last at 11-17 21:28 (~16:28 local). No triage class recorded there, ever.
- Both workers restart about hourly: prod worker 251 restarts, dev worker 206, in 8 days.

## 3. Why "the model gave no answer"

- That text is `TriageService` line 76: `OpenRouterClient::chat()` returned null. Four paths log an `OpenRouter:` error and return null: key not set, non-200 status, request failed, no message content.
- **I could not read any `OpenRouter:` line.** The worker (which runs triage) logs through the CLI logger, whose file is `/UbixCli.log` at the filesystem root; the `www` user cannot open it, so nothing reaches stdout, a file or `kubectl logs`. The container's last exit is exactly that error ("could not be opened in append mode ... Permission denied"; seen on prod, confirmed in the prod previous-container log only). The api pods do log to stderr and have **no** `OpenRouter` lines in 48h on dev or prod, but the api does not run these jobs.
- Ruled out: **key not set**. `OPENROUTER_API_KEY` is set (value not read) in dev and prod; `OPENROUTER_API_URL` is unset, so the default `https://openrouter.ai/api/v1` is used.
- **`KELPIE_TRIAGE_MODEL` is not set** in dev or prod, in the container environment or after the uBixVault hydration. With no per-project override either, the code default applies: **`google/gemini-3-flash`**. (`.env.example` suggests `anthropic/claude-haiku-5.5`; `docs/operations/worker.md` lists the gemini default.)
- Not ruled out from here: a non-200 (bad model id, 4xx from the provider) or a transport failure. Both log too and would produce the same message.

## Likely cause and smallest fix (recommendation only)

**Likely cause (unverified, no log line):** "no message content". The request asks `google/gemini-3-flash` for `response_format: json_object` with `max_tokens: 400`. A Gemini 3 model spends its reasoning tokens inside that cap, so the call can end with an empty `content`, which the client logs as "response had no message content" and the service reports as "no answer". It fits the facts: a 100% failure rate from the first row, on every thread, with the key set.

**Smallest fix:**
1. Set `KELPIE_TRIAGE_MODEL` in uBixVault `kelpie/dev/app` (and prod later) to a non-reasoning model such as `anthropic/claude-haiku-5.5`, with no code change; check it is not in the review's family (`sameAsReview` would otherwise refuse), or
2. Raise the default `max_tokens` (400) to leave room for reasoning, e.g. 2000, a one-line change in `OpenRouterClient::chat()`'s callers.
3. To confirm first, make the worker's logger writable (point the CLI log at stderr or a writable dir). It is a separate bug that hid this very error and also crashes the worker's exit.
