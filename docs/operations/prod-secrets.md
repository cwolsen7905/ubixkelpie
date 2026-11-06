# Prod bot token and webhook secret

Production uses its own GitLab bot token and webhook secret. Dev and staging use theirs; none are
shared. This file holds no secret value, and none belongs in it.

## How it is wired

- The pod reads `kelpie/prod/app` from **vault.prod** at start (`VAULT_APP_KV_PATH` in
  `app/KelpieApi/main-deploy.yaml`). Dev and staging read `kelpie/<env>/app` from vault.dev. There is
  no Kubernetes Secret in the manifests.
- The keys are fixed in the code: `KELPIE_BOT_GITLAB_TOKEN` (the bot's token),
  `GITLAB_WEBHOOK_SECRET` (the webhook's secret token), and `GITLAB_API_TOKEN`
  and `GITLAB_PROJECT_TOKENS` (read tokens; keep them prod-only too).
- Vault fails closed: if a key is missing, the pod does not become ready and the deploy fails.

## 1. The bot and its scopes

Use a bot that only prod uses. Do not put the dev or staging token in prod.

- Create a new **group access token** on the `kelpie` group. GitLab makes a new bot user for it.
  Name it `Kelpie Bot (prod)`.
- Role: the lowest that works. Start with **Developer**; raise to Maintainer only if a call is
  refused (see `docs/operations/bot-account.md` on the wide role the current bot has).
- Scope: **`api`** only. Leave out `read_repository`, `write_repository`, the registry scopes and
  `ai_features`: `api` covers repository access and the others are not used.
- Expiry: at most one year. Put the date in the team calendar.
- The bot must be a member of every project Kelpie acts on in prod.

## 2. Create the token and the webhook secret

1. Token: GitLab, group `kelpie` > Settings > Access tokens > Add new token. Copy it once, straight
   into Vault (step 3). Do not paste it into chat, tickets, shell history or a file.
2. Webhook secret: generate at least 32 random bytes, for example `openssl rand -hex 32`, and send
   it straight to Vault. Do not print or save it elsewhere.
3. Use a new value for each. Never reuse the token's or a dev/staging value.

## 3. Where they live

uBixVault is the source of truth: **vault.prod**, path `kelpie/prod/app`, keys
`KELPIE_BOT_GITLAB_TOKEN` and `GITLAB_WEBHOOK_SECRET`. Nothing is copied into the repo, an image or a
manifest. The pod picks up Vault's value at start, so a change reaches prod on the next pod start.

Use `vault kv patch` so the other keys at the path are kept (a `put` replaces the whole path).

Then set `KELPIE_BOT_USERNAME` in `main-deploy.yaml` to the new bot's username. It marks threads as
Kelpie's own, so it must match the prod bot. That is a manifest edit, made in its own MR once the bot exists.

## 4. Rotate

**Bot token** (before expiry, or at once if exposed):
1. Create a new token for the same bot (or a new one, if the old one leaked).
2. `vault kv patch` the new value into `kelpie/prod/app`.
3. Restart the prod pods (a human deploy or rollout) so they load it.
4. Verify (section 5), then revoke the old token in GitLab.

**Webhook secret**:
1. Generate a new value; `vault kv patch` it into `kelpie/prod/app`.
2. Restart the prod pods.
3. In each project's webhook, set the new secret token. Between steps 2 and 3 deliveries fail with
   an authentication error, so do both close together. GitLab retries are not guaranteed: run the
   test delivery after.
4. Verify (section 5).

## 5. Verify

- **Webhook test delivery:** project > Settings > Webhooks > the Kelpie hook > Test > Merge request
  events. Expect a 2xx. A 401 or 403 means the secret differs between GitLab and Vault.
- **Bot API call:** with the token loaded in your shell without printing it, call
  `GET <GITLAB_API_URL>/user` and expect the prod bot's username (`bot: true`); a 401 means a wrong
  or revoked token. Then check Kelpie's own log shows no GitLab auth failures after a webhook.
- Confirm the tokens differ: call `/user` with the prod token and with the dev one; the usernames
  must differ.

## Checklist for the person

1. Create the prod-only group access token (`api` scope, Developer role, expiry set) on group `kelpie`.
2. Generate the webhook secret.
3. `vault kv patch` both into vault.prod `kelpie/prod/app`; check dev and staging values differ.
4. Set the webhook secret token on the prod project hook(s).
5. Roll the prod pods through a normal deploy; confirm the pod is ready.
6. Run the verification in section 5.
7. Open a follow-up MR for `KELPIE_BOT_USERNAME` with the new bot's username.
8. Revoke any shared token that prod used before.
