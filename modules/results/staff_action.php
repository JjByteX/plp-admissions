<?php
// ============================================================
// modules/results/staff_action.php
// M6 — Staff: POST handler — single applicant result upsert
//       Supports both regular POST (modal) and AJAX (inline select)
// ============================================================

require_once CORE_PATH . '/bootstrap.php';
Auth::requireRole(ROLE_STAFF, ROLE_ADMIN);
csrf_check();

$db          = db();
$applicantId = (int)($_GET['id'] ?? 0);
$decision    = $_POST['result']  ?? '';
$remarks     = trim($_POST['remarks'] ?? '');
$staffId     = Auth::id();

$valid = ['accepted', 'waitlisted', 'rejected'];
$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']);

if (!$applicantId || !in_array($decision, $valid, true)) {
    if ($isAjax) {
        http_response_code(422);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Invalid result data.']);
        exit;
    }
    Session::flash('error', 'Invalid result data.');
    redirect('/staff/results');
}

// Upsert result
$stmt = $db->prepare(
    'INSERT INTO admission_results (applicant_id, result, remarks, released_by, released_at)
     VALUES (?,?,?,?,NOW())
     ON DUPLICATE KEY UPDATE result=VALUES(result), remarks=VALUES(remarks),
                             released_by=VALUES(released_by), released_at=NOW()'
);
$stmt->execute([$applicantId, $decision, $remarks ?: null, $staffId]);

// Advance overall_status to released
$db->prepare('UPDATE applicants SET overall_status="released" WHERE id=?')
   ->execute([$applicantId]);

audit_log('admission_result', "Set applicant {$applicantId} result to: {$decision}", 'applicant', $applicantId);

if ($isAjax) {
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'result' => $decision]);
    exit;
}

Session::flash('success', 'Admission result saved.');
redirect('/staff/results');
