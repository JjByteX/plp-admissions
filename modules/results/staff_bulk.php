<?php
// ============================================================
// modules/results/staff_bulk.php
// M6 — Bulk set result for selected applicants
// ============================================================
require_once CORE_PATH . '/bootstrap.php';
Auth::requireRole(ROLE_STAFF, ROLE_ADMIN);
csrf_check();

$db      = db();
$staffId = Auth::id();
$ids     = array_map('intval', (array)($_POST['ids'] ?? []));
$action  = $_POST['action'] ?? '';
$valid   = ['accepted', 'waitlisted', 'rejected'];

if (empty($ids) || !in_array($action, $valid, true)) {
    Session::flash('error', 'Invalid bulk action data.');
    redirect('/staff/results');
}

$upsert = $db->prepare(
    'INSERT INTO admission_results (applicant_id, result, released_by, released_at)
     VALUES (?,?,?,NOW())
     ON DUPLICATE KEY UPDATE result=VALUES(result),
                             released_by=VALUES(released_by), released_at=NOW()'
);
$upStatus = $db->prepare('UPDATE applicants SET overall_status="released" WHERE id=?');

foreach ($ids as $aid) {
    $upsert->execute([$aid, $action, $staffId]);
    $upStatus->execute([$aid]);
    audit_log('admission_result', "Bulk set applicant {$aid} to: {$action}", 'applicant', $aid);
}

Session::flash('success', count($ids) . ' applicant(s) set to ' . ucfirst($action) . '.');
redirect('/staff/results');
