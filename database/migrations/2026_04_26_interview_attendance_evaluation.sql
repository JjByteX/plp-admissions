-- ============================================================
-- Migration: 2026-04-26 — Interview attendance & evaluation
-- ============================================================
-- Adds:
--   * interview_queue.attendance_status   ENUM('present','absent') NULL
--   * interview_queue.evaluation_result   ENUM('pass','fail')      NULL
--   * interview_queue.interview_status    ENUM('pending','completed','absent','rescheduled')
--   * reschedule_logs — history of every reschedule action
--
-- Safe to re-run (uses ADD COLUMN IF NOT EXISTS / CREATE TABLE
-- IF NOT EXISTS, MariaDB 10.2+ / MySQL 8+).
-- ============================================================

-- ------------------------------------------------------------
-- interview_queue — attendance + evaluation + lifecycle status
-- ------------------------------------------------------------
ALTER TABLE `interview_queue`
    ADD COLUMN IF NOT EXISTS `attendance_status`
        ENUM('present','absent') NULL DEFAULT NULL
        COMMENT 'Filled in by staff at interview time' AFTER `interview_notes`,
    ADD COLUMN IF NOT EXISTS `evaluation_result`
        ENUM('pass','fail') NULL DEFAULT NULL
        COMMENT 'Only meaningful when attendance_status = present' AFTER `attendance_status`,
    ADD COLUMN IF NOT EXISTS `interview_status`
        ENUM('pending','completed','absent','rescheduled') NOT NULL DEFAULT 'pending'
        COMMENT 'End-to-end lifecycle state' AFTER `evaluation_result`,
    ADD COLUMN IF NOT EXISTS `evaluated_by`  INT(10) UNSIGNED NULL AFTER `interview_status`,
    ADD COLUMN IF NOT EXISTS `evaluated_at`  DATETIME         NULL AFTER `evaluated_by`,
    ADD INDEX IF NOT EXISTS `idx_iq_interview_status` (`interview_status`),
    ADD INDEX IF NOT EXISTS `idx_iq_attendance`       (`attendance_status`);

-- Backfill interview_status for any pre-existing rows so indexes and
-- filters behave correctly.
UPDATE `interview_queue`
SET `interview_status` = CASE
    WHEN `status` = 'completed' THEN 'completed'
    WHEN `status` = 'no_show'   THEN 'absent'
    ELSE 'pending'
END
WHERE `interview_status` IS NULL OR `interview_status` = 'pending';

-- ------------------------------------------------------------
-- reschedule_logs — append-only history of rescheduling actions
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `reschedule_logs` (
    `id`               INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `applicant_id`     INT(10) UNSIGNED NOT NULL,
    `from_slot_id`     INT(10) UNSIGNED NULL,
    `to_slot_id`       INT(10) UNSIGNED NULL,
    `from_slot_date`   DATE             NULL,
    `from_slot_time`   TIME             NULL,
    `reason`           VARCHAR(255)     NOT NULL DEFAULT 'absent',
    `rescheduled_by`   INT(10) UNSIGNED NULL,
    `rescheduled_at`   DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_rl_applicant` (`applicant_id`),
    KEY `idx_rl_from_slot` (`from_slot_id`),
    KEY `idx_rl_to_slot`   (`to_slot_id`),
    CONSTRAINT `fk_rl_applicant`
        FOREIGN KEY (`applicant_id`) REFERENCES `applicants` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_rl_from_slot`
        FOREIGN KEY (`from_slot_id`) REFERENCES `interview_slots` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_rl_to_slot`
        FOREIGN KEY (`to_slot_id`)   REFERENCES `interview_slots` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
