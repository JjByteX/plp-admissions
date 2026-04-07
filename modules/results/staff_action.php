<?php
// ============================================================
// modules/results/staff_action.php
// M6 — Staff: POST handler for releasing/updating a result
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
if (!$applicantId || !in_array($decision, $valid, true)) {
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

// Advance applicant overall_status to released
$db->prepare('UPDATE applicants SET overall_status="released" WHERE id=?')
   ->execute([$applicantId]);

Session::flash('success', 'Admission result saved.');
redirect('/staff/results');