# Kelpie's bot account

Inventory of the GitLab bot that acts for Kelpie on gitlab.brainchurts.com, taken 2026-10-17 with
read-only API calls. Names and metadata only: no token value was read, and none belongs in this file.

## 1. The bot user

| Field | Value |
|---|---|
| Name | Kelpie Bot |
| Username | `group_223_bot_b747917a6c6e1334faf2e8ca743faae9` |
| User id | 37 |
| Kind | Group access-token bot (`bot: true`); not a service account, not a human |
| State | active |
| Created | 2026-10-15 |
| Last activity | 2026-10-17 |

A second bot has "kelpie" in its name but belongs to another project: `kelpie-merge-signoff`
(user id 33, project token bot of `ubixsys/ubixshepherd`). It is listed in sections 2 and 3 so it is not mistaken for this one.

## 2. Memberships

| Bot | Where | Role | Membership expires |
|---|---|---|---|
| Kelpie Bot (37) | group `kelpie` (id 223) | Maintainer (40) | never |
| Kelpie Bot (37) | project `ubixsys/ubixkelpie` (id 163) | Maintainer (40), shown by the members listing | never |
| kelpie-merge-signoff (33) | project `ubixsys/ubixshepherd` (id 160) | Developer (30) | never |

The `kelpie` group has no projects of its own that the API shows. How the bot comes to be a member of
`ubixsys/ubixkelpie` was not traced (no group link was found).

## 3. Access tokens

| Bot | Token name | Scopes | Created | Expires | Last used |
|---|---|---|---|---|---|
| Kelpie Bot (group 223) | Kelpie Bot | api, read_repository, write_repository, read_registry, write_registry, ai_features | 2026-10-15 | 2027-10-14 | 2026-10-17 |
| kelpie-merge-signoff (project 160) | kelpie-merge-signoff | api | 2026-10-13 | 2027-10-13 | 2026-10-17 |

All are active and unrevoked. Other project-160 bots (`GITLAB_PROMOTE_TOKEN`, `APPROVAL_CHECK_TOKEN`,
`AI_REVIEW_GITLAB_TOKEN`) are Shepherd's, not Kelpie's, and are not inventoried here.

## 4. Vault locations

| Item | Result |
|---|---|
| Paths and key names holding the Kelpie Bot token | **Not visible.** The `vault` CLI is not installed and no Vault token is available in this environment, so neither vault.dev nor vault.prod was queried. |
| Documented Kelpie secret paths | `kelpie/<env>/{app,db}` (see `CLAUDE.md`); no token key for the bot is documented there. |

## 5. CI variables and policies

| Where | Variable | Masked | Protected | Notes |
|---|---|---|---|---|
| project 163 `ubixkelpie` | `APPROVAL_GITLAB_TOKEN` | yes | yes | hidden; which token it holds was not verified |
| project 163 | `UBIXVAULT_CI_TOKEN_DEV` | yes | yes | Vault CI credential, not a GitLab token |
| project 163 | `UBIXVAULT_CI_TOKEN_PROD` | yes | yes | same |
| project 163 | `MERGE_APPROVAL_OWNERS` | no | yes | not a secret |
| group 223 `kelpie` | none | | | |
| project 160 `ubixshepherd` | `GITLAB_PROMOTE_TOKEN`, `APPROVAL_CHECK_TOKEN` | yes | promote: yes, check: no | belong to Shepherd's bots |

Vault policies (for example `kelpie-ci-ro`) were not read: no Vault access.

## 6. Concerns

- **Expiry:** none within 60 days. Both tokens run to October 2027.
- **Scope breadth:** the Kelpie Bot token has `api` plus registry and `ai_features`. `api` already
  covers repository access, so `read_repository` and `write_repository` add nothing; check whether Kelpie needs
  the registry and `ai_features` scopes at all. The Maintainer role on the whole group is also wide for a bot.
- **`kelpie-merge-signoff`:** its token has `api` scope as Developer on the Shepherd project. Confirm that the sign-off needs more than `read_api`.
- **Unused tokens:** none; both were used on 2026-10-17.
- **Token in more than one place:** unknown. Vault was not readable, and the hidden CI variable
  `APPROVAL_GITLAB_TOKEN` could not be tied to a bot. Someone with Vault access should check that the Kelpie Bot token is stored in one place.
- **Gaps:** the Vault path and key names and the Vault policies still need an inventory by someone with a Vault token.
