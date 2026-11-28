# Changelog

## Unreleased

- **Triage reads the replies in a thread, not only the finding.** A person's reply re-opens a thread for triage, but
  the model was given only the thread's first note, so a reply saying the finding was out of date never reached it:
  on an MR whose base moved past a rename, Kelpie twice proposed reverting to the old names after the author had
  replied why they were wrong. The newest five replies by people now follow the finding, each fenced and passed
  through the context scan (Kelpie's own notes are left out, each reply is cut to 2000 characters, and older ones are
  counted as "not shown"). A reply saying the finding is out of date, handled or mistaken makes the verdict
  `needs_human`, never `fix`: the model cannot check the claim against the code, and a reply is untrusted, so a person
  decides rather than any commenter. A thread with replies cut off is `needs_human` too.

- **The unattended command list is now enforced.** Every command Kelpie's worker, sweep and review start goes through
  `GuardedProcessRunner`: a command that is not one of the listed shapes (git read/checkout/commit/plain push, the
  PHP gate, exactly `make check`, the `claude` CLI with file tools only, clean-up under the temp directory) is not
  started and comes back as a failure with the reason. The list was written and tested before but never bound, and it
  would have refused Kelpie's own checkout: it now allows `core.fsmonitor=false`, `core.hooksPath=/dev/null`, the
  checkout's `--separate-git-dir` under the temp directory, and `make check`, with tests that bending any of them is
  refused. A project `gate_command` of another shape is now refused instead of run. See `docs/security/context-scan.md`.
- **Forge text is scanned before it reaches a model.** Thread notes, `Triage:` steering, the fixer's context, and issue
  titles and descriptions go through `ContextScanner::quote()`: hidden HTML comments are defused, invisible and
  bidirectional characters removed, and text that tries to steer the model is flagged in the prompt as data. Repo
  guides the review reads go through `guide()`: one that tries to steer the model is skipped; one with only a stray
  comment is kept, defused.
- **The review never reads credential files.** Its `read`, `grep` and `glob` tools skip `.env` (not `.env.example`),
  key and certificate files, `.npmrc`, `.netrc`, `auth.json` and credential directories, so their content is never
  sent to a model.

- **An owner's approval resolves the open threads again.** Since it shipped, every approval by a
  `KELPIE_APPROVAL_OWNERS` owner retried the sign-off job but answered "could not resolve open threads (cURL error
  3: URL rejected: No host present)", so merge-when-pipeline-succeeds kept waiting on "All threads must be
  resolved". The API container never bound `AutofixSettings`, so PHP-DI built it with its defaults: no GitLab URL,
  no bot token. It is now read from the environment as in the CLI container, and a test builds the API container
  and checks it.

- **Kelpie fixes only what it is sure of, and asks when it cannot tell.** Triage now gives a confidence (`high`,
  `medium`, `low`; missing reads as `low`), and a `fix` below `medium` becomes `needs_human` with the model's reason
  and the confidence named, so nothing is proposed or pushed on a guess. A discussion or question is `needs_human`,
  never `fix`. A new `needs_context` verdict replies asking for what is missing (on an issue, a comment and the
  `kelpie::needs-context` label) and triages again once someone answers. See `docs/triage-confidence.md`.

- **An open-source repo is now reviewed with a cheaper model.** With `AI_REVIEW_MODEL_PUBLIC` set, `kelpie-review`
  reads the license GitLab detected in the repo and, for an open-source one (BSD, MIT, Apache, GPL, ...), reviews
  with that model instead of `AI_REVIEW_MODEL`: `meta/muse-spark-1.3-contributor` costs $0.10/$0.20 per million
  tokens against Haiku's $0.10/$0.50. A repo with no license, or one the job cannot read, stays on
  `AI_REVIEW_MODEL`. When the open-source model gives no valid review (a training tier the OpenRouter account's
  data policy excludes answers 404), the review runs again on `AI_REVIEW_MODEL` instead of going unreviewed, and
  the summary names the model that wrote it. Since either model may have written an open-source project's review,
  triage and edit proposals refuse both families there, and also on a project whose license the worker could not
  read (fail closed); a closed-source project refuses only the regular family. Triage defaults change to match: `deepseek/deepseek-v4.1-flash` (was
  `google/gemini-3.8-flash`, eight times the price) and, for open-source projects, `upstage/solar-pro-4-contributor`
  (was Muse Spark, which the guard would now refuse there).

- **The AI review now labels the merge request with where it is and the worst finding still open.**
  `kelpie-review::` is one of `reviewing`, `reviewed`, `clean`, `last-round`, `hard-cap`, `skipped` or `unreviewed`;
  `kelpie-findings::` is one of `blocker`, `major`, `minor-only` or `none`. On ubixvault !74 the review used its third
  and last round with five threads open, and nothing on the MR said so: the summary read "round 3 of 3" and the
  round-limit note only appears on a fourth push. The last round now says so in the summary and in the
  `last-round` label. The labels are created in the project when first needed. See "Labels" in `docs/ai-review.md`.

- **A settings API, so a web page can read and edit each project's settings.** `GET /api/v1/projects`,
  `GET /api/v1/projects/{path}` and `PATCH /api/v1/projects/{path}` on `KelpieApi` show and change a project's mode,
  soft and hard caps and models, validated whole; a hard cap of `null` is unlimited. It takes its own bearer token,
  `KELPIE_SETTINGS_API_TOKEN` (not the webhook secret) and answers 503 until that is set. See `docs/settings-api.md`.

- **Kelpie's autofix commits and notes now say they are Kelpie's, and which agent and model wrote them.** A commit
  gets an `Assisted-by: Kelpie <agent> (<model id>)` trailer beside the `Kelpie-Autofix` marker (the author stays the
  bot account). The usage footer of autofix replies and of the AI review summary opens with
  `Kelpie · agent `<agent>` · model `<model>``: the existing footer, not a second one. The model is the one the run
  used; a push round names the agent `autofix` because the stored proposal keeps the model but not which of the two
  made it. See `docs/autofix/push-mode.md` and `docs/ai-review.md`.

- **Kelpie can now fix review threads whose fix is in another file, or is a test.** The propose model sees only the
  thread's file, so on ubixshepherd !450 it gave up on "add a failing-gate test" and on "the brief in
  `internal/dispatch/adapters.go` was not updated". When the propose model has no usable edit, the fixing agent (the
  `claude` CLI, file tools only, on the propose model and under the same family guard) now works in the same scratch
  checkout with the whole repository to read. Its change is measured from git, checked by the safety policy, posted
  as the proposal's diff and stored as exact-text edits (new files included), and its cost counts toward the spend
  cap. `KELPIE_FIXER_AGENT=off` switches it off. A push round may now also edit test files and files the thread's
  finding names by path, not only files the MR changes. The round's gate is the project's own (`gate_command`, else
  `make check`, else the PHP gate) instead of always the PHP gate; `gate_command = 'ci'` leaves the gate to the MR
  pipeline and the `Fixed:` reply says so (for ubixshepherd, whose Go toolchain is not in Kelpie's image). See
  `docs/autofix/push-mode.md`.

- **Every cap now has a soft and a hard limit.** Today's values are the soft limits: passing one warns (a single MR
  note with the label `kelpie::over-soft-cap`, or a log line for project-wide caps) and the work keeps going. A hard
  limit stops the work, is settable, and is unlimited by default: `KELPIE_AUTOFIX_*_HARD` for the four autofix caps,
  `KELPIE_MAX_COST_USD_DAY_GLOBAL_HARD` for global spend, and new nullable `*_hard` columns on `projects` for daily
  spend and the two issue counts (migration `20261122120000_projects_hard_caps`; the issue-count caps are not enforced
  yet). **Behaviour change:** with no hard limit set, a cap no longer stops anything; set a hard limit where you want
  the old stop. **uBixVault:** no change is needed on deploy: the keys already set
  (`KELPIE_AUTOFIX_*`, `KELPIE_MAX_COST_USD_DAY_GLOBAL`) are now the soft limits, so alerts start at today's values, and every
  hard limit is unlimited until you set it. Each soft key also has a `_SOFT` name (it wins when both are set); prefer that
  for new settings. See `docs/autofix/caps.md` and `docs/design/spend-caps.md`.

- **"Triage: retry" no longer stays silent on a thread Kelpie has not looked at.** From the MR author or a maintainer
  it queues the thread for triage; when it cannot be queued (the project is not enrolled, or a cap was hit) Kelpie
  replies in the thread once, with the reason (which cap was hit, or that the project is not enrolled), using its own bot-authored marker (separate from the over-cap note's) so it never
  posts twice. See `docs/autofix/triage-retry.md`.

- **Over a daily job cap, Kelpie now says so on the MR, and the caps are configurable.** The per-project daily cap is
  replaced by a per-MR cap (default 30 jobs per MR per 24 h, `KELPIE_AUTOFIX_JOBS_PER_MR_PER_DAY`) and a project-wide
  ceiling as a spend guard (default 200 per project per 24 h, `KELPIE_AUTOFIX_JOBS_PER_PROJECT_PER_DAY`), so one busy MR
  no longer uses up the day for the others. A job refused for either leaves one note on the MR naming the cap that was hit
  (the count, and about when it frees up) plus the label `kelpie::over-cap`, at most once per MR per 24 hours, instead of
  being dropped silently. `KELPIE_AUTOFIX_COMMITS_PER_MR` and `KELPIE_AUTOFIX_ATTEMPTS_PER_THREAD` are settings too; a
  missing or invalid value gives the default and a logged warning. See `docs/autofix/caps.md`. The notice runs in the webhook
  request, so its GitLab calls share one 4 s budget (2 s each at most): a slow GitLab skips the label, then the note,
  rather than holding the delivery past GitLab's webhook timeout.

- **CI: uBixCore's Gemini review (`ai-review-mr`) is switched off.** `kelpie-review` is the only review on Kelpie's
  MRs. The Gemini job ran on the free tier, failed now and then, and doubled the findings to answer. The
  template has no setting for it, so `.gitlab-ci.yml` overrides its rules with `when: never`.

- **Fixed: Kelpie left dismissed and fixed threads open on ubixshepherd.** Three causes, seen on ubixshepherd !429:
  - On open-source projects triage asked `meta/muse-spark-1.3-contributor`, which OpenRouter answered with 404 while the
    workspace was restricted to BYOK providers (since lifted), so every thread became `needs_human` ("the model gave no answer"). When the open-source model gives no
    answer, triage (threads and issues) now asks the regular `KELPIE_TRIAGE_MODEL` once. A project's own
    `triage_model` gets no fallback. OpenRouter's error body is now logged (up to 300 characters), so a 404 says why.
  - A reasoned "Dismissed:" stopped counting once Kelpie's own notes (its release marker, its triage reply) followed
    it. The bot's later notes no longer take the last word from a dismissal.
  - On a thread the AI review opened, only the review bot itself could settle it, and it never replies. The MR's
    author may now settle those threads with a reasoned dismissal or a "fixed in <sha>" the MR carries. A person's
    thread still needs its opener, and the review is recognised by a GitLab bot username plus the `ai-review:` marker,
    so a person typing the marker does not count.

- **Fixed: triage always answered "the model gave no answer".** The default `KELPIE_TRIAGE_MODEL`,
  `google/gemini-3-flash`, is not an OpenRouter model id, so every triage call failed and every thread became
  `needs_human`. The default is now `google/gemini-3.8-flash` (listed by OpenRouter's public
  `GET /api/v1/models`): not the review's family, and not a `-contributor` tier, the suffix `TriageLlmService`
  treats as training on prompts. A thread triage request now allows 2000 tokens instead of the client's 400, since
  a Gemini 3.x Flash model spends its reasoning inside that cap and could otherwise return empty content.

- Kelpie's triage summary ("Kelpie triage looked at N thread(s): ...") is now a plain MR note, not a resolvable discussion
  (item 316), so one left open no longer blocks auto-merge. It is still edited in place when the run ends. Replies in a
  thread stay replies. A new summary is no longer a thread the settle step (item 290) can see, so its "summary settled" rule
  applies only to summaries posted earlier as discussions.
- MR status labels are now the scoped `kelpie::` set (item 311). While a run works on an MR it carries `kelpie::working`;
  when it ends, exactly one of `kelpie::pushed` (it pushed a fix), `kelpie::proposed` (it proposed a change) or
  `kelpie::needs-you` (a person must act) replaces it. `kelpie::needs-you` replaces the MR label `kelpie::needs-human`, and
  setting any of them takes off the others and the old `triage: working`, `triage: pushed`, `triage: proposed`,
  `triage: needs you` and `kelpie::needs-human`. The old `triage: working` still counts as a claim. The sweep (`kelpie:sweep`,
  and the worker after a job) clears a `kelpie::working` label left by a run that died: no pending job and no running job
  with a live lease for the MR; with autofix off it only reports it. Issues keep `kelpie::needs-human`.

- Kelpie resolves MR threads once they are settled, so nobody clears them by hand (item 290, and the summary notes of 287).
  A thread with a reply is resolved when it was fixed and pushed (a `Fixed:` reply from Kelpie or "fixed in <sha>" naming a
  commit the MR carries), or when its last reply says "dismissed" and gives a reason; Kelpie's own triage summary is resolved
  once the threads it covers are. A thread with a newer note from a person, or whose deciding note is a question, is left
  open. Each resolve is logged with its reason (`fixed_pushed`, `dismissed`, `summary_settled`). It runs on every worker job
  and in `kelpie:sweep`, and only reports ("would resolve") while `KELPIE_AUTOFIX_ENABLED` is off. Summary notes now list the
  threads they cover in their marker. See `docs/autofix/settled-threads.md`.

- Triage comments and auto-fix thread replies now end with the model, effort, tokens and cost (OpenRouter), like review summaries; cost is left out when OpenRouter does not report it.

- Issue autofix failure paths (step 4): a fix that is too big, touches a protected
  path or fails the gate gets one hand-off comment with the reason and
  `kelpie::needs-human`, records the outcome as failed and pushes no branch; a run
  after a failure settles at once with no second comment (the one-attempt rule).
  Tests only; the fixer is unchanged.

- `kelpie review` says what the model sent when its reply is not a valid review. The warning now carries
  `reply_bytes` and the first 4 KB of the raw reply, with the model key, the review token and common key,
  token and password patterns masked. Review behaviour is unchanged.

- AI review says why a reply failed. When `review.sh` cannot parse or validate the model's reply, it logs the
  finish reason, model, token counts, any error field and the first 4 KB of the raw reply (secrets masked), and
  keeps the same text as the `ai-review-diagnostics/` job artifact. Review behaviour is unchanged.

- Push mode runs on its own (K-33, part 2). On a project switched to `push`, a thread job that
  ends with a stored fix queues one `round` job for its MR. The round waits (up to 15 minutes)
  for the MR's other thread jobs, then pushes every fix proposed at the current head as one
  `fix(review)` commit and one plain push, with no thumbs-up. Each pushed thread is recorded as
  the new `pushed` outcome (a thumbs-up push stays `applied`), and the MR gets `triage: pushed`.
  A round is capped at 5 threads and 80 changed lines: over either, nothing is pushed, each
  thread is told once, and its proposal stays open for a thumbs-up. Push mode stays off by
  default and is switched per project; a fix that lands while a round is running makes an idle round
  run again (after a push the head moved, so it is triaged afresh); propose and off projects behave as before. The stored-edit
  reader moved into its own service.

- Visible reply when the spend cap holds a job (K-14 follow-up). When a daily cap stops an
  autofix job, Kelpie posts one reply on the thread: which cap (project or Kelpie-wide), the
  spend against it and that work resumes when the cap resets (no clock time: the cap's day follows the database's time zone). At most one per thread per day. The notice has
  its own hidden marker and never the `reply` one, and only counts as Kelpie's bookkeeping when
  the bot account wrote it, so it does not mark the thread answered: the job stays pending and
  runs after the reset, and a person's note after it still counts.

- Prod gets its own GitLab bot token and webhook secret (K-12). Docs and comments only: prod
  already reads `kelpie/prod/app` on vault.prod, apart from dev and staging. New runbook
  `docs/operations/prod-secrets.md` covers the bot and its least scope, creating, storing,
  rotating and verifying the token and the secret. Creating the token and loading it into Vault
  are manual steps, and `KELPIE_BOT_USERNAME` in `main-deploy.yaml` follows in a later MR.

- Push rules for a pushed fix (K-33, part 1). A push now goes through one round service: it
  edits only files the MR already changes (a fix outside them is not pushed and its thread is
  told), runs the gate once, and makes one `fix(review)` commit with a `Thread:` line per
  thread and one plain push. Each fixed thread gets a `Fixed: <commit>` reply and is resolved
  only if Kelpie opened it. The thumbs-up path is a round of one. A round is gated as a whole: a
  red gate pushes nothing and fails every thread in it, and each reply says so and offers
  `Triage:` to retry one alone. Not yet: push mode taking
  fixes without a thumbs-up, and the round and changed-code limits in the safety policy.

## v0.1.0-beta.2

Second beta. Issue intake and triage, the propose workflow with per-project modes, plus
the database, review tooling and planning docs along the way.

- Per-project mode is enforced (K-30 cutover, !58). New table `projects` (migration
  `20261022150000_create_projects`, apply it before the new image; it is created empty).
  The webhook, the worker, `kelpie:sweep`, `kelpie:triage-backfill`, `kelpie:fix` and
  `kelpie:respond` now read each project's row: no row or `off` means Kelpie does
  nothing there, `propose` can only comment, label and propose a diff, and only `push`
  lets a thumbs-up apply a proposal (the one push). `KELPIE_AUTOFIX_PROJECTS` is removed
  from the code and the manifests, with no fallback; `KELPIE_AUTOFIX_ENABLED` stays as the
  global kill switch. Until a row is inserted for a project it stays off, so enrol
  `ubixsys/ubixkelpie` (and any other) with an `INSERT` after the migration.
- Document how a project is enrolled in Kelpie, with Kelpie's own repo as the example (!57).
- Thumbs and `Triage:` on a proposal (K-32, !56): a 👍 from the MR author or a maintainer
  on Kelpie's proposed fix makes the worker apply the stored edits at the recorded head,
  run the gate and push `fix(review): ...` to the MR branch (a moved head gets a "stale"
  reply, a red gate pushes nothing); a 👎 declines it. A reply starting `Triage:` runs the
  thread again with the rest of the text as steering for the triage and propose models.
  Anyone else, and Kelpie itself, is ignored. New webhook handling for Emoji events
  (alongside the sign-off retry, which is unchanged) and `apply`/`decline` job sources;
  the proposal reply now says what the reactions do. Migration
  `20261022140000_autofix_jobs_steering` (apply it before the new image). Emoji events
  must be ticked on the project hook, as for the sign-off retry. No `bin/` change.
- Document the CI variables Kelpie relies on (!54).
- Propose mode (K-31, !53): for each unresolved thread `kelpie:work` now triages, and for
  a `fix` asks a second model for exact-text edits, applies them itself in a scratch
  checkout and replies on the thread with the diff git writes. It no longer pushes. A
  thread can also get a proposed dismissal or a proposed deferral (new `defer` triage
  class) with a reason. The triage and propose models (`KELPIE_TRIAGE_MODEL`,
  `KELPIE_PROPOSE_MODEL`, defaults `google/gemini-3-flash` and `openai/gpt-5.5`) must be
  from a different family than the review's (`AI_REVIEW_MODEL`), or the thread goes to a
  person. New table `thread_outcomes` (migration `20261022100000_create_thread_outcomes`,
  apply it before the new image) records each thread's verdict, outcome, models and cost.
  `docs/operations/worker.md` now also says when a running job is superseded.
- The webhook writes jobs and `kelpie:work` runs them (K-09, !52): MR comments, failed
  gate pipelines and finished `kelpie-review` jobs (new Job events handler) become rows
  in `autofix_jobs`; the worker leases a row with a conditional UPDATE, retries a failure
  after 1 then 5 minutes (three attempts) and runs the fixer. `kelpie:sweep` is now the
  backstop that only queues jobs. Migration `20261021124000_autofix_jobs_worker_columns`;
  see `docs/operations/worker.md`.
- Test image on the official PHP base with pcntl/posix via install-php-extensions (!51).
- Give the review's chat client the OpenRouter timeout too (!50).
- Go-live plan for enrolling the ubixsys group (!49).
- Give the OpenRouter client its own HTTP timeout, 150 s by default (!48).
- Show the container's logs in the kelpie-review job when the review exits non-zero; run
  it advisory in Kelpie's own pipeline (!47).
- Turn autofix off in every manifest until propose mode exists (!46).
- Triage status labels, summary note and 👀 show where triage is on a merge request (!45).
- `kelpie:review`: the AI merge request review as a command in Kelpie's image, running in
  shadow in Kelpie's own pipeline (!44).
- The `projects` table's reads: ProjectMode, Project, repository and mode service (K-30,
  !43).
- Issue triage worker: `kelpie:work` triages new issues, `kelpie:triage-backfill` queues
  issues that were open before triage (!42).
- Document why issue intake showed no 👀 and the path to triage (!41).
- Thin the kelpie commands into services with DI wiring (K-07 part 2, !40).
- Acknowledge accepted issues with a 👀 award emoji (!39).
- Rehome Kelpie classes into family folders (K-07 part 1, !38).
- Inventory of the Kelpie bot account (!37).
- Classify Issue Hook events at intake: triage, gate or ignored (!36).
- Approval retry falls back to `KELPIE_BOT_GITLAB_TOKEN` (!35).
- Plan for issue autofix, first step on ubixshepherd (!34).
- Remove planning documents from the repo (!33).
- Keep fixes out of the moved manifests and all of `sql/` (!32).
- Empty schema baseline for ubixkelpie so phpunit can rebuild the test database (!31).
- ubixkelpie database via the uBixCore CI template; uBixCore 0.47.0 (!30).
- Stamp model, effort, tokens and cost on every AI review summary (!29).
- Architecture audit of uBixCore standards vs Kelpie placement (!28).
- Scheduled autofix sweep and the failed-pipeline source, behind
  `KELPIE_AUTOFIX_ENABLED` (!27).
- Kelpie database setup runbook (!26).
- Resolution comment after a fix, resolve on already_fixed (!25).
- Fixer runner for fix-triaged threads, behind `KELPIE_AUTOFIX_ENABLED` (!24).
- Reply, needs-human label and claim marker for triaged threads (!23).
- Autofix job state: coalescing and caps, plus the `autofix_jobs` migration, job record
  model and SQL repository (!22).
- Classify a review thread via OpenRouter with the safety policy as code, plus a
  `kelpie:triage` dry-run command (!21).
- Filter Note and Pipeline hooks into autofix jobs (log only, !20).
- MR thread reader (Thread DTO + DiscussionReader service, !19).
- Record the eight MR thread autofix decisions (!18).
- Plan MR thread autofix: trigger, triage, fix, resolution (!17).

## v0.1.0-beta.1

First beta. Foundation plus the merge request workflow; issue fixing and code monitoring are
not built yet.

- AI merge request review (`ci/ai-review`, `docs/ai-review.md`): reviews a merge request and
  leaves a visible note when it finds nothing or is skipped.
- Approval webhook: a 👍 emoji on a merge request starts the sign-off job. Requires uBixCore
  ^0.46.1 (`ApprovalWebhookService` reads GitLab 19.4.2's top-level `event_type` for emoji
  events).
- `app/KelpieApi`: health, version, CORS, JSON errors and the GitLab webhook; deployed through
  the `dev` → `staging` → `main` pipeline.
- `app/HelloApi`: health.
