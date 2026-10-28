# Propose mode

How Kelpie answers a review thread without changing the branch. The runner is
`Kelpie\Service\Autofix\FixerRunnerService`; the operator's view is `docs/operations/worker.md`.

## What it does for one thread

1. Triage (cheap model) returns one class: `fix`, `needs_human`, `not_an_issue`, `already_fixed`, `defer`.
2. `fix`: a second call (stronger model) gets the thread and the file it is on and returns exact-text edits as
   JSON. Kelpie applies them in a scratch checkout at the head the job was made at and posts the diff git writes.
3. `not_an_issue` and `defer` are replies with a reason, as proposals to dismiss or to defer.
4. One `thread_outcomes` row records verdict, mode, outcome, models, tokens and cost.

Nothing is pushed until someone says so, and a proposal never resolves a thread. Only a pushed fix
does, and only one for a thread Kelpie opened (see below).

## What a person does with a proposal

The MR author or a maintainer (access level 40 or more; Kelpie's own reactions and everyone else's are ignored)
answers on the proposal note:

- **👍 on a fix** applies it. The webhook only checks who reacted and writes an `apply` job; the worker then
  re-reads the outcome (still `proposed`) and the MR. If the head is no longer the one the proposal was made at,
  it replies "stale" and sets nothing. Otherwise it applies the stored edits in a fresh checkout at that head,
  runs the gate (`composer install`, phpcs, phpstan, phpunit), commits `fix(review): ...` with a `Thread:` line and
  the `Kelpie-Autofix` trailer, looks at the head once more, and pushes to the source branch, never forced. The
  outcome becomes `applied`; a red gate pushes nothing and the outcome becomes `failed`, with the step named in the
  reply. After the push the reply says `Kelpie fixed this in <sha>`. When Kelpie opened the thread itself (an
  AI-review finding or a pipeline-log thread, written by the bot account, which also answered it) the thread is then
  resolved, and the reply says so; a failed resolve keeps the reply. A person's thread, or a note that merely
  copies the finding and reply markers under another account, stays open for the reviewer to confirm.
- **👎** sets the outcome to `declined` (a `decline` job), replies once and leaves the thread open.
- **A reply starting `Triage:`** in a thread Kelpie has already looked at runs the thread again. What follows the colon is
  steering, passed to both model calls as its own fenced block that cannot change the rules or the answer format.
  The outcome becomes `reopened` until the run records a new one. Such a note is not handed to the ordinary filter,
  so a stranger's `Triage:` neither steers nor re-runs the thread.

A 👍 on a dismissal or a deferral has nothing to apply and is ignored; a 👎 on one declines it. Kelpie never awards a
👍 or 👎 itself: the only emoji it posts is its 👀 on a thread it is working on. Pushing needs the checkout, the
gate and minutes of time, which is why the webhook writes a job instead of doing it.

## Why exact-text edits that Kelpie applies

A model asked for a patch gets line numbers and context wrong; asked to edit files, it can touch anything. A list
of (file, old text, new text) is checkable: the old text must occur exactly once or the edit is refused, and one
refused edit refuses the lot, so a proposal either applies in full or does not exist. The diff in the reply is
produced by git from the result, not written by the model, so it applies with `git apply` to the head it names.

Proposing happens in a throwaway checkout that has no gate run on it: a proposal is advice, and the gate belongs
to the step that pushes it (on a 👍, above).

## Limits that do not depend on the prompt

- A path must be an existing plain file inside the checkout (no `..`, not under `.git`, no link out).
- At most 5 edits; `SafetyPolicy` then measures the real diff (protected paths, size, deletes, tests, attempts).
- A diff that contains one of Kelpie's own hidden markers is refused, so a model cannot plant one in a reply.
- The head is read again just before the reply; if it moved, nothing is posted.
- Model text is cleaned of markers and mentions before it is echoed into a note.

## Model family

A second opinion only counts if it is independent, so the triage and propose models may not be from the family
that wrote the review. Family is the vendor part of the OpenRouter id. An id that cannot be placed is treated as
the same family. The check runs before every call, so a misconfigured model fails closed to `needs_human`. The
review's model is read from `AI_REVIEW_MODEL` in the worker's environment; if that differs from the review job's,
the guard is checking against the wrong family, which is why the operations doc says to set them together.

## The outcome table

One row per thread, replaced by a later run, so the table is a record of the latest state of each thread, not a
log. It keeps the edits and the diff so that a later step can apply a proposal without a new model call. The
outcome values are written as follows: `proposed`, `answered` and `failed` by a run; `applied`, `declined`, and
`failed` again by a reaction; `reopened` by a `Triage:` reply. `overridden` and `reverted` are not written yet.
A proposal is found by its note id (`proposal_note_id`), so only a reaction on the note of the latest proposal counts.

## Not here

- Noticing that a person changed the code after a proposal (`overridden`), or that a pushed fix was later reverted.
- Letting a reaction through when another job is open on the thread: it is reported in the webhook response and the
  reactor reacts again after the run.
- Choosing the mode per project; the allow-list still decides which projects the worker touches.
- A budget on spend; cost is recorded, not capped.

## When Kelpie resolves a thread

- After a gated push, for a thread it opened (above). Never for a person's thread, a proposal, or a push that failed.
- For `already_fixed`, only when the code near the finding changed since the review raised it: the thread's file and
  line, and the review's commit from the finding marker, are compared with the MR head, and a change within three
  lines of the finding counts. Without a file, a line or a review commit on the thread, an unreadable comparison or
  nothing changed there, Kelpie still replies but leaves the thread open for the reviewer.
