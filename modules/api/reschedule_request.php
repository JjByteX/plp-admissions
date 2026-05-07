<?php
// ============================================================
// modules/api/reschedule_request.php
// Student POST: request interview reschedule
// ============================================================

require_once CORE_PATH . '/bootstrap.php';
Auth::requireRole(ROLE_STUDENT);
csrf_check();

$userId = Auth::id();
$db = db();

$stmt = $db->prepare('SELECT id FROM applicants WHERE user_id = ? ORDER BY id DESC LIMIT 1');
$stmt->execute([$userId]);
$applicant = $stmt->fetch();
if (!$applicant) {
    Session::flash('error', 'No application found.');
    redirect('/student/interview');
}

$stmt = $db->prepare('SELECT id, status FROM interview_queue WHERE applicant_id = ? LIMIT 1');
$stmt->execute([$applicant['id']]);
$queue = $stmt->fetch();
if (!$queue || !in_array($queue['status'], ['scheduled', 'waiting'], true)) {
    Session::flash('error', 'No active interview slot to reschedule.');
    redirect('/student/interview');
}

$reason = trim($_POST['reschedule_reason'] ?? '');
if (!$reason) {
    Session::flash('error', 'Please provide a reason for rescheduling.');
    redirect('/student/interview');
}

ensure_reschedule_requests_table();

// Check for existing pending request
$stmt = $db->prepare('SELECT id FROM reschedule_requests WHERE applicant_id = ? AND status = "pending" LIMIT 1');
$stmt->execute([$applicant['id']]);
if ($stmt->fetch()) {
    Session::flash('error', 'You already have a pending reschedule request.');
    redirect('/student/interview');
}

$db->prepare('INSERT INTO reschedule_requests (applicant_id, queue_id, reason) VALUES (?, ?, ?)')
    ->execute([$applicant['id'], $queue['id'], $reason]);

audit_log('reschedule_requested', "Applicant {$applicant['id']} requested interview reschedule: {$reason}", 'applicant', $applicant['id']);

// Notify staff
notify_staff('reschedule_request', 'Reschedule Request', "A student has requested to reschedule their interview.", '/staff/interviews/absent');

Session::flash('success', 'Your reschedule request has been submitted. Staff will review it shortly.');
redirect('/student/interview');
