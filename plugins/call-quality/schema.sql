-- Call quality evidence.
--
-- A plugin owns only its own tables and every one carries the plg_ prefix: the
-- loader refuses a schema.sql that touches sys_* or pbx_* tables, or that
-- contains DROP. So this file creates one table and nothing else; the RTCP
-- counters it reads live on the core signaling stream.
--
-- The counters are stored exactly as Asterisk sent them: loss as RFC 3550's
-- 8-bit fraction is converted to a percentage before it lands here, and jitter
-- stays in RTP timestamp units because the sample rate is not in the report.
--
-- `dedupe_key` is one incident, not one observation: hash(call_id . first_ts)
-- lets a later cron pass upgrade the same row instead of telling the story
-- twice, and lets the six-hour demotion cooldown be read from the evidence
-- rather than from a second place to get out of step.

CREATE TABLE IF NOT EXISTS plg_call_quality_evidence (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    created_at DATETIME NOT NULL,
    call_id VARCHAR(128) NOT NULL DEFAULT '',
    trunk_name VARCHAR(50) NOT NULL DEFAULT '',
    peer VARCHAR(64) NOT NULL DEFAULT '',
    level VARCHAR(8) NOT NULL DEFAULT 'poor',
    loss_pct DECIMAL(5,2) NOT NULL DEFAULT 0,
    jitter INT UNSIGNED NOT NULL DEFAULT 0,
    reports INT UNSIGNED NOT NULL DEFAULT 0,
    first_ts DATETIME NULL DEFAULT NULL,
    last_ts DATETIME NULL DEFAULT NULL,
    evidence_json TEXT NULL,
    action VARCHAR(32) NOT NULL DEFAULT 'none',
    action_detail VARCHAR(500) NOT NULL DEFAULT '',
    source VARCHAR(16) NOT NULL DEFAULT 'cron',
    dedupe_key VARCHAR(64) NOT NULL DEFAULT '',
    PRIMARY KEY (id),
    KEY plg_call_quality_evidence_created_at (created_at),
    KEY plg_call_quality_evidence_call_id (call_id),
    KEY plg_call_quality_evidence_dedupe_key (dedupe_key),
    KEY plg_call_quality_evidence_trunk_action (trunk_name, action)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
