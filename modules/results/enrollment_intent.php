<?php
// ============================================================
// modules/results/enrollment_intent.php
// Student POST: confirm / decline enrollment, or withdraw application.
//
// action=withdraw  → marks applicant as withdrawn (any stage before
//                    enrollment is confirmed).
// intent=confirmed|declined → the original enrollment intent flow.
//
// When a student declines, the top waitlisted applicant for
// the same course is automatically promoted to "accepted".
// ============================================================

require_once CORE_PATH . '/bootstrap.php';
Auth::requireRole(ROLE_STUDENT);
csrf_check();

$db     = db();
$userId = Auth::id();
$action = $_POST['action'] ?? '';   // 'withdraw' | ''

// ─────────────────────────────────────────────────────────────
// WITHDRAWAL
// ─────────────────────────────────────────────────────────────
if ($action === 'withdraw') {
    $stmt = $db->prepare(
        'SELECT id, overall_status, course_applied
         FROM applicants WHERE user_id = ? ORDER BY id DESC LIMIT 1'
    );
    $stmt->execute([$userId]);
    $applicant = $stmt->fetch();

    if (!$applicant) {
        Session::flash('error', 'No application found.');
        redirect('/student/result');
    }

    if ($applicant['overall_status'] === 'withdrawn') {
        Session::flash('error', 'Your application has already been withdrawn.');
        redirect('/student/result');
    }

    // Cannot withdraw after enrollment has been confirmed
    $stmt = $db->prepare(
        'SELECT enrollment_intent FROM admission_results WHERE applicant_id = ? LIMIT 1'
    );
    $stmt->execute([$applicant['id']]);
    $ar = $stmt->fetch();
    if ($ar && $ar['enrollment_intent'] === 'confirmed') {
        Session::flash('error', 'You cannot withdraw after confirming enrollment. Please visit the admissions office.');
        redirect('/student/result');
    }

    $reason = trim($_POST['withdraw_reason'] ?? '');

    $db->beginTransaction();
    try {
        $db->prepare(
            "UPDATE applicants
             SET overall_status = 'withdrawn',
                 withdrawn_at   = NOW(),
                 withdrawn_reason = ?
             WHERE id = ?"
        )->execute([$reason ?: null, $applicant['id']]);

        audit_log('application_withdrawn',
            "Applicant {$applicant['id']} withdrew their application" . ($reason ? ": {$reason}" : ''),
            'applicant', $applicant['id']
        );

        $db->commit();

        // Automation: notify and auto-promote waitlist if this was an accepted student
        notify_stage_transition($applicant['id'], 'withdrawn');
        if ($ar && $ar['enrollment_intent'] !== 'confirmed') {
            auto_promote_waitlist($applicant['id']);
        }

        Session::flash('success', 'Your application has been successfully withdrawn.');
    } catch (\Throwable $e) {
        $db->rollBack();
        error_log('withdraw error: ' . $e->getMessage());
        Session::flash('error', 'Something went wrong. Please try again.');
    }

    redirect('/student/result');
}

// ─────────────────────────────────────────────────────────────
// ENROLLMENT INTENT (original flow)
// ─────────────────────────────────────────────────────────────
$intent = $_POST['intent'] ?? ''; // 'confirmed' | 'declined'

if (!in_array($intent, ['confirmed', 'declined'], true)) {
    Session::flash('error', 'Invalid intent value.');
    redirect('/student/result');
}

// Fetch applicant + result
$stmt = $db->prepare(
    'SELECT a.id AS applicant_id, a.course_applied, ar.id AS result_id,
            ar.result, ar.enrollment_intent, ar.intent_deadline
     FROM applicants a
     JOIN admission_results ar ON ar.applicant_id = a.id
     WHERE a.user_id = ?
     ORDER BY a.id DESC LIMIT 1'
);
$stmt->execute([$userId]);
$row = $stmt->fetch();

if (!$row) {
    Session::flash('error', 'No released result found.');
    redirect('/student/result');
}

// Only accepted applicants can submit intent
if ($row['result'] !== 'accepted') {
    Session::flash('error', 'Only accepted applicants can submit enrollment intent.');
    redirect('/student/result');
}

// Cannot change after already submitted
if ($row['enrollment_intent'] !== null) {
    Session::flash('error', 'You have already submitted your enrollment intent.');
    redirect('/student/result');
}

// Check deadline
if ($row['intent_deadline'] && date('Y-m-d') > $row['intent_deadline']) {
    Session::flash('error', 'The enrollment intent deadline has passed.');
    redirect('/student/result');
}

$db->beginTransaction();
try {
    // Save the student's intent
    $db->prepare(
        'UPDATE admission_results
         SET enrollment_intent = ?, intent_submitted_at = NOW()
         WHERE id = ?'
    )->execute([$intent, $row['result_id']]);

    audit_log('enrollment_intent',
        "Applicant {$row['applicant_id']} chose: {$intent}",
        'applicant', $row['applicant_id']);

    // If student declined → promote the next waitlisted applicant
    if ($intent === 'declined') {
        _promote_next_waitlisted($db, $row['course_applied']);
        auto_promote_waitlist((int) $row['applicant_id']);
    }

    $db->commit();
    $msg = $intent === 'confirmed'
        ? 'Enrollment confirmed! Welcome to Pamantasan ng Lungsod ng Pasig.'
        : 'You have declined enrollment. Your slot has been released.';
    Session::flash('success', $msg);

} catch (\Throwable $e) {
    $db->rollBack();
    error_log('enrollment_intent error: ' . $e->getMessage());
    Session::flash('error', 'Something went wrong. Please try again.');
}

redirect('/student/result');

// ─────────────────────────────────────────────────────────────
// Promote the top-ranked waitlisted applicant for the same course
// ─────────────────────────────────────────────────────────────
function _promote_next_waitlisted(PDO $db, string $course): void
{
    $stmt = $db->prepare(
        "SELECT ar.id AS result_id, ar.applicant_id,
                er.rank_score
         FROM admission_results ar
         JOIN applicants a  ON a.id  = ar.applicant_id
         LEFT JOIN exam_results er ON er.applicant_id = ar.applicant_id
         WHERE ar.result = 'waitlisted'
           AND ar.enrollment_intent IS NULL
           AND a.overall_status != 'withdrawn'
           AND a.course_applied = ?
         ORDER BY er.rank_score DESC, ar.released_at ASC
         LIMIT 1"
    );
    $stmt->execute([$course]);
    $next = $stmt->fetch();

    if (!$next) {
        return;
    }

    $db->prepare(
        "UPDATE admission_results
         SET result = 'accepted',
             promoted_from_waitlist = 1,
             released_at = NOW()
         WHERE id = ?"
    )->execute([$next['result_id']]);

    audit_log('waitlist_promotion',
        "Applicant {$next['applicant_id']} promoted from waitlist to accepted for course: {$course}",
        'applicant', $next['applicant_id']);
}
