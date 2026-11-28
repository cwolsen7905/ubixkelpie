# Push mode: the automatic round

Push mode lets Kelpie fix review threads on an MR without a person pressing a button. It is
off unless the project's row says `push`; enrolling a project and switching it to push are the
maintainer's decisions, made per project.

| Mode | A proposal is pushed |
|---|---|
| `off` | Never |
| `propose` | When the MR's author or a maintainer gives that proposal a 👍: one proposal, gated, then pushed |
| `push` | By a 👍, as in propose mode, and also in an automatic round (below) without asking |

Kelpie puts 👀 on a proposal as soon as a 👍 or 👎 on it is queued; the result follows as a reply.

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

All the fixes go to the push round service: edits only in files the MR already changes, test
files, and files the thread's finding names by path (see below); one checkout, the project's gate
on the whole result, one `fix(review)` commit with a `Thread:` line per thread, one plain push
(never forced). A moved head or a rejected push changes nothing.
Each pushed thread gets `Fixed:` with the commit and is resolved only if Kelpie opened it. The
MR label becomes `triage: pushed`.

## Who wrote it: commit trailers and note footers

Every autofix commit and reply says it is Kelpie's, and which agent and model made it. The commit author
and committer stay the bot account. Example commit message of a push round:

```
fix(review): rename the helper

Applies the changes Kelpie proposed in these review threads.

Thread: 4f1c9a
Kelpie-Autofix: propose
Assisted-by: Kelpie autofix (openai/gpt-5.5)
```

`Kelpie-Autofix` is the marker the loop guard reads. `Assisted-by: Kelpie <agent> (<model id>)` is the
attribution: one line per distinct model in the round. The model is the one the run used, read from the proposal's
stored row; a row stored before that was kept has none, and its trailer is `Assisted-by: Kelpie autofix` alone
(no configured default is guessed). A push round names the agent `autofix` because the stored row keeps the model,
not whether the propose model or the fixing agent made the edits. An issue fix commit names `propose-model`.

The usage footer of every reply Kelpie writes from a model call opens with the same attribution, in the one footer
(there is no second one):

```
_Kelpie · agent `fixer-agent` · model `openai/gpt-5.5`, effort default, 12.3k in / 1.0k out, $0.0105 (OpenRouter)._
```

The agent is `triage-model` (a triage reply), `propose-model` (a proposal from the one-shot model) or
`fixer-agent` (a proposal the fixing agent made). The model is the later call's when two calls sit behind one reply.

## Where a fix comes from: the propose model, then the agent

The propose model is one call that sees only the file the thread is on. When it finds no edit,
or its edits do not apply, Kelpie runs the fixing agent in the same scratch checkout: the
`claude` CLI, headless, with Read, Grep, Glob, Edit and Write only (no shell, no git, no
network beyond the model), on the same model as the propose step and under the same family
guard. It can read the whole repository, so it can add the missing test or change the file
the finding is really about. What it changed is measured from git, checked by the safety
policy (size, file count, protected paths, no deletes), posted as the proposal's diff and
stored as exact-text edits (one whole-file edit per file, or a new file), so a round replays it
like any other proposal. Its cost counts toward the project's spend cap. The CLI prices runs
from its own table, which may not know the model: a missing or zero cost is estimated from the
token counts at a deliberately high rate ($10 in / $40 out per million tokens). A run that
fails (an error, the 30-turn limit, a timeout) is still charged: its reported cost, the
estimate, or a flat $1.00 when nothing says what it spent. It is never counted as free.

The checkout keeps its git repository outside the work tree, and every git call names it and
turns fsmonitor and hooks off. While the agent runs, the work tree has no `.git` at all; after
it, a clean pointer is put back (replacing anything the agent left there), so a project gate
that calls git still works and a `.git/config` the agent might be talked into writing is never
read.

`KELPIE_FIXER_AGENT=off` on the worker switches the agent off; the propose model alone then
proposes, as before.

## Which files a fix may touch

- a file the MR already changes;
- a test file (`tests/` or `test/` folders, `_test.go`, `Test.php`, `.test.`/`.spec.` JS and TS
  files, pytest files), new or existing;
- a file the thread's opening note names by its path, such as `internal/dispatch/adapters.go`.

Anything else is left out of the round and the thread says why. The safety policy's protected
paths (CI, migrations, secrets, lock files, tool configuration, manifests) are refused whatever
the list says.

## The gate

The round runs the project's gate: its `gate_command` (from the `projects` row), else
`make check` when the Makefile has a `check` target, else Kelpie's PHP gate. The value `ci`
runs nothing here: it is for a project whose toolchain Kelpie's image does not have (Go, for
ubixshepherd). Its merge request pipeline then gates the pushed commit, and every `Fixed:`
reply says the gate was not run here. A merge still needs a person's approval.

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
