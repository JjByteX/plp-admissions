<?php
// ============================================================
// modules/results/staff_bulk.php
// Bulk release POST handler. The only supported action is
// 'release_selected' — the server picks accepted vs rejected
// per applicant from their bucket (exam_passed + interview Pass/Reject).
// Applicants still in 'awaiting' or already released are skipped.
// ============================================================

require_once CORE_PATH . '/bootstrap.php';
Auth::requireRole(ROLE_SSO, ROLE_ADMIN);
csrf_check();

$db      = db();
$staffId = Auth::id();
$ids     = array_values(array_filter(array_map('intval', (array)($_POST['ids'] ?? [])), fn($v) => $v > 0));
$action  = $_POST['action'] ?? '';

if (empty($ids) || $action !== 'release_selected') {
    Session::flash('error', 'Invalid bulk action data.');
    redirect('/staff/results');
}

// Pull bucket inputs for every selected applicant in one shot.
$placeholders = implode(',', array_fill(0, count($ids), '?'));
$stmt = $db->prepare(
    "SELECT a.id, a.overall_status,
            ar.result AS existing_result,
            er.passed AS exam_passed,
            iq.evaluation_result
     FROM applicants a
     LEFT JOIN admission_results ar ON ar.applicant_id = a.id
     LEFT JOIN exam_results       er ON er.applicant_id = a.id
     LEFT JOIN interview_queue    iq ON iq.applicant_id = a.id
     WHERE a.id IN ($placeholders)"
);
$stmt->execute($ids);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$upsert = $db->prepare(
    'INSERT INTO admission_results (applicant_id, result, released_by, released_at)
     VALUES (?, ?, ?, NOW())
     ON DUPLICATE KEY UPDATE result = VALUES(result),
                             released_by = VALUES(released_by),
                             released_at = NOW()'
);
$upStatus = $db->prepare('UPDATE applicants SET overall_status = "released" WHERE id = ?');

$counts  = ['accepted' => 0, 'rejected' => 0];
$skipped = 0;

foreach ($rows as $row) {
    if ($row['overall_status'] === 'withdrawn' || $row['existing_result'] !== null) {
        $skipped++;
        continue;
    }

    $examPassed   = (int)($row['exam_passed'] ?? -1);
    $interviewRes = $row['evaluation_result'];

    if ($examPassed === 0 || $interviewRes === 'reject') {
        $decision = 'rejected';
    } elseif ($examPassed === 1 && $interviewRes === 'pass') {
        $decision = 'accepted';
    } else {
        // Still 'awaiting' — interview not evaluated yet. Skip.
        $skipped++;
        continue;
    }

    $appId = (int)$row['id'];
    $upsert->execute([$appId, $decision, $staffId]);
    $upStatus->execute([$appId]);
    notify_stage_transition($appId, 'released', 'Result: ' . ucfirst($decision));
    audit_log('admission_result_released',
        "Bulk-released applicant {$appId} as {$decision}",
        'applicant', $appId);
    $counts[$decision]++;
}

$released = $counts['accepted'] + $counts['rejected'];
if ($released > 0) {
    $msg = "Released {$released} result(s): {$counts['accepted']} accepted, {$counts['rejected']} rejected.";
    if ($skipped > 0) $msg .= " {$skipped} skipped (still awaiting interview or already released).";
    Session::flash('success', $msg);
} else {
    Session::flash('info', 'No applicants were eligible for release.');
}

redirect('/staff/results');
