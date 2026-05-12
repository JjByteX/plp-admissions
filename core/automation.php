<?php
// ============================================================
// core/automation.php
// Automation helpers for the admissions pipeline
// ============================================================

// ----------------------------------------------------------------
// NOTIFICATIONS
// ----------------------------------------------------------------

/**
 * Create an in-app notification for a user.
 */
function create_notification(int $userId, string $type, string $title, string $message = '', string $link = ''): void
{
    try {
        // Ensure table exists (graceful upgrade)
        ensure_notifications_table();

        db()->prepare(
            'INSERT INTO notifications (user_id, type, title, message, link)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([$userId, $type, $title, $message ?: null, $link ?: null]);
    } catch (\Throwable $e) {
        error_log('Notification error: ' . $e->getMessage());
    }
}

/**
 * Get unread notification count for a user.
 */
function notification_count(int $userId): int
{
    try {
        ensure_notifications_table();
        $stmt = db()->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
        $stmt->execute([$userId]);
        return (int) $stmt->fetchColumn();
    } catch (\Throwable) {
        return 0;
    }
}

/**
 * Get notifications for a user (newest first).
 */
function get_notifications(int $userId, int $limit = 20): array
{
    try {
        ensure_notifications_table();
        $stmt = db()->prepare(
            'SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT ?'
        );
        $stmt->execute([$userId, $limit]);
        return $stmt->fetchAll();
    } catch (\Throwable) {
        return [];
    }
}

/**
 * Mark all notifications as read for a user.
 */
function mark_notifications_read(int $userId): void
{
    try {
        ensure_notifications_table();
        db()->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0')
            ->execute([$userId]);
    } catch (\Throwable) {}
}

/**
 * Notify a student when their status changes.
 */
function notify_stage_transition(int $applicantId, string $newStatus, string $extra = ''): void
{
    $pdo = db();
    $stmt = $pdo->prepare('SELECT user_id FROM applicants WHERE id = ?');
    $stmt->execute([$applicantId]);
    $row = $stmt->fetch();
    if (!$row) return;

    $userId = (int) $row['user_id'];

    $messages = [
        'submitted'            => ['Documents Submitted', 'Your application documents have been submitted for review.', '/student/documents'],
        'exam'                 => ['Documents Approved', 'All your documents have been approved. You are now eligible for the entrance exam.' . ($extra ? ' ' . $extra : ''), '/student/exam'],
        'interview'            => ['Exam Passed', 'Congratulations! You passed the entrance exam. Your interview will be scheduled soon.', '/student/interview'],
        'released'             => ['Result Released', 'Your admission result has been released. Check your result now.' . ($extra ? ' ' . $extra : ''), '/student/result'],
        'withdrawn'            => ['Application Withdrawn', 'Your application has been withdrawn.', '/student/documents'],
        'enrollment_confirmed' => ['Enrollment Confirmed', 'You have confirmed your enrollment. Welcome to PLP!', '/student/result'],
    ];

    if (!isset($messages[$newStatus])) return;

    [$title, $message, $link] = $messages[$newStatus];
    create_notification($userId, 'stage_' . $newStatus, $title, $message, $link);

    // Send email notification
    try {
        $userStmt = $pdo->prepare('SELECT email, name FROM users WHERE id = ?');
        $userStmt->execute([$userId]);
        $user = $userStmt->fetch();
        if ($user && !empty($user['email'])) {
            $fullLink = rtrim(BASE_URL, '/') . $link;
            $emailBody = '<p>' . e($message) . '</p>'
                . '<p style="margin-top:16px"><a href="' . e($fullLink) . '" '
                . 'style="display:inline-block;padding:10px 24px;background:' . e(school_setting('accent_color', '#2d6a4f')) . ';color:#fff;'
                . 'text-decoration:none;border-radius:6px;font-weight:bold">View Details</a></p>';
            send_email(
                $user['email'],
                $title . ' — ' . school_setting('school_name', 'PLP Admissions'),
                email_template($title, $emailBody),
                $user['name']
            );
        }
    } catch (\Throwable $e) {
        error_log('Stage email failed: ' . $e->getMessage());
    }
}

/**
 * Send a welcome email after registration.
 */
function send_registration_email(string $email, string $name): void
{
    $body = '<p>Welcome, <strong>' . e($name) . '</strong>!</p>'
        . '<p>Your account has been created successfully. Please log in and upload your required documents to continue with your application.</p>'
        . '<p style="margin-top:16px"><a href="' . e(rtrim(BASE_URL, '/')) . '/student/documents" '
        . 'style="display:inline-block;padding:10px 24px;background:' . e(school_setting('accent_color', '#2d6a4f')) . ';color:#fff;'
        . 'text-decoration:none;border-radius:6px;font-weight:bold">Upload Documents</a></p>';
    send_email(
        $email,
        'Welcome to ' . school_setting('school_name', 'PLP Admissions'),
        email_template('Welcome!', $body),
        $name
    );
}

// ----------------------------------------------------------------
// AUTO-ASSIGN EXAM SLOTS
// ----------------------------------------------------------------

/**
 * Auto-assign an applicant to the next available exam slot.
 *
 * Called automatically after all documents are approved, and as a
 * backfill from a handful of safety-net code paths (exam page open,
 * new slot created, manual advance-to-exam, etc.) so an applicant
 * who was advanced before any matching slot existed will still get
 * assigned the moment one does.
 *
 * Hardening over the original version:
 *   - Wraps the slot pick + insert in a transaction with FOR UPDATE
 *     so two concurrent approvals can't both grab the last seat.
 *   - Skips withdrawn applicants and anyone not actually at the
 *     exam stage.
 *   - Skips slots whose date is today AND whose start time has
 *     already passed.
 *   - Honors the same exam (when exam_id is set on the slot) and
 *     prefers the active exam.
 *   - Falls back when the department field is blank or mistyped
 *     instead of giving up.
 */
function auto_assign_exam_slot(int $applicantId): ?int
{
    if (school_setting('auto_assign_exam_slots', '1') !== '1') return null;
    if ($applicantId <= 0) return null;

    $pdo = db();

    // ----------------------------------------------------------------
    // Pre-checks (cheap, no locks).
    // ----------------------------------------------------------------
    $stmt = $pdo->prepare(
        'SELECT a.id, a.overall_status, a.course_applied, u.department
           FROM applicants a
           JOIN users      u ON u.id = a.user_id
          WHERE a.id = ?
          LIMIT 1'
    );
    $stmt->execute([$applicantId]);
    $appl = $stmt->fetch();
    if (!$appl) return null;
    if (($appl['overall_status'] ?? '') === 'withdrawn') return null;
    // Only assign students who are currently at the exam stage. If a
    // caller fires this for someone still at documents/submitted we
    // skip rather than booking them prematurely.
    if (!in_array($appl['overall_status'] ?? '', ['exam'], true)) return null;

    $stmt = $pdo->prepare('SELECT id FROM applicant_exam_slots WHERE applicant_id = ? LIMIT 1');
    $stmt->execute([$applicantId]);
    if ($stmt->fetch()) return null;

    $dept = trim((string)($appl['department'] ?? ''))
          ?: course_to_department((string)($appl['course_applied'] ?? ''));

    // Active exam (if any) — when multiple exams coexist we prefer
    // slots tied to the active one so applicants don't get booked
    // into a stale exam.
    $activeExamId = null;
    try {
        $activeExamId = (int)($pdo->query('SELECT id FROM exams WHERE is_active = 1 ORDER BY id DESC LIMIT 1')
                              ->fetchColumn() ?: 0) ?: null;
    } catch (\Throwable) {}

    // ----------------------------------------------------------------
    // Candidate-slot search, in priority order:
    //   1. Same department + active exam
    //   2. Same department + any exam
    //   3. Department-agnostic ('' dept) + active exam
    //   4. Any open slot
    //
    // Each query already filters out past slots (date < today, or
    // date = today AND slot_time <= now).
    // ----------------------------------------------------------------
    $today   = date('Y-m-d');
    $nowTime = date('H:i:s');

    // ----------------------------------------------------------------
    // Candidate-slot search.
    //
    // HARD RULE: an applicant must NEVER be auto-assigned to a slot in
    // a different department/college. The old fallback (which booked
    // the student into "any open slot" if their own college had none)
    // is what caused BSA/BSIT students to appear in a CAS proctor's
    // roster. Removing it: if no matching slot exists, the student
    // stays on "Awaiting Slot Assignment" until SSO creates one for
    // their college, which is the correct UX.
    //
    // The dept-agnostic fallback is kept ONLY when the applicant has
    // no resolvable department at all (unknown course), so legacy
    // rows without a course→department mapping still get booked
    // somewhere instead of being silently stuck.
    // ----------------------------------------------------------------
    $candidates = [];
    $queries = [];
    if ($dept !== '' && $activeExamId !== null) {
        $queries[] = [
            'sql' => 'SELECT id FROM exam_slot_schedule
                       WHERE department = ?
                         AND exam_id = ?
                         AND filled < capacity
                         AND (exam_date > ? OR (exam_date = ? AND slot_time > ?))
                       ORDER BY exam_date ASC, slot_time ASC',
            'params' => [$dept, $activeExamId, $today, $today, $nowTime],
        ];
    }
    if ($dept !== '') {
        $queries[] = [
            'sql' => 'SELECT id FROM exam_slot_schedule
                       WHERE department = ?
                         AND filled < capacity
                         AND (exam_date > ? OR (exam_date = ? AND slot_time > ?))
                       ORDER BY exam_date ASC, slot_time ASC',
            'params' => [$dept, $today, $today, $nowTime],
        ];
    }
    if ($dept === '' && $activeExamId !== null) {
        $queries[] = [
            'sql' => 'SELECT id FROM exam_slot_schedule
                       WHERE (department IS NULL OR department = "")
                         AND exam_id = ?
                         AND filled < capacity
                         AND (exam_date > ? OR (exam_date = ? AND slot_time > ?))
                       ORDER BY exam_date ASC, slot_time ASC',
            'params' => [$activeExamId, $today, $today, $nowTime],
        ];
    }
    if ($dept === '') {
        $queries[] = [
            'sql' => 'SELECT id FROM exam_slot_schedule
                       WHERE (department IS NULL OR department = "")
                         AND filled < capacity
                         AND (exam_date > ? OR (exam_date = ? AND slot_time > ?))
                       ORDER BY exam_date ASC, slot_time ASC',
            'params' => [$today, $today, $nowTime],
        ];
    }

    foreach ($queries as $q) {
        $stmt = $pdo->prepare($q['sql']);
        $stmt->execute($q['params']);
        $rows = $stmt->fetchAll();
        if ($rows) {
            $candidates = $rows;
            break;
        }
    }

    if (!$candidates) return null;

    // ----------------------------------------------------------------
    // Try each candidate inside its own transaction. The FOR UPDATE
    // lock guarantees the capacity check + INSERT are atomic — if
    // someone else grabbed the last seat between SELECT and INSERT
    // we move on to the next candidate.
    // ----------------------------------------------------------------
    foreach ($candidates as $row) {
        $slotId = (int)$row['id'];
        try {
            $pdo->beginTransaction();

            $lock = $pdo->prepare(
                'SELECT id, exam_date, slot_time, end_time, room_label, capacity, filled
                   FROM exam_slot_schedule
                  WHERE id = ?
                  FOR UPDATE'
            );
            $lock->execute([$slotId]);
            $slot = $lock->fetch();
            if (!$slot
                || (int)$slot['filled'] >= (int)$slot['capacity']
                || (string)$slot['exam_date'] < $today
                || ((string)$slot['exam_date'] === $today
                    && !empty($slot['slot_time'])
                    && (string)$slot['slot_time'] <= $nowTime)) {
                $pdo->rollBack();
                continue;
            }

            // Defensive: re-check the applicant hasn't been assigned
            // between our pre-check and this point.
            $dupe = $pdo->prepare(
                'SELECT id FROM applicant_exam_slots WHERE applicant_id = ? FOR UPDATE'
            );
            $dupe->execute([$applicantId]);
            if ($dupe->fetch()) {
                $pdo->rollBack();
                return null;
            }

            $pdo->prepare(
                'INSERT INTO applicant_exam_slots (applicant_id, slot_id) VALUES (?, ?)'
            )->execute([$applicantId, $slotId]);

            $pdo->prepare(
                'UPDATE exam_slot_schedule SET filled = filled + 1 WHERE id = ?'
            )->execute([$slotId]);

            $pdo->commit();

            // ── post-commit notifications (best-effort) ──────────
            $dateStr = date('F j, Y', strtotime((string)$slot['exam_date']));
            $timeStr = !empty($slot['slot_time'])
                     ? date('g:i A', strtotime((string)$slot['slot_time']))
                     : '';
            $room    = (string)($slot['room_label'] ?? '');

            try {
                $uStmt = $pdo->prepare('SELECT user_id FROM applicants WHERE id = ?');
                $uStmt->execute([$applicantId]);
                $userId = (int)$uStmt->fetchColumn();
                if ($userId > 0) {
                    create_notification(
                        $userId,
                        'exam_slot_assigned',
                        'Exam Slot Assigned',
                        trim("You have been assigned to take the exam on {$dateStr}"
                            . ($timeStr ? " at {$timeStr}" : '')
                            . ($room    ? " in {$room}"    : '')
                            . '.'),
                        '/student/exam'
                    );
                }
            } catch (\Throwable $e) {
                error_log('exam slot assigned notification failed: ' . $e->getMessage());
            }

            audit_log(
                'exam_slot_auto_assigned',
                "Auto-assigned applicant {$applicantId} to slot {$slotId}",
                'applicant',
                $applicantId
            );
            return $slotId;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Auto-assign exam slot error: ' . $e->getMessage());
            // Continue to next candidate on transient errors.
            continue;
        }
    }

    return null;
}

/**
 * Backfill exam-slot assignments for every applicant who is at the
 * exam stage but has no slot row yet. Safe to call multiple times.
 *
 * Use cases:
 *   - When a new exam slot is created (a waiting applicant who
 *     matches its department / capacity is picked up immediately).
 *   - As a safety-net after bulk advance-to-exam.
 *
 * Returns the number of applicants who actually got assigned.
 */
function backfill_exam_slot_assignments(): int
{
    $pdo = db();
    $assigned = 0;
    try {
        $stmt = $pdo->query(
            'SELECT a.id
               FROM applicants a
          LEFT JOIN applicant_exam_slots aes ON aes.applicant_id = a.id
              WHERE a.overall_status = "exam"
                AND aes.id IS NULL
              ORDER BY a.documents_approved_at ASC, a.id ASC'
        );
        $waiting = $stmt->fetchAll();
    } catch (\Throwable $e) {
        error_log('backfill_exam_slot_assignments query failed: ' . $e->getMessage());
        return 0;
    }
    foreach ($waiting as $r) {
        $newSlot = auto_assign_exam_slot((int)$r['id']);
        if ($newSlot !== null) $assigned++;
    }
    return $assigned;
}

/**
 * Symmetric helper for the interview side. Scans for every applicant
 * sitting at the interview stage with no active interview_queue row
 * and tries to assign each one. Safe to call multiple times.
 *
 * Use cases:
 *   - As a safety-net after staff bulk actions, when the student
 *     refreshes /student/interview without a slot, or any place the
 *     applicant has been advanced to interview stage without being
 *     paired with a slot yet.
 *
 * Unlike bulk_assign_pending_applicants() (which bails on the first
 * applicant who can't be assigned, since it's normally used right
 * after one specific department's slot was created), this one walks
 * the full waiting list and keeps going — different applicants may
 * have different department matches.
 *
 * Returns the number of applicants who actually got assigned.
 */
function backfill_interview_slot_assignments(?int $actorUserId = null): int
{
    if (!function_exists('assign_interview_slot')) {
        return 0;
    }
    $pdo = db();
    try {
        $stmt = $pdo->query(
            'SELECT a.id
               FROM applicants a
          LEFT JOIN interview_queue q ON q.applicant_id = a.id
                                   AND q.interview_status IN ("pending","completed")
              WHERE a.overall_status = "interview"
                AND q.id IS NULL
              ORDER BY a.id ASC'
        );
        $waiting = array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN) ?: []);
    } catch (\Throwable $e) {
        error_log('backfill_interview_slot_assignments query failed: ' . $e->getMessage());
        return 0;
    }
    $assigned = 0;
    foreach ($waiting as $aid) {
        try {
            if (assign_interview_slot($aid, $actorUserId)) {
                $assigned++;
            }
        } catch (\Throwable $e) {
            error_log("backfill_interview_slot_assignments: applicant #{$aid} failed — " . $e->getMessage());
        }
    }
    return $assigned;
}

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
    if ($id !== null) return $id;
    try {
        $stmt = db()->prepare('SELECT id FROM users WHERE role = "admin" ORDER BY id ASC LIMIT 1');
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
         WHERE a.overall_status IN ("exam","interview","released")
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
             VALUES (?, ?, "Auto-released", ?, NOW())
             ON DUPLICATE KEY UPDATE result      = VALUES(result),
                                     remarks     = VALUES(remarks),
                                     released_by = VALUES(released_by),
                                     released_at = NOW()'
        )->execute([(int) $appl['applicant_id'], $decision, $systemUserId]);

        $pdo->prepare('UPDATE applicants SET overall_status = "released" WHERE id = ?')
            ->execute([(int) $appl['applicant_id']]);

        notify_stage_transition((int) $appl['applicant_id'], 'released', 'Result: ' . (RESULT_LABELS[$decision] ?? ucfirst($decision)));
        $counts[$decision]++;
    }

    if (array_sum($counts) > 0) {
        audit_log('auto_release_results',
            "Auto-released results: {$counts['accepted']} accepted, {$counts['rejected']} declined");
    }

    return $counts;
}

// ----------------------------------------------------------------
// BATCH INTERVIEW SESSION CREATION
// ----------------------------------------------------------------

/**
 * Create interview sessions in batch based on a template.
 *
 * @param array  $config  ['start_date', 'end_date', 'start_time', 'end_time',
 *                         'capacity', 'days' => [1,2,3,4,5] (Mon-Fri)]
 * @param string $department  Department name (or '' for all)
 * @param int    $staffId     Staff who created the sessions
 */
function batch_create_interview_sessions(array $config, string $department, int $staffId): int
{
    $pdo = db();
    $created = 0;

    $startDate = new DateTime($config['start_date']);
    $endDate   = new DateTime($config['end_date']);
    $startTime = $config['start_time'] ?? '09:00';
    $endTime   = $config['end_time']   ?? '16:00';
    $capacity  = max(1, (int)($config['capacity'] ?? 30));
    $days      = $config['days'] ?? [1, 2, 3, 4, 5]; // Mon-Fri by default

    // After the desk/session merge, sessions carry interviewer + location
    // directly. Callers can pass any of these in $config.
    $assignedTo    = isset($config['assigned_to']) && (int)$config['assigned_to'] > 0
        ? (int)$config['assigned_to']
        : null;
    $locationLabel = trim((string)($config['location_label'] ?? ''));
    $locationNotes = isset($config['location_notes']) ? (string)$config['location_notes'] : null;

    $departments = $department ? [$department] : departments_list();

    // Detect which optional columns exist on interview_slots so this works
    // before *and* after the merge migration is run.
    $hasAssignedTo = false;
    $hasLocLabel   = false;
    $hasLocNotes   = false;
    try { $pdo->query("SELECT assigned_to    FROM interview_slots LIMIT 0"); $hasAssignedTo = true; } catch (\Throwable $e) {}
    try { $pdo->query("SELECT location_label FROM interview_slots LIMIT 0"); $hasLocLabel   = true; } catch (\Throwable $e) {}
    try { $pdo->query("SELECT location_notes FROM interview_slots LIMIT 0"); $hasLocNotes   = true; } catch (\Throwable $e) {}

    $current = clone $startDate;
    while ($current <= $endDate) {
        $dayOfWeek = (int) $current->format('N'); // 1=Mon, 7=Sun

        if (in_array($dayOfWeek, $days, true)) {
            $dateStr = $current->format('Y-m-d');

            foreach ($departments as $dept) {
                // Skip if a session already exists for this date/department.
                $stmt = $pdo->prepare(
                    'SELECT id FROM interview_slots
                     WHERE slot_date = ? AND department = ?
                     LIMIT 1'
                );
                $stmt->execute([$dateStr, $dept]);
                if ($stmt->fetch()) continue;

                // Build INSERT dynamically so we only reference columns that exist.
                $cols   = ['slot_date', 'slot_time', 'end_time', 'capacity', 'department', 'created_by'];
                $values = [$dateStr, $startTime . ':00', $endTime . ':00', $capacity, $dept, $staffId];

                if ($hasAssignedTo) {
                    $cols[]   = 'assigned_to';
                    $values[] = $assignedTo;
                }
                if ($hasLocLabel) {
                    $cols[]   = 'location_label';
                    $values[] = $locationLabel;
                }
                if ($hasLocNotes) {
                    $cols[]   = 'location_notes';
                    $values[] = $locationNotes;
                }

                $placeholders = implode(', ', array_fill(0, count($cols), '?'));
                $colsSql      = implode(', ', $cols);

                $pdo->prepare(
                    "INSERT INTO interview_slots ({$colsSql}) VALUES ({$placeholders})"
                )->execute($values);
                $created++;

                // Auto-assign pending applicants to this new session
                bulk_assign_pending_applicants($dept, $staffId);
            }
        }

        $current->modify('+1 day');
    }

    if ($created > 0) {
        audit_log('batch_interview_sessions',
            "Batch-created {$created} interview session(s) for " . ($department ?: 'all departments'),
            'interview_slot');
    }

    return $created;
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
                MAX(DATEDIFF(NOW(), a.updated_at)) AS max_days
         FROM applicants a
         WHERE a.overall_status NOT IN ("released", "withdrawn")
           AND DATEDIFF(NOW(), a.updated_at) >= ?
         GROUP BY a.overall_status
         ORDER BY count DESC'
    );
    $stmt->execute([$days]);
    return $stmt->fetchAll();
}

// ----------------------------------------------------------------
// TABLE CREATION HELPERS (graceful upgrades)
// ----------------------------------------------------------------

function ensure_notifications_table(): void
{
    static $checked = false;
    if ($checked) return;
    $checked = true;

    try {
        db()->query('SELECT 1 FROM notifications LIMIT 0');
    } catch (\Throwable) {
        db()->exec('CREATE TABLE IF NOT EXISTS `notifications` (
            `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` INT(10) UNSIGNED NOT NULL,
            `type` VARCHAR(80) NOT NULL,
            `title` VARCHAR(255) NOT NULL,
            `message` TEXT DEFAULT NULL,
            `link` VARCHAR(500) DEFAULT NULL,
            `is_read` TINYINT(1) NOT NULL DEFAULT 0,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_notif_user` (`user_id`, `is_read`),
            KEY `idx_notif_created` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }
}

// ----------------------------------------------------------------
// EMAIL VERIFICATION
// ----------------------------------------------------------------

function ensure_email_verification_columns(): void
{
    static $checked = false;
    if ($checked) return;
    $checked = true;

    $pdo = db();

    // Base columns (legacy installs may not have these yet).
    try {
        $pdo->query('SELECT email_verified FROM users LIMIT 0');
    } catch (\Throwable) {
        $pdo->exec("ALTER TABLE users ADD COLUMN `email_verified` TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_active`");
        $pdo->exec("ALTER TABLE users ADD COLUMN `email_verify_token` VARCHAR(64) DEFAULT NULL AFTER `email_verified`");
        // Mark existing users as verified so they're not locked out
        $pdo->exec("UPDATE users SET email_verified = 1 WHERE id > 0");
    }

    // Newer columns used by the 6-digit code + cooldown flow.
    foreach ([
        ['email_verify_code',             "VARCHAR(8) DEFAULT NULL AFTER `email_verify_token`"],
        ['email_verify_code_expires_at',  "DATETIME DEFAULT NULL AFTER `email_verify_code`"],
        ['email_verify_attempts',         "TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER `email_verify_code_expires_at`"],
        ['email_verify_last_sent_at',     "DATETIME DEFAULT NULL AFTER `email_verify_attempts`"],
    ] as [$col, $def]) {
        try { $pdo->query("SELECT `{$col}` FROM users LIMIT 0"); }
        catch (\Throwable) {
            try { $pdo->exec("ALTER TABLE users ADD COLUMN `{$col}` {$def}"); }
            catch (\Throwable) {}
        }
    }
}

function generate_verify_token(int $userId): string
{
    ensure_email_verification_columns();
    $token = bin2hex(random_bytes(16));
    db()->prepare('UPDATE users SET email_verify_token = ? WHERE id = ?')->execute([$token, $userId]);
    return $token;
}

/**
 * Generate a fresh token + 6-digit code for the email-verification flow,
 * persist them on the user row, and return both so the caller can email them.
 */
function generate_verify_credentials(int $userId): array
{
    ensure_email_verification_columns();
    $token = bin2hex(random_bytes(16));
    $code  = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

    db()->prepare(
        'UPDATE users
            SET email_verify_token            = ?,
                email_verify_code             = ?,
                email_verify_code_expires_at  = DATE_ADD(NOW(), INTERVAL ? SECOND),
                email_verify_attempts         = 0,
                email_verify_last_sent_at     = NOW()
          WHERE id = ?'
    )->execute([$token, $code, (int) VERIFY_CODE_TTL_SECS, $userId]);

    return ['token' => $token, 'code' => $code];
}

/**
 * Returns ['ok' => bool, 'retry_after' => int] — caller is allowed to send a
 * fresh code only when ok=true. retry_after is the seconds remaining until the
 * cooldown window closes.
 */
function can_resend_verification(int $userId): array
{
    ensure_email_verification_columns();
    $stmt = db()->prepare(
        'SELECT GREATEST(0, ? - TIMESTAMPDIFF(SECOND, email_verify_last_sent_at, NOW()))
           FROM users
          WHERE id = ?
          LIMIT 1'
    );
    $stmt->execute([(int) VERIFY_RESEND_COOLDOWN_SECS, $userId]);
    $remaining = (int) ($stmt->fetchColumn() ?: 0);
    return ['ok' => $remaining <= 0, 'retry_after' => $remaining];
}

function find_user_by_verify_token(string $token): ?array
{
    if ($token === '') return null;
    ensure_email_verification_columns();
    $stmt = db()->prepare('SELECT * FROM users WHERE email_verify_token = ? AND is_active = 1 LIMIT 1');
    $stmt->execute([$token]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function mark_user_email_verified(int $userId): void
{
    ensure_email_verification_columns();
    db()->prepare(
        'UPDATE users
            SET email_verified                = 1,
                email_verify_token            = NULL,
                email_verify_code             = NULL,
                email_verify_code_expires_at  = NULL,
                email_verify_attempts         = 0
          WHERE id = ?'
    )->execute([$userId]);
}

/**
 * Validate a 6-digit code submitted via /verify-pending. Returns:
 *   ['ok' => true,  'user' => array, 'already' => bool]
 *   ['ok' => false, 'error' => string, 'attempts_remaining' => int]
 */
function verify_user_by_code(string $email, string $code): array
{
    ensure_email_verification_columns();

    $email = strtolower(trim($email));
    $code  = preg_replace('/\D/', '', $code);
    if ($email === '' || strlen($code) !== 6) {
        return ['ok' => false, 'error' => 'Invalid or expired code.'];
    }

    $stmt = db()->prepare('SELECT * FROM users WHERE LOWER(email) = ? AND is_active = 1 LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    if (!$user) {
        return ['ok' => false, 'error' => 'Invalid or expired code.'];
    }

    if (!empty($user['email_verified'])) {
        return ['ok' => true, 'user' => $user, 'already' => true];
    }

    if (empty($user['email_verify_code']) || empty($user['email_verify_code_expires_at'])) {
        return ['ok' => false, 'error' => 'Request a new code, then try again.'];
    }

    if (strtotime($user['email_verify_code_expires_at']) < time()) {
        return ['ok' => false, 'error' => 'This code has expired. Request a new one.'];
    }

    $tries = (int) ($user['email_verify_attempts'] ?? 0);
    if ($tries >= (int) VERIFY_MAX_CODE_ATTEMPTS) {
        return ['ok' => false, 'error' => 'Too many incorrect attempts. Request a new code.'];
    }

    if (!hash_equals((string) $user['email_verify_code'], $code)) {
        db()->prepare('UPDATE users SET email_verify_attempts = email_verify_attempts + 1 WHERE id = ?')
            ->execute([$user['id']]);
        return [
            'ok'                 => false,
            'error'              => 'Incorrect code.',
            'attempts_remaining' => max(0, (int) VERIFY_MAX_CODE_ATTEMPTS - ($tries + 1)),
        ];
    }

    mark_user_email_verified((int) $user['id']);
    $user['email_verified'] = 1;
    return ['ok' => true, 'user' => $user, 'already' => false];
}

function send_verification_email(string $email, string $name, string $token, ?string $code = null): void
{
    $verifyUrl = rtrim(BASE_URL, '/') . '/verify-email?token=' . $token;
    $accent    = e(school_setting('accent_color', '#2d6a4f'));

    $body  = '<p>Hello, <strong>' . e($name) . '</strong>!</p>'
        . '<p>Please verify your email address to activate your account.</p>';

    if ($code !== null && $code !== '') {
        $body .= '<p style="margin:16px 0 6px 0;color:#6b7280;font-size:13px">Verification code:</p>'
            . '<p style="margin:0 0 16px 0;font-family:monospace;font-size:28px;font-weight:bold;'
            . 'letter-spacing:.4em;color:' . $accent . '">' . e($code) . '</p>';
    }

    $body .= '<p style="margin-top:16px"><a href="' . e($verifyUrl) . '" '
        . 'style="display:inline-block;padding:12px 32px;background:' . $accent . ';color:#fff;'
        . 'text-decoration:none;border-radius:6px;font-weight:bold;font-size:16px">Verify Email</a></p>'
        . '<p style="margin-top:16px;color:#6b7280;font-size:13px">If the button doesn\'t work, copy this link:<br>'
        . '<a href="' . e($verifyUrl) . '" style="color:' . $accent . '">' . e($verifyUrl) . '</a></p>';

    send_email($email, 'Verify Your Email — ' . school_setting('school_name', 'PLP Admissions'), email_template('Verify Your Email', $body), $name);
}

// ----------------------------------------------------------------
// LOGIN RATE LIMITING
// ----------------------------------------------------------------

function ensure_login_attempts_table(): void
{
    static $checked = false;
    if ($checked) return;
    $checked = true;
    try {
        db()->query('SELECT 1 FROM login_attempts LIMIT 0');
    } catch (\Throwable) {
        db()->exec('CREATE TABLE IF NOT EXISTS `login_attempts` (
            `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            `email` VARCHAR(180) NOT NULL,
            `ip_address` VARCHAR(45) NOT NULL,
            `attempted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_la_email` (`email`, `attempted_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }
}

function record_failed_login(string $email): void
{
    ensure_login_attempts_table();
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
    if (str_contains($ip, ',')) $ip = trim(explode(',', $ip)[0]);
    db()->prepare('INSERT INTO login_attempts (email, ip_address) VALUES (?, ?)')->execute([$email, $ip]);
}

function clear_login_attempts(string $email): void
{
    ensure_login_attempts_table();
    db()->prepare('DELETE FROM login_attempts WHERE email = ?')->execute([$email]);
}

function is_login_locked(string $email): bool
{
    ensure_login_attempts_table();
    $stmt = db()->prepare('SELECT COUNT(*) FROM login_attempts WHERE email = ? AND attempted_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)');
    $stmt->execute([$email]);
    return (int) $stmt->fetchColumn() >= 5;
}

// ----------------------------------------------------------------
// EXAM AUTO-SAVE
// ----------------------------------------------------------------

function ensure_exam_drafts_table(): void
{
    static $checked = false;
    if ($checked) return;
    $checked = true;
    try {
        db()->query('SELECT 1 FROM exam_drafts LIMIT 0');
    } catch (\Throwable) {
        db()->exec('CREATE TABLE IF NOT EXISTS `exam_drafts` (
            `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            `applicant_id` INT(10) UNSIGNED NOT NULL,
            `exam_id` INT(10) UNSIGNED NOT NULL,
            `answers` LONGTEXT NOT NULL,
            `saved_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_draft` (`applicant_id`, `exam_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }
}

// ----------------------------------------------------------------
// INTERVIEW CHECK-IN CODES
// ----------------------------------------------------------------

function ensure_checkin_code_column(): void
{
    static $checked = false;
    if ($checked) return;
    $checked = true;
    try {
        db()->query('SELECT checkin_code FROM interview_queue LIMIT 0');
    } catch (\Throwable) {
        db()->exec("ALTER TABLE interview_queue ADD COLUMN `checkin_code` VARCHAR(20) DEFAULT NULL AFTER `status`");
        db()->exec("ALTER TABLE interview_queue ADD UNIQUE KEY `uq_checkin_code` (`checkin_code`)");
    }
}

/**
 * Generate a memorable check-in code like PLP-STAR-42
 */
function generate_checkin_code(): string
{
    $words = [
        'STAR','BLUE','HAWK','PINE','WAVE','BOLT','SAGE','PEAK',
        'DAWN','FERN','GLOW','JADE','LAKE','MINT','ONYX','RUBY',
        'VALE','WIND','ARCH','COVE','DOVE','ECHO','FLAX','HAZE',
        'IRIS','KITE','LARK','MIST','NOVA','OPAL','PALM','REEF',
        'SILK','TIDE','WREN','AURA','BIRCH','CLAY','DUSK','FAWN',
        'GILT','HAZE','IVY','LUNA','MOSS','NOOK','OAK','PIER',
        'RAIN','SKY','TEAL','VINE','WOLF','ZEST','REED','ROSE',
        'SAGE','SNOW','SAND','LUSH','CREST','DRIFT','FLAME','GROVE'
    ];

    ensure_checkin_code_column();
    $maxAttempts = 20;
    for ($i = 0; $i < $maxAttempts; $i++) {
        $word = $words[array_rand($words)];
        $num  = rand(10, 99);
        $code = "PLP-{$word}-{$num}";

        $stmt = db()->prepare('SELECT id FROM interview_queue WHERE checkin_code = ? LIMIT 1');
        $stmt->execute([$code]);
        if (!$stmt->fetch()) {
            return $code;
        }
    }
    // Fallback: use timestamp-based code
    return 'PLP-' . strtoupper(substr(base_convert(time(), 10, 36), -4)) . '-' . rand(10, 99);
}

// ----------------------------------------------------------------
// INTERVIEW RESCHEDULE REQUESTS
// ----------------------------------------------------------------

function ensure_reschedule_requests_table(): void
{
    static $checked = false;
    if ($checked) return;
    $checked = true;
    try {
        db()->query('SELECT 1 FROM reschedule_requests LIMIT 0');
    } catch (\Throwable) {
        db()->exec('CREATE TABLE IF NOT EXISTS `reschedule_requests` (
            `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            `applicant_id` INT(10) UNSIGNED NOT NULL,
            `queue_id` BIGINT(20) UNSIGNED NOT NULL,
            `reason` TEXT NOT NULL,
            `status` ENUM("pending","approved","denied") NOT NULL DEFAULT "pending",
            `reviewed_by` INT(10) UNSIGNED DEFAULT NULL,
            `reviewed_at` DATETIME DEFAULT NULL,
            `deny_reason` TEXT DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_rr_applicant` (`applicant_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }
    // Add deny_reason on existing installs without a manual migration.
    try {
        db()->query('SELECT deny_reason FROM reschedule_requests LIMIT 0');
    } catch (\Throwable) {
        try {
            db()->exec('ALTER TABLE `reschedule_requests` ADD COLUMN `deny_reason` TEXT NULL AFTER `reviewed_at`');
        } catch (\Throwable) {}
    }
}

// ----------------------------------------------------------------
// EXAM RESCHEDULE REQUESTS
// ----------------------------------------------------------------
//
// Mirror of reschedule_requests but for exam slots. Created on-demand
// the first time a student submits an exam-reschedule request, so
// existing installs don't need a manual migration.
//
function ensure_exam_reschedule_requests_table(): void
{
    static $checked = false;
    if ($checked) return;
    $checked = true;
    try {
        db()->query('SELECT 1 FROM exam_reschedule_requests LIMIT 0');
    } catch (\Throwable) {
        db()->exec('CREATE TABLE IF NOT EXISTS `exam_reschedule_requests` (
            `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            `applicant_id` INT(10) UNSIGNED NOT NULL,
            `slot_id` INT(10) UNSIGNED NOT NULL,
            `reason` TEXT NOT NULL,
            `status` ENUM("pending","approved","denied") NOT NULL DEFAULT "pending",
            `reviewed_by` INT(10) UNSIGNED DEFAULT NULL,
            `reviewed_at` DATETIME DEFAULT NULL,
            `deny_reason` TEXT DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_err_applicant` (`applicant_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }
    // Add deny_reason on existing installs without a manual migration.
    try {
        db()->query('SELECT deny_reason FROM exam_reschedule_requests LIMIT 0');
    } catch (\Throwable) {
        try {
            db()->exec('ALTER TABLE `exam_reschedule_requests` ADD COLUMN `deny_reason` TEXT NULL AFTER `reviewed_at`');
        } catch (\Throwable) {}
    }
}

// ----------------------------------------------------------------
// RESCHEDULE: shared decision notification (in-app + email)
// ----------------------------------------------------------------

/**
 * In-app notification + branded email letting a student know their
 * reschedule request was approved or denied. Used by both the
 * interview and exam approve/deny handlers.
 *
 * $kind     = 'interview' | 'exam'
 * $decision = 'approved'  | 'denied'
 * $extra    = optional details ("new slot: Mon Jan 5, 9 AM, Rm 201"
 *             on approve; "reason: scheduling conflict" on deny)
 */
function notify_reschedule_decision(
    int $applicantId,
    string $kind,
    string $decision,
    string $extra = ''
): void {
    $kind     = $kind === 'exam' ? 'exam' : 'interview';
    $decision = $decision === 'approved' ? 'approved' : 'denied';

    try {
        $stmt = db()->prepare(
            'SELECT u.id AS user_id, u.email, u.name
               FROM applicants a
               JOIN users u ON u.id = a.user_id
              WHERE a.id = ?
              LIMIT 1'
        );
        $stmt->execute([$applicantId]);
        $user = $stmt->fetch();
        if (!$user) return;

        $link = $kind === 'exam' ? '/student/exam' : '/student/interview';

        if ($decision === 'approved') {
            $title = 'Reschedule approved';
            $msg   = ucfirst($kind) . " reschedule approved."
                   . ($extra ? ' ' . $extra : '');
        } else {
            $title = 'Reschedule request denied';
            $msg   = "Your {$kind} reschedule request was not approved."
                   . ($extra ? ' ' . $extra : '');
        }

        create_notification((int)$user['user_id'], 'reschedule_' . $decision, $title, $msg, $link);

        if (!empty($user['email'])) {
            $fullLink  = rtrim(BASE_URL, '/') . $link;
            $emailBody = '<p>' . e($msg) . '</p>'
                       . '<p style="margin-top:16px"><a href="' . e($fullLink) . '" '
                       . 'style="display:inline-block;padding:10px 24px;background:' . e(school_setting('accent_color', '#2d6a4f')) . ';color:#fff;'
                       . 'text-decoration:none;border-radius:6px;font-weight:bold">View Details</a></p>';
            send_email(
                (string)$user['email'],
                $title . ' — ' . school_setting('school_name', 'PLP Admissions'),
                email_template($title, $emailBody),
                (string)$user['name']
            );
        }
    } catch (\Throwable $e) {
        error_log('reschedule decision notify failed: ' . $e->getMessage());
    }
}

// ----------------------------------------------------------------
// A3: STAFF NOTIFICATIONS
// ----------------------------------------------------------------

/**
 * Notify all staff/admin users about an event.
 */
function notify_staff(string $type, string $title, string $message = '', string $link = ''): void
{
    try {
        $staff = db()->query("SELECT id FROM users WHERE role IN ('staff','admin') AND is_active=1")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($staff as $userId) {
            create_notification((int)$userId, $type, $title, $message, $link);
        }
    } catch (\Throwable $e) {
        error_log('notify_staff error: ' . $e->getMessage());
    }
}

/**
 * Notify staff when new documents are uploaded and awaiting review.
 */
function notify_staff_new_documents(int $applicantId, string $applicantName): void
{
    notify_staff(
        'staff_docs_uploaded',
        'New Documents Uploaded',
        "{$applicantName} has uploaded documents awaiting review.",
        '/staff/applicants/' . $applicantId
    );
}

/**
 * Notify staff when an interview session is about to start (30 min reminder).
 */
function notify_staff_interview_reminder(int $slotId, string $date, string $time, string $dept): void
{
    notify_staff(
        'staff_interview_reminder',
        'Interview Starting Soon',
        "Interview session for {$dept} on " . date('M j', strtotime($date)) . " at " . date('g:i A', strtotime($time)) . " starts in 30 minutes.",
        '/staff/interviews/queue'
    );
}

/**
 * Notify staff when a no-show is detected.
 */
function notify_staff_no_show(int $applicantId, string $applicantName): void
{
    notify_staff(
        'staff_no_show',
        'No-Show Detected',
        "{$applicantName} was marked as a no-show for their interview.",
        '/staff/interviews/absent'
    );
}

/**
 * Notify staff when results are pending release.
 */
function notify_staff_results_pending(int $count): void
{
    notify_staff(
        'staff_results_pending',
        'Results Pending Release',
        "{$count} applicant(s) have completed interviews but don't have a decision yet.",
        '/staff/results?result=pending'
    );
}

// ----------------------------------------------------------------
// B1: AUTO-RESCHEDULE NO-SHOWS
// ----------------------------------------------------------------

/**
 * Auto-reschedule a no-show applicant to the next available interview slot.
 * Called from record_interview_evaluation() when absent=true, from the
 * mark_no_show staff action, and from the dashboard "Reschedule absent
 * students" button.
 *
 * Expects the applicant's queue row to already be in the canonical
 * absent state (interview_status='absent'). When called from
 * record_interview_evaluation / mark_no_show / auto_detect_interview_no_shows
 * that is already true. If called against a still-pending row, this
 * routine also flips it to 'absent' first so the rescheduler has a
 * consistent starting point.
 */
function auto_reschedule_noshow(int $applicantId, ?int $actorUserId = null): ?int
{
    if (school_setting('auto_reschedule_noshows', '1') !== '1') return null;

    $pdo = db();

    // If somehow the row is still 'pending', normalise it to the
    // canonical absent state. This is a no-op when the caller (mark_no_show,
    // record_interview_evaluation, auto_detect_interview_no_shows) already
    // did it.
    $pdo->prepare(
        'UPDATE interview_queue
            SET status            = "no_show",
                interview_status  = "absent",
                attendance_status = "absent",
                evaluated_at      = COALESCE(evaluated_at, NOW())
          WHERE applicant_id = ?
            AND interview_status = "pending"'
    )->execute([$applicantId]);

    // Ensure applicant stays in interview stage
    $pdo->prepare(
        'UPDATE applicants SET overall_status = "interview" WHERE id = ? AND overall_status IN ("interview","result")'
    )->execute([$applicantId]);

    // Try to reschedule via the absent-aware path (which deletes the
    // absent row and asks the scheduler for a new one). Fall back to
    // the generic rescheduler for legacy rows that aren't yet absent.
    try {
        $newSlotId = null;
        if (function_exists('reschedule_absent_applicant')) {
            $newSlotId = reschedule_absent_applicant($applicantId, null, $actorUserId ?? 0);
        }
        if (!$newSlotId && function_exists('reschedule_interview')) {
            $newSlotId = reschedule_interview($applicantId, $actorUserId);
        }
        if ($newSlotId) {
            // Notify the student
            $stmt = $pdo->prepare('SELECT user_id FROM applicants WHERE id = ?');
            $stmt->execute([$applicantId]);
            $userId = (int)$stmt->fetchColumn();

            $slotStmt = $pdo->prepare('SELECT slot_date, slot_time FROM interview_slots WHERE id = ?');
            $slotStmt->execute([$newSlotId]);
            $slotInfo = $slotStmt->fetch();

            if ($slotInfo && $userId) {
                create_notification(
                    $userId,
                    'interview_rescheduled',
                    'Interview Rescheduled',
                    'You missed your previous interview. You have been rescheduled to '
                        . date('F j, Y', strtotime($slotInfo['slot_date']))
                        . ' at ' . date('g:i A', strtotime($slotInfo['slot_time'])) . '.',
                    '/student/interview'
                );
            }

            audit_log('auto_reschedule_noshow', "Auto-rescheduled no-show applicant {$applicantId} to slot {$newSlotId}", 'applicant', $applicantId);
            return $newSlotId;
        }
    } catch (\Throwable $e) {
        error_log('auto_reschedule_noshow error: ' . $e->getMessage());
    }

    return null;
}

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
    if ($reminderDays <= 0) return 0;

    $pdo = db();
    $notified = 0;

    $stmt = $pdo->prepare(
        "SELECT a.id, a.user_id, u.name
         FROM applicants a
         JOIN users u ON u.id = a.user_id
         WHERE a.overall_status IN ('pending','documents')
           AND DATEDIFF(NOW(), a.updated_at) >= ?
           AND NOT EXISTS (
               SELECT 1 FROM notifications n
               WHERE n.user_id = a.user_id
                 AND n.type = 'doc_reminder'
                 AND n.created_at > DATE_SUB(NOW(), INTERVAL ? DAY)
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
        audit_log('doc_reminders_sent', "Sent document reminders to {$notified} applicant(s)");
    }

    return $notified;
}

// ----------------------------------------------------------------
// B3: BATCH EXAM SLOT CREATION
// ----------------------------------------------------------------

/**
 * Create exam slots in batch based on a date range template.
 */
function batch_create_exam_slots(array $config, string $department, int $staffId): int
{
    $pdo = db();
    $created = 0;

    $startDate = new DateTime($config['start_date']);
    $endDate   = new DateTime($config['end_date']);
    $time      = $config['slot_time']   ?? '08:00';
    $endTime   = $config['end_time']    ?? '';
    $room      = $config['room_label']  ?? '';
    $capacity  = max(1, (int)($config['capacity'] ?? 35));
    $days      = $config['days'] ?? [1, 2, 3, 4, 5];

    // Default close time = open + 90 min if caller didn't specify one.
    if ($endTime === '') {
        $endTime = date('H:i', strtotime($time . ' +90 minutes'));
    }

    $schoolYear = school_setting('current_school_year', date('Y') . '-' . (date('Y') + 1));
    $activeExam = $pdo->query('SELECT id FROM exams WHERE is_active=1 LIMIT 1')->fetch();
    $examId     = $activeExam ? (int)$activeExam['id'] : null;

    $current = clone $startDate;
    while ($current <= $endDate) {
        $dayOfWeek = (int) $current->format('N');

        if (in_array($dayOfWeek, $days, true)) {
            $dateStr = $current->format('Y-m-d');

            // Check if slot already exists for this date/dept/room
            $stmt = $pdo->prepare(
                'SELECT id FROM exam_slot_schedule
                 WHERE exam_date = ? AND department = ? AND room_label = ?
                 LIMIT 1'
            );
            $stmt->execute([$dateStr, $department, $room]);
            if (!$stmt->fetch()) {
                $pdo->prepare(
                    'INSERT INTO exam_slot_schedule
                        (exam_id, exam_date, slot_time, end_time, room_label, department, capacity, school_year, created_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
                )->execute([$examId, $dateStr, $time . ':00', $endTime . ':00', $room, $department, $capacity, $schoolYear, $staffId]);
                $created++;
            }
        }

        $current->modify('+1 day');
    }

    if ($created > 0) {
        audit_log('batch_exam_slots',
            "Batch-created {$created} exam slot(s) for " . ($department ?: 'unassigned'),
            'exam_slot');
    }

    return $created;
}

// ----------------------------------------------------------------
// B4: AUTO-CLOSE EXPIRED INTERVIEW SESSIONS
// ----------------------------------------------------------------

/**
 * Auto-close interview sessions past their end time.
 * Marks remaining scheduled applicants as no-shows.
 * Returns count of sessions closed.
 */
function auto_close_expired_sessions(): int
{
    $pdo = db();
    $closed = 0;

    $stmt = $pdo->query(
        "SELECT s.id, s.slot_date, s.end_time, s.department
         FROM interview_slots s
         WHERE s.status = 'open'
           AND (
               s.slot_date < CURDATE()
               OR (s.slot_date = CURDATE() AND s.end_time IS NOT NULL AND s.end_time < CURTIME())
           )"
    );
    $expired = $stmt->fetchAll();

    foreach ($expired as $slot) {
        // Mark remaining unevaluated applicants as absent (canonical
        // no-show state used by record_interview_evaluation). The
        // dedicated helper sets status='no_show',
        // interview_status='absent', attendance_status='absent' and
        // notifies each student.
        $noShows = 0;
        if (function_exists('auto_detect_interview_no_shows')) {
            try {
                $noShows = auto_detect_interview_no_shows((int)$slot['id']);
            } catch (\Throwable $e) {
                error_log('auto_detect_interview_no_shows on slot ' . $slot['id'] . ' failed: ' . $e->getMessage());
            }
        }

        // Close the slot
        $pdo->prepare('UPDATE interview_slots SET status = "closed" WHERE id = ?')
            ->execute([$slot['id']]);

        audit_log('auto_close_session',
            "Auto-closed expired session #{$slot['id']} ({$slot['slot_date']}). {$noShows} marked as absent.",
            'interview_slot', $slot['id']);

        $closed++;
    }

    return $closed;
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
    if ($deadlineDays <= 0) return 0;

    $pdo = db();
    $expired = 0;

    $stmt = $pdo->prepare(
        "SELECT a.id, a.user_id, a.course_applied, ar.released_at
         FROM applicants a
         JOIN admission_results ar ON ar.applicant_id = a.id
         WHERE ar.result = 'accepted'
           AND a.overall_status = 'released'
           AND ar.released_at IS NOT NULL
           AND DATEDIFF(NOW(), ar.released_at) > ?"
    );
    $stmt->execute([$deadlineDays]);
    $stale = $stmt->fetchAll();

    foreach ($stale as $row) {
        // Mark as slot_expired
        $pdo->prepare(
            "UPDATE admission_results SET result = 'rejected',
                    remarks = CONCAT(COALESCE(remarks,''), '\nSlot expired — no action within {$deadlineDays} days')
             WHERE applicant_id = ?"
        )->execute([$row['id']]);

        $pdo->prepare("UPDATE applicants SET overall_status = 'withdrawn' WHERE id = ?")
            ->execute([$row['id']]);

        // Notify
        create_notification(
            (int)$row['user_id'],
            'acceptance_expired',
            'Acceptance Slot Expired',
            "Your acceptance for {$row['course_applied']} has expired because no action was taken within {$deadlineDays} days.",
            '/student/result'
        );

        // Promote next waitlisted
        auto_promote_waitlist($row['id']);

        $expired++;
    }

    if ($expired > 0) {
        audit_log('auto_expire_accepted', "Auto-expired {$expired} accepted applicant(s) past deadline");
    }

    return $expired;
}

// ----------------------------------------------------------------
// B7: SMART EXAM SCHEDULING
// ----------------------------------------------------------------

/**
 * Smart exam slot assignment — assigns to least-filled slot instead of first available.
 * Enhanced version of auto_assign_exam_slot().
 */
function smart_assign_exam_slot(int $applicantId): ?int
{
    if (school_setting('auto_assign_exam_slots', '1') !== '1') return null;

    $pdo = db();

    // Check if already assigned
    $stmt = $pdo->prepare('SELECT id FROM applicant_exam_slots WHERE applicant_id = ?');
    $stmt->execute([$applicantId]);
    if ($stmt->fetch()) return null;

    // Get applicant's department
    $stmt = $pdo->prepare(
        'SELECT a.course_applied, u.department
         FROM applicants a JOIN users u ON u.id = a.user_id
         WHERE a.id = ?'
    );
    $stmt->execute([$applicantId]);
    $appl = $stmt->fetch();
    if (!$appl) return null;

    $dept = $appl['department'] ?: course_to_department($appl['course_applied']);

    // Find the LEAST FILLED slot (smart load balancing) instead of first available
    $params = [$dept];
    $sql = 'SELECT ess.id, ess.capacity,
                   (SELECT COUNT(*) FROM applicant_exam_slots WHERE slot_id = ess.id) AS filled
            FROM exam_slot_schedule ess
            WHERE ess.exam_date >= CURDATE()';

    if ($dept) {
        $sql .= ' AND (ess.department = ? OR ess.department = "")';
    } else {
        $sql .= ' AND 1=1';
        $params = [];
    }
    $sql .= ' HAVING filled < ess.capacity
              ORDER BY (filled / ess.capacity) ASC, ess.exam_date ASC, ess.slot_time ASC
              LIMIT 1';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $slot = $stmt->fetch();

    if (!$slot) return null;

    $slotId = (int) $slot['id'];

    try {
        $pdo->prepare(
            'INSERT INTO applicant_exam_slots (applicant_id, slot_id) VALUES (?, ?)'
        )->execute([$applicantId, $slotId]);

        $pdo->prepare(
            'UPDATE exam_slot_schedule SET filled = filled + 1 WHERE id = ?'
        )->execute([$slotId]);

        // Notification
        $stmt = $pdo->prepare('SELECT exam_date, slot_time, room_label FROM exam_slot_schedule WHERE id = ?');
        $stmt->execute([$slotId]);
        $slotInfo = $stmt->fetch();

        if ($slotInfo) {
            $stmt = $pdo->prepare('SELECT user_id FROM applicants WHERE id = ?');
            $stmt->execute([$applicantId]);
            $userId = (int) $stmt->fetchColumn();

            // Day-before reminder
            create_notification(
                $userId,
                'exam_slot_assigned',
                'Exam Slot Assigned',
                "You have been assigned to take the exam on "
                    . date('F j, Y', strtotime($slotInfo['exam_date']))
                    . " at " . date('g:i A', strtotime($slotInfo['slot_time']))
                    . " in {$slotInfo['room_label']}.",
                '/student/exam'
            );
        }

        audit_log('smart_exam_slot_assigned', "Smart-assigned applicant {$applicantId} to least-filled slot {$slotId}", 'applicant', $applicantId);
        return $slotId;
    } catch (\Throwable $e) {
        error_log('Smart assign exam slot error: ' . $e->getMessage());
        return null;
    }
}

/**
 * Send exam day-before reminder notifications.
 */
function send_exam_reminders(): int
{
    $pdo = db();
    $tomorrow = date('Y-m-d', strtotime('+1 day'));
    $notified = 0;

    $stmt = $pdo->prepare(
        "SELECT aes.applicant_id, a.user_id, ess.exam_date, ess.slot_time, ess.room_label
         FROM applicant_exam_slots aes
         JOIN exam_slot_schedule ess ON ess.id = aes.slot_id
         JOIN applicants a ON a.id = aes.applicant_id
         WHERE ess.exam_date = ?
           AND NOT EXISTS (
               SELECT 1 FROM notifications n
               WHERE n.user_id = a.user_id
                 AND n.type = 'exam_reminder'
                 AND n.created_at > DATE_SUB(NOW(), INTERVAL 1 DAY)
           )"
    );
    $stmt->execute([$tomorrow]);

    foreach ($stmt->fetchAll() as $row) {
        create_notification(
            (int)$row['user_id'],
            'exam_reminder',
            'Exam Tomorrow',
            'Your exam is tomorrow, ' . date('F j, Y', strtotime($row['exam_date']))
                . ' at ' . date('g:i A', strtotime($row['slot_time']))
                . ' in ' . $row['room_label'] . '. Good luck!',
            '/student/exam'
        );
        $notified++;
    }

    return $notified;
}


