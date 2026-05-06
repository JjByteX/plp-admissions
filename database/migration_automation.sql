-- ============================================================
-- Migration: Automation Features
-- Adds tables and columns for:
--   - In-app notifications
--   - Document auto-validation logs
--   - Automation settings
-- ============================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET FOREIGN_KEY_CHECKS = 0;

-- ============================================================
-- 1. Notifications table (in-app notifications)
-- ============================================================
CREATE TABLE IF NOT EXISTS `notifications` (
    `id`           BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`      INT(10) UNSIGNED    NOT NULL,
    `type`         VARCHAR(80)         NOT NULL
                   COMMENT 'e.g. docs_approved, exam_slot_assigned, result_released',
    `title`        VARCHAR(255)        NOT NULL,
    `message`      TEXT                DEFAULT NULL,
    `link`         VARCHAR(500)        DEFAULT NULL
                   COMMENT 'URL path to navigate to when clicked',
    `is_read`      TINYINT(1)          NOT NULL DEFAULT 0,
    `created_at`   DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_notif_user`    (`user_id`, `is_read`),
    KEY `idx_notif_created` (`created_at`),
    CONSTRAINT `fk_notif_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 2. Document validation logs (OCR / AI results)
-- ============================================================
CREATE TABLE IF NOT EXISTS `document_validations` (
    `id`              BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    `document_id`     INT(10) UNSIGNED    NOT NULL,
    `validation_type` ENUM('ocr','ai','file_check') NOT NULL DEFAULT 'file_check',
    `status`          ENUM('passed','failed','uncertain') NOT NULL,
    `confidence`      DECIMAL(5,2)        DEFAULT NULL
                      COMMENT 'Confidence score 0-100',
    `details`         TEXT                DEFAULT NULL
                      COMMENT 'JSON details of validation result',
    `validated_at`    DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_dv_document` (`document_id`),
    CONSTRAINT `fk_dv_document`
        FOREIGN KEY (`document_id`) REFERENCES `documents` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 3. Add auto_validated flag to documents table
-- ============================================================
-- ALTER TABLE `documents` ADD COLUMN IF NOT EXISTS
--   `auto_validated` TINYINT(1) NOT NULL DEFAULT 0
--   COMMENT '1 = passed automatic validation (OCR/AI)'
--   AFTER `staff_remarks`;

-- ============================================================
-- 4. Automation-related school settings
-- ============================================================
INSERT IGNORE INTO `school_settings` (`setting_key`, `setting_value`) VALUES
    ('auto_validate_documents', '1'),
    ('auto_assign_exam_slots',  '1'),
    ('auto_promote_waitlist',   '1'),
    ('auto_reschedule_noshows', '1'),
    ('auto_release_results',    '0'),
    ('idle_applicant_days',     '7'),
    ('doc_reminder_days',       '3');

SET FOREIGN_KEY_CHECKS = 1;
