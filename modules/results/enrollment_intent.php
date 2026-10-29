<?php
// ============================================================
// modules/results/enrollment_intent.php
// Student POST: withdraw application.
//
// action=confirm_enrollment → confirms, then sends the applicant to the
//                    Enrollment system (core/enrollment_handoff.php).
// action=withdraw  → marks applicant as withdrawn (any stage before
//                    enrollment is confirmed).
// ============================================================

require_once CORE_PATH . '/bootstrap.php';
Auth::requireRole(ROLE_STUDENT);
csrf_check();

$db     = db();
$userId = Auth::id();
$action = post_str('action');   // 'withdraw' | 'confirm_enrollment'

// ─────────────────────────────────────────────────────────────
// ENROLLMENT CONFIRMATION
// ─────────────────────────────────────────────────────────────
if ($action === 'confirm_enrollment') {
    $stmt = $db->prepare(
        'SELECT a.id, ar.id AS result_id, ar.result, ar.enrollment_intent
         FROM applicants a
         JOIN admission_results ar ON ar.applicant_id = a.id
         WHERE a.user_id = ? ORDER BY a.id DESC LIMIT 1'
    );
    $stmt->execute([$userId]);
    $row = $stmt->fetch();

    if (!$row || $row['result'] !== 'accepted') {
        Session::flash('error', 'Only accepted applicants can confirm enrollment.');
    } elseif ($row['enrollment_intent'] === 'confirmed') {
        Session::flash('error', 'Your enrollment is already confirmed.');
    } else {
        $db->prepare("UPDATE admission_results SET enrollment_intent = 'confirmed' WHERE id = ?")
            ->execute([$row['result_id']]);
        audit_log(AuditAction::ENROLLMENT_CONFIRMED, "Applicant {$row['id']} confirmed enrollment", 'applicant', $row['id']);
        // Pass the applicant to Enrollment first, so the one confirmation message can
        // carry the visit date Enrollment booked. A failed send stays pending for
        // staff to retry and never blocks the confirmation.
        enrollment_handoff_send($db, (int) $row['id']);
        notify_stage_transition(
            $row['id'],
            'enrollment_confirmed',
            enrollment_confirmation_note(enrollment_handoff_state($db, (int) $row['id']))
        );
        Session::flash('success', 'Your enrollment has been confirmed! Welcome to PLP!');
    }
    redirect('/student/result');
}

// ─────────────────────────────────────────────────────────────
// WITHDRAWAL
// ─────────────────────────────────────────────────────────────
if ($action === 'withdraw') {
    $applicant = applicant_for_user((int)$userId, 'id, overall_status, course_applied');

    if (!$applicant) {
        fail('No application found.', '/student/result');
    }

    if ($applicant['overall_status'] === 'withdrawn') {
        fail('Your application has already been withdrawn.', '/student/result');
    }

    // Cannot withdraw after enrollment has been confirmed
    $stmt = $db->prepare(
        'SELECT enrollment_intent FROM admission_results WHERE applicant_id = ? LIMIT 1'
    );
    $stmt->execute([$applicant['id']]);
    $ar = $stmt->fetch();
    if ($ar && $ar['enrollment_intent'] === 'confirmed') {
        fail('Already enrolled. Visit the admissions office to withdraw.', '/student/result');
    }

    $reason = post_str('withdraw_reason');

    $db->beginTransaction();
    try {
        $db->prepare(
            "UPDATE applicants
             SET overall_status = 'withdrawn',
                 withdrawn_at   = NOW(),
                 withdrawn_reason = ?
             WHERE id = ?"
        )->execute([$reason ?: null, $applicant['id']]);

        audit_log(AuditAction::APPLICATION_WITHDRAWN,
            "Applicant {$applicant['id']} withdrew their application" . ($reason ? ": {$reason}" : ''),
            'applicant', $applicant['id']
        );

        $db->commit();

        // Notify the student. Waitlist promotion was retired with the role
        // redesign — a withdrawal no longer cascades to anyone else.
        notify_stage_transition($applicant['id'], 'withdrawn');

        Session::flash('success', 'Your application has been successfully withdrawn.');
    } catch (\Throwable $e) {
        $db->rollBack();
        error_log('withdraw error: ' . $e->getMessage());
        Session::flash('error', 'Something went wrong. Please try again.');
    }

    redirect('/student/result');
}

// No other POST actions supported — redirect back
fail('Invalid action.', '/student/result');
