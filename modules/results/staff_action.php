<?php
// ============================================================
// modules/results/staff_action.php
// Per-applicant result POST handler.
//
// Roles & responsibilities (post role-redesign):
//   Professor  — interviews the student, marks Pass/Reject. This is a
//                RECOMMENDATION only, not a verdict.
//   Dean       — final decision-maker for applicants in their college.
//                Picks Accept or Reject per row regardless of what the
//                Professor recommended. Overriding the Professor's
//                recommendation requires a written reason (audited).
//   SSO        — same release powers as the Dean, but school-wide.
//                Can also trigger "Close Admissions" (see staff_bulk.php).
//   Admin      — can do everything + override an already-released result.
//
// Actions:
//   action=release   — SSO/Dean/Admin. POST a `decision` of 'accepted' or
//                      'rejected'. If the decision conflicts with the
//                      Professor's recommendation (or with a failed exam
//                      result), a non-empty `reason` is required and is
//                      stored in admission_results.remarks for the audit
//                      trail.
//   action=override  — Admin only. Edit an already-released result.
//                      Audited; requires a non-empty remarks reason.
// ============================================================

require_once CORE_PATH . '/bootstrap.php';
Auth::requireRole(ROLE_SSO, ROLE_DEAN, ROLE_ADMIN);
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

// ── Release (SSO / Dean / Admin) ──────────────────────────────
// The releaser explicitly picks Accept or Reject. The server pulls the
// Professor's recommendation + exam result to decide whether the choice
// counts as an "override" (in which case a written reason is required
// and gets stored as the remarks for the audit trail).
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

$decision = $_POST['decision'] ?? '';
$reason   = trim($_POST['reason'] ?? '');

if (!in_array($decision, ['accepted', 'rejected'], true)) {
    Session::flash('error', 'Pick a result: Accept or Reject.');
    redirect('/staff/results');
}

$examPassed   = isset($row['exam_passed']) ? (int) $row['exam_passed'] : -1;
$interviewRes = $row['evaluation_result'];

// Block release while the interview hasn't been evaluated yet. The Dean
// can still override later — but only after the Professor records a
// Pass/Reject so the recommendation is on file.
if ($interviewRes !== 'pass' && $interviewRes !== 'reject' && $examPassed !== 0) {
    Session::flash('error', 'Cannot release yet — interview must be evaluated first.');
    redirect('/staff/results');
}

// Determine the Professor + exam recommendation.
$recommended = ($examPassed === 0 || $interviewRes === 'reject')
    ? 'rejected'
    : (($examPassed === 1 && $interviewRes === 'pass') ? 'accepted' : null);

$isOverride = ($recommended !== null && $recommended !== $decision);

if ($isOverride && $reason === '') {
    Session::flash('error',
        'A written reason is required to release this applicant as '
        . ucfirst($decision) . ' against the Professor\'s recommendation.');
    redirect('/staff/results');
}

$remarks = $isOverride ? $reason : null;

$db->prepare(
    'INSERT INTO admission_results (applicant_id, result, remarks, released_by, released_at)
     VALUES (?, ?, ?, ?, NOW())
     ON DUPLICATE KEY UPDATE result      = VALUES(result),
                             remarks     = VALUES(remarks),
                             released_by = VALUES(released_by),
                             released_at = NOW()'
)->execute([$applicantId, $decision, $remarks, $staffId]);

$db->prepare('UPDATE applicants SET overall_status = "released" WHERE id = ?')
   ->execute([$applicantId]);

notify_stage_transition($applicantId, 'released', 'Result: ' . ucfirst($decision));

if ($isOverride) {
    audit_log(
        'admission_result_released_override',
        "Released applicant {$applicantId} as {$decision} "
        . "(Professor recommended " . ucfirst($recommended) . "). Reason: {$reason}",
        'applicant', $applicantId
    );
} else {
    audit_log(
        'admission_result_released',
        "Released applicant {$applicantId} as {$decision}",
        'applicant', $applicantId
    );
}

Session::flash('success', 'Result released as ' . ucfirst($decision) . '.'
    . ($isOverride ? ' (Override of Professor recommendation recorded.)' : ''));
redirect('/staff/results');
