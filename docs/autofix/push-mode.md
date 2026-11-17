# Push mode: the automatic round

Push mode lets Kelpie fix review threads on an MR without a person pressing a button. It is
off unless the project's row says `push`; enrolling a project and switching it to push are the
maintainer's decisions, made per project.

## What starts a round

1. The webhook writes a job for a thread; the worker runs it and, as in propose mode, stores a
   proposal (exact-text edits) and posts it on the thread.
2. If the project is in `push` mode and the thread ended with a stored fix, the worker queues
   one `round` job for that MR and head. An MR has at most one open round job; later threads
   join it.
3. The round job waits while the MR still has open thread jobs, rechecking every minute, and
   stops waiting 15 minutes after it was created.
4. It then reads the MR's unresolved threads and takes those whose proposal is still `proposed`,
   a `fix`, made at the MR's current head, with edits stored. Declined, failed and
   other-head proposals are left out.

## What a round does

All the fixes go to the push round service: edits only in files the MR already changes, one
checkout, the project gate on the whole result, one `fix(review)` commit with a `Thread:` line
per thread, one plain push (never forced). A moved head or a rejected push changes nothing.
Each pushed thread gets `Fixed:` with the commit and is resolved only if Kelpie opened it. The
MR label becomes `triage: pushed`.

## Outcomes

| Outcome | Meaning |
|---|---|
| `pushed` | Pushed by an automatic round |
| `applied` | Pushed after a person's thumbs-up |
| `failed` | Left out or refused; the thread says why |
| `proposed` | Still open, including a round that was over the cap |

## The round cap

A round is refused, with nothing pushed, when it has more than **5 threads** or changes more
than **80 lines** (the safety policy's figures, held in `PushRoundCapService`). Each thread gets
one "over the round cap" notice, not repeated by later rounds, and its proposal stays open: a
thumbs-up pushes it alone. The thumbs-up path is not capped, because a person asked for it.

## Settings

There are none yet: the cap figures are constants, and the mode is the project's row. A
per-project cap would need a column on the projects table.
