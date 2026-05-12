-- =============================================================
-- CLEANUP — remove cross-college exam slot assignments
-- =============================================================
-- Run AFTER deploying the three patched files.
-- Safe to run multiple times.
--
-- Step 1: see what's wrong (run first to confirm scope)
-- Step 2: delete the bad rows
-- Step 3: re-tally the filled counts on every slot
-- Step 4: let the system re-place the unbooked applicants
--         (either call backfill_exam_slot_assignments() from PHP,
--          or just have each student refresh /student/exam)
-- =============================================================

-- ── Step 1: audit (read-only) ─────────────────────────────────
SELECT
    a.id                    AS applicant_id,
    a.course_applied,
    u.department            AS student_dept,
    s.department            AS slot_dept,
    s.room_label,
    s.exam_date,
    aes.slot_id
FROM applicant_exam_slots aes
JOIN exam_slot_schedule   s ON s.id = aes.slot_id
JOIN applicants           a ON a.id = aes.applicant_id
JOIN users                u ON u.id = a.user_id
WHERE COALESCE(u.department, '') <> ''
  AND COALESCE(s.department, '') <> ''
  AND u.department <> s.department
ORDER BY s.room_label, a.id;

-- ── Step 2: delete cross-college assignments ──────────────────
START TRANSACTION;

DELETE aes
  FROM applicant_exam_slots aes
  JOIN exam_slot_schedule   s ON s.id = aes.slot_id
  JOIN applicants           a ON a.id = aes.applicant_id
  JOIN users                u ON u.id = a.user_id
 WHERE COALESCE(u.department, '') <> ''
   AND COALESCE(s.department, '') <> ''
   AND u.department <> s.department;

-- ── Step 3: re-tally filled counts (authoritative recount) ────
UPDATE exam_slot_schedule s
   SET s.filled = (
       SELECT COUNT(*) FROM applicant_exam_slots aes WHERE aes.slot_id = s.id
   );

COMMIT;

-- ── Step 4: re-place the unbooked applicants ──────────────────
-- Option A (preferred — from inside a PHP shell or admin page):
--     <?php backfill_exam_slot_assignments(); ?>
-- Option B: do nothing — students automatically get re-placed
--           the next time they open /student/exam, which calls
--           auto_assign_exam_slot() on every load.

-- ── Verification (must return 0 rows after cleanup) ───────────
SELECT COUNT(*) AS still_misrouted
  FROM applicant_exam_slots aes
  JOIN exam_slot_schedule   s ON s.id = aes.slot_id
  JOIN applicants           a ON a.id = aes.applicant_id
  JOIN users                u ON u.id = a.user_id
 WHERE COALESCE(u.department, '') <> ''
   AND COALESCE(s.department, '') <> ''
   AND u.department <> s.department;
