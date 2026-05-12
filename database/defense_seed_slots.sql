-- =============================================================
-- DEFENSE-DAY SEED — 6 exam slots + 6 interview slots + caps=300
-- =============================================================
-- Run as root (or any user with INSERT/UPDATE on plp_admissions).
--
--   mysql -u root plp_admissions < defense_seed_slots.sql
--
-- What it does:
--   1. Sets every course's max_slots to 300 (current school year).
--   2. Creates ONE exam slot per college, today (2026-05-12)
--      6:00 PM – 11:59 PM, capacity 300, random room label.
--   3. Creates ONE interview slot per college, same schedule.
--   4. Does NOT touch students. No applicants are assigned to
--      any of the new slots.
--
-- Safe to re-run: course_caps upserts; exam/interview slot inserts
-- are guarded with WHERE NOT EXISTS so you can't end up with
-- duplicates if you run this twice.
-- =============================================================

USE plp_admissions;

-- ── Common knobs ──────────────────────────────────────────────
SET @today      := '2026-05-12';
SET @slot_open  := '17:45:00';
SET @slot_close := '23:59:00';
SET @capacity   := 300;
SET @max_slots  := 300;

-- Active school year (e.g. "2026-2027"). Falls back to YYYY-(YYYY+1) if unset.
SET @sy := (SELECT setting_value FROM school_settings WHERE setting_key='current_school_year' LIMIT 1);
SET @sy := COALESCE(@sy, CONCAT(YEAR(@today), '-', YEAR(@today)+1));

-- First admin user — used as created_by.
SET @admin_id := (SELECT id FROM users WHERE role='admin' ORDER BY id ASC LIMIT 1);

-- SSO user — preferred created_by for SSO-style scheduling actions.
SET @sso_id := (SELECT id FROM users WHERE role='sso'  ORDER BY id ASC LIMIT 1);
SET @creator_id := COALESCE(@sso_id, @admin_id);

-- Active exam, if any. Slots tied to NULL exam_id will match the
-- active exam at runtime, so this is fine even if there's no
-- active row right now.
SET @exam_id := (SELECT id FROM exams WHERE is_active=1 ORDER BY id DESC LIMIT 1);

-- ── 1. Set every course's max_slots to 300 ────────────────────
INSERT INTO course_caps (course_name, school_year, max_slots)
VALUES
    ('BS Accountancy (BSA)',                                              @sy, @max_slots),
    ('BS Business Administration major in Marketing Management (BSBA)',  @sy, @max_slots),
    ('BS Entrepreneurship (BSENT)',                                       @sy, @max_slots),
    ('BS Hospitality Management (BSHM)',                                  @sy, @max_slots),
    ('Bachelor of Elementary Education (BEED)',                           @sy, @max_slots),
    ('Bachelor of Secondary Education Major in English (BSED-ENG)',       @sy, @max_slots),
    ('Bachelor of Secondary Education Major in Filipino (BSED-FIL)',      @sy, @max_slots),
    ('Bachelor of Secondary Education Major in Mathematics (BSED-MATH)',  @sy, @max_slots),
    ('AB Psychology (AB Psych)',                                          @sy, @max_slots),
    ('BS Computer Science (BSCS)',                                        @sy, @max_slots),
    ('BS Information Technology (BSIT)',                                  @sy, @max_slots),
    ('BS Electronics Engineering (BSECE)',                                @sy, @max_slots),
    ('BS Nursing (BSN)',                                                  @sy, @max_slots)
ON DUPLICATE KEY UPDATE max_slots = VALUES(max_slots);

-- Also bump any custom-course caps in case Dean added courses
-- not in the built-in PLP_COURSES list. (No-op when there are
-- none.)
INSERT INTO course_caps (course_name, school_year, max_slots)
SELECT cc.course_name, @sy, @max_slots
  FROM custom_courses cc
 WHERE cc.is_active = 1
ON DUPLICATE KEY UPDATE max_slots = VALUES(max_slots);

-- ── 2. One exam slot per college (idempotent) ─────────────────
INSERT INTO exam_slot_schedule
       (exam_id, exam_date, slot_time, end_time, room_label, department, capacity, filled, school_year, created_by)
SELECT  @exam_id, @today, @slot_open, @slot_close, t.room, t.dept, @capacity, 0, @sy,
        COALESCE(
            (SELECT id FROM users WHERE role='proctor' AND department = t.dept ORDER BY id ASC LIMIT 1),
            @creator_id
        )
  FROM (
        SELECT 'Room A-501' AS room, 'College of Computer Studies'      AS dept UNION ALL
        SELECT 'Room B-301',         'College of Nursing'                       UNION ALL
        SELECT 'Room C-201',         'College of Business and Accountancy'      UNION ALL
        SELECT 'Room D-401',         'College of Education'                     UNION ALL
        SELECT 'Room E-101',         'College of Arts and Sciences'             UNION ALL
        SELECT 'Room F-601',         'College of Engineering'
       ) AS t
 WHERE NOT EXISTS (
       SELECT 1 FROM exam_slot_schedule s
        WHERE s.exam_date  = @today
          AND s.slot_time  = @slot_open
          AND s.department = t.dept
 );

-- ── 3. One interview slot per college (idempotent) ────────────
INSERT INTO interview_slots
       (slot_date, slot_time, end_time, capacity, department, status, created_by, assigned_to, location_label, location_notes)
SELECT  @today, @slot_open, @slot_close, @capacity, t.dept, 'open',
        @creator_id,
        COALESCE(
            -- Prefer the seeded Professor for this college (role='staff')
            (SELECT id FROM users WHERE role='staff' AND department = t.dept ORDER BY id ASC LIMIT 1),
            -- Fallback: any user in that department
            (SELECT id FROM users WHERE department = t.dept AND role IN ('staff','proctor','dean') ORDER BY FIELD(role,'staff','proctor','dean'), id ASC LIMIT 1),
            -- Last-resort fallback so the slot still gets created
            @creator_id
        ),
        t.room, NULL
  FROM (
        SELECT 'Room A-502' AS room, 'College of Computer Studies'      AS dept UNION ALL
        SELECT 'Room B-302',         'College of Nursing'                       UNION ALL
        SELECT 'Room C-202',         'College of Business and Accountancy'      UNION ALL
        SELECT 'Room D-402',         'College of Education'                     UNION ALL
        SELECT 'Room E-102',         'College of Arts and Sciences'             UNION ALL
        SELECT 'Room F-602',         'College of Engineering'
       ) AS t
 WHERE NOT EXISTS (
       SELECT 1 FROM interview_slots s
        WHERE s.slot_date  = @today
          AND s.slot_time  = @slot_open
          AND s.department = t.dept
 );

-- ── Verification ──────────────────────────────────────────────
SELECT 'course_caps' AS what, course_name, school_year, max_slots
  FROM course_caps WHERE school_year = @sy
 ORDER BY course_name;

SELECT 'exam_slots'  AS what, department, room_label, exam_date, slot_time, end_time, capacity, filled, school_year
  FROM exam_slot_schedule
 WHERE exam_date = @today AND slot_time = @slot_open
 ORDER BY department;

SELECT 'interview_slots' AS what,
       i.department,
       i.location_label  AS room,
       i.slot_date,
       i.slot_time,
       i.end_time,
       i.capacity,
       i.status,
       u.name            AS interviewer,
       u.email           AS interviewer_email,
       u.role            AS interviewer_role
  FROM interview_slots i
  LEFT JOIN users u ON u.id = i.assigned_to
 WHERE i.slot_date = @today AND i.slot_time = @slot_open
 ORDER BY i.department;

SELECT 'exam_slot_proctor' AS what,
       s.department,
       s.room_label,
       u.name  AS proctor,
       u.email AS proctor_email,
       u.role  AS proctor_role
  FROM exam_slot_schedule s
  LEFT JOIN users u ON u.id = s.created_by
 WHERE s.exam_date = @today AND s.slot_time = @slot_open
 ORDER BY s.department;
