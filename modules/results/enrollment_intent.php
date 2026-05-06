<?php
// ============================================================
// modules/results/enrollment_intent.php
// Student POST: withdraw application.
//
// action=withdraw  → marks applicant as withdrawn (any stage before
//                    enrollment is confirmed).
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

// No other POST actions supported — redirect back
Session::flash('error', 'Invalid action.');
redirect('/student/result');
