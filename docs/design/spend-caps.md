# Spend caps

Kelpie's fixer run spends model money (OpenRouter). A daily cap, per project and for all of Kelpie, is
checked before the run's first model call.

## Where cost is recorded

`thread_outcomes.cost_usd` holds what the triage and propose calls of a thread cost (NULL when the provider
did not say). A thread has one row; a new run on it replaces the row, but adds its cost to the sum already there
when the row was last written today (a run that reports no cost leaves the sum alone), so every run today counts.
A row last written on an earlier day starts again from the new run's cost, so a day's spend is only that day's.
The price is that an earlier day's cost for a re-run thread is not kept in the row. A reaction to a proposal
changes the outcome without moving `date_last_updated`, so it does not pull the row into today. A per-run ledger
would keep the history; it needs a migration.

Not recorded anywhere, so not counted: issue triage and `kelpie:review`. Counting them needs a table or column
(a migration); that is a follow-up.

## The check

`SpendCapService::check($projectPath)` sums `cost_usd` of rows updated since the start of today, by the
database's clock (`CURDATE()`, the same clock that stamps `date_last_updated`), for the project and for all projects, and compares with:

- the project cap: `projects.max_cost_usd_day` (default 2.00). A project with no row is off: the check says
  `not_enrolled`, not a cap, and the run is skipped and its job superseded;
- the global cap: `KELPIE_MAX_COST_USD_DAY_GLOBAL`, default 10.00. Unset, blank, negative or not a number
  means the default. `0` is a real cap: nothing runs.

The cap is reached when spend so far is at or above it. The check reads the spend and the call comes later,
with nothing reserved in between, so runs that start together all pass. The overshoot is bounded by the number
of runs in flight (the workers) times one run's cost, not by one run's cost. Reserving an estimate under a lock
would close this; it is not done here. The project cap is checked first.

## A run that crashes

A crash after a paid call (the outcome row failing to write, a reply that throws) records a `failed` row with the
cost of the calls made so far, so the cap still counts them. A crash inside the triage call itself leaves no
cost to record.

## At the cap

`FixerRunnerService::run` checks after the thread is known to be unanswered and before it claims it or calls a
model. It logs a warning (which cap, spend, cap, project) and returns `FixOutcome::OverCap`. The job is left
held, and nothing is posted: a reply would mark the thread answered and the job would be dropped instead of run
once the day rolls over. The worker puts the job back as pending, due at the next midnight, and does not count
the held attempt, so the retry limit is not used up. The webhook is not involved.

## Not done here

No index on `thread_outcomes.date_last_updated`; the sum scans the table, which is small. Add one with the
migration that records the rest of the spend.
