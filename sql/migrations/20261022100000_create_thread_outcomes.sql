-- Migration: 20261022100000_create_thread_outcomes
-- Database: ubixkelpie
-- Description: ubixkelpie.thread_outcomes, one row per review thread Kelpie touched: what it decided
--              (verdict), the mode it acted in, what became of it (outcome), the models and what the
--              calls cost. A new run on the same thread updates its row. The edits and the diff are kept
--              so a later step can apply or check a proposal without asking a model again.
--
--              cost_usd is NULL when the provider reported no cost. Tokens and cost add up both model
--              calls (triage and propose). review_family is the family the models were kept away from.
-- Author: Christopher W. Olsen

CREATE TABLE ubixkelpie.thread_outcomes (
    id                BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    project_path      VARCHAR(255)     NOT NULL,
    mr_iid            INT UNSIGNED     NOT NULL,
    discussion_id     VARCHAR(64)      NOT NULL,
    head_sha          CHAR(40)         NOT NULL,
    verdict           VARCHAR(16)      NOT NULL,
    mode              VARCHAR(16)      NOT NULL DEFAULT 'propose',
    outcome           VARCHAR(16)      NOT NULL,
    reason            VARCHAR(500)     NOT NULL DEFAULT '',
    triage_model      VARCHAR(128)     NOT NULL DEFAULT '',
    propose_model     VARCHAR(128)     NOT NULL DEFAULT '',
    review_family     VARCHAR(64)      NOT NULL DEFAULT '',
    prompt_tokens     INT UNSIGNED     NOT NULL DEFAULT 0,
    completion_tokens INT UNSIGNED     NOT NULL DEFAULT 0,
    cost_usd          DECIMAL(10,6)    NULL,
    proposal_note_id  BIGINT UNSIGNED  NULL,
    edits_json        MEDIUMTEXT       NULL,
    diff              MEDIUMTEXT       NULL,
    date_created      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    date_last_updated DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    -- One record per thread: a later run on it updates the row.
    UNIQUE KEY uq_thread (project_path, mr_iid, discussion_id),
    -- The record per project over time (the evidence for moving a project up a mode).
    KEY idx_project_created (project_path, date_created)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
