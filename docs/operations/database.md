# Giving Kelpie its database

Runbook for the MariaDB database `kelpie` in each tier (dev, staging, prod), so CI can run
migrations and the pods can read and write. It is modelled on kitg and was checked against
kitg's `.gitlab-ci.yml` (`.migrate`, `.phpunit`), `sql/grants/app.sql`, `bin/lib/notify.sh`,
`app/SowingMeApi/*-deploy.yaml` and `bin/vault-set-key.sh` on 2026-10-15.

**Status today.** Kelpie has no database. The three manifests set `VAULT_DB_STRATEGY=none`;
`.gitlab-ci.yml` has no migrate stage; `HealthController::health` returns a static
`{"status":"ok"}` and never touches a database; `app/KelpieApi/src/Dependencies.php` already
binds `SqlService` to `MysqlPdoSqlService` and the autofix job repositories to
`AutofixJobSqlRepository`; the one migration (`migrations/20261015120000_create_autofix_jobs.sql`,
header `Database: kelpie`) has never been applied by CI (design doc: "applied by hand").

Passwords never go in this repo, in an MR, or in chat. Hosts are written as `<placeholders>`:
no Kelpie database host is documented anywhere yet.

## 1. How the pieces fit

| Who | Reads | From |
|---|---|---|
| CI migrate job | `host`, `port`, `database`, `write_username`, `write_password`, `migration_username`, `migration_password` | uBixVault `secret/<VAULT_KV_PREFIX>/db-<tier>` via the branch's read-only CI token (`vault_kv`) |
| Pod at boot | `MYSQL_*` credentials | the path in `VAULT_DB_KV_PATH` (kitg: `sowingme/<env>/db`, `VAULT_DB_STRATEGY=kv`), after logging in with k8s role `VAULT_K8S_ROLE` |
| Pod, not secret | `MYSQL_WRITE_HOST`, `_PORT`, `_DATABASE` (and `MYSQL_READ_*`) | literal values in `app/KelpieApi/k8s/<branch>-deploy.yaml` |

Two things follow, both easy to get wrong:

- **The migrate host and the pod host come from two different places** (the Vault secret and
  the manifest). kitg's `.migrate` job refuses to run unless they agree
  (`REFUSING TO MIGRATE ... the migration target and its pods disagree`), because a mismatch is
  silent: migrations go green against one server and the app 500s against another.
- **Vault selection is by branch**: `dev` uses `https://vault.dev.ubixsys.com` with
  `UBIXVAULT_CI_TOKEN_DEV`; `staging`, `main`/`master` use `https://vault.prod.ubixsys.com` with
  `UBIXVAULT_CI_TOKEN_PROD` (`vault_for_branch`). Kelpie's manifests already follow this
  (dev → vault.dev; staging and prod → vault.prod).

Tier names: CI tier `dev` / `staging` / `prod`; branch `dev` / `staging` / `main`; manifest
prefix `dev` / `staging` / `main` (note `prod` ↔ `main-deploy.yaml`); namespace
`kelpie-dev` / `kelpie-staging` / `kelpie-prod`.

## 2. Accounts per tier

Two accounts per tier, plus the shared test-database account (section 2.4).

### 2.1 Table

| Account | Used by | On `kelpie.*` | On `SYSTEMS` | Host part |
|---|---|---|---|---|
| **app** (runtime), `<app_user>` | pods; `write_username` in Vault | `SELECT, INSERT, UPDATE, DELETE` | `SELECT, UPDATE (sanitised_at, sanitised_by)` on `Database_Environment` only. Granted by `sql/grants/app.sql`, not by hand. No access to `Schema_Migrations` | the pod/node address range that reaches the server (see 2.2) |
| **migration / DDL**, `<ddl_user>` | CI migrate job; `migration_username` in Vault | `SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, DROP, INDEX, REFERENCES` | `ALL PRIVILEGES` on `SYSTEMS.*` **WITH GRANT OPTION**: creates and writes `Schema_Migrations` and `Database_Environment`, and may pass the app grants on | the CI runner's address range (see 2.2) |

Why `SYSTEMS`: the framework tracks applied migrations in `SYSTEMS.Schema_Migrations` and the
server's declared environment in `SYSTEMS.Database_Environment` (uBixCore migrations doc §11.10).
`migrate:up` creates the latter; the job also runs `CREATE DATABASE IF NOT EXISTS SYSTEMS` as the
DDL user. The app user never touches the tracker. GRANT OPTION is what lets
`sql/grants/app.sql` run: MariaDB only lets you grant what you hold with grant option, so the DDL
user needs the same privileges on `SYSTEMS` that the file hands the app user. Without it kitg's
job warns and continues, and the grants are then applied by hand (`WARNING: App grants NOT applied`).

`DELETE` for the app user is a starting point so that retention and cleanup of
`autofix_jobs` need no further grant; drop it if the code never deletes. Add `LOCK TABLES` or
`CREATE TEMPORARY TABLES` only when a query needs them.

If the person prefers not to give the DDL account `GRANT OPTION`: skip it, and run
`sql/grants/app.sql` by hand as a DB admin after the first migrate (section 3, step 3). The
job still succeeds.

### 2.2 Host part per tier

kitg's values, checked 2026-09-21 and noted in its `.gitlab-ci.yml`: dev account `'%'`,
staging/prod account `'10.50.%'`. Kelpie's servers are not chosen yet, so these are **defaults to
confirm**, not facts about Kelpie:

| Tier | `<app_host>` / `<ddl_host>` default | Confirm |
|---|---|---|
| dev | `%` | the dev server accepts connections from the pods' and runner's addresses |
| staging | `10.50.%` | same range as kitg's staging server, if the same network |
| prod | `10.50.%` | same |

The host part in `sql/grants/app.sql` must match the existing account exactly. A `GRANT` does
not create an account (the job's `APP_DB_HOST` variable carries it, one value per migrate job).

### 2.3 Exact statements

Run as a DB admin on each tier's server (section 3, step 2). Substitute the placeholders;
generate the passwords yourself and put them straight into Vault (step 3), nowhere else.

```sql
-- Database and tracker schema
CREATE DATABASE IF NOT EXISTS kelpie CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS SYSTEMS;

-- Runtime (app) user
CREATE USER IF NOT EXISTS '<app_user>'@'<app_host>' IDENTIFIED BY '<generated-app-password>';
GRANT SELECT, INSERT, UPDATE, DELETE ON kelpie.* TO '<app_user>'@'<app_host>';
-- SYSTEMS.Database_Environment does not exist until the first migrate:up; the
-- migrate job applies sql/grants/app.sql for it afterwards (or run that file by hand).

-- Migration / DDL user
CREATE USER IF NOT EXISTS '<ddl_user>'@'<ddl_host>' IDENTIFIED BY '<generated-ddl-password>';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, DROP, INDEX, REFERENCES
    ON kelpie.* TO '<ddl_user>'@'<ddl_host>';
GRANT ALL PRIVILEGES ON SYSTEMS.* TO '<ddl_user>'@'<ddl_host>' WITH GRANT OPTION;
FLUSH PRIVILEGES;
```

`sql/grants/app.sql` (written by the agents' MR 2, modelled on kitg's, with kitg's
`Pii_Access_Audits` line left out because Kelpie has no such table):

```sql
GRANT SELECT, UPDATE (sanitised_at, sanitised_by)
    ON SYSTEMS.Database_Environment TO '__APP_USER__'@'__APP_HOST__';
```

The job substitutes `__APP_USER__` with the Vault `write_username` and `__APP_HOST__` with
`APP_DB_HOST`. It is idempotent and runs on every deploy.

### 2.4 Test database

kitg's phpunit job uses one shared MariaDB container (`ubixcore-test-db` on the runner host,
shared with ubixcore, docker-bridge address only) and Vault `secret/kitg/test-db` (`host`, `port`,
`username`, `password`, `database`). Its test account may drop and recreate the project's
database and create `SYSTEMS`, so for Kelpie it needs `ALL PRIVILEGES ON kelpie.*` and on
`SYSTEMS.*` on that server. Prefer reusing that account (step 5 in section 3) over inventing a
fourth one; the grant for `kelpie.*` is the only addition.

```sql
-- Test server only, as its admin:
GRANT ALL PRIVILEGES ON kelpie.* TO '<test_user>'@'<test_host>';
```

## 3. The person's steps

Do these in order. Every step is per tier unless noted.

1. **Choose the server per tier.** Decide, per tier, which MariaDB server hosts `kelpie`:
   a new server, or a new database on an existing one (kitg's dev, or its staging/prod server).
   Write the three hosts (and ports) down. They must be reachable from the cluster's pods and
   from the CI runner. Recommendation: one shared non-prod server for dev and staging is fine;
   prod should be separate from non-prod. This is your call (money, blast radius).
2. **Create the database and both users** as a DB admin, with the SQL in 2.3, using the host
   parts from 2.2. Check: `SHOW GRANTS FOR '<app_user>'@'<app_host>';` and the same for the DDL
   user.
3. **Write the Vault secrets** (never in chat, a ticket or an MR; use
   `bin/vault-set-key.sh` from kitg so values are typed at a prompt, with `--any-case` because
   these keys are lower case).
   - **For CI**, KV v2 at `secret/kelpie/db-dev` on vault.dev, and `secret/kelpie/db-staging`,
     `secret/kelpie/db-prod` on vault.prod, each with exactly kitg's fields:
     `host`, `port`, `database` (`kelpie`), `write_username`, `write_password`,
     `migration_username`, `migration_password`.
   - **For the pods**, `kelpie/dev/db` (vault.dev), `kelpie/staging/db`, `kelpie/prod/db`
     (vault.prod), the path the manifests will name in `VAULT_DB_KV_PATH`, holding the
     UPPER_SNAKE_CASE keys the resolver hydrates into environment variables (it skips any other
     case). See open question 1: the exact key set must be confirmed against kitg's
     `sowingme/<env>/db` before writing.
   - **Test database**: `secret/kelpie/test-db` with `host`, `port`, `username`, `password`,
     `database`, on whichever Vault the CI token for `dev` reads (vault.dev), or confirm that
     Kelpie's CI may read kitg's.
   - Host values in the CI secret and in the manifest (`MYSQL_WRITE_HOST`) must be identical:
     the migrate job's cross-check compares them literally.
   - Give CI read access: the branch tokens `UBIXVAULT_CI_TOKEN_DEV` and `UBIXVAULT_CI_TOKEN_PROD`
     must exist in Kelpie's GitLab CI variables (protected, as in kitg) and their Vault policy must
     allow reading `secret/data/kelpie/*`. kitg's `vault_kv` reads `secret/data/<prefix>/<path>`
     with the prefix from `VAULT_KV_PREFIX`; Kelpie's job must set it to `kelpie`.
4. **Create the Vault k8s role** per env. The manifests already name `kelpie-dev`,
   `kelpie-staging` and `kelpie-prod` (`VAULT_K8S_ROLE`) and comment that they are bound to the
   matching namespace. Confirm each role exists, is bound
   (`bound_service_account_namespaces`) to `kelpie-dev` / `kelpie-staging` / `kelpie-prod`, and
   that its policy can read both `kelpie/<env>/app` (already used) and the new `kelpie/<env>/db`.
   Roles live on the Vault the pod logs in to: vault.dev for dev, vault.prod for staging and
   prod. Roles are created in the kubernetes repo's ubixvault configure script (not found in the
   read-only copy on this machine; see open question 3).
5. **Test database**: have the shared test server's admin run the `GRANT` in 2.4 and write
   `secret/kelpie/test-db`.
6. **Check the CI runner can reach each server**: from the runner host (tag `shell`), as the
   runner user:

   ```bash
   mariadb -h <host> -P <port> -u <ddl_user> -p -e 'SELECT 1; SHOW GRANTS;'
   ```

   once per server (dev, staging, prod, and test). Reaching the prod server from the runner
   must also be allowed by the prod firewall. If a server is unreachable, nothing below can
   pass: the migrate job exits at its first connection.

## 4. The agents' steps

One MR each, in this order; each is small and can merge on its own. MR 3 and MR 4 must not
merge before the person has finished steps 1–4 above, or the migrate job blocks the deploy.

1. **MR 1, move the migration**: `git mv migrations/20261015120000_create_autofix_jobs.sql
   sql/migrations/` (kitg's layout; `sql/migrations/.gitkeep` already exists). Delete the old
   `migrations/` directory. No SQL changes. Gate: phpcs, phpstan, phpunit.
2. **MR 2, `sql/grants/app.sql`**: the file in 2.3. Needed because the app user needs
   `Database_Environment` access outside `kelpie.*`. If the person drops the framework's
   environment check, skip this MR and the grant step in MR 3.
3. **MR 3, CI migrate jobs**: add a `migrate` stage between `lint-and-test` and `deploy`.
   `migrate-dev` and `migrate-staging` run automatically on their branches; `migrate-main` (tier
   `prod`) is `when: manual, allow_failure: false`, as in kitg, so the deploy waits for it.
   Preferred source: the uBixCore standard CI template being designed in ubixcore
   `docs/design/ci-pipeline-template.md` (proposal on branch `origin/docs/ci-pipeline-template`,
   not yet in `dev`; it ships `migrate-verify-*`, `migrate-apply-*`, the host cross-check and
   `MIGRATE_TIER`, `APP_DB_HOST`). If that template is not released when this MR is
   picked up, copy kitg's `.migrate` (lines ~417–540) and set `VAULT_KV_PREFIX=kelpie`,
   `APP_DB_HOST` per job, container name `kelpie-migrate-$CI_JOB_ID`, backup dir
   `$HOME/ubix-migration-backups/kelpie`. Keep: `migrate:status` then `migrate:up --target=<tier>`,
   `CREATE DATABASE IF NOT EXISTS SYSTEMS`, the grants file applied as the DDL user, the
   Vault-host-vs-manifest cross-check (matches `MYSQL_WRITE_HOST` in
   `app/KelpieApi/k8s/<prefix>-deploy.yaml`, with the `prod` ↔ `main` prefix mapping), and make
   the deploy jobs `needs` the migrate job. Kelpie's notifier is not wired, so drop the
   `discord` calls.
4. **MR 4, manifests to Vault**: in `app/KelpieApi/k8s/{dev,staging,main}-deploy.yaml` replace
   `VAULT_DB_STRATEGY=none` with `kv`, add `VAULT_DB_KV_PATH` (`kelpie/<env>/db`; `<env>` is
   `dev`, `staging`, `prod`) and the literal env vars `MYSQL_WRITE_HOST`, `MYSQL_WRITE_PORT`,
   `MYSQL_WRITE_DATABASE`, plus `MYSQL_READ_*` pointing at the same server (kitg sets both
   to the same values). Hosts are the ones from step 1; do not guess them. Update the stale
   "Kelpie has no database" comments and the "Migrate ... not wired" paragraph in
   `.gitlab-ci.yml` and `CLAUDE.md`.
5. **MR 5, test database for phpunit**: change the `phpunit` job to read `secret/kelpie/test-db`
   with `vault_kv`, export `TEST_MYSQL_WRITE_*`, `DROP DATABASE IF EXISTS kelpie`,
   `CREATE DATABASE IF NOT EXISTS SYSTEMS`, delete Kelpie's rows from
   `SYSTEMS.Schema_Migrations` (`target_database = 'kelpie'`), then `php bin/ubix
   migrate:up --target=test --yes -n` and `vendor/bin/phpunit`, as kitg's `.phpunit` does.
   A separate MR-pipeline variant that runs phpunit alone (kitg's `phpunit-mr`) is needed
   unless the person un-protects the CI token: protected variables are invisible to
   unprotected branches (kitg finding F14). Either the tests that need a database are skipped
   or marked, or the person provides non-secret CI-only test credentials.
6. **MR 6, make the health check prove the database** (code change, separate from the
   infra MRs): add a cheap `SELECT 1` through `SqlService` to the `/health` response (or a
   separate `/health/db`), with its test under `tests/`. Today `/health` cannot fail on a
   dead database, so it cannot be the verification in section 5. Keep the liveness probe
   itself on the plain route if a database blip must not restart pods. This one needs the
   person's choice (open question 5).

## 5. How to verify

Per tier, after steps 1–4 and MR 1–4 have merged:

1. **Pipeline**: the tier's migrate job is green and its log shows
   `host cross-check OK: <tier> migrates and serves from <host>`, then the migration list, then
   `App grants applied (sql/grants/app.sql).`
2. **Status** (read-only; as the DDL user from a shell that can reach the server, or in the
   migrate job's `migrate:status` output):

   ```bash
   php bin/ubix migrate:status --target=<dev|staging|prod>
   ```

   Expect `20261015120000_create_autofix_jobs` applied and nothing pending. Cross-check with
   `SELECT * FROM SYSTEMS.Schema_Migrations WHERE target_database = 'kelpie';` and
   `SHOW TABLES FROM kelpie;`.
3. **Grants**: as an admin, `SHOW GRANTS FOR '<app_user>'@'<app_host>';` lists exactly
   `kelpie.*` (`SELECT, INSERT, UPDATE, DELETE`) and `SYSTEMS.Database_Environment`, nothing wider.
4. **Pod reaches the DB**: `curl -fsS https://kelpie.<tier>.ubixsys.com/health` returns `ok`
   **and** (after MR 6) its database field; before MR 6 it proves nothing about the database.
   Interim check: `kubectl -n kelpie-<tier> exec deploy/<name> -- php bin/ubix migrate:status
   --target=<tier>` from inside the pod, which uses the pod's own Vault-resolved credentials
   (needs `MYSQL_MIGRATION_*` in the pod; otherwise it falls back to the write user).
5. **A real write**: a webhook that creates an autofix job inserts a row in
   `kelpie.autofix_jobs` (`SELECT status FROM kelpie.autofix_jobs ORDER BY id DESC LIMIT 1;`).
6. **phpunit** (MR 5): the phpunit job's log shows the schema rebuilt and the suite passing.

## 6. Open questions

1. **Pod-side Vault keys.** kitg's pods read `sowingme/<env>/db` (`VAULT_DB_STRATEGY=kv`), while
   the CI job reads `secret/kitg/db-<tier>` with lower-case field names. The pod-side secret's key
   names are decided by uBixCore's `VaultCredentialResolverService`, whose source was not
   available to read for this runbook (vendor not installed). Confirm the key set against
   kitg's live `sowingme/dev/db` (or the framework doc) before step 3. Could the two be one secret?
2. **One server or three?** In kitg, staging and prod once shared a server and schema, and since
   2026-09-27 staging has its own. For Kelpie, choose now; the cross-check works either way but
   `APP_DB_HOST` and the grants depend on it.
3. **Vault k8s roles.** The manifests say they are created by `ubixvault configure.sh` in the
   kubernetes repo, but no such script was found in `~/git/kubernetes` or `~/git/ubixvault` on
   this machine (`ubixvault/internal/api/kubernetes.go` shows only the role API:
   `bound_service_account_namespaces`, `bound_service_account_names`, `policies`, TTL). Who
   creates and owns the roles, and do `kelpie-dev/-staging/-prod` already exist with a policy
   covering `kelpie/<env>/db`?
4. **Which Vault token and prefix does Kelpie's CI use?** `vault_kv` hardcodes the prefix
   default `kitg`. Kelpie needs `VAULT_KV_PREFIX=kelpie` (or the template's equivalent), the
   two `UBIXVAULT_CI_TOKEN_*` variables in its GitLab project, and a policy that reads
   `secret/data/kelpie/*`. Is that already set up?
5. **Health check scope** (MR 6): should `/health` fail when the database is down, or should
   there be a separate readiness route? Affects whether a database outage restarts pods.
6. **DELETE and other grants** for the app user depend on how `autofix_jobs` retention is
   designed; confirm with the autofix design (`docs/design/mr-thread-autofix.md`).
7. **Test database access for MR pipelines** (F14 above), and whether Kelpie may share the
   `ubixcore-test-db` container with kitg and ubixcore.
8. **Destructive migrations and the drift gate.** kitg also runs backup-before-destructive jobs
   and a schema-drift gate (`REPLAY_<db>`). Out of scope for the first database; add them when the
   standard template provides them, and then the DDL user needs `CREATE` on `REPLAY_kelpie`.
9. **Backups and retention** of the production `kelpie` database are not covered here.
