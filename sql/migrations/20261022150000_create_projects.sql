-- Migration: 20261022150000_create_projects
-- Database: ubixkelpie
-- Description: ubixkelpie.projects, one row per project Kelpie may act on and how far (docs/operations/projects.md).
--              mode is off, propose or push; a project with no row is off, and a mode the code does not know
--              reads as off. The other columns are per-project settings; NULL means Kelpie's default.
--              The table is created empty: enrolling a project is an operator step.
-- Author: Christopher W. Olsen

CREATE TABLE ubixkelpie.projects (
    id                        INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    path                      VARCHAR(255)  NOT NULL,
    mode                      VARCHAR(16)   NOT NULL DEFAULT 'off',
    gate_command              TEXT          NULL,
    protected_paths           TEXT          NULL,
    triage_model              VARCHAR(128)  NULL,
    review_model              VARCHAR(128)  NULL,
    max_issues_per_day        INT UNSIGNED  NOT NULL DEFAULT 30,
    max_issues_per_author_day INT UNSIGNED  NOT NULL DEFAULT 10,
    max_cost_usd_day          DECIMAL(8,2)  NOT NULL DEFAULT 2.00,
    max_fix_commits_per_mr    INT UNSIGNED  NOT NULL DEFAULT 3,
    date_created              DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    date_last_updated         DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_project_path (path),
    KEY idx_mode (mode)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
