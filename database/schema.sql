-- ============================================================
-- PLP Student Admission & Management System
-- Database Schema v1.0
-- Pamantasan ng Lungsod ng Pasig
-- ============================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+08:00";

-- ------------------------------------------------------------
-- users
-- Stores all accounts: students, staff, admins
-- ------------------------------------------------------------
CREATE TABLE `users` (
    `id`            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `name`          VARCHAR(120)    NOT NULL,
    `email`         VARCHAR(180)    NOT NULL,
    `password_hash` VARCHAR(255)    NOT NULL,
    `role`          ENUM('student','staff','admin') NOT NULL DEFAULT 'student',
    `is_active`     TINYINT(1)      NOT NULL DEFAULT 1,
    `created_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- applicants
-- One row per student per school year
-- ------------------------------------------------------------
CREATE TABLE `applicants` (
    `id`              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `user_id`         INT UNSIGNED    NOT NULL,
    `applicant_type`  ENUM('freshman','transferee','foreign') NOT NULL,
    `course_applied`  VARCHAR(120)    NOT NULL,
    `overall_status`  ENUM('pending','documents','exam','interview','released') NOT NULL DEFAULT 'pending',
    `school_year`     VARCHAR(9)      NOT NULL COMMENT 'e.g. 2024-2025',
    `created_at`      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_user_id`      (`user_id`),
    KEY `idx_school_year`  (`school_year`),
    KEY `idx_overall_status` (`overall_status`),
    CONSTRAINT `fk_applicants_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- documents
-- One row per required document per applicant
-- ------------------------------------------------------------
CREATE TABLE `documents` (
    `id`            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `applicant_id`  INT UNSIGNED    NOT NULL,
    `doc_type`      VARCHAR(80)     NOT NULL COMMENT 'slug key e.g. psa_birth_cert',
    `file_path`     VARCHAR(500)    DEFAULT NULL,
    `status`        ENUM('pending','uploaded','under_review','approved','rejected') NOT NULL DEFAULT 'pending',
    `staff_remarks` TEXT            DEFAULT NULL,
    `reviewed_by`   INT UNSIGNED    DEFAULT NULL,
    `updated_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_applicant_id` (`applicant_id`),
    KEY `idx_status`       (`status`),
    CONSTRAINT `fk_documents_applicant` FOREIGN KEY (`applicant_id`) REFERENCES `applicants` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_documents_reviewer`  FOREIGN KEY (`reviewed_by`)  REFERENCES `users`      (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- exams
-- An exam definition (one active exam at a time)
-- ------------------------------------------------------------
CREATE TABLE `exams` (
    `id`               INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `title`            VARCHAR(160)  NOT NULL,
    `duration_minutes` SMALLINT      NOT NULL DEFAULT 60,
    `is_active`        TINYINT(1)    NOT NULL DEFAULT 0,
    `created_at`       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- questions
-- Multiple-choice questions linked to an exam
-- choices stored as JSON array of strings
-- ------------------------------------------------------------
CREATE TABLE `questions` (
    `id`            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `exam_id`       INT UNSIGNED  NOT NULL,
    `question_text` TEXT          NOT NULL,
    `choices`       JSON          NOT NULL COMMENT 'Array of choice strings',
    `correct_index` TINYINT       NOT NULL COMMENT '0-based index into choices',
    `sort_order`    SMALLINT      NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    KEY `idx_exam_id` (`exam_id`),
    CONSTRAINT `fk_questions_exam` FOREIGN KEY (`exam_id`) REFERENCES `exams` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- exam_results
-- One row per applicant exam submission
-- ------------------------------------------------------------
CREATE TABLE `exam_results` (
    `id`           INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `applicant_id` INT UNSIGNED  NOT NULL,
    `exam_id`      INT UNSIGNED  NOT NULL,
    `score`        SMALLINT      NOT NULL DEFAULT 0,
    `total_items`  SMALLINT      NOT NULL DEFAULT 0,
    `answers`      JSON          DEFAULT NULL COMMENT 'Array of chosen indices per question',
    `submitted_at` DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_applicant_exam` (`applicant_id`, `exam_id`),
    KEY `idx_exam_id` (`exam_id`),
    CONSTRAINT `fk_examresults_applicant` FOREIGN KEY (`applicant_id`) REFERENCES `applicants` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_examresults_exam`      FOREIGN KEY (`exam_id`)      REFERENCES `exams`      (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- interview_slots
-- Time slots created by staff; one applicant per slot
-- ------------------------------------------------------------
CREATE TABLE `interview_slots` (
    `id`                   INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `slot_date`            DATE          NOT NULL,
    `slot_time`            TIME          NOT NULL,
    `capacity`             TINYINT       NOT NULL DEFAULT 1,
    `assigned_applicant_id` INT UNSIGNED DEFAULT NULL,
    `status`               ENUM('open','scheduled','completed','no_show') NOT NULL DEFAULT 'open',
    `created_by`           INT UNSIGNED  NOT NULL,
    `created_at`           DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_assigned`   (`assigned_applicant_id`),
    KEY `idx_slot_date`  (`slot_date`),
    CONSTRAINT `fk_slots_applicant` FOREIGN KEY (`assigned_applicant_id`) REFERENCES `applicants` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_slots_creator`   FOREIGN KEY (`created_by`)            REFERENCES `users`      (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- admission_results
-- Final decision per applicant
-- ------------------------------------------------------------
CREATE TABLE `admission_results` (
    `id`           INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `applicant_id` INT UNSIGNED  NOT NULL,
    `result`       ENUM('accepted','waitlisted','rejected') NOT NULL,
    `remarks`      TEXT          DEFAULT NULL,
    `released_by`  INT UNSIGNED  NOT NULL,
    `released_at`  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_applicant_result` (`applicant_id`),
    KEY `idx_result` (`result`),
    CONSTRAINT `fk_results_applicant` FOREIGN KEY (`applicant_id`) REFERENCES `applicants` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_results_releasedby` FOREIGN KEY (`released_by`) REFERENCES `users`      (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- school_settings
-- Key-value store for logo, name, accent color, school year
-- ------------------------------------------------------------
CREATE TABLE `school_settings` (
    `id`         INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `setting_key`   VARCHAR(80)   NOT NULL,
    `setting_value` TEXT          DEFAULT NULL,
    `updated_at`    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_setting_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- password_resets
-- Token-based password reset (displayed on screen, no email)
-- ------------------------------------------------------------
CREATE TABLE `password_resets` (
    `id`         INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `user_id`    INT UNSIGNED  NOT NULL,
    `token`      VARCHAR(64)   NOT NULL,
    `expires_at` DATETIME      NOT NULL,
    `used`       TINYINT(1)    NOT NULL DEFAULT 0,
    `created_at` DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_token`   (`token`),
    KEY `idx_user_id` (`user_id`),
    CONSTRAINT `fk_resets_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Seed: Default school settings
-- ============================================================
INSERT INTO `school_settings` (`setting_key`, `setting_value`) VALUES
    ('school_name',    'Pamantasan ng Lungsod ng Pasig'),
    ('school_logo',    ''),
    ('accent_color',   '#2d6a4f'),
    ('current_school_year', '2024-2025'),
    ('system_version', '1.0.0');

-- ============================================================
-- Seed: Default admin account
-- Password: Admin@PLP2024  (bcrypt hash below)
-- CHANGE THIS IMMEDIATELY after first login
-- ============================================================
INSERT INTO `users` (`name`, `email`, `password_hash`, `role`) VALUES
    ('System Administrator', 'admin@plp.edu.ph',
     '$2y$12$placeholder.hash.change.on.first.run.xxxxxxxxxxxxxxxxxx',
     'admin');
