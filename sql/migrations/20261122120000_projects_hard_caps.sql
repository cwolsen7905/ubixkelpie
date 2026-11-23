-- Migration: 20261122120000_projects_hard_caps
-- Database: ubixkelpie
-- Description: ubixkelpie.projects gains a hard cap for each settable limit. The existing columns are the soft
--              caps (Kelpie warns and keeps working); a hard cap, when set, stops the work. NULL is unlimited,
--              so a project with no hard cap set behaves exactly as before.
-- Author: Christopher W. Olsen
-- AlterAck: the three columns are nullable with no default, so the ALTER is instant on any table size.

ALTER TABLE ubixkelpie.projects
    ADD COLUMN max_cost_usd_day_hard          DECIMAL(8,2)  NULL AFTER max_cost_usd_day,
    ADD COLUMN max_issues_per_day_hard        INT UNSIGNED  NULL AFTER max_issues_per_day,
    ADD COLUMN max_issues_per_author_day_hard INT UNSIGNED  NULL AFTER max_issues_per_author_day;
