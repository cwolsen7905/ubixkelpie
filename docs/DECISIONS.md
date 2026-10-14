# Decisions

Short records of product and design decisions. One entry each: what, why, date. Newest last.
Details live in `docs/design/`.

## 2026-10-14: Issue triage and review routing are designed first, in two halves

The AI review will route findings to *thread* (blocks the MR) or *issue* (does not), by a
deterministic rule; Kelpie intakes, triages and optionally fixes those issues. The issue format
is the contract between the halves. Review routing is built upstream in uBixCore; triage and
fixing in `Kelpie\`. Fixing is opt-in, always a gated MR, never a merge or approval.

Status: **proposed**, open questions in `docs/design/issue-triage.md` section 8.
