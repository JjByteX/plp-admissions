<?php
// ============================================================
// modules/interview/staff_call_next.php
// M5 — Staff: Call the next checked-in applicant
//
// After the desk/session merge, the queue is always scoped to the
// logged-in interviewer's sessions for today (assigned_to with
// created_by fallback for legacy rows). Any legacy ?desk_id form
// field is ignored.
// ============================================================

require_once CORE_PATH . '/bootstrap.php';
Auth::requireRole(ROLE_STAFF, ROLE_SSO, ROLE_ADMIN);
csrf_check();

$db      = db();
$staffId = Auth::id();
$today   = date('Y-m-d');

$stmt = $db->prepare(
    'SELECT q.id FROM interview_queue q
     JOIN   interview_slots s ON s.id = q.slot_id
     WHERE  s.slot_date = ?
       AND  COALESCE(s.assigned_to, s.created_by) = ?
       AND  q.status = "checked_in"
     ORDER BY q.queue_number ASC
     LIMIT 1'
);
$stmt->execute([$today, $staffId]);
$nextId = $stmt->fetchColumn();

if ($nextId) {
    $db->prepare(
        'UPDATE interview_queue SET status="in_progress"
         WHERE id=? AND status="checked_in"'
    )->execute([$nextId]);
    Session::flash('success', 'Next applicant called in.');
} else {
    Session::flash('info', 'No applicants are waiting in the queue.');
}

redirect('/staff/interviews/queue');
