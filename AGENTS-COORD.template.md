# AGENTS-COORD.template.md — working this repo with several agents at once

> **This is the committed template.** The live file is the **untracked** `AGENTS-COORD.md` at
> the repo root: per-sandbox session state, never pushed.
>
> ```bash
> cp AGENTS-COORD.template.md AGENTS-COORD.md
> ```

Several agent sessions may work this repo at once. This file is how they stay out of each
other's way. **Read it before you branch, before you edit a shared file, and before you merge.**
The rules themselves live in uBixCore's `docs/standards/branching-and-git-workflow.md`
§ Concurrent Agent Sessions, and **the standard wins** over this file. Here `dev` is the base
branch and is MR-only; branch off `dev`, target MRs at `dev`.

## 1. Lanes

| Lane | Scope | Branch prefix | Session |
| ---- | ----- | ------------- | ------- |

Add your row first, with a branch prefix nobody else is using.

## 2. Branches and worktrees

- **One worktree per session**, always:
  `git worktree add ../ubixkelpie-worktrees/<lane> -b <prefix>/<slice> origin/dev`.
- Branch off **freshly fetched `origin/dev`**. Never commit to, rebase or delete a branch you do
  not own; never force-push a shared branch.
- `git worktree remove ../ubixkelpie-worktrees/<lane>` once the slice lands.
- **Never** `git stash`, `git reset --hard` or switch branches in a checkout holding work you did
  not write. `git status` first.

## 3. Shared files — claim before editing

| Path | Why it collides |
| ---- | --------------- |
| `app/<App>/src/Routes.php`, `Dependencies.php` | every feature adds a route and a binding |
| `.gitlab-ci.yml`, `composer.json` / `composer.lock` | one line, everyone's branch |
| `CLAUDE.md`, `README.md`, `AGENTS-COORD.md` | prose everyone appends to |

## 4. Merging

- `dev` is MR-only. Push with GitLab push options:
  `git push -u origin <branch> -o merge_request.create -o merge_request.target=dev -o merge_request.remove_source_branch`.
- **Merge when the pipeline succeeds** rather than waiting on it:
  `glab mr merge <iid> --when-pipeline-succeeds --sha <head> --remove-source-branch --yes`.
- Rebase on `origin/dev` before merging; if the rebase only conflicts in docs, resolve by keeping
  **both** sides.
- Agents never approve a merge request.

## 5. Live log — append only, newest at the bottom

Record: lane, branch, worktree, the paths you claim, and a second entry when the MR opens and
when it lands. Do not edit another lane's entries.
