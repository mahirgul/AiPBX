-- Auto Dialer Plugin Database Schema (plg_* tables only)

CREATE TABLE IF NOT EXISTS `plg_autodial_campaigns` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(120) NOT NULL,
    `status` VARCHAR(20) NOT NULL DEFAULT 'paused',
    `caller_id` VARCHAR(64) NOT NULL DEFAULT '',
    `max_concurrent` INT NOT NULL DEFAULT 2,
    `queue_free_agent_pacing` TINYINT(1) NOT NULL DEFAULT 1,
    `max_retries` INT NOT NULL DEFAULT 2,
    `retry_interval_sec` INT NOT NULL DEFAULT 300,
    `amd_enabled` TINYINT(1) NOT NULL DEFAULT 1,
    `amd_machine_action` VARCHAR(20) NOT NULL DEFAULT 'hangup',
    `amd_machine_sound` VARCHAR(160) NOT NULL DEFAULT '',
    `dest_type` VARCHAR(32) NOT NULL DEFAULT 'queue',
    `dest_id` VARCHAR(64) NOT NULL DEFAULT '',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `plg_autodial_numbers` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `campaign_id` INT NOT NULL,
    `phone_number` VARCHAR(40) NOT NULL,
    `contact_name` VARCHAR(120) NOT NULL DEFAULT '',
    `notes` VARCHAR(255) NOT NULL DEFAULT '',
    `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
    `attempts` INT NOT NULL DEFAULT 0,
    `call_result` VARCHAR(32) NOT NULL DEFAULT '',
    `amd_status` VARCHAR(24) NOT NULL DEFAULT '',
    `amd_cause` VARCHAR(64) NOT NULL DEFAULT '',
    `duration` INT NOT NULL DEFAULT 0,
    `uniqueid` VARCHAR(64) NOT NULL DEFAULT '',
    `last_attempt_at` DATETIME DEFAULT NULL,
    `next_attempt_at` DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_camp_status` (`campaign_id`, `status`, `next_attempt_at`),
    INDEX `idx_phone` (`phone_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
