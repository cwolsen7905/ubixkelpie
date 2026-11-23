# Settings API

A small HTTP API for reading and editing each project's Kelpie settings: the rows of `projects` that an
operator would otherwise change with SQL (`docs/operations/projects.md`). It exists so a web page, such as
Shepherd's Kelpie page, can show and edit them.

It is on `KelpieApi`, under `/api/v1/projects`. JSON in and out. It does not create or delete projects:
enrolling a project stays an operator step.

## Authentication

Every request carries `Authorization: Bearer <token>`. The token is the environment variable
`KELPIE_SETTINGS_API_TOKEN` (deployed: uBixVault `kelpie/<env>/app`, next to the other app secrets). It is a
different secret from `GITLAB_WEBHOOK_SECRET`; neither opens the other's door. One token, no scopes: it can
read and write.

| Situation | Answer |
| --- | --- |
| Token missing, malformed or wrong | `401`, `WWW-Authenticate: Bearer`, no hint which |
| `KELPIE_SETTINGS_API_TOKEN` not set | `503`: the API fails closed and never lets a request through |

Use a long random value (for example `openssl rand -base64 32`) and send it only over TLS. The token is compared in
constant time and is never logged. To rotate it, change the Vault value and restart; there is no overlap period.

## Endpoints

| Method and path | Does |
| --- | --- |
| `GET /api/v1/projects` | Every project, including `off` ones, in path order: `{"projects": [ ... ]}` |
| `GET /api/v1/projects/{path}` | One project. `404` when it has no row (never an empty `off` project) |
| `PATCH /api/v1/projects/{path}` | Partial update. Answers `200` with the new representation |

`{path}` is the project path with its namespace, URL-encoded: `/api/v1/projects/ubixsys%2Fubixkelpie`.
Any other method on these paths is `405`. Preflight (`OPTIONS`) is answered without a token, as for every route.

## Representation

```json
{
  "path": "ubixsys/ubixkelpie",
  "mode": "propose",
  "settings": {
    "triage_model": null,
    "review_model": "anthropic/claude-sonnet-5.5",
    "max_issues_per_day":        { "soft": 30,     "hard": null },
    "max_issues_per_author_day": { "soft": 10,     "hard": null },
    "max_cost_usd_day":          { "soft": "2.00", "hard": "5.00" },
    "max_fix_commits_per_mr":    { "soft": 3,      "hard": null }
  }
}
```

- `mode` is `off`, `propose` or `push`.
- Every cap is `{soft, hard}`. A soft limit makes Kelpie warn and keep working; a hard limit stops the work.
- **Unlimited is `null`**, on a hard limit. That is what the database holds, so nothing is translated. A soft limit
  always has a number. `0` and `-1` are not "unlimited" anywhere in this API; they are refused.
- A model of `null` means "use Kelpie's default model".
- Spend is a decimal **string** (`"2.00"`) so no binary float is rounded; counts are JSON integers.
- `max_fix_commits_per_mr.hard` is always `null` for now: no column holds it yet. Setting it to anything else is
  refused. When a column is added the shape does not change.
- Not shown and not settable: `gate_command` and `protected_paths`. They widen what the fixer may run or touch, so
  they stay an operator step.

## Editing

`PATCH` takes a JSON object. Only the keys present change; a nested object merges, so
`{"settings": {"max_cost_usd_day": {"hard": "5.00"}}}` leaves the soft limit alone.

```json
{ "mode": "push", "settings": { "max_issues_per_day": { "soft": 40, "hard": null }, "review_model": null } }
```

The whole body is validated first and applied only if every rule holds; a rejected patch changes nothing. A patch
that changes nothing writes nothing. There is no `If-Match` yet, so the last write wins.

| Field | Rule |
| --- | --- |
| `mode` | exactly `off`, `propose` or `push` |
| count caps (`max_issues_per_day`, `max_issues_per_author_day`, `max_fix_commits_per_mr`) | whole JSON number from 1 to 1,000,000; floats and strings are refused |
| `max_cost_usd_day` | decimal string, at most 2 places, above 0, at most `"9999.99"`; a JSON number is refused |
| soft limits | never `null` |
| hard limits | the same range as the soft one, or `null` for unlimited |
| soft and hard | the hard limit must be at least the soft limit, checked on the values the row will have, so setting only one side is checked against the stored other |
| `triage_model`, `review_model` | `null` (default) or a string of 1 to 100 characters with no whitespace. The model is not checked against the provider |
| anything else | refused, so a typo is not silently dropped |

`mode` may be widened (`off` to `propose`, `propose` to `push`) with this token; every change is logged with the
project and the columns it touched.

## Errors

| Status | When | Body |
| --- | --- | --- |
| `400` | the body is not a JSON object | `{"error": "..."}` |
| `401` / `503` | see Authentication | `{"error": "..."}` |
| `404` | no such project | `{"error": "No such project"}` |
| `405` | a method this API does not have | the framework's error body |
| `422` | a rule broke | `{"error": "...", "errors": [{"field": "settings.max_cost_usd_day.hard", "message": "..."}]}` |

All the broken rules are listed together, each by the dotted field it concerns.

## Adding more settings later

The soft and hard limits of further caps are added in `Kelpie\Settings\ProjectSettingsValidator` (one entry in its
cap table), `Kelpie\Settings\ProjectSettingsRepresentation` and the repository's column list. The routes and the
shape of a cap do not change.
