# Context scan and the unattended action list

Two guards in `php/Kelpie/Security/`. Neither replaces the main controls: text is still fenced as data in
every prompt, triage still has no tools, and the fixer still has no token. These catch what slips past.

## 1. Scanning text before a model

`ContextScanner::scan($text, $source, $fromKelpie)` returns the cleaned text and the kinds found.
`ContextScanner::guide($path, $text)` returns a repo guide unchanged, or null when anything was found.

| Kind | What it matches |
|---|---|
| `html_comment` | Any `<!-- ... -->`, including one never closed (it hides the rest of the text) |
| `invisible_char` | Soft hyphen, U+180E, zero-width characters, U+2060–2064, BOM, tag characters U+E0000–E007F |
| `bidi_control` | LRM/RLM, U+061C, U+202A–202E, U+2066–2069 |
| `injection_phrase` | "ignore / disregard / forget / override / bypass" followed by your, my, all, previous, prior, above, system... and "instructions", "rules", "prompt"...; "you are now in developer mode"; "new instructions:"; "reveal your system prompt" |
| `secret_path` | A read, copy or send verb near `.env`, `id_rsa`, `*.pem`, `/etc/passwd`, `.ssh/`, `.aws/credentials`, `.npmrc`, `.netrc`, a kube config, a vault token, `secrets.yml` |

Plain prose such as "never ignore the instructions in CONTRIBUTING.md" is not a hit: the phrase needs a
qualifier like *previous* or *your*.

### What a hit does

- **Repo guide** (`.ai-review.md`, `AGENTS.md`, `CLAUDE.md`, `CONTRIBUTING.md`): the guide is skipped whole and
  the path is logged.
- **MR description, thread note, issue body, job log**: the text is kept as a quotation. Comments are defused
  (`<! -- ... -- >`), invisible and bidirectional characters are removed, and the caller fences the result as
  data. `isSuspicious($kinds)` is true for a phrase or a secret path, so the caller can tell the model the text
  is untrusted.
- Every hit is logged as a warning with its source and a short excerpt, never the full text.
- A hit never stops a run, never sets a label and never approves anything.

### Kelpie's own markers

Kelpie writes HTML comments (`kelpie:claim|release|reply|summary|cap-held|pipeline`, `ai-review:fp|summary`).
They carry no signature, so a match proves nothing on its own. The scanner leaves a comment alone only when the
caller passes `$fromKelpie = true` (the note's author is Kelpie's own bot) **and** the comment is exactly one of
those names followed by plain `key=value` words. The same marker typed by anyone else, or a marker with prose
inside, is a hit. Signing the markers (for example with an HMAC) would be a separate change to the code that
writes them.

## 2. The unattended action list

`UnattendedAction` is the list; everything not in it is refused. Nobody is there to approve a prompt.

| Action | Allowed shape |
|---|---|
| `read_repo` | model tools `read`, `grep`, `glob` |
| `edit_files` | the `claude` CLI with `--allowedTools` limited to Read, Grep, Glob, Edit, Write, and no permission-skipping switch |
| `run_gate` | `composer install`, `vendor/bin/phpcs`, `vendor/bin/phpstan`, `vendor/bin/phpunit` |
| `git_read` | `git diff, show, ls-tree, cat-file, rev-parse, status, log` |
| `git_checkout` | `git clone, fetch, checkout, add` |
| `git_commit` | `git commit`, not `--amend` |
| `git_push` | `git push` with no force flag, no delete, no `+` or `:` refspec |
| `clean_checkout` | `rm -rf -- <path under the temp directory>` |

`git -c` may set only `user.name`, `user.email`, `commit.gpgsign` and `safe.directory`; any other `-c` (such as
`core.hooksPath` or an alias) is refused. Not on the list, so refused: approve, merge, resolve a thread Kelpie
did not open, delete, any other program, shell, or network use by the agent. Posting a thread note and setting a
label are `post_thread` and `set_label` in the enum for the HTTP side; the bot's HTTP client already allows
only those.

`GuardedProcessRunner` wraps a `ProcessRunnerInterface`: a refused command is not started, is logged, and comes
back as exit code 126 with the reason on stderr.

## 3. Where it is wired

Built and tested in this change: `ContextScanner`, `ActionGuard`, `GuardedProcessRunner`.

Still to wire, in code outside this change's paths:

- Call `scan()` on MR descriptions, thread notes, issue bodies and job-log tails where the prompts are built
  (`Prompt/`, `Service/Autofix/`, `Service/Review/`), passing `$fromKelpie` from the note's author; call
  `guide()` where `Service/Review/StandardsService` reads the repo guides.
- Bind `ProcessRunnerInterface` to `GuardedProcessRunner` around `ProcOpenProcessRunner` in the worker and
  sweep commands (`app/KelpieApi/src/Dependencies.php`).
- Have `Service/Review/RepoToolsService` use `ActionGuard::permitsTool()`; it already answers an unknown tool
  with an error text.
- Have `Agent/ClaudeCliFixerAgent` take its tool list from the shared list.
