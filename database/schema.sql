-- ============================================================
-- PLP Student Admission & Management System
-- Database Schema — synced to live DB
-- Pamantasan ng Lungsod ng Pasig
-- ============================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+08:00";

-- ------------------------------------------------------------
-- users
-- ------------------------------------------------------------
CREATE TABLE `users` (
    `id`            INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`          VARCHAR(120)     NOT NULL,
    `first_name`    VARCHAR(100)     NOT NULL DEFAULT '',
    `middle_name`   VARCHAR(100)     NOT NULL DEFAULT '',
    `last_name`     VARCHAR(100)     NOT NULL DEFAULT '',
    `suffix`        VARCHAR(20)      NOT NULL DEFAULT '',
    `birthdate`     DATE             DEFAULT NULL,
    `sex`           ENUM('M','F')    DEFAULT NULL,
    `address`       VARCHAR(255)     NOT NULL DEFAULT '',
    `phone`         VARCHAR(20)      NOT NULL DEFAULT '',
    `email`         VARCHAR(180)     NOT NULL,
    `password_hash` VARCHAR(255)     NOT NULL,
    `role`          ENUM('student','staff','admin') NOT NULL DEFAULT 'student',
    `is_active`     TINYINT(1)       NOT NULL DEFAULT 1,
    `desk_label`    VARCHAR(120)     NOT NULL DEFAULT '',
    `desk_notes`    TEXT             DEFAULT NULL,
    `created_at`    DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- applicants
-- ------------------------------------------------------------
CREATE TABLE `applicants` (
    `id`              INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`         INT(10) UNSIGNED NOT NULL,
    `applicant_type`  ENUM('freshman','transferee','foreign') NOT NULL,
    `course_applied`  VARCHAR(120)     NOT NULL,
    `shs_strand`      VARCHAR(60)      DEFAULT NULL COMMENT 'SHS strand key (freshmen only)',
    `overall_status`  ENUM('pending','documents','submitted','exam','interview','released') NOT NULL DEFAULT 'pending',
    `school_year`     VARCHAR(9)       NOT NULL COMMENT 'e.g. 2024-2025',
    `created_at`      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_user_id`        (`user_id`),
    KEY `idx_school_year`    (`school_year`),
    KEY `idx_overall_status` (`overall_status`),
    CONSTRAINT `fk_applicants_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- documents
-- ------------------------------------------------------------
CREATE TABLE `documents` (
    `id`            INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `applicant_id`  INT(10) UNSIGNED NOT NULL,
    `doc_type`      VARCHAR(80)      NOT NULL COMMENT 'slug key e.g. psa_birth_cert',
    `file_path`     VARCHAR(500)     DEFAULT NULL,
    `status`        ENUM('pending','uploaded','under_review','approved','rejected') NOT NULL DEFAULT 'pending',
    `staff_remarks` TEXT             DEFAULT NULL,
    `reviewed_by`   INT(10) UNSIGNED DEFAULT NULL,
    `updated_at`    DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_applicant_id` (`applicant_id`),
    KEY `idx_status`       (`status`),
    KEY `idx_reviewed_by`  (`reviewed_by`),
    CONSTRAINT `fk_documents_applicant` FOREIGN KEY (`applicant_id`) REFERENCES `applicants` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_documents_reviewer`  FOREIGN KEY (`reviewed_by`)  REFERENCES `users`      (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- exams
-- ------------------------------------------------------------
CREATE TABLE `exams` (
    `id`               INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `title`            VARCHAR(160)     NOT NULL,
    `description`      TEXT             DEFAULT NULL,
    `duration_minutes` SMALLINT(6)      NOT NULL DEFAULT 60,
    `passing_score`    SMALLINT(6)      DEFAULT NULL,
    `shuffle_questions` TINYINT(1)      NOT NULL DEFAULT 0,
    `shuffle_choices`  TINYINT(1)       NOT NULL DEFAULT 0,
    `is_active`        TINYINT(1)       NOT NULL DEFAULT 0,
    `created_at`       DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `scheduled_start`  DATETIME         DEFAULT NULL,
    `scheduled_end`    DATETIME         DEFAULT NULL,
    `access_password`  VARCHAR(255)     DEFAULT NULL,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- exam_sections
-- ------------------------------------------------------------
CREATE TABLE `exam_sections` (
    `id`            INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `exam_id`       INT(10) UNSIGNED NOT NULL,
    `title`         VARCHAR(255)     NOT NULL,
    `question_type` VARCHAR(50)      NOT NULL DEFAULT 'multiple_choice',
    `sort_order`    INT(11)          NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    KEY `idx_exam_id` (`exam_id`),
    CONSTRAINT `fk_sections_exam` FOREIGN KEY (`exam_id`) REFERENCES `exams` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- questions
-- ------------------------------------------------------------
CREATE TABLE `questions` (
    `id`              INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `exam_id`         INT(10) UNSIGNED NOT NULL,
    `question_text`   TEXT             NOT NULL,
    `question_type`   ENUM('multiple_choice','checkboxes','short_answer','paragraph','linear_scale','dropdown') NOT NULL DEFAULT 'multiple_choice',
    `description`     TEXT             DEFAULT NULL,
    `points`          SMALLINT(6)      NOT NULL DEFAULT 1,
    `is_required`     TINYINT(1)       NOT NULL DEFAULT 1,
    `choices`         LONGTEXT         DEFAULT NULL COMMENT 'JSON array of choice strings',
    `correct_index`   TINYINT(4)       DEFAULT NULL COMMENT '0-based index into choices',
    `correct_answer`  TEXT             DEFAULT NULL,
    `scale_min`       TINYINT(4)       NOT NULL DEFAULT 1,
    `scale_max`       TINYINT(4)       NOT NULL DEFAULT 5,
    `scale_min_label` VARCHAR(80)      DEFAULT NULL,
    `scale_max_label` VARCHAR(80)      DEFAULT NULL,
    `sort_order`      SMALLINT(6)      NOT NULL DEFAULT 0,
    `section_id`      INT(11)          DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_exam_id` (`exam_id`),
    CONSTRAINT `fk_questions_exam` FOREIGN KEY (`exam_id`) REFERENCES `exams` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- exam_results
-- ------------------------------------------------------------
CREATE TABLE `exam_results` (
    `id`           INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `applicant_id` INT(10) UNSIGNED NOT NULL,
    `exam_id`      INT(10) UNSIGNED NOT NULL,
    `score`        SMALLINT(6)      NOT NULL DEFAULT 0,
    `total_items`  SMALLINT(6)      NOT NULL DEFAULT 0,
    `answers`      LONGTEXT         DEFAULT NULL COMMENT 'JSON array of chosen indices per question',
    `submitted_at` DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_applicant_id` (`applicant_id`),
    KEY `idx_exam_id`      (`exam_id`),
    CONSTRAINT `fk_examresults_applicant` FOREIGN KEY (`applicant_id`) REFERENCES `applicants` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_examresults_exam`      FOREIGN KEY (`exam_id`)      REFERENCES `exams`      (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- interview_slots  (one session per desk per day)
-- ------------------------------------------------------------
CREATE TABLE `interview_slots` (
    `id`          INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `slot_date`   DATE             NOT NULL,
    `slot_time`   TIME             DEFAULT NULL,
    `end_time`    TIME             DEFAULT NULL,
    `capacity`    SMALLINT(5)      NOT NULL DEFAULT 30,
    `status`      ENUM('open','closed') NOT NULL DEFAULT 'open',
    `created_by`  INT(10) UNSIGNED NOT NULL,
    `created_at`  DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_slot_date`  (`slot_date`),
    KEY `idx_created_by` (`created_by`),
    CONSTRAINT `fk_slots_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- interview_queue  (one row per student per session)
-- ------------------------------------------------------------
CREATE TABLE `interview_queue` (
    `id`              INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `slot_id`         INT(10) UNSIGNED NOT NULL,
    `applicant_id`    INT(10) UNSIGNED NOT NULL,
    `queue_number`    INT UNSIGNED     DEFAULT NULL,
    `status`          ENUM('scheduled','checked_in','in_progress','completed','no_show') NOT NULL DEFAULT 'scheduled',
    `checked_in_at`   DATETIME         DEFAULT NULL,
    `interview_notes` TEXT             DEFAULT NULL,
    `created_at`      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_applicant_active` (`applicant_id`),
    KEY `idx_iq_applicant` (`applicant_id`),
    KEY `idx_iq_slot`      (`slot_id`),
    CONSTRAINT `fk_iq_slot`      FOREIGN KEY (`slot_id`)      REFERENCES `interview_slots` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_iq_applicant` FOREIGN KEY (`applicant_id`) REFERENCES `applicants`       (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- admission_results
-- ------------------------------------------------------------
CREATE TABLE `admission_results` (
    `id`           INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `applicant_id` INT(10) UNSIGNED NOT NULL,
    `result`       ENUM('accepted','waitlisted','rejected') NOT NULL,
    `remarks`      TEXT             DEFAULT NULL,
    `released_by`  INT(10) UNSIGNED NOT NULL,
    `released_at`  DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_applicant_result` (`applicant_id`),
    KEY `idx_result`      (`result`),
    KEY `idx_released_by` (`released_by`),
    CONSTRAINT `fk_results_applicant`  FOREIGN KEY (`applicant_id`) REFERENCES `applicants` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_results_releasedby` FOREIGN KEY (`released_by`)  REFERENCES `users`      (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- school_settings
-- ------------------------------------------------------------
CREATE TABLE `school_settings` (
    `id`            INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `setting_key`   VARCHAR(80)      NOT NULL,
    `setting_value` TEXT             DEFAULT NULL,
    `updated_at`    DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_setting_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- password_resets
-- ------------------------------------------------------------
CREATE TABLE `password_resets` (
    `id`         INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`    INT(10) UNSIGNED NOT NULL,
    `token`      VARCHAR(64)      NOT NULL,
    `expires_at` DATETIME         NOT NULL,
    `used`       TINYINT(1)       NOT NULL DEFAULT 0,
    `created_at` DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_token`   (`token`),
    KEY `idx_user_id` (`user_id`),
    CONSTRAINT `fk_resets_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Seed: Default school settings
-- ============================================================
INSERT INTO `school_settings` (`setting_key`, `setting_value`) VALUES
    ('school_name',         'Pamantasan ng Lungsod ng Pasig'),
    ('school_logo',         ''),
    ('accent_color',        '#2d6a4f'),
    ('current_school_year', '2026-2027'),
    ('admissions_open',     '2026-01-06'),
    ('admissions_close',    '2026-03-31'),
    ('admissions_override', '0'),
    ('system_version',      '1.0.0');

-- ============================================================
-- Seed: Default admin account
-- Password: Admin@PLP2024  (change immediately after first login)
-- ============================================================
INSERT INTO `users` (`name`, `first_name`, `last_name`, `email`, `password_hash`, `role`) VALUES
    ('System Administrator', 'System', 'Administrator', 'admin@plp.edu.ph',
     '$2y$12$placeholder.hash.change.on.first.run.xxxxxxxxxxxxxxxxxx',
     'admin');
-- ------------------------------------------------------------
-- audit_logs
-- ------------------------------------------------------------
CREATE TABLE `audit_logs` (
    `id`          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`     INT(10) UNSIGNED    DEFAULT NULL,
    `user_name`   VARCHAR(150)        NOT NULL DEFAULT '',
    `user_role`   VARCHAR(20)         NOT NULL DEFAULT '',
    `action`      VARCHAR(80)         NOT NULL,
    `description` TEXT                DEFAULT NULL,
    `entity_type` VARCHAR(60)         DEFAULT NULL,
    `entity_id`   INT(10) UNSIGNED    DEFAULT NULL,
    `ip_address`  VARCHAR(45)         DEFAULT NULL,
    `created_at`  DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_user_id`    (`user_id`),
    KEY `idx_action`     (`action`),
    KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;