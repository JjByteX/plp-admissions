-- ============================================================
-- Migration: Merge `interview_desks` into `interview_slots`
-- ------------------------------------------------------------
-- Run this ONCE against your existing `plp_admissions` database
-- to upgrade in place without losing data. After this runs you
-- can safely overwrite the PHP files with the new versions.
--
-- Usage (XAMPP shell):
--   mysql -u root -p plp_admissions < database/migration_merge_desks_into_sessions.sql
-- ============================================================

USE plp_admissions;

-- ----------------------------------------------------------------
-- 1. Add new columns onto interview_slots (idempotent — safe to
--    re-run; will skip columns that already exist)
-- ----------------------------------------------------------------
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'interview_slots'
      AND COLUMN_NAME = 'assigned_to'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE interview_slots ADD COLUMN assigned_to INT(10) UNSIGNED DEFAULT NULL AFTER created_by',
    'SELECT "assigned_to already exists" AS msg');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'interview_slots'
      AND COLUMN_NAME = 'location_label'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE interview_slots ADD COLUMN location_label VARCHAR(120) NOT NULL DEFAULT "" AFTER assigned_to',
    'SELECT "location_label already exists" AS msg');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'interview_slots'
      AND COLUMN_NAME = 'location_notes'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE interview_slots ADD COLUMN location_notes TEXT DEFAULT NULL AFTER location_label',
    'SELECT "location_notes already exists" AS msg');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ----------------------------------------------------------------
-- 2. Backfill: copy from interview_desks (where desk_id is set)
-- ----------------------------------------------------------------
SET @desks_exists := (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'interview_desks'
);

SET @desk_id_col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'interview_slots'
      AND COLUMN_NAME = 'desk_id'
);

-- 2a. Copy from interview_desks via desk_id when both exist
SET @sql := IF(@desks_exists = 1 AND @desk_id_col_exists = 1,
    'UPDATE interview_slots s
        LEFT JOIN interview_desks d ON d.id = s.desk_id
        SET s.assigned_to    = COALESCE(s.assigned_to, d.assigned_to, d.created_by, s.created_by),
            s.location_label = IF(s.location_label = "" AND d.desk_label IS NOT NULL,
                                  d.desk_label, s.location_label),
            s.location_notes = COALESCE(s.location_notes, d.desk_notes)
        WHERE s.desk_id IS NOT NULL',
    'SELECT "skipping desk_id-based backfill" AS msg');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2b. Copy from interview_desks via department (where slot has no desk_id
--     but the department has a single desk record)
SET @sql := IF(@desks_exists = 1,
    'UPDATE interview_slots s
        LEFT JOIN (
            SELECT department,
                   MIN(desk_label) AS desk_label,
                   MIN(desk_notes) AS desk_notes,
                   MIN(COALESCE(assigned_to, created_by)) AS staff_id
              FROM interview_desks
             WHERE is_active = 1
          GROUP BY department
        ) d ON d.department = s.department
        SET s.assigned_to    = COALESCE(s.assigned_to, d.staff_id, s.created_by),
            s.location_label = IF(s.location_label = "" AND d.desk_label IS NOT NULL,
                                  d.desk_label, s.location_label),
            s.location_notes = COALESCE(s.location_notes, d.desk_notes)
        WHERE s.assigned_to IS NULL OR s.location_label = ""',
    'SELECT "skipping department-based backfill" AS msg');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2c. Last-resort fallback: pull from users.desk_label (legacy column)
SET @users_has_desk := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'desk_label'
);
SET @sql := IF(@users_has_desk = 1,
    'UPDATE interview_slots s
        LEFT JOIN users u ON u.id = s.created_by
        SET s.assigned_to    = COALESCE(s.assigned_to, s.created_by),
            s.location_label = IF(s.location_label = "" AND u.desk_label IS NOT NULL,
                                  u.desk_label, s.location_label),
            s.location_notes = COALESCE(s.location_notes, u.desk_notes)
        WHERE s.assigned_to IS NULL OR s.location_label = ""',
    'UPDATE interview_slots SET assigned_to = COALESCE(assigned_to, created_by)');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ----------------------------------------------------------------
-- 3. Drop the old desk_id FK + column from interview_slots
-- ----------------------------------------------------------------
SET @fk_exists := (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'interview_slots'
      AND CONSTRAINT_NAME = 'fk_slots_desk'
);
SET @sql := IF(@fk_exists = 1,
    'ALTER TABLE interview_slots DROP FOREIGN KEY fk_slots_desk',
    'SELECT "fk_slots_desk already dropped" AS msg');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'interview_slots'
      AND INDEX_NAME = 'idx_slots_desk'
);
SET @sql := IF(@idx_exists = 1,
    'ALTER TABLE interview_slots DROP INDEX idx_slots_desk',
    'SELECT "idx_slots_desk already dropped" AS msg');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(@desk_id_col_exists = 1,
    'ALTER TABLE interview_slots DROP COLUMN desk_id',
    'SELECT "desk_id column already dropped" AS msg');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ----------------------------------------------------------------
-- 4. Add the new FK + index for assigned_to
-- ----------------------------------------------------------------
SET @fk_assigned := (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'interview_slots'
      AND CONSTRAINT_NAME = 'fk_slots_assigned'
);
SET @sql := IF(@fk_assigned = 0,
    'ALTER TABLE interview_slots
        ADD CONSTRAINT fk_slots_assigned
        FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL',
    'SELECT "fk_slots_assigned already present" AS msg');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_assigned := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'interview_slots'
      AND INDEX_NAME = 'idx_slots_assigned_to'
);
SET @sql := IF(@idx_assigned = 0,
    'ALTER TABLE interview_slots ADD INDEX idx_slots_assigned_to (assigned_to)',
    'SELECT "idx_slots_assigned_to already present" AS msg');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ----------------------------------------------------------------
-- 5. Drop the now-unused interview_desks table
-- ----------------------------------------------------------------
DROP TABLE IF EXISTS `interview_desks`;

-- Done.
SELECT 'Migration complete: interview_desks merged into interview_slots' AS status;
