# Changelog

## Unreleased

## v0.1.0-beta.1

First beta. Foundation plus the merge request workflow; issue fixing and code monitoring are
not built yet.

- AI merge request review (`ci/ai-review`, `docs/ai-review.md`): reviews a merge request and
  leaves a visible note when it finds nothing or is skipped.
- Approval webhook: a 👍 emoji on a merge request starts the sign-off job. Requires uBixCore
  ^0.46.1 (`ApprovalWebhookService` reads GitLab 19.4.2's top-level `event_type` for emoji
  events).
- `app/KelpieApi`: health, version, CORS, JSON errors and the GitLab webhook; deployed through
  the `dev` → `staging` → `main` pipeline.
- `app/HelloApi`: health.
