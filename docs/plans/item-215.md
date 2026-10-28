# Item 215 — prod bot token and webhook secret (K-12)

Goal: prod has its own bot token and webhook secret, separate from dev and staging, so enrolling the
group does not widen what the shared token can reach or let a dev-Vault reader forge prod events.

## Scope
- `app/KelpieApi/main-deploy.yaml` (the env that reads the prod secrets from `kelpie/prod/app`)
- uBixVault `kelpie/prod/app` (the new token and secret)
- `docs/operations/go-live.md` (section 4, #215)

## Depends on
uBixVault and GitLab group settings; nothing in the code changes (the manifest already reads
`KELPIE_BOT_GITLAB_TOKEN` and `GITLAB_WEBHOOK_SECRET` from the Vault path).

## Current state (dev, 3d820e9)
Not done. The bot token and webhook secret are the same on dev, staging and prod, and dev and staging
read them from the weaker vault. The prod manifest (`main-deploy.yaml`) already points at
`kelpie/prod/app`; the secrets themselves are not split.

## Steps
1. Read the tracker (item 215 / K-12) and `docs/operations/go-live.md` section 4 for the token scope.
2. Create a fresh prod bot token (Reporter for `propose`; Developer only for projects later set to
   `push`) and a fresh prod webhook secret.
3. Store both in uBixVault `kelpie/prod/app`; rotate the shared token out of dev and staging.
4. Point the prod webhook (and, when it goes in, the group webhook) at the new secret.

## Done when
Prod's token and secret are distinct from dev and staging, the prod pod is ready with them, and the
prod-Vault rule (prod holds production secrets only) holds.

## Needs the person
Everything: Vault writes, GitLab token creation and webhook settings, and the token scope are the
maintainer's. This is not a code lane.
