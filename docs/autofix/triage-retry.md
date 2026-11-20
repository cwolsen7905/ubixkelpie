# "Triage: retry" on a thread Kelpie has not looked at

A `Triage:` reply in a thread Kelpie has already triaged runs the thread again, with the text after
the colon as steering (see `docs/operations/worker.md`). On a thread Kelpie has **not** triaged, the
one command that does something is `Triage: retry` (case does not matter; a trailing `.` or `!` is fine).
Any other `Triage: ...` text there is ignored, as before.

Who may: the MR author or a maintainer, the same as for steering. Anyone else is ignored and gets no answer.
Nothing happens while `KELPIE_AUTOFIX_ENABLED` is off.

## What it does

- **It can be queued:** the thread is queued for triage like any other thread job. Kelpie says nothing
  until the triage itself replies.
- **It cannot be queued:** Kelpie replies in the thread once, so the person is not left with silence:

  > Kelpie hasn't looked at this thread yet, and "Triage: retry" could not queue it: <reason>.

  The reason is one of:
  - this project is not enrolled in Kelpie (it has no `projects` row, or its mode is `off`);
  - this MR is over its daily job cap, or this project is over its project-wide daily job ceiling (see `caps.md`);
  - this MR has reached its limit of fix commits, or this thread its limit of attempts.

## Once only, and never a loop

The reply carries its own hidden marker (`<!-- kelpie:retry-not-queued -->`), not the over-cap MR note's.
Before replying, Kelpie reads the thread and posts nothing if a note by the bot with that marker is already
there, however old: a second `Triage: retry` on the same thread is not answered again. The reply is the bot's own
and carries the `<!-- kelpie:` marker, and the `Triage:` command guard refuses the bot, so it cannot queue a
job.

When the cause is a daily cap, the MR-level over-cap note (`caps.md`) is posted as well, by its own rule. The
two markers differ, so neither suppresses the other.
