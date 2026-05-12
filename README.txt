==============================================
Cross-college slot leak fix — 3 file replacements
==============================================

PROBLEM
-------
Proctor's roster for a College of Arts and Sciences room was showing
applicants from BS Accountancy and BS Information Technology. Those
students belong to different colleges and should never have landed in
that slot.

ROOT CAUSE
----------
`auto_assign_exam_slot()` in `core/automation.php` falls back to
"any open slot in any college" if the student's own college has no
matching slot yet. That fallback was meant to be a last-resort safety
net for legacy applicants without a department mapping, but in
practice it routes brand-new applicants into foreign colleges
whenever their own college's slots are created late.

`modules/exam/staff_reschedule.php` had the same fallback on the
reschedule-approval path. The manual SSO "Assign" action in
`modules/exam/staff_slots.php` had no dept check at all, so an
operator could accidentally drop a student into the wrong college's
room.

FIXES
-----
1. core/automation.php — auto_assign_exam_slot()
   • Department-agnostic candidate queries now only run when the
     applicant has no resolvable department at all (unknown course
     mapping). For everyone else: if no matching slot exists in their
     own department, the applicant stays "Awaiting Slot Assignment"
     until SSO creates one. No cross-college fallback.

2. modules/exam/staff_reschedule.php — reschedule approval
   • Removed the "fall back to any open future slot" branch. If no
     same-department slot exists, surfaces a clear error telling SSO
     to create one first.

3. modules/exam/staff_slots.php — manual `assign` action
   • Added a guard: refuses the assign when the slot's department
     doesn't match the applicant's department, with a message
     pointing to the right college. Pulls dept from `users.department`
     with a `course_to_department()` fallback.

DEPLOY
------
1. Drag-and-drop the contents of this folder on top of `plp-admissions/`.
2. Run the cleanup SQL below — this removes already-misrouted
   assignments and decrements `filled` counters so the new
   auto-assign can place them correctly into their own colleges.
3. Re-run `backfill_exam_slot_assignments()` (or just let students
   refresh `/student/exam` — the page self-heals).

SANITY CHECK
------------
After deploying:

  -- Should return 0 rows. If it does, a stale misroute slipped
  -- through; re-run the cleanup query.
  SELECT a.id AS applicant_id, u.department AS student_dept,
         s.department AS slot_dept, s.room_label
    FROM applicant_exam_slots aes
    JOIN exam_slot_schedule s ON s.id = aes.slot_id
    JOIN applicants a         ON a.id = aes.applicant_id
    JOIN users u              ON u.id = a.user_id
   WHERE COALESCE(u.department,'') <> ''
     AND COALESCE(s.department,'') <> ''
     AND u.department <> s.department;
