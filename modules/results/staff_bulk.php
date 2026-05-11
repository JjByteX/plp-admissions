<?php
// ============================================================
// modules/results/staff_bulk.php
// Bulk POST handler for the Results page.
//
// Supported actions:
//   action=release_selected   — Legacy: server picks accepted/rejected
//                               per row using the bucket. Kept for
//                               back-compat with any cached forms.
//   action=bulk_accept        — Release every selected applicant as
//                               'accepted'. Skips withdrawn / already-
//                               released / awaiting-interview rows.
//   action=bulk_reject        — Same, but as 'rejected'.
//   action=close_admissions   — SSO/Admin only. Bulk-reject every
//                               applicant who has not been released yet
//                               (and is not withdrawn). Used to finalise
//                               the admissions cycle so unreleased
//                               applicants don't sit indefinitely.
// ============================================================

require_once CORE_PATH . '/bootstrap.php';
Auth::requireRole(ROLE_SSO, ROLE_DEAN, ROLE_ADMIN);
csrf_check();

$db      = db();
$staffId = Auth::id();
$role    = Auth::role();
$action  = $_POST['action'] ?? '';

// ── Close Admissions (SSO / Admin only) ───────────────────────
// Bulk-reject every applicant in the current cycle who hasn't been
// released yet (ready_accept / ready_reject / awaiting). Withdrawn
// applicants are left alone. The reason is recorded as the remarks
// so the rejection trail is auditable.
if ($action === 'close_admissions') {
    if ($role !== ROLE_SSO && $role !== ROLE_ADMIN) {
        Session::flash('error', 'Only SSO or Admin can close admissions.');
        redirect('/staff/results');
    }

    $reason = trim($_POST['reason'] ?? '');
    if ($reason === '') {
        $reason = 'Admissions cycle closed — bulk rejection of unreleased applicants.';
    }

    // Optional course filter (defensive — UI doesn't currently send one).
    $courseFilter = trim($_POST['course'] ?? '');

    $sql = "SELECT a.id
            FROM applicants a
            LEFT JOIN admission_results ar ON ar.applicant_id = a.id
            WHERE a.overall_status IN ('exam','interview','released')
              AND ar.id IS NULL
              AND a.overall_status <> 'withdrawn'";
    $params = [];
    if ($courseFilter !== '') {
        $sql .= ' AND a.course_applied = :course';
        $params[':course'] = $courseFilter;
    }
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

    $upsert = $db->prepare(
        'INSERT INTO admission_results (applicant_id, result, remarks, released_by, released_at)
         VALUES (?, "rejected", ?, ?, NOW())
         ON DUPLICATE KEY UPDATE result      = VALUES(result),
                                 remarks     = VALUES(remarks),
                                 released_by = VALUES(released_by),
                                 released_at = NOW()'
    );
    $upStatus = $db->prepare('UPDATE applicants SET overall_status = "released" WHERE id = ?');

    $count = 0;
    foreach ($ids as $appId) {
        $upsert->execute([$appId, $reason, $staffId]);
        $upStatus->execute([$appId]);
        notify_stage_transition($appId, 'released', 'Result: Rejected');
        audit_log('admission_close_admissions',
            "Closed admissions: applicant {$appId} bulk-rejected. Reason: {$reason}",
            'applicant', $appId);
        $count++;
    }

    if ($count > 0) {
        Session::flash('success',
            "Closed admissions. {$count} unreleased applicant(s) bulk-rejected.");
    } else {
        Session::flash('info', 'No unreleased applicants left — nothing to close.');
    }
    redirect('/staff/results');
}

// ── Per-row bulk actions (release_selected / bulk_accept / bulk_reject) ──
$ids = array_values(array_filter(array_map('intval', (array)($_POST['ids'] ?? [])), fn($v) => $v > 0));

if (empty($ids) || !in_array($action, ['release_selected', 'bulk_accept', 'bulk_reject'], true)) {
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
    'INSERT INTO admission_results (applicant_id, result, remarks, released_by, released_at)
     VALUES (?, ?, ?, ?, NOW())
     ON DUPLICATE KEY UPDATE result      = VALUES(result),
                             remarks     = VALUES(remarks),
                             released_by = VALUES(released_by),
                             released_at = NOW()'
);
$upStatus = $db->prepare('UPDATE applicants SET overall_status = "released" WHERE id = ?');

$counts  = ['accepted' => 0, 'rejected' => 0];
$skipped = 0;
$overrideReason = trim($_POST['reason'] ?? '');

foreach ($rows as $row) {
    if ($row['overall_status'] === 'withdrawn' || $row['existing_result'] !== null) {
        $skipped++;
        continue;
    }

    $examPassed   = (int)($row['exam_passed'] ?? -1);
    $interviewRes = $row['evaluation_result'];

    // Server-derived recommendation from exam + interview.
    if ($examPassed === 0 || $interviewRes === 'reject') {
        $recommended = 'rejected';
    } elseif ($examPassed === 1 && $interviewRes === 'pass') {
        $recommended = 'accepted';
    } else {
        // Still awaiting interview — can't release.
        $skipped++;
        continue;
    }

    if ($action === 'release_selected') {
        // Legacy: take whatever the recommendation says.
        $decision = $recommended;
    } elseif ($action === 'bulk_accept') {
        $decision = 'accepted';
    } else { // bulk_reject
        $decision = 'rejected';
    }

    $isOverride = ($decision !== $recommended);
    if ($isOverride && $overrideReason === '') {
        // For bulk overrides we still want a single shared reason — if
        // none was supplied, fall back to a generic note rather than
        // silently dropping the row.
        $remarks = 'Bulk ' . $decision . ' (overrides Professor recommendation: '
                 . ucfirst($recommended) . ').';
    } elseif ($isOverride) {
        $remarks = $overrideReason;
    } else {
        $remarks = null;
    }

    $appId = (int)$row['id'];
    $upsert->execute([$appId, $decision, $remarks, $staffId]);
    $upStatus->execute([$appId]);
    notify_stage_transition($appId, 'released', 'Result: ' . ucfirst($decision));
    audit_log(
        $isOverride ? 'admission_result_released_override' : 'admission_result_released',
        "Bulk-released applicant {$appId} as {$decision}"
            . ($isOverride ? " (Professor recommended " . ucfirst($recommended) . ")" : ''),
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
