# Per-project settings API (plan)

Status: plan only. No code, no migration and no route exists for it yet. Each item in section 8 is one
merge request.

Today `KelpieApi` answers `GET /health`, `GET /version`, the OPTIONS catch-all and
`POST /gitlab/webhook`. A project's mode, caps and models are columns of `ubixkelpie.projects`, changed
by an operator with SQL (`docs/operations/projects.md`). Shepherd's web UI is to grow a Kelpie
management page, and a browser page cannot run SQL. This plan adds a small, authenticated, audited
settings API that Shepherd can call, without widening what Kelpie may do on its own.

It builds on two other documents: `docs/design/web-ui.md` (the read-only stats API under `/api/v1/`, its
own bearer token, the rule that Kelpie's UI and the CLI share one validating service) and the soft and
hard limits of the open merge request `feat/soft-hard-caps` (!104). Where !104 has not merged, the names
below are what it proposes; the doc is revised if they change.

## 1. Scope

In: list projects, read one, change one project's mode, soft and hard caps and models.

Out of v1: creating or deleting a project row (enrolment stays an operator step; see open question 7),
`gate_command` and `protected_paths` (they widen what the fixer may run or touch, so they stay SQL until
someone asks), and the global kill switch and every credential (env and Vault only, as in the web-ui
plan).

## 2. Endpoints and payloads

All under `/api/v1/`, JSON in and out, errors in the framework's JSON error shape. The project path
contains slashes, so it is sent URL-encoded in the path (`/api/v1/projects/ubixsys%2Fubixkelpie`);
nginx must pass the encoded form through unchanged (checked in item 4 of section 8).

| Method and path | Scope | Does |
| --- | --- | --- |
| `GET /api/v1/projects` | `settings:read` | One entry per enrolled project, with an `ETag` per item inside the body. Paged by `?limit=` (default 50, max 200) and `?after=<path>`. |
| `GET /api/v1/projects/{path}` | `settings:read` | One project. Header `ETag: W/"<version>"`. A path with no row is 404, not an empty `off` project. |
| `PATCH /api/v1/projects/{path}` | `settings:write` | Partial update (JSON merge patch). Requires `If-Match`. Returns the new representation and the new `ETag`. |

No `PUT`, `POST` or `DELETE` in v1. Those methods on these paths answer 405.

### Representation

```json
{
  "path": "ubixsys/ubixkelpie",
  "mode": "propose",
  "version": 7,
  "date_last_updated": "2026-11-23T09:12:44Z",
  "settings": {
    "triage_model": null,
    "review_model": "anthropic/claude-sonnet-5-5",
    "max_issues_per_day":        { "soft": 30,   "hard": null },
    "max_issues_per_author_day": { "soft": 10,   "hard": null },
    "max_cost_usd_day":          { "soft": "2.00", "hard": "5.00" },
    "max_fix_commits_per_mr":    { "soft": 3,    "hard": null },
    "max_fix_rounds_per_thread": { "soft": 2,    "hard": null }
  },
  "effective": {
    "triage_model": "<the deployment default>",
    "review_model": "anthropic/claude-sonnet-5-5"
  }
}
```

`max_fix_rounds_per_thread` appears only if open question 1 is answered yes. Not in the representation,
and so not settable: `gate_command`, `protected_paths`.

### Representing "unlimited"

Three candidates: JSON `null`, an absent key, or a sentinel (`0` or `-1`).

**Pick `null`, on the hard limit only.** Reasons:

- It is what the database holds in !104 (`*_hard` columns, NULL is unlimited), so there is no
  translation layer to get wrong.
- An absent key already means "leave unchanged" in a PATCH, so absence cannot also mean "unlimited".
  `null` is the one value that says "set it to nothing".
- A sentinel is a trap: `0` is a real cap elsewhere in Kelpie (the global spend cap `0` means nothing
  runs), and `-1` invites arithmetic on it.

Soft limits are never unlimited: they always have a number (the stored one, or the default when the
column is NULL). In a PATCH, `"soft": null` means "reset to the default"; the `effective` block and the
GET show what that default is. Models behave the same way: `null` is "use the default", and `effective`
shows the model that will actually run.

### Partial updates and concurrency

- `PATCH` takes `application/merge-patch+json`. Only the keys present change; nested objects merge
  (`{"settings":{"max_cost_usd_day":{"hard":"5.00"}}}` leaves the soft limit alone).
- Optimistic concurrency by a new `projects.version` integer, bumped on every write, exposed as a weak
  `ETag` (`W/"7"`). `PATCH` without `If-Match` is 428; with a stale one it is 412 and the response body
  is the current representation so the caller can show the difference.
  A version column beats hashing the row: it is cheap, readable in the audit log, and the `UPDATE ...
  WHERE path = ? AND version = ?` is the whole lock. Zero affected rows means 412.
- An empty patch, or one that changes nothing, is 200 with no version bump and no audit row.

### Validation

All of it lives in one `ProjectSettingsService`, which the CLI can use too (the web-ui plan's rule that
rules exist once). A rejected patch changes nothing: it is validated whole and applied in one
transaction. Failures answer 422 with a list of `{field, message}`.

| Field | Rule |
| --- | --- |
| `mode` | One of `off`, `propose`, `push` (`Kelpie\Enum\ProjectMode`). Case-sensitive. An unknown value is refused (the read side treating unknown as `off` is a safety net, not an input rule). |
| Count caps | Whole number from 1 to 1,000,000. `0`, negatives, floats and strings are refused; they never mean "off". |
| Spend caps | Decimal string with at most 2 places, greater than 0, at most 9999.99 (`DECIMAL(8,2)`). A JSON number is refused, so no binary-float rounding. |
| Hard vs soft | A hard limit must be at least its soft limit, checked against the resulting values (the stored soft when the patch sets only the hard). Below it the soft warning could never fire (`docs/autofix/caps.md`). |
| Ceiling | The web-ui plan's outside ceiling, if it lands: a soft or hard cap above it is refused. |
| `triage_model`, `review_model` | Non-empty string, at most 100 characters, no whitespace, matching the provider's id shape. A model the provider does not know is not checked here (Kelpie holds no provider client in the API process). `-contributor` models are refused unless the project is public, as the worker already does; the API checks it at write time so the error shows up in the UI and not in a job. |
| Unknown keys | Refused (422), so a typo is not silently dropped. |
| Widening `mode` | `off`→`propose` and `propose`→`push` are writes that widen what Kelpie may do. The web-ui plan wants a confirmation step for these. See the end of section 5.4 and open question 4. |

## 3. Round caps

Today the only per-project cap on repeated fixing is `max_fix_commits_per_mr` (default 3): fix commits
Kelpie may push to one merge request. Separately, the env-set caps in `docs/autofix/caps.md` include
attempts per thread (default 2).

"Round" has three plausible meanings:

1. **Commits per MR** (what exists). Counts pushes, in `push` mode only. A `propose`-only project never
   reaches it.
2. **Rounds per thread**: how many times Kelpie may run the propose-then-revise loop on one review
   thread (a reply from a person triggers another round). Counts in `propose` as well, because a
   proposal is a model run that costs money. This is what the existing attempts-per-thread cap already
   bounds, but only through an env var for the whole deployment.
3. **Rounds per MR**: all threads together, the number of automatic passes before Kelpie stops and hands
   the MR to a person.

**Is it the same as `max_fix_commits_per_mr`?** No. Commits bound what lands on the branch, rounds bound
how long a conversation Kelpie keeps having and how much it spends on it. They diverge: a thread can take
three rounds and end in one commit, or in none in `propose`.

**Recommendation:** define a round as meaning 2, one autofix run on one thread, and expose it as
`max_fix_rounds_per_thread`, a per-project soft and hard pair that overrides the deployment-wide
attempts-per-thread default. Keep `max_fix_commits_per_mr` as the separate push cap. Do not build meaning
3 until someone wants it; the per-MR job cap already bounds an MR's total runs. This needs two nullable
columns (soft, hard) and a small change in the cap service to read the project value first.
This is open question 1.

Independent of that answer, `max_fix_commits_per_mr` has a hard limit only through an env variable in
!104, with no column. The API either exposes it as soft-only, or the same migration that adds the
`version` column adds `max_fix_commits_per_mr_hard`. Recommendation: add the column, so every cap in the
API has the same `{soft, hard}` shape.

## 4. Data this needs

- `projects.version INT UNSIGNED NOT NULL DEFAULT 1` (bumped by the service on each write).
- `max_fix_commits_per_mr_hard` (and the two round columns if question 1 is yes), nullable.
- `settings_api_tokens` (section 5.2) and `settings_audit` (section 5.6).

Migrations follow the repo's rules (`sql/migrations/`, header, `AlterAck` for each ALTER). The `version`
column has a non-null default, so on a small table it is instant; the migration says so.

## 5. How Shepherd authenticates to Kelpie

### 5.1 Options

| | A. Service bearer token | B. mTLS in the cluster | C. HMAC-signed requests |
| --- | --- | --- | --- |
| What proves the caller | A secret in `Authorization: Bearer` | A client certificate | A signature over method, path, body and timestamp |
| Secret exposure on the wire | Sent every request; safe only under TLS | None | None (the key never travels) |
| Replay | A captured token works until revoked; TLS makes capture hard | Not replayable without the private key | Prevented by timestamp and nonce, which Kelpie must track |
| Fits where Shepherd runs | Anywhere with TLS | Needs a certificate authority, issuance, renewal, and something terminating mTLS (ingress or mesh); awkward for a daemon on a workstation | Anywhere |
| Rotation | Two valid tokens; no downtime | Reissue certificates; revocation lists | Two valid keys; no downtime |
| New moving parts | A table, a middleware, a Vault entry | A CA and its operations | Canonicalisation rules both sides must agree on byte for byte; the largest source of bugs |
| Fits what uBixSys already runs | Yes: Vault secrets, the webhook's constant-time compare | No mesh or CA is part of Kelpie's stack today | No shared helper in uBixCore found |

**Recommendation: A, a per-client service bearer token.** It is the least new machinery and matches
what the stack already does for the webhook. Its one weakness, a replayable captured credential, is
covered by TLS on every hop, by keeping the API off the public ingress (5.4), by scopes (5.3), by a
short rotation interval and by the audit log. mTLS is the better answer if Kelpie and Shepherd ever
share a service mesh; HMAC pays its complexity only where TLS cannot be trusted, which is not the case
here. Both stay noted as upgrades behind the same middleware (the token check is one authenticator
among possible others).

### 5.2 The token, in detail

- **Separate credentials.** Not the GitLab webhook secret, not the stats API token of the web-ui plan,
  not the OAuth client secret. One leaked secret then opens one door. The webhook secret and the stats
  token are refused on settings routes, and a settings token is refused on the webhook and the stats
  routes (tests for each).
- **One token per client**, named (`shepherd`), so each is revocable and each audit row says which
  client acted. v1 has one client.
- **Generation.** At least 32 random bytes from a CSPRNG, shown once as a prefixed string
  (`klp_` plus base64url) so secret scanners can find a leaked one.
- **Storage in Kelpie: hashed.** A table `settings_api_tokens` holds `client`, `slot` (1 or 2),
  `token_sha256`, `scopes`, `date_created`, `date_expires` (nullable), `date_revoked`. A fast hash
  (SHA-256) is right here, not a password hash, because the token is high-entropy random, not
  human-chosen; there is nothing to brute-force, and the check runs on every request. Compare with a
  constant-time function on the digest of the presented token. The plaintext is never in the database
  or a log.
- **Storage elsewhere.** The plaintext lives in uBixVault, in a path the maintainer chooses for Shepherd
  (do not guess it; see open question 8), and is read by Shepherd only. The hash is inserted by an
  operator command (`kelpie:token:create`, which prints the plaintext once and stores the hash) so
  Kelpie's database never sees anything it cannot verify with.
- **Zero-downtime rotation (two valid tokens).** A client has up to two live rows. Rotation:
  1. `kelpie:token:create --client shepherd` adds slot 2 while slot 1 is live; both authenticate.
  2. Put the new plaintext in Vault; Shepherd picks it up (its config re-read or restart).
  3. When Shepherd's audit rows show only the new token's slot, `kelpie:token:revoke` sets
     `date_revoked` on the old slot. Revoked rows are kept for the audit trail.
  Each audit row records the slot, so step 3 is a check, not a guess. A token past `date_expires` is
  refused; the default expiry is 90 days and the rotation is a calendar item, not a crisis.
- **Failure behaviour.** No header, an unknown token, a revoked or expired one: 401 with no hint as to
  which, a `WWW-Authenticate: Bearer` header and a log line with the client IP and the token's
  non-secret prefix. If the token table cannot be read the API answers 503, never allows.

### 5.3 Scopes

Two scopes: `settings:read` (the two GETs) and `settings:write` (PATCH, which also implies reading the
current state to apply a patch). A token carries a set of scopes; Shepherd's panel that only displays
could use a read-only token, and the web-ui plan's stats token stays a third, separate thing. A write
route checks the scope in the middleware, before the controller. A missing scope is 403, a missing or bad
credential is 401.

### 5.4 Keeping it cluster-internal

The current ingress for each tier sends the whole host, path `/`, to `kelpie-api`, so a new route would be
on the public hostname by default. Layers, in order of strength:

1. **Network policy.** A `NetworkPolicy` for `kelpie-api` that allows ingress to the API port from the
   ingress controller (for the webhook) and from the namespace or addresses where Shepherd runs.
   Caveat: the policy works at the pod, not the path, so it cannot by itself hide `/api/` from the ingress
   controller.
2. **Ingress path block.** The ingress (or nginx location in `config/devops/nginx.conf`) returns 404 for
   `/api/` when the request came through the public hostname, so only in-cluster callers using the
   service name reach it. Webhook and health stay as they are.
3. **The token** (5.2), which still has to be right even if 1 and 2 fail: the network is not the only
   defence.

**Where Shepherd runs decides this.** Shepherd is described as a local orchestrator. If its daemon runs
in the cluster, the service name plus items 1 and 2 are enough. If it runs on a workstation or a host
outside the cluster, in-cluster access is not available, and the choices are a source-IP allow-list on a
separate, non-public ingress for `/api/`, or a VPN. This is open question 3, and the doc's
recommendation holds either way: token plus no public exposure.

The widening confirmation of the web-ui plan (a person confirms before `propose`→`push`) cannot be
enforced by a bearer token alone, since the token identifies Shepherd, not a person. Recommendation:
the API refuses any `mode` change that widens autonomy for a token without an extra scope
`settings:widen`, which no token has in v1. Shepherd's UI can narrow modes and edit caps; widening stays
an operator step until the person decides otherwise (open question 4, matching the web-ui plan's own
recommendation that the UI does not set `push`).

### 5.5 Rate limiting

A write is a human clicking, so the limits are loose but stop a runaway loop or a stolen token:

- Per token: 60 requests a minute, 10 writes a minute, answer 429 with `Retry-After`.
- Per source IP before authentication: 30 failed credential checks a minute, so guessing is slowed
  (the token's entropy already makes guessing infeasible; this is to protect the log and the table read).
- Counters live in the database or in process memory per pod. A per-pod counter multiplies the limit by
  the number of pods; acceptable at this size, noted rather than solved. Check first whether uBixCore
  already ships a limiter and use it; add it upstream if not (the pinned release is checked in item 1).

### 5.6 Audit log

Every write, and every refused write, leaves a row in an append-only `settings_audit` table: `id`,
`date_created`, `client` and `token_slot`, `actor` (below), `project_path`, `field`, `old_value`,
`new_value` (JSON), `version_before`, `version_after`, `request_id`, `result` (`applied`, `refused`),
`reason` (for refusals). One row per changed field, so "who moved the hard spend cap from null to 5.00"
is a single-row lookup. Kelpie's database user for the app has `INSERT` only on this table (no `UPDATE`
or `DELETE`), if the database grants allow it; otherwise the rule is enforced in code and a test.
Reads are not audited row by row, they are in the access log; failed authentications are logged with
the client IP.

Who: the token identifies the client (Shepherd), but Shepherd acts for a person. Shepherd sends the
person's GitLab login in an `X-Acting-User` header on writes; Kelpie records it as `actor` and states in
the row that it is *asserted by the client, not verified*. Since Shepherd is trusted with the token,
trusting its attribution is the same trust. Writes without the header are refused (422).
Old and new values hold only settings, never secrets, so the table is safe to show in a UI.

## 6. How Shepherd uses it

Describes the Shepherd side; the work there is its own lane and repo.

- **Config keys** (names are a proposal for Shepherd to adopt): `kelpie.base_url` (the in-cluster service
  URL or the internal hostname), `kelpie.token` (read from uBixVault at start, never from the
  repository or the browser), `kelpie.timeout_seconds` (default 5), `kelpie.enabled` (false when
  Kelpie is not configured, so the page says so).
- **Proxy through Shepherd's daemon.** The browser talks only to Shepherd's own backend, behind Shepherd's
  own sign-in and CSRF protection. The backend calls Kelpie with the token. The token is never sent to the
  browser, put in a page, a cookie, a log or an error message. Shepherd's backend maps the signed-in
  person to `X-Acting-User`.
- **Shape of the calls.** The page loads a project list with `GET`, stores each item's `version`, and
  sends it back as `If-Match` on save. On 412 it shows the current values and asks the person to reapply.
  On 401 or 403 it shows "Kelpie refused Shepherd's credentials", not a retry loop. On a timeout or 5xx
  it shows "Kelpie unreachable" and no stale numbers, as the stats panel already does.
- **Shepherd decides which of its users may call the write routes** (its own allow-list); Kelpie's token
  scope is the outer limit, not the only one.
- **Review bots page.** The existing page keeps working untouched when `kelpie.enabled` is false.

## 7. Compatibility with what exists

- The webhook, health, version and CORS behaviour do not change. The new routes have their own
  middleware group; no existing route becomes authenticated by accident.
- The worker and the webhook already re-read `projects` on every pass, so a patch applies without a
  restart (`docs/operations/projects.md`). Env-set caps still apply as defaults and are not editable
  here; the representation shows only the project's own values and the `effective` models.
- SQL edits by an operator remain possible. They do not bump `version`, so the next PATCH after a manual
  edit could miss a conflict. Mitigation: a trigger or, more simply, the operations doc says to bump
  `version` in the same statement; the service also compares the full row it read, not only the version
  (open question 6 asks whether that is enough).
- This plan relies on !104. Items that touch the caps wait for it to merge.

## 8. Implementation items

Each is one merge request with its tests, written before the code, ending green on
`vendor/bin/phpcs && vendor/bin/phpstan && vendor/bin/phpunit`. Order matters.

| # | Item | Repo | Done when |
| --- | --- | --- | --- |
| 1 | **Check uBixCore** for a bearer-auth middleware, a rate limiter and a constant-time token helper; if missing, add them upstream and bump the pin | uBixCore, then Kelpie (pin bump) | a note in the MR says what the pinned release ships; no local shim |
| 2 | **Migration and readers:** `projects.version`, `max_fix_commits_per_mr_hard`, round columns if question 1 is yes; the `Project` DTO and reader/writer methods; after !104 merges | Kelpie | repository tests cover version bump and 0-row update |
| 3 | **Token store and middleware:** `settings_api_tokens`, `kelpie:token:create` and `kelpie:token:revoke`, the bearer middleware with scopes, two live tokens, expiry; routing for `/api/v1/` with the encoded path; 401 / 403 / 503 behaviour | Kelpie | webhook secret and stats token refused; two tokens both work, a revoked one fails; no plaintext in DB or logs (a test greps) |
| 4 | **Read endpoints:** `GET /api/v1/projects` and `/{path}`, ETag, paging, `effective` values | Kelpie | encoded slashes in a path work through nginx; unknown path is 404 |
| 5 | **ProjectSettingsService and PATCH:** validation table of section 2, merge patch, `If-Match`, one transaction; writes the audit row; the `settings_audit` migration | Kelpie | stale `If-Match` is 412, missing is 428, a hard below soft is 422, a patch with an unknown key changes nothing, each change leaves one audit row with actor and old→new |
| 6 | **Exposure and limits:** ingress/nginx block of `/api/` on the public host, `NetworkPolicy`, rate limiting, manifests for each tier | Kelpie (manifests) | a request to `/api/` through the public hostname is 404 on dev; the limit answers 429 |
| 7 | **Round caps** (only if question 1 says yes): cap service reads the project's value; docs | Kelpie | a thread stops at its hard limit and warns at its soft one |
| 8 | **Operations docs:** token creation and rotation runbook, `docs/operations/projects.md` points at the API, SQL edits bump `version` | Kelpie | rotation steps run on dev without a failed request |
| 9 | **Shepherd client:** config keys, a Kelpie client with timeouts and the error mapping of section 6, a daemon proxy route | Shepherd | token never appears in a browser response (a test); unreachable shows no numbers |
| 10 | **Shepherd page:** the Kelpie management page: list, edit with `If-Match`, 412 handling | Shepherd | an edit lands in Kelpie and in its audit; a conflict is shown, not overwritten |

Items 1 to 5 are the minimum for a working read-write API; 6 must land before any tier with a real
token is reachable from outside. Items 9 and 10 are tracked in Shepherd (its item 357 is the page).

## 9. Open questions for the person

1. **What is a "round cap"?** *Recommendation:* one autofix run on one thread, exposed per project as
   `max_fix_rounds_per_thread` (soft and hard), separate from `max_fix_commits_per_mr`; skip a per-MR
   round cap. Or say it is just the commit cap and nothing is added.
2. **Unlimited as `null` on hard limits?** *Recommendation:* yes, section 2.
3. **Where does Shepherd's daemon run: in the cluster, or outside?** It decides between "service name
   plus network policy" and "a separate non-public ingress with an IP allow-list or a VPN".
   *Recommendation:* in-cluster access if it can run there; otherwise the allow-listed internal ingress.
4. **May the API widen a project's mode** (`off`→`propose`, `propose`→`push`)? *Recommendation:* no in
   v1; needs a `settings:widen` scope nobody holds, so widening stays an operator step, narrowing and
   caps are allowed.
5. **Bearer token (A) as the auth method**, with mTLS and HMAC kept as later upgrades?
   *Recommendation:* yes.
6. **Manual SQL edits and `version`:** is "the doc says to bump it, and the service compares the row" good
   enough, or should a database trigger bump it? *Recommendation:* the doc plus the row comparison;
   a trigger is more machinery than a rare manual edit warrants.
7. **Should the API ever create a project** (enrol)? *Recommendation:* not in v1; it is the web-ui
   plan's last phase and has its own rules.
8. **Vault path and rotation period for Shepherd's token.** I did not choose a path; it is yours to set.
   *Recommendation:* a path of its own under the existing per-tier Vault area (not the app secret), and
   90 days.
9. **Order against the web-ui plan:** the stats API (its phase 1) and this one share the middleware,
   routing prefix and token table. *Recommendation:* build item 1 and 3 once for both, stats first if
   Shepherd's panel is the nearer need, since it is read-only.
