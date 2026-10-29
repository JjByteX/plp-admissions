<?php
// ============================================================
// core/automation.php
// Automation helpers for the admissions pipeline
// ============================================================

require_once __DIR__ . '/automation/notifications.php';

require_once __DIR__ . '/automation/slots.php';

// ----------------------------------------------------------------
// AUTO-PROMOTE WAITLIST  (DEPRECATED)
// ----------------------------------------------------------------

/**
 * DEPRECATED — Waitlist tier was retired in the role redesign. Results are
 * now Accept-only or Reject-only, so there is nothing to promote from.
 * Kept as a no-op stub so any older callers don't blow up; new code should
 * not invoke this.
 */
function auto_promote_waitlist(int $applicantId): ?int
{
    return null;
}

/**
 * Get the system admin user ID (for automated actions).
 */
function get_system_user_id(): int
{
    static $id = null;
    if ($id !== null) {
        return $id;
    }
    try {
        $stmt = db()->prepare('SELECT id FROM users WHERE role = \'admin\' ORDER BY id ASC LIMIT 1');
        $stmt->execute();
        $id = (int) $stmt->fetchColumn();
    } catch (\Throwable) {
        $id = 1;
    }
    return $id;
}

// ----------------------------------------------------------------
// AUTO-RELEASE RESULTS
// ----------------------------------------------------------------

/**
 * Auto-release results for applicants who have completed both exam and interview,
 * based on score thresholds.
 */
function auto_release_results(): array
{
    // Auto-release only runs when the school-year toggle is on.
    if (school_setting('auto_release_results', '0') !== '1') {
        return ['accepted' => 0, 'rejected' => 0];
    }

    $pdo          = db();
    $systemUserId = get_system_user_id();
    $counts       = ['accepted' => 0, 'rejected' => 0];

    // Same bucket rules used by modules/results/staff_manage.php and
    // staff_action.php: exam_passed + interview Pass/Fail. Waitlist tier
    // was retired in the role redesign, so the only outcomes are
    // accepted or rejected.
    //
    // Pull every applicant who hasn't been released yet and has enough
    // signal to make a decision (exam scored, interview evaluated, OR
    // exam failed outright).
    $stmt = $pdo->query(
        'SELECT a.id AS applicant_id,
                er.passed AS exam_passed,
                iq.evaluation_result
         FROM applicants a
         LEFT JOIN exam_results       er ON er.applicant_id = a.id
         LEFT JOIN interview_queue    iq ON iq.applicant_id = a.id
         LEFT JOIN admission_results  ar ON ar.applicant_id = a.id
         WHERE a.overall_status IN (\'exam\',\'interview\',\'released\')
           AND ar.id IS NULL'
    );
    $applicants = $stmt->fetchAll();

    foreach ($applicants as $appl) {
        $examPassed   = isset($appl['exam_passed']) ? (int) $appl['exam_passed'] : -1;
        $interviewRes = $appl['evaluation_result'];

        if ($examPassed === 0 || $interviewRes === 'reject') {
            $decision = 'rejected';
        } elseif ($examPassed === 1 && $interviewRes === 'pass') {
            $decision = 'accepted';
        } else {
            // Still 'awaiting' — interview hasn't been evaluated yet.
            continue;
        }

        $pdo->prepare(
            'INSERT INTO admission_results (applicant_id, result, remarks, released_by, released_at)
             VALUES (?, ?, \'Auto-released\', ?, NOW())
             ON CONFLICT (applicant_id) DO UPDATE SET
                                     result      = EXCLUDED.result,
                                     remarks     = EXCLUDED.remarks,
                                     released_by = EXCLUDED.released_by,
                                     released_at = NOW()'
        )->execute([(int) $appl['applicant_id'], $decision, $systemUserId]);

        $pdo->prepare('UPDATE applicants SET overall_status = \'released\' WHERE id = ?')
            ->execute([(int) $appl['applicant_id']]);

        notify_stage_transition(
            (int) $appl['applicant_id'],
            'released',
            'Result: ' . (RESULT_LABELS[$decision] ?? ucfirst($decision))
        );
        $counts[$decision]++;
    }

    if (array_sum($counts) > 0) {
        audit_log(AuditAction::AUTO_RELEASE_RESULTS,
            "Auto-released results: {$counts['accepted']} accepted, {$counts['rejected']} declined");
    }

    return $counts;
}

// ----------------------------------------------------------------
// IDLE APPLICANT ALERTS
// ----------------------------------------------------------------

/**
 * Get summary of idle applicants by stage.
 */
function get_idle_summary(int $days = 7): array
{
    $pdo = db();

    $stmt = $pdo->prepare(
        'SELECT a.overall_status AS stage,
                COUNT(*) AS count,
                MAX(CURRENT_DATE - CAST(a.updated_at AS date)) AS max_days
         FROM applicants a
         WHERE a.overall_status NOT IN (\'released\', \'withdrawn\')
           AND (CURRENT_DATE - CAST(a.updated_at AS date)) >= ?
         GROUP BY a.overall_status
         ORDER BY count DESC'
    );
    $stmt->execute([$days]);
    return $stmt->fetchAll();
}

require_once __DIR__ . '/automation/auth_flows.php';

// ----------------------------------------------------------------
// B2: DOCUMENT REMINDER NUDGES
// ----------------------------------------------------------------

/**
 * Send document reminder notifications to idle students.
 * Returns count of students notified.
 */
function send_document_reminders(): int
{
    $reminderDays = (int) school_setting('doc_reminder_days', '3');
    if ($reminderDays <= 0) {
        return 0;
    }

    $pdo = db();
    $notified = 0;

    $stmt = $pdo->prepare(
        "SELECT a.id, a.user_id, u.name
         FROM applicants a
         JOIN users u ON u.id = a.user_id
         WHERE a.overall_status IN ('pending','documents')
           AND (CURRENT_DATE - CAST(a.updated_at AS date)) >= ?
           AND NOT EXISTS (
               SELECT 1 FROM notifications n
               WHERE n.user_id = a.user_id
                 AND n.type = 'doc_reminder'
                 AND n.created_at > NOW() - (CAST(? AS integer) * INTERVAL '1 day')
           )"
    );
    $stmt->execute([$reminderDays, $reminderDays]);
    $stalled = $stmt->fetchAll();

    foreach ($stalled as $row) {
        create_notification(
            (int)$row['user_id'],
            'doc_reminder',
            'Document Reminder',
            'You have incomplete documents. Please upload them to continue your application.',
            '/student/documents'
        );
        $notified++;
    }

    if ($notified > 0) {
        audit_log(AuditAction::DOC_REMINDERS_SENT, "Sent document reminders to {$notified} applicant(s)");
    }

    return $notified;
}

// ----------------------------------------------------------------
// B5: ACCEPTANCE CONFIRMATION DEADLINE
// ----------------------------------------------------------------

/**
 * Auto-expire accepted applicants who haven't taken action within the deadline.
 * Promotes the next waitlisted applicant.
 * Returns count of expired applicants.
 */
function auto_expire_accepted(): int
{
    $deadlineDays = (int) school_setting('acceptance_deadline_days', '0');
    if ($deadlineDays <= 0) {
        return 0;
    }

    $pdo = db();
    $expired = 0;

    $stmt = $pdo->prepare(
        "SELECT a.id, a.user_id, a.course_applied, ar.released_at
         FROM applicants a
         JOIN admission_results ar ON ar.applicant_id = a.id
         WHERE ar.result = 'accepted'
           AND a.overall_status = 'released'
           AND ar.released_at IS NOT NULL
           AND (CURRENT_DATE - CAST(ar.released_at AS date)) > ?"
    );
    $stmt->execute([$deadlineDays]);
    $stale = $stmt->fetchAll();

    foreach ($stale as $row) {
        // Mark as slot_expired
        $pdo->prepare(
            "UPDATE admission_results SET result = 'rejected',
                    remarks = CONCAT(COALESCE(remarks,''), '\nSlot expired - no action within {$deadlineDays} days')
             WHERE applicant_id = ?"
        )->execute([$row['id']]);

        $pdo->prepare("UPDATE applicants SET overall_status = 'withdrawn' WHERE id = ?")
            ->execute([$row['id']]);

        // Notify
        create_notification(
            (int)$row['user_id'],
            'acceptance_expired',
            'Acceptance Slot Expired',
            "Your acceptance for {$row['course_applied']} has expired because no action was taken "
            . "within {$deadlineDays} days.",
            '/student/result'
        );

        // Promote next waitlisted
        auto_promote_waitlist($row['id']);

        $expired++;
    }

    if ($expired > 0) {
        audit_log(AuditAction::AUTO_EXPIRE_ACCEPTED, "Auto-expired {$expired} accepted applicant(s) past deadline");
    }

    return $expired;
}

require_once __DIR__ . '/automation/exam_finalizer.php';

