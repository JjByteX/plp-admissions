-- ============================================================
-- PLP Student Admission & Management System
-- Complete database schema  —  Pamantasan ng Lungsod ng Pasig
-- ============================================================
--
-- This is a single, idempotent schema file.  Running it will:
--   1. Drop every table the application uses (data is wiped)
--   2. Recreate every table fresh, with all current columns
--   3. Seed default settings, departments, courses and an admin
--
-- WARNING: This file DROPS existing tables.  Only run it on a
-- production database when you intentionally want to reset.
--
-- HOW TO RUN
--   phpMyAdmin:   Select the database → Import tab → choose this
--                 file → click "Import"
--   MariaDB CLI:  USE plp_admissions; source schema.sql;
--                 (forward slashes in path on Windows)
--
-- Requires:  MariaDB 10.2+   or   MySQL 8.0+
-- ============================================================

SET SQL_MODE       = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone      = "+08:00";
SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------
-- Drop all existing tables (FK checks disabled above so order
-- doesn't matter).  Remove anything from a previous install so
-- we can recreate a clean, complete schema below.
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `admission_results`;
DROP TABLE IF EXISTS `applicant_exam_slots`;
DROP TABLE IF EXISTS `applicants`;
DROP TABLE IF EXISTS `audit_logs`;
DROP TABLE IF EXISTS `course_caps`;
DROP TABLE IF EXISTS `course_departments`;
DROP TABLE IF EXISTS `course_passing_scores`;
DROP TABLE IF EXISTS `course_suggestions`;
DROP TABLE IF EXISTS `custom_courses`;
DROP TABLE IF EXISTS `department_schedules`;
DROP TABLE IF EXISTS `departments`;
DROP TABLE IF EXISTS `document_validations`;
DROP TABLE IF EXISTS `documents`;
DROP TABLE IF EXISTS `exam_results`;
DROP TABLE IF EXISTS `exam_sections`;
DROP TABLE IF EXISTS `exam_slot_schedule`;
DROP TABLE IF EXISTS `exams`;
DROP TABLE IF EXISTS `interview_queue`;
DROP TABLE IF EXISTS `interview_slots`;
DROP TABLE IF EXISTS `notifications`;
DROP TABLE IF EXISTS `password_resets`;
DROP TABLE IF EXISTS `questions`;
DROP TABLE IF EXISTS `reschedule_logs`;
DROP TABLE IF EXISTS `school_settings`;
DROP TABLE IF EXISTS `sessions`;
DROP TABLE IF EXISTS `users`;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- 1. Core: users
-- ============================================================
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
    `role`          ENUM('student','staff','proctor','sso','dean','admin') NOT NULL DEFAULT 'student',
    `department`    VARCHAR(120)     NOT NULL DEFAULT ''
                    COMMENT 'College/department name (see departments.name)',
    `is_active`     TINYINT(1)       NOT NULL DEFAULT 1,
    `desk_label`    VARCHAR(120)     NOT NULL DEFAULT '',
    `desk_notes`    TEXT             DEFAULT NULL,
    `created_at`    DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_email`             (`email`),
    KEY        `idx_users_department` (`department`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 2. Departments  +  course → department mapping
-- ============================================================
CREATE TABLE `departments` (
    `id`         INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `code`       VARCHAR(20)      NOT NULL COMMENT 'Short code, e.g. CCS, CON',
    `name`       VARCHAR(120)     NOT NULL COMMENT 'Official college name',
    `created_at` DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY  `uq_dept_code` (`code`),
    UNIQUE KEY  `uq_dept_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `course_departments` (
    `id`            INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `course_name`   VARCHAR(200)     NOT NULL,
    `department_id` INT(10) UNSIGNED NOT NULL,
    `created_at`    DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_cd_course`     (`course_name`),
    KEY        `idx_cd_department` (`department_id`),
    CONSTRAINT `fk_cd_department`
        FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `department_schedules` (
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
    KEY        `idx_ds_department`    (`department_id`),
    CONSTRAINT `fk_ds_department`
        FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 3. Applicants + documents
-- ============================================================
CREATE TABLE `applicants` (
    `id`               INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`          INT(10) UNSIGNED NOT NULL,
    `applicant_type`   ENUM('freshman','transferee','foreign') NOT NULL,
    `course_applied`   VARCHAR(120)     NOT NULL,
    `shs_strand`       VARCHAR(60)      DEFAULT NULL COMMENT 'SHS strand key (freshmen only)',
    `overall_status`   ENUM('pending','documents','submitted','exam','interview','released','withdrawn')
                       NOT NULL DEFAULT 'pending'
                       COMMENT 'Current stage; withdrawn = applicant voluntarily withdrew',
    `school_year`      VARCHAR(9)       NOT NULL COMMENT 'e.g. 2024-2025',
    `created_at`       DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `withdrawn_at`     DATETIME         NULL DEFAULT NULL
                       COMMENT 'Timestamp of withdrawal submission',
    `withdrawn_reason` TEXT             NULL DEFAULT NULL
                       COMMENT 'Optional reason given by applicant for withdrawal',
    `documents_approved_at` DATETIME    NULL DEFAULT NULL
                       COMMENT 'Set when last required document is approved; used for FCFS exam-slot allocation',
    PRIMARY KEY (`id`),
    KEY `idx_user_id`         (`user_id`),
    KEY `idx_school_year`     (`school_year`),
    KEY `idx_overall_status`  (`overall_status`),
    KEY `idx_withdrawn_at`    (`withdrawn_at`),
    KEY `idx_docs_approved_at`(`documents_approved_at`),
    CONSTRAINT `fk_applicants_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
    CONSTRAINT `fk_documents_applicant`
        FOREIGN KEY (`applicant_id`) REFERENCES `applicants` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_documents_reviewer`
        FOREIGN KEY (`reviewed_by`)  REFERENCES `users`      (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 4. Exams: definition, sections, questions, results, schedule
-- ============================================================
-- exams now describes only the *content* of the entrance exam:
-- title, sections, questions, passing score. The schedule, duration,
-- and access code all live on each room slot (exam_slot_schedule) —
-- see the comment on that table below.
CREATE TABLE `exams` (
    `id`                 INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `title`              VARCHAR(160)     NOT NULL,
    `description`        TEXT             DEFAULT NULL,
    `passing_score`      SMALLINT(6)      DEFAULT NULL,
    `shuffle_questions`  TINYINT(1)       NOT NULL DEFAULT 0,
    `shuffle_choices`    TINYINT(1)       NOT NULL DEFAULT 0,
    `is_active`          TINYINT(1)       NOT NULL DEFAULT 0,
    `created_at`         DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `exam_sections` (
    `id`            INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `exam_id`       INT(10) UNSIGNED NOT NULL,
    `title`         VARCHAR(255)     NOT NULL,
    `description`   TEXT             DEFAULT NULL,
    `question_type` VARCHAR(50)      NOT NULL DEFAULT 'multiple_choice',
    `sort_order`    INT(11)          NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    KEY `idx_exam_id` (`exam_id`),
    CONSTRAINT `fk_sections_exam`
        FOREIGN KEY (`exam_id`) REFERENCES `exams` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `questions` (
    `id`              INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `exam_id`         INT(10) UNSIGNED NOT NULL,
    `question_text`   TEXT             NOT NULL,
    `question_type`   ENUM('multiple_choice','checkboxes','short_answer','paragraph','linear_scale','dropdown')
                      NOT NULL DEFAULT 'multiple_choice',
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
    CONSTRAINT `fk_questions_exam`
        FOREIGN KEY (`exam_id`) REFERENCES `exams` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `exam_results` (
    `id`           INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `applicant_id` INT(10) UNSIGNED NOT NULL,
    `exam_id`      INT(10) UNSIGNED NOT NULL,
    `score`        SMALLINT(6)      NOT NULL DEFAULT 0,
    `total_items`  SMALLINT(6)      NOT NULL DEFAULT 0,
    `rank_score`   TINYINT(3)       NOT NULL DEFAULT 0
                   COMMENT '1-10 ranking based on percentage',
    `passed`       TINYINT(1)       NOT NULL DEFAULT 0
                   COMMENT '1=passed threshold for applied course',
    `answers`      LONGTEXT         DEFAULT NULL COMMENT 'JSON array of chosen indices per question',
    `submitted_at` DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_applicant_id` (`applicant_id`),
    KEY `idx_exam_id`      (`exam_id`),
    CONSTRAINT `fk_examresults_applicant`
        FOREIGN KEY (`applicant_id`) REFERENCES `applicants` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_examresults_exam`
        FOREIGN KEY (`exam_id`)      REFERENCES `exams`      (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Exam-day room scheduling (auto-assigns 35 applicants per room per slot).
--
-- Each row = one physical room running one session at a specific date/time.
-- Owns its own open/close window so each room can run on its own clock,
-- and its own access code so each room's proctor can generate / regenerate
-- independently without affecting other rooms. The exam content table
-- (`exams`) no longer carries duration — the slot's `slot_time` and
-- `end_time` together define the exam window. Late cutoff is the global
-- school-setting `exam_late_cutoff_minutes` (default 15).
CREATE TABLE `exam_slot_schedule` (
    `id`                 INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `exam_id`            INT(10) UNSIGNED DEFAULT NULL COMMENT 'FK to exams; NULL = any active exam',
    `exam_date`          DATE             NOT NULL,
    `slot_time`          TIME             NOT NULL DEFAULT '08:00:00'
        COMMENT 'When this slot opens (room start time)',
    `end_time`           TIME             NOT NULL DEFAULT '09:30:00'
        COMMENT 'When this slot closes; duration = end_time - slot_time',
    `room_label`         VARCHAR(80)      NOT NULL DEFAULT '',
    `department`         VARCHAR(120)     NOT NULL DEFAULT '' COMMENT 'College/department this slot is for',
    `capacity`           SMALLINT(5)      NOT NULL DEFAULT 35,
    `filled`             SMALLINT(5)      NOT NULL DEFAULT 0,
    `access_password`    VARCHAR(64)      DEFAULT NULL
        COMMENT 'Per-room access code; valid 5 min after password_issued_at',
    `password_issued_at` DATETIME         DEFAULT NULL
        COMMENT 'When the room proctor last generated the code',
    `school_year`        VARCHAR(9)       NOT NULL,
    `created_by`         INT(10) UNSIGNED NOT NULL,
    `created_at`         DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_ess_date` (`exam_date`),
    KEY `idx_ess_year` (`school_year`),
    CONSTRAINT `fk_ess_exam`    FOREIGN KEY (`exam_id`)    REFERENCES `exams` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_ess_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `applicant_exam_slots` (
    `id`           INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `applicant_id` INT(10) UNSIGNED NOT NULL,
    `slot_id`      INT(10) UNSIGNED NOT NULL,
    `assigned_at`  DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_aes_applicant` (`applicant_id`),
    KEY        `idx_aes_slot`     (`slot_id`),
    CONSTRAINT `fk_aes_applicant` FOREIGN KEY (`applicant_id`) REFERENCES `applicants`         (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_aes_slot`      FOREIGN KEY (`slot_id`)      REFERENCES `exam_slot_schedule` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 5. Interview sessions (merged: time slot + assigned interviewer
--    + location label/notes — replaces the old interview_desks table)
-- ============================================================
CREATE TABLE `interview_slots` (
    `id`             INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `slot_date`      DATE             NOT NULL,
    `slot_time`      TIME             DEFAULT NULL,
    `end_time`       TIME             DEFAULT NULL,
    `capacity`       SMALLINT(5)      NOT NULL DEFAULT 30,
    `department`     VARCHAR(120)     NOT NULL DEFAULT ''
                     COMMENT 'College this session serves (see departments.name)',
    `status`         ENUM('open','closed') NOT NULL DEFAULT 'open',
    `created_by`     INT(10) UNSIGNED NOT NULL,
    `assigned_to`    INT(10) UNSIGNED DEFAULT NULL
                     COMMENT 'Staff member running this session',
    `location_label` VARCHAR(120)     NOT NULL DEFAULT ''
                     COMMENT 'Visible location/desk label, e.g. "Room 201"',
    `location_notes` TEXT             DEFAULT NULL
                     COMMENT 'Directions for the student, e.g. "2nd floor, turn left"',
    `created_at`     DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_slot_date`         (`slot_date`),
    KEY `idx_created_by`        (`created_by`),
    KEY `idx_slots_assigned_to` (`assigned_to`),
    KEY `idx_slots_department`  (`department`),
    CONSTRAINT `fk_slots_creator`
        FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
    CONSTRAINT `fk_slots_assigned`
        FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `interview_queue` (
    `id`                INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `slot_id`           INT(10) UNSIGNED NOT NULL,
    `applicant_id`      INT(10) UNSIGNED NOT NULL,
    `queue_number`      INT UNSIGNED     DEFAULT NULL,
    `status`            ENUM('scheduled','checked_in','in_progress','completed','no_show')
                        NOT NULL DEFAULT 'scheduled',
    `checked_in_at`     DATETIME         DEFAULT NULL,
    `interview_notes`   TEXT             DEFAULT NULL,
    `attendance_status` ENUM('present','absent') NULL DEFAULT NULL
                        COMMENT 'Filled in by staff at interview time',
    `evaluation_result` ENUM('pass','fail') NULL DEFAULT NULL
                        COMMENT 'Only meaningful when attendance_status = present',
    `interview_status`  ENUM('pending','completed','absent','rescheduled')
                        NOT NULL DEFAULT 'pending'
                        COMMENT 'End-to-end lifecycle state',
    `evaluated_by`      INT(10) UNSIGNED NULL DEFAULT NULL,
    `evaluated_at`      DATETIME         NULL DEFAULT NULL,
    `created_at`        DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_applicant_active`     (`applicant_id`),
    KEY        `idx_iq_applicant`        (`applicant_id`),
    KEY        `idx_iq_slot`             (`slot_id`),
    KEY        `idx_iq_interview_status` (`interview_status`),
    KEY        `idx_iq_attendance`       (`attendance_status`),
    CONSTRAINT `fk_iq_slot`      FOREIGN KEY (`slot_id`)      REFERENCES `interview_slots` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_iq_applicant` FOREIGN KEY (`applicant_id`) REFERENCES `applicants`       (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
        FOREIGN KEY (`applicant_id`) REFERENCES `applicants`       (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_rl_from_slot`
        FOREIGN KEY (`from_slot_id`) REFERENCES `interview_slots` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_rl_to_slot`
        FOREIGN KEY (`to_slot_id`)   REFERENCES `interview_slots` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 6. Admission results, intent, course suggestions
-- ============================================================
CREATE TABLE `admission_results` (
    `id`                     INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `applicant_id`           INT(10) UNSIGNED NOT NULL,
    `result`                 ENUM('accepted','waitlisted','rejected') NOT NULL,
    `enrollment_intent`      ENUM('confirmed','declined') DEFAULT NULL
                             COMMENT 'Student-submitted intent after acceptance',
    `intent_deadline`        DATE             DEFAULT NULL
                             COMMENT 'Optional date by which the student must respond',
    `intent_submitted_at`    DATETIME         DEFAULT NULL
                             COMMENT 'When the student submitted their enrollment intent',
    `promoted_from_waitlist` TINYINT(1)       NOT NULL DEFAULT 0
                             COMMENT '1 = this row was auto-promoted from waitlist after a decline',
    `remarks`                TEXT             DEFAULT NULL,
    `released_by`            INT(10) UNSIGNED NOT NULL,
    `released_at`            DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_applicant_result`    (`applicant_id`),
    KEY        `idx_result`             (`result`),
    KEY        `idx_released_by`        (`released_by`),
    KEY        `idx_enrollment_intent`  (`enrollment_intent`),
    CONSTRAINT `fk_results_applicant`  FOREIGN KEY (`applicant_id`) REFERENCES `applicants` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_results_releasedby` FOREIGN KEY (`released_by`)  REFERENCES `users`      (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `course_suggestions` (
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
    KEY        `idx_cs_status`   (`status`),
    CONSTRAINT `fk_cs_applicant` FOREIGN KEY (`applicant_id`) REFERENCES `applicants` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_cs_staff`     FOREIGN KEY (`suggested_by`) REFERENCES `users`      (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 7. Per-course capacity caps and pass-thresholds
-- ============================================================
CREATE TABLE `course_caps` (
    `id`          INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `course_name` VARCHAR(200)     NOT NULL,
    `school_year` VARCHAR(9)       NOT NULL,
    `max_slots`   SMALLINT(5) UNSIGNED DEFAULT NULL COMMENT 'NULL = unlimited',
    `updated_at`  DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_cc_course_year` (`course_name`, `school_year`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `course_passing_scores` (
    `id`          INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `course_name` VARCHAR(200)     NOT NULL,
    `pass_from`   TINYINT(3)       NOT NULL DEFAULT 4
                  COMMENT 'Minimum rank to pass (1-10)',
    `high_from`   TINYINT(3)       NOT NULL DEFAULT 7
                  COMMENT 'Minimum rank for "high" tier qualification (1-10)',
    `avg_from`    TINYINT(3)       NOT NULL DEFAULT 4
                  COMMENT 'Minimum rank for "average" tier qualification (1-10)',
    `confirmed`   TINYINT(1)       NOT NULL DEFAULT 0,
    `updated_at`  DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_cps_course` (`course_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `custom_courses` (
    `id`           INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `course_name`  VARCHAR(200)     NOT NULL,
    `strands`      TEXT             NOT NULL DEFAULT '[]'
                   COMMENT 'JSON array of accepted SHS strand keys, e.g. ["STEM","ABM"]',
    `pass_from`    TINYINT UNSIGNED NOT NULL DEFAULT 4
                   COMMENT 'Minimum rank score (1-10) required to pass this course',
    `is_active`    TINYINT(1)       NOT NULL DEFAULT 1,
    `created_by`   INT(10) UNSIGNED DEFAULT NULL,
    `created_at`   DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`   DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_cc_name`  (`course_name`),
    KEY        `idx_cc_active` (`is_active`),
    CONSTRAINT `fk_cc_user`
        FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 8. Settings, audit, sessions, password resets
-- ============================================================
CREATE TABLE `school_settings` (
    `id`            INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `setting_key`   VARCHAR(80)      NOT NULL,
    `setting_value` TEXT             DEFAULT NULL,
    `updated_at`    DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_setting_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
    CONSTRAINT `fk_resets_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- DB-backed session store (used in serverless environments).
CREATE TABLE `sessions` (
    `id`            VARCHAR(128)     NOT NULL,
    `payload`       MEDIUMTEXT       NOT NULL,
    `last_activity` INT(10) UNSIGNED NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_sessions_last_activity` (`last_activity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 8b. Notifications (in-app)
-- ============================================================
CREATE TABLE `notifications` (
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
-- 8c. Document validation logs (OCR / AI results)
-- ============================================================
CREATE TABLE `document_validations` (
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
-- 9. Seed data
-- ============================================================

-- 9a. Default school settings
INSERT INTO `school_settings` (`setting_key`, `setting_value`) VALUES
    ('school_name',           'Pamantasan ng Lungsod ng Pasig'),
    ('school_logo',           ''),
    ('accent_color',          '#2d6a4f'),
    ('current_school_year',   '2026-2027'),
    ('admissions_open',       ''),
    ('admissions_close',      ''),
    ('document_deadline',     ''),

    ('system_version',          '1.0.0'),
    ('exam_default_duration',   '90'),
    ('exam_room_capacity',      '35'),
    ('exam_daily_cap',          '3000'),
    ('exam_late_cutoff_minutes','15'),

    ('auto_validate_documents', '1'),
    ('auto_assign_exam_slots',  '1'),
    ('auto_promote_waitlist',   '1'),
    ('auto_reschedule_noshows', '1'),
    ('auto_release_results',    '0'),
    ('idle_applicant_days',     '7'),
    ('doc_reminder_days',       '3'),
    ('acceptance_deadline_days','7');

-- 9b. Default admin account
-- Email:    admin@plp.edu.ph
-- Password: Admin@PLP2024  (CHANGE IMMEDIATELY AFTER FIRST LOGIN)
INSERT INTO `users` (`name`, `first_name`, `last_name`, `email`, `password_hash`, `role`) VALUES
    ('System Administrator', 'System', 'Administrator', 'admin@plp.edu.ph',
     '$2y$12$Z.nk6UtugYH1P4RsIwqEjuLpRoYFxNoIOV9cIiSa8VfU6j2lbuKES',
     'admin');

-- 9c. Departments (one row per college)
INSERT INTO `departments` (`code`, `name`) VALUES
    ('CCS', 'College of Computer Studies'),
    ('CON', 'College of Nursing'),
    ('CBA', 'College of Business and Accountancy'),
    ('COE', 'College of Education'),
    ('CAS', 'College of Arts and Sciences'),
    ('CEN', 'College of Engineering');

-- 9d. Course → department mapping (covers all courses in PLP_COURSES)
INSERT INTO `course_departments` (`course_name`, `department_id`) VALUES
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

-- 9e. Department schedules — Mon–Fri 09:00–16:00, 30-min slots, capacity 1
INSERT INTO `department_schedules`
    (`department_id`, `day_of_week`, `start_time`, `end_time`, `slot_minutes`, `capacity_per_slot`)
SELECT d.id, v.dow, '09:00:00', '16:00:00', 30, 1
FROM `departments` d,
     (SELECT 1 AS dow UNION ALL SELECT 2 UNION ALL SELECT 3
      UNION ALL SELECT 4 UNION ALL SELECT 5) v;

-- 9f. Default per-course passing scores (BSIT confirmed; rest are tentative)
INSERT INTO `course_passing_scores` (`course_name`, `pass_from`, `high_from`, `avg_from`, `confirmed`) VALUES
    ('BS Accountancy (BSA)',                                              4, 7, 4, 0),
    ('BS Business Administration major in Marketing Management (BSBA)',  4, 7, 4, 0),
    ('BS Entrepreneurship (BSENT)',                                       4, 7, 4, 0),
    ('BS Hospitality Management (BSHM)',                                  4, 7, 4, 0),
    ('Bachelor of Elementary Education (BEED)',                           4, 7, 4, 0),
    ('Bachelor of Secondary Education Major in English (BSED-ENG)',      4, 7, 4, 0),
    ('Bachelor of Secondary Education Major in Filipino (BSED-FIL)',     4, 7, 4, 0),
    ('Bachelor of Secondary Education Major in Mathematics (BSED-MATH)', 4, 7, 4, 0),
    ('AB Psychology (AB Psych)',                                          4, 7, 4, 0),
    ('BS Computer Science (BSCS)',                                        4, 7, 4, 0),
    ('BS Information Technology (BSIT)',                                  4, 7, 4, 1),
    ('BS Electronics Engineering (BSECE)',                                4, 7, 4, 0),
    ('BS Nursing (BSN)',                                                   4, 7, 4, 0);

-- ============================================================
-- Done.  Database is ready to use.
-- ============================================================
