<?php
// ============================================================
// modules/api/exam_reschedule_request.php
// Student POST: request EXAM reschedule
//
// Mirrors modules/api/reschedule_request.php (which handles the
// interview side). Inserts a pending row in exam_reschedule_requests
// and notifies the staff who can actually approve it (Proctor /
// SSO / Dean / Admin, since those are the roles allowed on
// /staff/exam/reschedule).
// ============================================================

require_once CORE_PATH . '/bootstrap.php';
Auth::requireRole(ROLE_STUDENT);
csrf_check();

$userId = Auth::id();
$db     = db();

$stmt = $db->prepare('SELECT id, overall_status FROM applicants WHERE user_id = ? ORDER BY id DESC LIMIT 1');
$stmt->execute([$userId]);
$applicant = $stmt->fetch();
if (!$applicant) {
    Session::flash('error', 'No application found.');
    redirect('/student/exam');
}

// Only students still at the exam stage can request an exam reschedule
// — once they've moved on to interview/result/released, the exam side
// is fixed.
if (($applicant['overall_status'] ?? '') !== 'exam') {
    Session::flash('error', 'Your exam stage has already finished — a reschedule is no longer possible.');
    redirect('/student/exam');
}

// The student must currently be assigned to an exam slot.
$stmt = $db->prepare(
    'SELECT aes.id, aes.slot_id
       FROM applicant_exam_slots aes
      WHERE aes.applicant_id = ?
      LIMIT 1'
);
$stmt->execute([$applicant['id']]);
$mySlot = $stmt->fetch();
if (!$mySlot) {
    Session::flash('error', 'You do not have an assigned exam slot yet, so there is nothing to reschedule.');
    redirect('/student/exam');
}

$reason = trim($_POST['reschedule_reason'] ?? '');
if (!$reason) {
    Session::flash('error', 'Please provide a reason for rescheduling.');
    redirect('/student/exam');
}

ensure_exam_reschedule_requests_table();

// Reject duplicate pending requests so the student can't queue up two.
$stmt = $db->prepare('SELECT id FROM exam_reschedule_requests WHERE applicant_id = ? AND status = "pending" LIMIT 1');
$stmt->execute([$applicant['id']]);
if ($stmt->fetch()) {
    Session::flash('error', 'You already have a pending exam reschedule request.');
    redirect('/student/exam');
}

$db->prepare('INSERT INTO exam_reschedule_requests (applicant_id, slot_id, reason) VALUES (?, ?, ?)')
    ->execute([$applicant['id'], (int)$mySlot['slot_id'], $reason]);

audit_log(
    'exam_reschedule_requested',
    "Applicant {$applicant['id']} requested EXAM reschedule: {$reason}",
    'applicant',
    (int)$applicant['id']
);

// Notify roles that can act on exam reschedule requests. notify_staff()
// covers staff + admin; we add Proctor / SSO / Dean explicitly so the
// request shows up in their notification bell too.
notify_staff(
    'exam_reschedule_request',
    'Exam Reschedule Request',
    'A student has requested to reschedule their entrance exam.',
    '/staff/exam/reschedule'
);
try {
    $reviewers = $db->query(
        "SELECT id FROM users WHERE role IN ('proctor','sso','dean') AND is_active = 1"
    )->fetchAll(PDO::FETCH_COLUMN);
    foreach ($reviewers as $rid) {
        create_notification(
            (int)$rid,
            'exam_reschedule_request',
            'Exam Reschedule Request',
            'A student has requested to reschedule their entrance exam.',
            '/staff/exam/reschedule'
        );
    }
} catch (\Throwable $e) {
    error_log('notify reviewers (exam reschedule) failed: ' . $e->getMessage());
}

Session::flash('success', 'Your exam reschedule request has been submitted. Staff will review it shortly.');
redirect('/student/exam');
