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
| `injection_phrase` | A verb that tells a model to drop its rules (ignore, disregard, forget, override, bypass) followed by a qualifier (your, my, all, previous, prior, above, system...) and a word like instructions, rules or prompt; a request to switch to an unrestricted mode; a line announcing new instructions; a request to reveal the system prompt |
| `secret_path` | A read, copy or send verb near `.env`, `id_rsa`, `*.pem`, `/etc/passwd`, `.ssh/`, `.aws/credentials`, `.npmrc`, `.netrc`, a kube config, a vault token, `secrets.yml` |

Plain prose that merely tells a reader to follow a guide is not a hit: the verb needs a qualifier like
*previous* or *your*.

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
| `run_gate` | `composer install` with `--no-scripts`, `vendor/bin/phpcs`, `vendor/bin/phpstan`, `vendor/bin/phpunit` |
| `git_read` | `git diff, show, ls-tree, cat-file, rev-parse, status, log` |
| `git_checkout` | `git clone, fetch, checkout, add`, without options that run a program or hook (`--template`, `--config`, `-c`, `--upload-pack`, `-u`, `--receive-pack`, `--exec`, `--server-option`, `--reference`, `--separate-git-dir`, `--recurse-submodules`, and any abbreviation of them), without a `::`, `file:`, `ext:` or `fd:` transport, and with only https URLs |
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

### What the list does not cover: the gate runs repository code

The gate is `composer install`, phpcs, phpstan and phpunit, and the agent may edit any file in the checkout
(`edit_files`). So the agent can write code that those tools then run: a test, a `vendor/bin` script, a
composer plugin, a phpstan or phpcs extension. The list controls which programs start, not what repository code
does once one has. This is a design limit, not something the list can fix. What reduces it:

- `composer install` runs with `--no-scripts`, and the guard refuses it without. Composer plugins still run.
- `GateRunner` gives the registry credential (`COMPOSER_AUTH`) to the `composer install` step only; phpcs, phpstan
  and phpunit get just `HOME` and `PATH`.
- The fixer has no GitLab or Vault value in its environment, and the bot token reaches git only through the
  askpass helper, never to the gate.
- The real boundary is isolation: running the gate in a container with no secrets and a network policy
  (a known design item, FIX-013). Until then, treat the gate as running untrusted code on the worker.

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
