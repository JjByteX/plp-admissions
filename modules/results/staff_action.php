<?php
// ============================================================
// modules/results/staff_action.php
// Per-applicant result POST handler.
//
// Actions:
//   action=release   — SSO/Admin only. Release a fresh result. Server
//                      computes accepted vs rejected from the bucket
//                      (exam_passed + interview Pass/Fail). Will not
//                      run if the applicant is still 'awaiting'.
//   action=override  — Admin only. Edit an already-released result.
//                      Audited; requires a non-empty remarks reason.
// ============================================================

require_once CORE_PATH . '/bootstrap.php';
Auth::requireRole(ROLE_SSO, ROLE_ADMIN);
csrf_check();

$db          = db();
$applicantId = (int)($_GET['id'] ?? 0);
$action      = $_POST['action']  ?? 'release';
$staffId     = Auth::id();
$role        = Auth::role();

if (!$applicantId) {
    Session::flash('error', 'Missing applicant.');
    redirect('/staff/results');
}

// ── Override (Admin-only) ─────────────────────────────────────
if ($action === 'override') {
    if ($role !== ROLE_ADMIN) {
        Session::flash('error', 'Only Admin can override a released result.');
        redirect('/staff/results');
    }
    $decision = $_POST['result']  ?? '';
    $remarks  = trim($_POST['remarks'] ?? '');
    $valid    = ['accepted', 'rejected'];

    if (!in_array($decision, $valid, true) || $remarks === '') {
        Session::flash('error', 'Override requires a result and a written reason.');
        redirect('/staff/results');
    }

    // Must already be released (override only edits existing rows).
    $exists = $db->prepare('SELECT result FROM admission_results WHERE applicant_id = ?');
    $exists->execute([$applicantId]);
    $prev = $exists->fetchColumn();
    if ($prev === false) {
        Session::flash('error', 'Cannot override — applicant has no released result yet.');
        redirect('/staff/results');
    }

    $db->prepare(
        'UPDATE admission_results
         SET result = ?, remarks = ?, released_by = ?, released_at = NOW()
         WHERE applicant_id = ?'
    )->execute([$decision, $remarks, $staffId, $applicantId]);

    $db->prepare('UPDATE applicants SET overall_status = "released" WHERE id = ?')
       ->execute([$applicantId]);

    notify_stage_transition($applicantId, 'released', 'Result updated: ' . ucfirst($decision));

    audit_log(
        'admission_result_override',
        "Admin override: applicant {$applicantId} {$prev} → {$decision}. Reason: {$remarks}",
        'applicant', $applicantId
    );

    Session::flash('success', 'Released result updated.');
    redirect('/staff/results');
}

// ── Release (SSO / Admin) ─────────────────────────────────────
// Server computes the decision from exam_passed + interview Pass/Fail.
$stmt = $db->prepare(
    'SELECT a.id, a.overall_status,
            ar.result AS existing_result,
            er.passed AS exam_passed,
            iq.evaluation_result
     FROM applicants a
     LEFT JOIN admission_results ar ON ar.applicant_id = a.id
     LEFT JOIN exam_results       er ON er.applicant_id = a.id
     LEFT JOIN interview_queue    iq ON iq.applicant_id = a.id
     WHERE a.id = ?'
);
$stmt->execute([$applicantId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    Session::flash('error', 'Applicant not found.');
    redirect('/staff/results');
}
if ($row['overall_status'] === 'withdrawn') {
    Session::flash('error', 'Cannot release a withdrawn applicant.');
    redirect('/staff/results');
}
if ($row['existing_result'] !== null) {
    Session::flash('error', 'Applicant already has a released result. Admin can use Edit to override.');
    redirect('/staff/results');
}

$examPassed   = (int)($row['exam_passed'] ?? -1);
$interviewRes = $row['evaluation_result'];

$decision = null;
if ($examPassed === 0 || $interviewRes === 'fail') {
    $decision = 'rejected';
} elseif ($examPassed === 1 && $interviewRes === 'pass') {
    $decision = 'accepted';
}

if ($decision === null) {
    Session::flash('error', 'Cannot release yet — interview must be evaluated first.');
    redirect('/staff/results');
}

$db->prepare(
    'INSERT INTO admission_results (applicant_id, result, released_by, released_at)
     VALUES (?, ?, ?, NOW())
     ON DUPLICATE KEY UPDATE result = VALUES(result),
                             released_by = VALUES(released_by),
                             released_at = NOW()'
)->execute([$applicantId, $decision, $staffId]);

$db->prepare('UPDATE applicants SET overall_status = "released" WHERE id = ?')
   ->execute([$applicantId]);

notify_stage_transition($applicantId, 'released', 'Result: ' . ucfirst($decision));

audit_log(
    'admission_result_released',
    "Released applicant {$applicantId} as {$decision}",
    'applicant', $applicantId
);

Session::flash('success', 'Result released as ' . ucfirst($decision) . '.');
redirect('/staff/results');
