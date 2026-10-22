-- Migration: 20261022140000_autofix_jobs_steering
-- Database: ubixkelpie
-- Description: ubixkelpie.autofix_jobs.steering is the text after "Triage:" in a reply from the MR author or a
--              maintainer. The worker hands it to the triage and propose prompts when it re-runs the thread.
--              NULL for every other job. source also takes 'apply' and 'decline' for a thumbs-up or thumbs-down
--              on a proposal (VARCHAR(16) already holds them).
-- Author: Christopher W. Olsen

ALTER TABLE ubixkelpie.autofix_jobs
    ADD COLUMN steering TEXT NULL AFTER after_autofix;
