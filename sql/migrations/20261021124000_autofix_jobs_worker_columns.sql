-- Migration: 20261021124000_autofix_jobs_worker_columns
-- Database: ubixkelpie
-- Description: ubixkelpie.autofix_jobs becomes the queue `kelpie:work` consumes.
--              next_attempt_at is when a retried job is next due, locked_by and
--              locked_until are the worker's lease (an expired lease is taken over),
--              last_error is why the last attempt failed. issue_iid is set on issue
--              triage rows only (they use mr_iid 0), so uq_issue_job never touches MR rows.
-- Author: Christopher W. Olsen

ALTER TABLE ubixkelpie.autofix_jobs
    ADD COLUMN issue_iid       INT UNSIGNED NULL AFTER mr_iid,
    ADD COLUMN next_attempt_at DATETIME     NULL AFTER attempt,
    ADD COLUMN locked_until    DATETIME     NULL AFTER next_attempt_at,
    ADD COLUMN locked_by       VARCHAR(64)  NULL AFTER locked_until,
    ADD COLUMN last_error      VARCHAR(255) NULL AFTER locked_by,
    ADD UNIQUE KEY uq_issue_job (project_path, issue_iid, source),
    ADD KEY idx_claim (status, next_attempt_at);
