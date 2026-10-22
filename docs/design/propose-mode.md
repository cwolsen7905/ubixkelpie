# Propose mode

How Kelpie answers a review thread without changing the branch. The runner is
`Kelpie\Service\Autofix\FixerRunnerService`; the operator's view is `docs/operations/worker.md`.

## What it does for one thread

1. Triage (cheap model) returns one class: `fix`, `needs_human`, `not_an_issue`, `already_fixed`, `defer`.
2. `fix`: a second call (stronger model) gets the thread and the file it is on and returns exact-text edits as
   JSON. Kelpie applies them in a scratch checkout at the head the job was made at and posts the diff git writes.
3. `not_an_issue` and `defer` are replies with a reason, as proposals to dismiss or to defer.
4. One `thread_outcomes` row records verdict, mode, outcome, models, tokens and cost.

Nothing is pushed and a proposal never resolves a thread.

## Why exact-text edits that Kelpie applies

A model asked for a patch gets line numbers and context wrong; asked to edit files, it can touch anything. A list
of (file, old text, new text) is checkable: the old text must occur exactly once or the edit is refused, and one
refused edit refuses the lot, so a proposal either applies in full or does not exist. The diff in the reply is
produced by git from the result, not written by the model, so it applies with `git apply` to the head it names.

Applying happens in a throwaway checkout that has no gate run on it: a proposal is advice, and the gate belongs
to the later step that pushes it.

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
outcome values beyond `proposed`, `answered` and `failed` exist for the reaction handling and the push mode that
follow; nothing writes them yet.

## Not here

- Reactions on a proposal (applying on 👍, handing the thread back on 👎).
- Pushing, and the gate on a pushed result.
- A `Triage:` reply that steers a re-run.
- Choosing the mode per project; the allow-list still decides which projects the worker touches.
- A budget on spend; cost is recorded, not capped.
