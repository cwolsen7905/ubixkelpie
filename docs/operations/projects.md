# Projects and modes

Which projects Kelpie acts on, and how far, is a row per project in `ubixkelpie.projects`. This
replaces the `KELPIE_AUTOFIX_PROJECTS` list; `KELPIE_AUTOFIX_ENABLED` stays as the global kill
switch (it can only stop things, never turn a project on).

## Modes

| Mode | Kelpie may |
| --- | --- |
| `off` | Nothing. A project with no row is `off` too. |
| `propose` | Triage: comment and label. The fixer works out a diff and offers it; it never pushes. |
| `push` | Everything `propose` does, and push a fix that passed the gate to the merge request's branch. |

Each mode allows what the one before it allows (`Kelpie\Enum\ProjectMode::allows()`). An unknown
value in the column is read as `off`, so a bad row never widens what Kelpie may do. There is no
fallback to the environment list when the table is empty: an unenrolled project is simply off.

## Settings

| Column | Meaning | NULL / default |
| --- | --- | --- |
| `path` | Project path with namespace (unique) | |
| `mode` | `off`, `propose` or `push` | `off` |
| `gate_command` | The project's own gate | the default gate |
| `protected_paths` | JSON list of extra globs the fixer must not touch | none |
| `triage_model`, `review_model` | Model overrides | `KELPIE_FIXER_MODEL` |
| `max_issues_per_day`, `max_issues_per_author_day` | Triage caps | 30, 10 |
| `max_cost_usd_day` | Daily spend cap (stored; enforced once run cost is recorded) | 2.00 |
| `max_fix_commits_per_mr` | Fix commits per merge request | 3 |

A `triage:off` label on an issue or merge request switches triage off for that one item, in any
mode but `off`. It never changes the table. A draft merge request is skipped the same way.

While triage works on a merge request it shows `triage: working`, and a finished run leaves one of
`triage: pushed`, `triage: proposed` or `triage: needs you` (see "Where triage is with a merge
request" in `docs/design/issue-autofix.md`). Kelpie creates these labels on the project if missing.

## Rollout

1. The table and its reads (`ProjectReader`, `ProjectModeService`) are in place; nothing consults
   them yet, so `KELPIE_AUTOFIX_PROJECTS` still decides.
2. The cutover makes triage and autofix read the table. Before it deploys, the migration and a
   row for each project in the old list (mode `push` keeps today's behaviour) must be applied on
   that environment, or the project goes quiet.
3. The per-project gate, protected paths, models and caps follow, one at a time.
