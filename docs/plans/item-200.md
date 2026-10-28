# Item 200 — issue fix and merge request (step 3)

Goal: when a Developer adds the gate label to a triaged `fix` issue, Kelpie opens a
`kelpie/fix-<iid>-<slug>` branch, edits files, checks `SafetyPolicy`, runs the gate, pushes and opens
an MR to `dev` with `Closes #N`, a reviewer, and a comment on the issue. One attempt, then hand off.

## Scope
- `php/Kelpie/Service/Triage/` (a new issue-fix service; the worker already runs issue jobs)
- `php/Kelpie/Policy/SafetyPolicy.php` (the size / paths / tests limits)
- `php/Kelpie/Sandbox/Checkout.php` (branch, push without force)
- `php/Kelpie/Service/Autofix/` (reuse the propose-mode fixer runner)
- `tests/Service/Triage/` (mirror the new service)

## Depends on
Item 199 (the triage verdict is `fix`); the gate label (item 198); `SafetyPolicy`, `Checkout` and the
propose-mode fixer (all present).

## Current state (dev, 3d820e9)
Not built. There is no issue-fixing path: the only fixer that runs is the MR-thread propose/apply path
(`ProposalReactionService`, `MrJobWorkerService`). No code opens an issue fix branch or an MR from an
issue. The design (this item's section in the prior `docs/design/issue-autofix.md`, in git history)
covers the flow, the in-pod sandbox and the limits.

## Steps
1. Read the tracker (item 200) and the prior design for the flow and the limits.
2. Add an issue-fix service: on the gate label, branch `kelpie/fix-<iid>-<slug>` from `dev`, run the
   fixer, check `SafetyPolicy`, run the gate.
3. Gate green: push the branch, open the MR to `dev` with `Closes #N`, set the reviewer, comment on the
   issue with the MR link.
4. Gate red or a policy violation: one attempt only — comment with the failing tail, label
   `kelpie::needs-human`, leave no pushed branch.
5. Enforce the spend cap (item 214) before the first live run.

## Done when
A throwaway "fix a typo in a doc comment" issue, gated by a Developer, opens an MR to `dev` with
`Closes #N`, the reviewer set, the gate green, and nothing merged. Gate green.

## Needs the person
The reviewer identity and the spend-cap figures; the in-pod gate needs the Go/Node toolchains in the
image (a design call). e2e on a throwaway issue.
