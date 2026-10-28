<?php
// ============================================================
// modules/results/staff_send_enrollment.php
// Staff POST: send (or retry sending) one confirmed applicant to the
// Enrollment system. Same code path as the automatic send on confirm.
// ============================================================

require_once CORE_PATH . '/bootstrap.php';
Auth::requireRole(ROLE_DEAN, ROLE_ADMIN);
csrf_check();

$db          = db();
$applicantId = (int)($_GET['id'] ?? 0);

if (!$applicantId) {
    fail('Missing applicant.', '/staff/results');
}

// A Dean only handles applicants in their own college.
if (StaffScope::current()->restricted()) {
    $stmt = $db->prepare('SELECT course_applied FROM applicants WHERE id = ?');
    $stmt->execute([$applicantId]);
    $course = (string) $stmt->fetchColumn();
    if ($course === '' || !StaffScope::current()->canAccessCourse($course)) {
        fail('That applicant is not in your college.', '/staff/results');
    }
}

[$ok, $message] = enrollment_handoff_send($db, $applicantId);

if ($ok) {
    ok('Sent to Enrollment. ' . $message, '/staff/results');
}
fail('Not sent to Enrollment. ' . $message, '/staff/results');
