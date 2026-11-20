# Autofix caps

Four caps bound how much work Kelpie takes on. They apply to the jobs that run on a thread
(a note, a reply, a thumbs-up); fan-out and round jobs are not counted against them.

| Cap | Variable | Default | Meaning |
|---|---|---|---|
| Jobs per MR | `KELPIE_AUTOFIX_JOBS_PER_MR_PER_DAY` | 30 | Jobs one MR may create in any 24 hours |
| Jobs per project | `KELPIE_AUTOFIX_JOBS_PER_PROJECT_PER_DAY` | 200 | Jobs a project may create in any 24 hours, across all its MRs: the spend guard |
| Commits per MR | `KELPIE_AUTOFIX_COMMITS_PER_MR` | 3 | Fix commits Kelpie may push to one MR |
| Attempts per thread | `KELPIE_AUTOFIX_ATTEMPTS_PER_THREAD` | 2 | Fix attempts on one thread |

The per-MR cap keeps one busy MR from using up the day for the others: when MR 12 reaches 30 jobs,
MR 13 in the same project still runs. The project ceiling is the high backstop that stops every MR
in the project if their jobs together reach it. A job is checked against commits per MR, attempts
per thread, the project ceiling and the per-MR cap, in that order, and the first one hit is the
one reported.

## Setting them

Set the variable in uBixVault, in `kelpie/<env>/app`, next to the other `KELPIE_*` settings. A value
must be a whole number of at least 1. A missing or empty value gives the default. Anything else
(`0`, a negative number, `lots`, `2.5`) gives the default and logs a warning
(`Invalid autofix cap setting, using the default`), so a typo never switches a cap off.

The variables are read when a process starts. After changing one, restart the pods that run Kelpie
(`kelpie-worker`, and `kelpie-api` for the webhook) so they pick it up.

## The over-cap note

When a job is refused because the MR is at its per-MR cap, or the project is at its ceiling, Kelpie
says so on the MR instead of dropping the job silently. It posts one plain MR note (not a thread, so
nothing to resolve) that names the cap that was hit:

> Kelpie is over its daily job cap for this MR (30 of 30 in 24 h). Threads here are not triaged until
> it frees up, around 13:05 UTC.

or, for the ceiling:

> Kelpie is over its project-wide daily job ceiling (200 of 200 in 24 h, across all MRs). Nothing in
> this project is triaged until it frees up, around 13:05 UTC.

and adds the label `kelpie::over-cap`. The time is when enough of the oldest jobs in the window (the
MR's, or the project's) age out for the count to drop below the cap.

- **At most once per MR per 24 hours.** The note carries a hidden marker; before posting, Kelpie looks
  for a note by the bot with that marker younger than 24 hours and posts nothing if it finds one.
- **It never queues a job.** The note is the bot's own and carries the `<!-- kelpie:` marker; the event
  filter ignores the bot's notes and marked notes, and a plain MR note is not a thread.
- **It cannot break enqueueing.** A GitLab failure is logged and the refusal stands. With no
  `GITLAB_API_URL` or `KELPIE_BOT_GITLAB_TOKEN` nothing is posted.
- The label is not removed by Kelpie; take it off by hand once the cap has freed up.
- Only the two job caps post a note; commits per MR and attempts per thread do not. A webhook request
  is its own process, so it costs one read of the MR's notes when a job is refused.
- Counting is unchanged: Kelpie's own review threads still count against the caps.
