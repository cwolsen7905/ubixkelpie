# Triage confidence, discussion and needs-context

How triage decides whether Kelpie may go on to a fix, and what it does when it cannot tell what a
thread or issue wants.

## Confidence

Both triage prompts (review threads, issues) ask the model for a `confidence`: `high`, `medium` or
`low`. A missing or unknown value is read as `low`.

`TriageConfidencePolicy` gates the `fix` class only. A fix below the threshold (default `medium`)
becomes `needs_human`; the model's reason is kept and the reply names the confidence. Nothing is
proposed or pushed on that thread or issue. Every other class passes through unchanged. The
threshold is a constructor default of the policy; it is not yet configurable per environment.

On a thread the gate runs before `SafetyPolicy`, which still checks the real diff afterwards.

## Discussion is needs-human

A thread or issue that is a discussion, a question or an opinion, not a defect, is `needs_human`
and never `fix`. Both prompts say so. There is no new class for it: `needs_human` already means no
fix and a person's attention.

## Needs-context

When the model cannot tell what the text asks for, it answers `needs_context` and says in its
reason what is missing.

- **Issue:** one comment asking for it, and the label `kelpie::needs-context`.
- **Review thread:** a short reply (`ReplyService`); the MR is marked `kelpie::needs-you`.

## Triaging again after a reply

- **Review thread:** any new note from a person makes the thread unanswered again
  (`ReplyService::isAnswered`), so the next run triages it with the reply in the thread text.
- **Issue:** an issue whose newest triage comment is `needs_context` and that carries the label is
  triaged again when a triage run is queued for it. The person's reply can be passed in (it is fenced
  as data like the issue), and the newest triage comment decides from then on.
  Known gaps, both outside the triage code: nothing queues that run when someone comments on an issue
  yet, and the old `kelpie::needs-context` label is not removed when the second verdict is a different class.
