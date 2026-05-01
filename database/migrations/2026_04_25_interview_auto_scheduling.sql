-- ============================================================
-- Migration: 2026-04-25 — Interview auto-scheduling + department
-- ============================================================
-- Adds:
--   * `departments` reference table (normalized college list)
--   * `course_departments` mapping table (course → department)
--   * `department_schedules` (predefined open windows per college)
--   * `users.department` column (+ index)
--   * `interview_slots.department` column (+ index)
--   * Backfills for both new columns based on existing data
--
-- Safe to re-run: uses CREATE TABLE IF NOT EXISTS and
-- ADD COLUMN IF NOT EXISTS (MariaDB 10.2+ / MySQL 8+).
-- ============================================================

-- ------------------------------------------------------------
-- departments — one row per college
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `departments` (
    `id`         INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `code`       VARCHAR(20)      NOT NULL COMMENT 'Short code, e.g. CCS, CON',
    `name`       VARCHAR(120)     NOT NULL COMMENT 'Official college name',
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

-- ------------------------------------------------------------
-- course_departments — maps each PLP course to one department
-- ------------------------------------------------------------
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

-- INSERT IGNORE skips rows whose course_name already has a mapping.
-- The scalar subquery per row is portable across MariaDB/MySQL.
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

-- ------------------------------------------------------------
-- department_schedules — predefined open windows per college
-- Used by the auto-scheduler to generate/validate interview slots.
-- ------------------------------------------------------------
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

-- Default schedule: every department opens Mon–Fri 09:00–16:00,
-- 30-min slots, 1 applicant per slot.  Admin can change later.
-- INSERT IGNORE is used because MariaDB disallows ON DUPLICATE KEY
-- UPDATE when the SELECT source includes a derived table.
INSERT IGNORE INTO `department_schedules`
    (`department_id`, `day_of_week`, `start_time`, `end_time`, `slot_minutes`, `capacity_per_slot`)
SELECT d.id, v.dow, '09:00:00', '16:00:00', 30, 1
FROM `departments` d,
     (SELECT 1 AS dow UNION ALL SELECT 2 UNION ALL SELECT 3
      UNION ALL SELECT 4 UNION ALL SELECT 5) v;

-- ------------------------------------------------------------
-- users.department
-- ------------------------------------------------------------
ALTER TABLE `users`
    ADD COLUMN IF NOT EXISTS `department` VARCHAR(120) NOT NULL DEFAULT '' AFTER `role`,
    ADD INDEX IF NOT EXISTS `idx_users_department` (`department`);

-- Backfill: each student's department is derived from their (most recent)
-- applicant row's course_applied → course_departments → departments.name.
UPDATE `users` u
JOIN (
    SELECT a.user_id, MAX(a.id) AS latest_applicant_id
    FROM applicants a
    GROUP BY a.user_id
) latest                     ON latest.user_id       = u.id
JOIN applicants          a   ON a.id                  = latest.latest_applicant_id
JOIN course_departments  cd  ON cd.course_name        = a.course_applied
JOIN departments         d   ON d.id                  = cd.department_id
SET u.department = d.name
WHERE u.role = 'student' AND (u.department = '' OR u.department IS NULL);

-- ------------------------------------------------------------
-- interview_slots.department
-- ------------------------------------------------------------
ALTER TABLE `interview_slots`
    ADD COLUMN IF NOT EXISTS `department` VARCHAR(120) NOT NULL DEFAULT '' AFTER `capacity`,
    ADD INDEX IF NOT EXISTS `idx_slots_department` (`department`);

-- Backfill existing slots using the creating staff's department (if any).
UPDATE `interview_slots` s
JOIN `users` u ON u.id = s.created_by
SET s.department = u.department
WHERE (s.department = '' OR s.department IS NULL)
  AND u.department <> '';
