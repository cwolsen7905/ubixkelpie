# Autofix caps

Three caps bound how much work Kelpie takes on. They apply to the jobs that run on a thread
(a note, a reply, a thumbs-up); fan-out and round jobs are not counted against them.

| Cap | Variable | Default | Meaning |
|---|---|---|---|
| Jobs per day | `KELPIE_AUTOFIX_JOBS_PER_DAY` | 100 | Jobs a project may create in any 24 hours |
| Commits per MR | `KELPIE_AUTOFIX_COMMITS_PER_MR` | 3 | Fix commits Kelpie may push to one MR |
| Attempts per thread | `KELPIE_AUTOFIX_ATTEMPTS_PER_THREAD` | 2 | Fix attempts on one thread |

## Setting them

Set the variable in uBixVault, in `kelpie/<env>/app`, next to the other `KELPIE_*` settings. A value
must be a whole number of at least 1. A missing or empty value gives the default. Anything else
(`0`, a negative number, `lots`, `2.5`) gives the default and logs a warning
(`Invalid autofix cap setting, using the default`), so a typo never switches a cap off.

The variables are read when a process starts. After changing one, restart the pods that run Kelpie
(`kelpie-worker`, and `kelpie-api` for the webhook) so they pick it up.

## The over-cap note

When a job is refused because the project is at its jobs-per-day cap, Kelpie says so on the MR
instead of dropping the job silently. It posts one plain MR note (not a thread, so nothing to resolve):

> Kelpie is over its daily job cap for this project (100 of 100 in 24 h). Threads here are not
> triaged until it frees up, around 13:05 UTC.

and adds the label `kelpie::over-cap`. The time is when enough of the oldest jobs in the window age
out for the count to drop below the cap.

- **At most once per MR per 24 hours.** The note carries a hidden marker; before posting, Kelpie looks
  for a note with that marker younger than 24 hours and posts nothing if it finds one.
- **It never queues a job.** The note is the bot's own and carries the `<!-- kelpie:` marker; the event
  filter ignores the bot's notes and marked notes, and a plain MR note is not a thread.
- **It cannot break enqueueing.** A GitLab failure is logged and the refusal stands. With no
  `GITLAB_API_URL` or `KELPIE_BOT_GITLAB_TOKEN` nothing is posted.
- The label is not removed by Kelpie; take it off by hand once the cap has freed up.
- Only the jobs-per-day cap posts a note. Counting is unchanged: Kelpie's own review threads still
  count against the cap.
