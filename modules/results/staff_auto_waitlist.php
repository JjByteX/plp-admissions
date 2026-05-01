<?php
// ============================================================
// modules/results/staff_auto_waitlist.php
// M6 — Auto-waitlist all pending applicants with score < 60%
// ============================================================
require_once CORE_PATH . '/bootstrap.php';
Auth::requireRole(ROLE_STAFF, ROLE_ADMIN);
csrf_check();

$db      = db();
$staffId = Auth::id();

$rows = $db->query(
    "SELECT a.id
     FROM applicants a
     JOIN exam_results er ON er.applicant_id = a.id
     LEFT JOIN admission_results ar ON ar.applicant_id = a.id
     WHERE a.overall_status IN ('result','released','exam','interview')
       AND ar.result IS NULL
       AND (er.rank_score IS NULL OR er.rank_score < 4)"
)->fetchAll(PDO::FETCH_COLUMN);

if (empty($rows)) {
    Session::flash('success', 'No pending low-scoring applicants found.');
    redirect('/staff/results');
}

$upsert = $db->prepare(
    "INSERT INTO admission_results (applicant_id, result, released_by, released_at)
     VALUES (?, 'waitlisted', ?, NOW())
     ON DUPLICATE KEY UPDATE result='waitlisted',
                             released_by=VALUES(released_by), released_at=NOW()"
);
$upStatus = $db->prepare('UPDATE applicants SET overall_status="released" WHERE id=?');

foreach ($rows as $aid) {
    $upsert->execute([$aid, $staffId]);
    $upStatus->execute([$aid]);
    audit_log('admission_result', "Auto-waitlisted applicant {$aid} (score < 60%)", 'applicant', $aid);
}

Session::flash('success', count($rows) . ' applicant(s) automatically set to Waitlisted.');
redirect('/staff/results');
