# Context scan and the unattended action list

Two guards in `php/Kelpie/Security/`. Neither replaces the main controls: text is still fenced as data in
every prompt, triage still has no tools, and the fixer still has no token. These catch what slips past.

## 1. Scanning text before a model

`ContextScanner::scan($text, $source, $fromKelpie)` returns the cleaned text and the kinds found.
`ContextScanner::guide($path, $text)` returns a repo guide cleaned, or null when it tries to steer the model.
`ContextScanner::quote($text, $source)` returns forge text cleaned for a prompt, with a one-line flag in front when it
tries to steer the model.

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

- **Repo guide** (`.ai-review.md`, `AGENTS.md`, `CLAUDE.md`, `CONTRIBUTING.md`, `docs/standards/*.md`): a phrase or a
  secret path skips the guide whole and logs the path. Stray markup alone (an HTML comment, an invisible character) is
  defused and the guide is kept: standards files carry harmless comments, such as a spell-checker note, and dropping
  them would silently take a project's standards out of every review.
- **MR description, thread note, issue body, job log**: the text is kept as a quotation. Comments are defused
  (`<! -- ... -- >`), invisible and bidirectional characters are removed, and the caller fences the result as
  data. When `isSuspicious($kinds)` is true (a phrase or a secret path), `quote()` puts a line in front saying the
  text tries to steer the model and is data. In prompts, Kelpie's own marker shapes are kept whoever wrote them:
  they hold only `key=value` words, carry no instruction, and nothing reads them back from a prompt.
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
| `run_gate` | `composer install` with `--no-scripts`, `vendor/bin/phpcs`, `vendor/bin/phpstan`, `vendor/bin/phpunit`, exactly `make check` |
| `git_read` | `git diff, show, ls-tree, cat-file, rev-parse, status, log` |
| `git_checkout` | `git clone, fetch, checkout, add`, without options that run a program or hook (`--template`, `--config`, `-c`, `--upload-pack`, `-u`, `--receive-pack`, `--exec`, `--server-option`, `--reference`, `--separate-git-dir`, `--recurse-submodules`, and any abbreviation of them), without a `::`, `file:`, `ext:` or `fd:` transport, and with only https URLs. One exception: `clone --separate-git-dir <path>` with the path inside the temp directory, which is how the checkout keeps the repository outside the work tree |
| `git_commit` | `git commit`, not `--amend` |
| `git_push` | `git push` with no force flag, no delete, no `+` or `:` refspec |
| `clean_checkout` | `rm -rf -- <path under the temp directory>` |

`git -c` may set only `user.name`, `user.email`, `commit.gpgsign` and `safe.directory`, plus exactly
`core.fsmonitor=false` and `core.hooksPath=/dev/null`, which only take a capability away; any other `-c` (such as
`core.hooksPath=/tmp/hooks` or an alias) is refused. A project `gate_command` that is not one of the `run_gate`
shapes is refused: the round reports the gate as failed with the reason, and nothing starts. Widening the list is a
code change, on purpose. Not on the list, so refused: approve, merge, resolve a thread Kelpie
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

- `ProcessRunnerInterface` is bound to `GuardedProcessRunner` around `ProcOpenProcessRunner` in the CLI container
  (`bin/cli-dependencies.php`), so every command the worker, the sweep and the review start goes through the list.
  `GuardedProcessRunnerTest` runs the real checkout, the PHP gate and `make check` through it, so a change that
  would refuse Kelpie's own work fails there.
- `quote()` runs on thread notes and steering (`TriagePrompt`, `ProposePrompt`, the fixer agent's prompt and its
  context), and on issue titles and descriptions (`IssueTriagePrompt`, `IssueFixPromptService`).
- `guide()` runs on every repo guide `StandardsService` reads for the review.
- The review's file tools (`RepoToolsService`) never read, grep or list credential files (`.env` and its variants
  but not `.env.example`-style templates, `*.pem`, `*.key`, SSH keys, `.npmrc`, `.netrc`, `auth.json`, and
  anything under `.ssh/`, `.aws/`, `.kube/`, `.gnupg/`, `.docker/`), so their content is never sent to a model.

Not yet: job-log tails are masked for secrets (`JobLogReader`) but not scanned; the MR title in the review is not
scanned (the diff and the title are the author's own text, and the review prompt already treats them as such).
