# Item 216 — prompt-injection scan (K-45)

Goal: untrusted issue, merge request and thread text is scanned before it reaches a model, so a
misleading comment or label, wasted spend, or private diff content sent to the provider is less likely.

## Scope
- `php/Kelpie/Policy/` (a new injection-scan policy)
- `php/Kelpie/Prompt/IssueTriagePrompt.php`, `TriagePrompt.php`, `ProposePrompt.php`, `Review/ReviewPrompt.php`
- `php/Kelpie/Service/Triage/` and `php/Kelpie/Service/Autofix/` (where the prompts are built)
- `tests/Policy/` (mirror the new policy)

## Depends on
The existing prompts and their call sites (all present). Not a blocker for `propose` on private,
member-only projects; a hard requirement before any project moves to `push` or an outside-author project
is enrolled (`docs/operations/go-live.md` section 4).

## Current state (dev, 3d820e9)
Not built. Untrusted text reaches the model directly through the prompts; there is no scan or
sanitization step in front of a model call. The design fences issue text as data and the triage/review
runs have no write tools and no token.

## Steps
1. Read the tracker (item 216 / K-45) for the scan's scope and the action on a hit.
2. Add a scan policy that runs on untrusted text before it is placed in a prompt.
3. Wire it into the issue-triage, thread-triage, propose and review call sites.
4. On a hit, act per the tracker (refuse the call, label `kelpie::needs-human`, or log and continue).
5. Add tests with known-bad payloads.

## Done when
Untrusted text is scanned before every model call and a hit is handled per the tracker, with tests for
known-bad payloads. Gate green.

## Needs the person
The action on a scan hit (refuse vs. continue) and when it is mandatory — a design call.
