# Settled threads

Kelpie resolves an MR thread once it is settled, so a finished review or triage does not leave threads that block the merge
until someone clears them by hand.

## When a thread is resolved

A thread needs at least one reply (a lone first note is never resolved) and one of these:

1. **Fixed and pushed.** A reply says `Fixed` at its start (Kelpie's push-mode reply) or "fixed in <sha>" (an agent), and names
   a commit the MR carries. The shas are read from the MR's own commit list, so a commit that is not on the MR does not count.
   Only the bot's note may use the strict `Fixed:` form; a proposal (a note carrying `head=` in its marker) never counts.
2. **Dismissed with a reason.** The last reply starts with `Dismissed:` (the bot) or says "dismissed" in its first words
   (anyone else), followed by at least three words of reason. "Not dismissed" and Kelpie's "proposes dismissing" do not count.
3. **Kelpie's triage summary.** A summary note the bot wrote is resolved once every thread it lists is resolved. The summary's
   marker is `<!-- kelpie:summary n=<count> t1=<id>.<id>... -->`, with the first ten characters of each discussion ID (five
   per attribute, up to 25 threads). A summary with no list (older ones, or a run of more than 25 threads) waits until the MR
   has no other open thread. A summary still saying "working on" is left for an hour, then treated like any other.

## When it is not

- A note from anyone but the bot or the fixer after the fix: a person has spoken since.
- The deciding note ends in a question: a person is still asking.
- A thread that cannot be resolved, or a summary typed by someone other than the bot account.

## Where it runs

- Every job `kelpie:work` takes for an MR (a Note Hook queues one), after the job's own work.
- `kelpie:sweep`, for every open MR, before it queues anything, so a settled thread is not queued.

Both only report ("would resolve") while `KELPIE_AUTOFIX_ENABLED` is off. Each resolve logs `Settled thread resolved` with the
project, MR, discussion and the reason (`fixed_pushed`, `dismissed` or `summary_settled`); a failed one logs a warning and is
tried again on the next pass.
