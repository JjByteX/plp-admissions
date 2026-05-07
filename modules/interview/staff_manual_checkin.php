<?php
// ============================================================
// modules/interview/staff_manual_checkin.php
// Staff manually checks in a student by code or name lookup.
//
// After the desk/session merge, the queue scope is always the
// logged-in interviewer (assigned_to with created_by fallback for
// legacy rows). Any legacy ?desk_id form field is ignored.
// ============================================================

require_once CORE_PATH . '/bootstrap.php';
Auth::requireRole(ROLE_STAFF, ROLE_ADMIN);
csrf_check();

$db      = db();
$staffId = Auth::id();
$today   = date('Y-m-d');
$search  = trim($_POST['checkin_search'] ?? '');

$queueRedirect = '/staff/interviews/queue';

if ($search === '') {
    Session::flash('error', 'Please enter a check-in code or student name.');
    redirect($queueRedirect);
}

ensure_checkin_code_column();

// Slot scope: today's sessions belonging to this interviewer.
$slotScopeSql    = 'COALESCE(s.assigned_to, s.created_by) = ?';
$slotScopeParams = [$staffId];

// Try check-in code first (exact match)
$stmt = $db->prepare(
    'SELECT q.id AS queue_id, q.status, q.applicant_id, q.checkin_code,
            u.name AS student_name, a.course_applied
     FROM   interview_queue q
     JOIN   interview_slots s ON s.id = q.slot_id
     JOIN   applicants a      ON a.id = q.applicant_id
     JOIN   users u           ON u.id = a.user_id
     WHERE  s.slot_date = ?
       AND  ' . $slotScopeSql . '
       AND  q.checkin_code = ?
     LIMIT 1'
);
$stmt->execute(array_merge([$today], $slotScopeParams, [strtoupper($search)]));
$match = $stmt->fetch();

// If no code match, try name search
if (!$match) {
    $stmt = $db->prepare(
        'SELECT q.id AS queue_id, q.status, q.applicant_id, q.checkin_code,
                u.name AS student_name, a.course_applied
         FROM   interview_queue q
         JOIN   interview_slots s ON s.id = q.slot_id
         JOIN   applicants a      ON a.id = q.applicant_id
         JOIN   users u           ON u.id = a.user_id
         WHERE  s.slot_date = ?
           AND  ' . $slotScopeSql . '
           AND  (u.name LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ?)
           AND  q.status = "scheduled"
         ORDER BY u.name ASC
         LIMIT 1'
    );
    $like = '%' . $search . '%';
    $stmt->execute(array_merge([$today], $slotScopeParams, [$like, $like, $like]));
    $match = $stmt->fetch();
}

if (!$match) {
    Session::flash('error', 'No student found for "' . htmlspecialchars($search) . '" in today\'s queue.');
    redirect($queueRedirect);
}

if ($match['status'] !== 'scheduled') {
    Session::flash('error', htmlspecialchars($match['student_name']) . ' is already checked in (status: ' . $match['status'] . ').');
    redirect($queueRedirect);
}

// Perform check-in (same logic as student self-check-in)
$db->beginTransaction();
try {
    // Compute next queue number scoped to today's sessions for THIS interviewer.
    $stmt = $db->prepare(
        'SELECT COALESCE(MAX(q.queue_number), 0) + 1
         FROM   interview_queue q
         JOIN   interview_slots s ON s.id = q.slot_id
         WHERE  s.slot_date = ?
           AND  ' . $slotScopeSql . '
           AND  q.queue_number IS NOT NULL'
    );
    $stmt->execute(array_merge([$today], $slotScopeParams));
    $nextNum = (int) $stmt->fetchColumn();

    $db->prepare(
        'UPDATE interview_queue
         SET    status        = "checked_in",
                queue_number  = ?,
                checked_in_at = NOW()
         WHERE  id = ? AND status = "scheduled"'
    )->execute([$nextNum, $match['queue_id']]);

    $db->commit();

    audit_log(
        'manual_checkin',
        "Staff #{$staffId} manually checked in " . $match['student_name']
            . " (code: " . ($match['checkin_code'] ?? 'N/A')
            . ", search: " . $search . ")",
        'interview_queue',
        $match['queue_id']
    );

    Session::flash('success', htmlspecialchars($match['student_name']) . ' has been checked in (Queue #' . $nextNum . ').');
} catch (\Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    error_log('Manual check-in failed: ' . $e->getMessage());
    Session::flash('error', 'Check-in failed. Please try again.');
}

redirect($queueRedirect);
