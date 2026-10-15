-- Migration: 20261015120000_create_autofix_jobs
-- Database: kelpie
-- Description: kelpie.autofix_jobs, the MR-thread autofix job state
--              (docs/design/mr-thread-autofix.md section 6): coalescing
--              (one open job per MR and thread) and the caps.
--
--              thread_key is the discussion id, or '' for an MR-level job, so
--              the unique index works without NULLs. open_marker is 1 while a
--              job is pending or running and NULL otherwise; a unique index
--              ignores NULLs, so only open jobs collide.
-- Author: Christopher W. Olsen

CREATE TABLE kelpie.autofix_jobs (
    id                BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    project_path      VARCHAR(255)     NOT NULL,
    mr_iid            INT UNSIGNED     NOT NULL,
    head_sha          CHAR(40)         NOT NULL,
    discussion_id     VARCHAR(64)      NULL,
    thread_key        VARCHAR(64)      NOT NULL DEFAULT '',
    source            VARCHAR(16)      NOT NULL,
    after_autofix     TINYINT(1)       NOT NULL DEFAULT 0,
    status            VARCHAR(16)      NOT NULL DEFAULT 'pending',
    triage_class      VARCHAR(16)      NULL,
    attempt           TINYINT UNSIGNED NOT NULL DEFAULT 0,
    commit_sha        CHAR(40)         NULL,
    open_marker       TINYINT(1)       AS (CASE WHEN status IN ('pending', 'running') THEN 1 ELSE NULL END) STORED,
    date_created      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    date_last_updated DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    -- One open job per MR and thread: the database enforces coalescing.
    UNIQUE KEY uq_open_job (project_path, mr_iid, thread_key, open_marker),
    -- The per-MR and per-day caps.
    KEY idx_project_mr_created (project_path, mr_iid, date_created),
    KEY idx_project_created (project_path, date_created)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
