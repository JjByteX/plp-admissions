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
    `department`    VARCHAR(120)     NOT NULL DEFAULT '' COMMENT 'College/department name (see departments.name)',
    `is_active`     TINYINT(1)       NOT NULL DEFAULT 1,
    `desk_label`    VARCHAR(120)     NOT NULL DEFAULT '',
    `desk_notes`    TEXT             DEFAULT NULL,
    `created_at`    DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_email` (`email`),
    KEY `idx_users_department` (`department`)
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
    `rank_score`   TINYINT(3)       NOT NULL DEFAULT 0 COMMENT '1–10 ranking based on percentage',
    `passed`       TINYINT(1)       NOT NULL DEFAULT 0 COMMENT '1=passed threshold for applied course',
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
    `department`  VARCHAR(120)     NOT NULL DEFAULT '' COMMENT 'College this slot serves (see departments.name)',
    `status`      ENUM('open','closed') NOT NULL DEFAULT 'open',
    `created_by`  INT(10) UNSIGNED NOT NULL,
    `created_at`  DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_slot_date`   (`slot_date`),
    KEY `idx_created_by`  (`created_by`),
    KEY `idx_slots_department` (`department`),
    CONSTRAINT `fk_slots_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- interview_queue  (one row per student per session)
-- ------------------------------------------------------------
CREATE TABLE `interview_queue` (
    `id`                INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `slot_id`           INT(10) UNSIGNED NOT NULL,
    `applicant_id`      INT(10) UNSIGNED NOT NULL,
    `queue_number`      INT UNSIGNED     DEFAULT NULL,
    `status`            ENUM('scheduled','checked_in','in_progress','completed','no_show') NOT NULL DEFAULT 'scheduled',
    `checked_in_at`     DATETIME         DEFAULT NULL,
    `interview_notes`   TEXT             DEFAULT NULL,
    `attendance_status` ENUM('present','absent') NULL DEFAULT NULL COMMENT 'Filled in by staff at interview time',
    `evaluation_result` ENUM('pass','fail')      NULL DEFAULT NULL COMMENT 'Only meaningful when attendance_status = present',
    `interview_status`  ENUM('pending','completed','absent','rescheduled') NOT NULL DEFAULT 'pending' COMMENT 'End-to-end lifecycle state',
    `evaluated_by`      INT(10) UNSIGNED NULL DEFAULT NULL,
    `evaluated_at`      DATETIME         NULL DEFAULT NULL,
    `created_at`        DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_applicant_active` (`applicant_id`),
    KEY `idx_iq_applicant`        (`applicant_id`),
    KEY `idx_iq_slot`             (`slot_id`),
    KEY `idx_iq_interview_status` (`interview_status`),
    KEY `idx_iq_attendance`       (`attendance_status`),
    CONSTRAINT `fk_iq_slot`      FOREIGN KEY (`slot_id`)      REFERENCES `interview_slots` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_iq_applicant` FOREIGN KEY (`applicant_id`) REFERENCES `applicants`       (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- reschedule_logs — append-only history of reschedule actions
-- ------------------------------------------------------------
CREATE TABLE `reschedule_logs` (
    `id`             INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `applicant_id`   INT(10) UNSIGNED NOT NULL,
    `from_slot_id`   INT(10) UNSIGNED NULL,
    `to_slot_id`     INT(10) UNSIGNED NULL,
    `from_slot_date` DATE             NULL,
    `from_slot_time` TIME             NULL,
    `reason`         VARCHAR(255)     NOT NULL DEFAULT 'absent',
    `rescheduled_by` INT(10) UNSIGNED NULL,
    `rescheduled_at` DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
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
-- ============================================================
-- PLP Admissions — Schema Additions (interview notes update)
-- ============================================================

-- ------------------------------------------------------------
-- exam_slot_schedule
-- Admin creates exam days + time slots. System auto-assigns
-- applicants to slots (35 per room, 3 000 per day cap).
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `exam_slot_schedule` (
    `id`          INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `exam_id`     INT(10) UNSIGNED DEFAULT NULL COMMENT 'FK to exams; NULL = any active exam',
    `exam_date`   DATE             NOT NULL,
    `slot_time`   TIME             NOT NULL DEFAULT '08:00:00',
    `room_label`  VARCHAR(80)      NOT NULL DEFAULT '',
    `capacity`    SMALLINT(5)      NOT NULL DEFAULT 35,
    `filled`      SMALLINT(5)      NOT NULL DEFAULT 0,
    `school_year` VARCHAR(9)       NOT NULL,
    `created_by`  INT(10) UNSIGNED NOT NULL,
    `created_at`  DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_ess_date`   (`exam_date`),
    KEY `idx_ess_year`   (`school_year`),
    CONSTRAINT `fk_ess_exam`    FOREIGN KEY (`exam_id`)    REFERENCES `exams`  (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_ess_creator` FOREIGN KEY (`created_by`) REFERENCES `users`  (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- applicant_exam_slots
-- One-to-one: each applicant is auto-assigned one exam slot.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `applicant_exam_slots` (
    `id`           INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `applicant_id` INT(10) UNSIGNED NOT NULL,
    `slot_id`      INT(10) UNSIGNED NOT NULL,
    `assigned_at`  DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_aes_applicant` (`applicant_id`),
    KEY `idx_aes_slot` (`slot_id`),
    CONSTRAINT `fk_aes_applicant` FOREIGN KEY (`applicant_id`) REFERENCES `applicants`         (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_aes_slot`      FOREIGN KEY (`slot_id`)      REFERENCES `exam_slot_schedule` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- course_caps
-- Admin sets max accepted applicants per course per year.
-- NULL max_slots = unlimited.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `course_caps` (
    `id`          INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `course_name` VARCHAR(200)     NOT NULL,
    `school_year` VARCHAR(9)       NOT NULL,
    `max_slots`   SMALLINT(5) UNSIGNED DEFAULT NULL COMMENT 'NULL = unlimited',
    `updated_at`  DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_cc_course_year` (`course_name`, `school_year`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- course_passing_scores
-- Per-course passing threshold (overrides the config default).
-- Admin can update these from the settings panel.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `course_passing_scores` (
    `id`          INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `course_name` VARCHAR(200)     NOT NULL,
    `pass_from`   TINYINT(3)       NOT NULL DEFAULT 4 COMMENT 'Minimum score to pass (1-10)',
    `confirmed`   TINYINT(1)       NOT NULL DEFAULT 0,
    `updated_at`  DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_cps_course` (`course_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Seed: Exam & interview capacity settings
-- ============================================================
INSERT INTO `school_settings` (`setting_key`, `setting_value`) VALUES
    ('exam_default_duration',  '90'),
    ('exam_room_capacity',     '35'),
    ('exam_daily_cap',         '3000'),
    ('interview_daily_cap',    '45')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);

-- ============================================================
-- Seed: Default course passing scores (BSIT confirmed; rest TBD)
-- ============================================================
INSERT INTO `course_passing_scores` (`course_name`, `pass_from`, `confirmed`) VALUES
    ('BS Accountancy (BSA)',                                               4, 0),
    ('BS Business Administration major in Marketing Management (BSBA)',   4, 0),
    ('BS Entrepreneurship (BSENT)',                                        4, 0),
    ('BS Hospitality Management (BSHM)',                                   4, 0),
    ('Bachelor of Elementary Education (BEED)',                            4, 0),
    ('Bachelor of Secondary Education Major in English (BSED-ENG)',       4, 0),
    ('Bachelor of Secondary Education Major in Filipino (BSED-FIL)',      4, 0),
    ('Bachelor of Secondary Education Major in Mathematics (BSED-MATH)',  4, 0),
    ('AB Psychology (AB Psych)',                                           4, 0),
    ('BS Computer Science (BSCS)',                                         4, 0),
    ('BS Information Technology (BSIT)',                                   4, 1),
    ('BS Electronics Engineering (BSECE)',                                 4, 0),
    ('BS Nursing (BSN)',                                                    4, 0)
ON DUPLICATE KEY UPDATE pass_from=VALUES(pass_from), confirmed=VALUES(confirmed);

-- ============================================================
-- Migration: add rank_score + passed to existing exam_results
-- Safe to run on existing installs (ALTER IGNORE / IF NOT EXISTS)
-- ============================================================
ALTER TABLE `exam_results`
    ADD COLUMN IF NOT EXISTS `rank_score` TINYINT(3) NOT NULL DEFAULT 0 COMMENT '1–10 ranking based on percentage' AFTER `total_items`,
    ADD COLUMN IF NOT EXISTS `passed`     TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1=passed threshold for applied course' AFTER `rank_score`;

-- ============================================================
-- course_suggestions
-- Records staff-recommended alternative courses for applicants
-- who failed their chosen course exam threshold.
-- ============================================================
CREATE TABLE IF NOT EXISTS `course_suggestions` (
    `id`               INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `applicant_id`     INT(10) UNSIGNED NOT NULL,
    `original_course`  VARCHAR(200)     NOT NULL,
    `suggested_course` VARCHAR(200)     NOT NULL,
    `suggested_by`     INT(10) UNSIGNED NOT NULL,
    `note`             TEXT             DEFAULT NULL,
    `status`           ENUM('pending','accepted','declined') NOT NULL DEFAULT 'pending',
    `created_at`       DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_cs_applicant` (`applicant_id`),
    KEY `idx_cs_status` (`status`),
    CONSTRAINT `fk_cs_applicant`  FOREIGN KEY (`applicant_id`) REFERENCES `applicants` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_cs_staff`      FOREIGN KEY (`suggested_by`) REFERENCES `users`      (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- sessions
-- DB-backed session store required for Vercel serverless
-- (file sessions are not shared across containers).
-- On localhost XAMPP, native file sessions are used instead
-- and this table is not needed.
-- ============================================================
CREATE TABLE IF NOT EXISTS `sessions` (
    `id`            VARCHAR(128)    NOT NULL,
    `payload`       MEDIUMTEXT      NOT NULL,
    `last_activity` INT(10) UNSIGNED NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_sessions_last_activity` (`last_activity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Departments + course→department mapping + department schedules
-- Backing tables for auto interview-slot assignment.
-- See database/migrations/2026_04_25_interview_auto_scheduling.sql
-- for the ALTERs/backfills against pre-existing databases.
-- ============================================================

CREATE TABLE IF NOT EXISTS `departments` (
    `id`         INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `code`       VARCHAR(20)      NOT NULL,
    `name`       VARCHAR(120)     NOT NULL,
    `created_at` DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_dept_code` (`code`),
    UNIQUE KEY `uq_dept_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `departments` (`code`, `name`) VALUES
    ('CCS', 'College of Computer Studies'),
    ('CON', 'College of Nursing'),
    ('CBA', 'College of Business and Accountancy'),
    ('COE', 'College of Education'),
    ('CAS', 'College of Arts and Sciences'),
    ('CEN', 'College of Engineering')
ON DUPLICATE KEY UPDATE name = VALUES(name);

CREATE TABLE IF NOT EXISTS `course_departments` (
    `id`            INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `course_name`   VARCHAR(200)     NOT NULL,
    `department_id` INT(10) UNSIGNED NOT NULL,
    `created_at`    DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_cd_course` (`course_name`),
    KEY `idx_cd_department` (`department_id`),
    CONSTRAINT `fk_cd_department`
        FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `course_departments` (`course_name`, `department_id`) VALUES
    ('BS Information Technology (BSIT)',                                       (SELECT id FROM departments WHERE code = 'CCS')),
    ('BS Computer Science (BSCS)',                                             (SELECT id FROM departments WHERE code = 'CCS')),
    ('BS Nursing (BSN)',                                                       (SELECT id FROM departments WHERE code = 'CON')),
    ('BS Accountancy (BSA)',                                                   (SELECT id FROM departments WHERE code = 'CBA')),
    ('BS Business Administration major in Marketing Management (BSBA)',        (SELECT id FROM departments WHERE code = 'CBA')),
    ('BS Entrepreneurship (BSENT)',                                            (SELECT id FROM departments WHERE code = 'CBA')),
    ('BS Hospitality Management (BSHM)',                                       (SELECT id FROM departments WHERE code = 'CBA')),
    ('Bachelor of Elementary Education (BEED)',                                (SELECT id FROM departments WHERE code = 'COE')),
    ('Bachelor of Secondary Education Major in English (BSED-ENG)',            (SELECT id FROM departments WHERE code = 'COE')),
    ('Bachelor of Secondary Education Major in Filipino (BSED-FIL)',           (SELECT id FROM departments WHERE code = 'COE')),
    ('Bachelor of Secondary Education Major in Mathematics (BSED-MATH)',       (SELECT id FROM departments WHERE code = 'COE')),
    ('AB Psychology (AB Psych)',                                               (SELECT id FROM departments WHERE code = 'CAS')),
    ('BS Electronics Engineering (BSECE)',                                     (SELECT id FROM departments WHERE code = 'CEN'));

CREATE TABLE IF NOT EXISTS `department_schedules` (
    `id`                INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `department_id`     INT(10) UNSIGNED NOT NULL,
    `day_of_week`       TINYINT(1) UNSIGNED NOT NULL COMMENT '0=Sun..6=Sat',
    `start_time`        TIME             NOT NULL DEFAULT '09:00:00',
    `end_time`          TIME             NOT NULL DEFAULT '16:00:00',
    `slot_minutes`      SMALLINT(5) UNSIGNED NOT NULL DEFAULT 30,
    `capacity_per_slot` SMALLINT(5) UNSIGNED NOT NULL DEFAULT 1,
    `is_active`         TINYINT(1)       NOT NULL DEFAULT 1,
    `created_at`        DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_ds_dept_dow_start` (`department_id`, `day_of_week`, `start_time`),
    KEY `idx_ds_department` (`department_id`),
    CONSTRAINT `fk_ds_department`
        FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed Mon–Fri 09:00-16:00 windows for every department.  INSERT IGNORE
-- is portable across MariaDB/MySQL and skips rows that would violate
-- the (department_id, day_of_week, start_time) uniqueness.
INSERT IGNORE INTO `department_schedules`
    (`department_id`, `day_of_week`, `start_time`, `end_time`, `slot_minutes`, `capacity_per_slot`)
SELECT d.id, v.dow, '09:00:00', '16:00:00', 30, 1
FROM `departments` d,
     (SELECT 1 AS dow UNION ALL SELECT 2 UNION ALL SELECT 3
      UNION ALL SELECT 4 UNION ALL SELECT 5) v;
