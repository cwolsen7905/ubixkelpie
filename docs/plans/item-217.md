# Item 217 — lessons from outcomes (K-44)

Goal: Kelpie reads its `thread_outcomes` and turns repeated results (a class it keeps getting wrong, a
path it keeps flagging, a model that keeps failing) into something it can act on — a note, a config
change, or a guard.

## Scope
- `php/Kelpie/Repository/ThreadOutcome/ThreadOutcomeReaderInterface.php` (the outcomes reader)
- `php/Kelpie/Service/` (a new lessons/outcomes-report service)
- `php/Kelpie/Console/Command/` (an optional report command)
- `tests/Service/` (mirror the new service)

## Depends on
The `thread_outcomes` table and reader (present; `getByThread`, `getByProposalNote`); the propose-mode
runner that writes the rows.

## Current state (dev, 3d820e9)
Not built. `ThreadOutcomeReaderInterface` reads one thread or one proposal note; nothing aggregates
outcomes into lessons, and there is no report. The rows carry the verdict, outcome, models, family,
tokens and cost.

## Steps
1. Read the tracker (item 217 / K-44) for what a "lesson" is and where it goes.
2. Add an outcomes-report service: aggregate `thread_outcomes` by verdict, outcome, model and project
   over a window.
3. Surface the result (a log summary, a command, or a note) per the tracker.
4. If a lesson changes behaviour (a model swap, a guard), apply it where the tracker says.
5. Add tests over a fixture set of outcomes.

## Done when
A command or report reads `thread_outcomes` and produces the lessons the tracker defines, with tests.
Gate green.

## Needs the person
What a "lesson" is and where it goes — a design call.
