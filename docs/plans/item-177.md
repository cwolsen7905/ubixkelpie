# Item 177 — push mode (K-33)

Goal: in a `push` project, a 👍 on one of Kelpie's proposals applies the stored edits, runs the gate,
commits and pushes to the MR's source branch, and replies "Fixed: <sha>"; only a thread Kelpie opened
is resolved.

## Scope
- `php/Kelpie/Service/Autofix/ProposalReactionService.php` (the apply path)
- `php/Kelpie/Service/Autofix/ReplyService.php` (the "Fixed:" reply)
- `php/Kelpie/Service/Autofix/EditApplyService.php` (where edits may land)
- `tests/Service/Autofix/ProposalReactionServiceTest.php`, `ReplyServiceTest.php`, `EditApplyServiceTest.php`

## Depends on
K-31 propose mode (the proposal and its stored edits) and the `projects` table (K-30) for the `push` mode.

## Current state (dev, 3d820e9)
Built: `ProposalReactionService` gates on `push` mode (ProposalReactionService.php:97), applies the
stored edits in a scratch checkout, runs the gate (a red gate pushes nothing), commits `fix(review): …`
with a `Thread:` and a `Kelpie-Autofix: propose` trailer, re-checks the head, pushes (never forced),
sets the outcome `applied`, and resolves only a thread Kelpie opened (`ReplyService::openedByKelpie`).
One commit and one push per round. Two gaps to confirm against the tracker: the reply reads "Kelpie
fixed this in <sha>:" (ReplyService.php:176), and `EditApplyService` applies an edit to any plain file
in the checkout, not only files the MR changes.

## Steps
1. Read the tracker (K-33) for the exact acceptance criteria; confirm which of the two gaps are in scope.
2. If in scope, restrict `EditApplyService` to the MR's changed paths (pass them from the MR read).
3. If in scope, change the `fixed()` reply lead to "Fixed: <sha>".
4. Keep one commit and one push per round and resolve only Kelpie-opened threads; update the tests.

## Done when
A 👍 in a `push` project pushes exactly one commit to the MR branch and the reply and edit scope match
the tracker; a red gate still pushes nothing. Gate green.

## Needs the person
Nothing expected — a code change inside the existing push path.
