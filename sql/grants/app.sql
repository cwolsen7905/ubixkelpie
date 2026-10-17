-- Grants the app user needs outside its own schema (ubixkelpie.*).
--
-- Applied by the migrate-apply-<tier> job after migrate:up, as the tier's DDL account
-- (Vault secret/kelpie/db-<tier>.migration_username), with __APP_USER__ replaced by the app
-- user (write_username) and __APP_HOST__ by UBIX_APP_DB_HOST. Idempotent; runs every deploy.
-- The DDL account holds these privileges WITH GRANT OPTION, which is what lets it pass
-- them on. The app user's rights on ubixkelpie.* are granted once by a DB admin
-- (docs/operations/database.md), not here.

-- The framework reads which environment this server declares itself to be, and marks a
-- sanitised copy, through SYSTEMS.Database_Environment.
GRANT SELECT, UPDATE (sanitised_at, sanitised_by)
    ON SYSTEMS.Database_Environment TO '__APP_USER__'@'__APP_HOST__';
