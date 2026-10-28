# Item 201 — failure paths (step 4)

Goal: the ways an issue fix can go wrong end in a comment and a label, never a pushed branch: too big,
a protected path, a red gate or a policy violation each hand the issue to a person.

## Scope
- `php/Kelpie/Service/Triage/` (the issue-fix service from item 200, and its failure branches)
- `php/Kelpie/Policy/SafetyPolicy.php` (the limits that produce a `needs_human` downgrade)
- `tests/Service/Triage/` (mirror the new service)

## Depends on
Item 200 (the failure paths are the failure branches of the issue fix). The MR-thread equivalent (red
gate → `failed`, no push) already exists in `php/Kelpie/Service/Autofix/ProposalReactionService.php`.

## Current state (dev, 3d820e9)
Not built for issues (there is no issue fix to fail). For MR threads it exists: a red gate sets the
outcome `failed` and pushes nothing (ProposalReactionService.php:175), and `SafetyPolicy` downgrades an
unsafe `fix` to `needs_human`.

## Steps
1. Read the tracker (item 201) for the exact failure cases.
2. In the issue-fix service (item 200), make each failure — too big, protected path, red gate, policy
   violation — post one comment with the reason and set `kelpie::needs-human`, and leave no pushed branch.
3. Enforce one attempt: a re-run needs a person to remove and re-add the gate label.
4. Add a test per failure case.

## Done when
Each failure case on a throwaway issue ends in a comment and the `kelpie::needs-human` label with no
branch left pushed, and a second attempt is refused until the label is cycled. Gate green.

## Needs the person
e2e on throwaway issues (one per failure case).
