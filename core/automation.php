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
        db()->prepare(
            'INSERT INTO notifications (user_id, type, title, message, link)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([$userId, $type, $title, $message ?: null, $link ?: null]);
    } catch (\Throwable $e) {
        error_log('Notification error: ' . $e->getMessage());
    }
}

/**
 * In-app notification + branded email for one user (student).
 * Looks up the email/name itself. A mail failure is logged, never thrown.
 * $link is an app path ('/student/exam'); the email button gets the full URL.
 */
function notify_student(
    int $userId,
    string $type,
    string $title,
    string $message,
    string $link,
    string $buttonLabel = 'View Details'
): void {
    create_notification($userId, $type, $title, $message, $link);

    try {
        $stmt = db()->prepare('SELECT email, name FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        if ($user && !empty($user['email'])) {
            $emailBody = '<p>' . e($message) . '</p>'
                . email_button(rtrim(BASE_URL, '/') . $link, $buttonLabel);
            send_email(
                (string) $user['email'],
                $title . ' - ' . school_setting('school_name', 'PLP Admissions'),
                email_template($title, $emailBody),
                (string) $user['name']
            );
        }
    } catch (\Throwable $e) {
        error_log('notify_student email failed: ' . $e->getMessage());
    }
}

/**
 * Get unread notification count for a user.
 */
function notification_count(int $userId): int
{
    try {
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
    if (!$row) {
        return;
    }

    $userId = (int) $row['user_id'];

    $messages = [
        'submitted' => [
            'Documents Submitted',
            'Your application documents have been submitted for review.',
            '/student/documents',
        ],
        'exam' => [
            'Documents Approved',
            'All your documents have been approved. You are now eligible for the entrance exam.'
                . ($extra ? ' ' . $extra : ''),
            '/student/exam',
        ],
        'interview' => [
            'Exam Passed',
            'Congratulations! You passed the entrance exam. Your interview will be scheduled soon.',
            '/student/interview',
        ],
        'released' => [
            'Result Released',
            'Your admission result has been released. Check your result now.' . ($extra ? ' ' . $extra : ''),
            '/student/result',
        ],
        'withdrawn' => [
            'Application Withdrawn',
            'Your application has been withdrawn.',
            '/student/documents',
        ],
        'enrollment_confirmed' => [
            'Enrollment Confirmed',
            'You have confirmed your enrollment. Welcome to PLP!' . ($extra ? ' ' . $extra : ''),
            '/student/result',
        ],
    ];

    if (!isset($messages[$newStatus])) {
        return;
    }

    [$title, $message, $link] = $messages[$newStatus];
    notify_student($userId, 'stage_' . $newStatus, $title, $message, $link);
}

/**
 * Send a welcome email after registration.
 */
function send_registration_email(string $email, string $name): void
{
    $body = '<p>Welcome, <strong>' . e($name) . '</strong>!</p>'
        . '<p>Your account has been created successfully. Please log in and upload your required documents '
        . 'to continue with your application.</p>'
        . email_button(rtrim(BASE_URL, '/') . '/student/documents', 'Upload Documents');
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
    $debug = [];  // collects reasons for failure - written to error_log if we return null

    if (school_setting('auto_assign_exam_slots', '1') !== '1') {
        error_log("[auto_assign #{$applicantId}] SKIP: auto_assign_exam_slots setting is OFF");
        return null;
    }
    if ($applicantId <= 0) {
        return null;
    }

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
    if (!$appl) {
        error_log("[auto_assign #{$applicantId}] SKIP: applicant not found");
        return null;
    }
    if (($appl['overall_status'] ?? '') === 'withdrawn') {
        error_log("[auto_assign #{$applicantId}] SKIP: status=withdrawn");
        return null;
    }
    if (!in_array($appl['overall_status'] ?? '', ['exam'], true)) {
        error_log("[auto_assign #{$applicantId}] SKIP: status={$appl['overall_status']} (not exam stage)");
        return null;
    }

    $stmt = $pdo->prepare('SELECT id FROM applicant_exam_slots WHERE applicant_id = ? LIMIT 1');
    $stmt->execute([$applicantId]);
    if ($stmt->fetch()) {
        error_log("[auto_assign #{$applicantId}] SKIP: already has a slot");
        return null;
    }

    $dept = trim((string)($appl['department'] ?? ''))
          ?: course_to_department((string)($appl['course_applied'] ?? ''));

    error_log(
        "[auto_assign #{$applicantId}] status={$appl['overall_status']} course={$appl['course_applied']} "
        . "users.department={$appl['department']} resolved_dept={$dept}"
    );

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
                       WHERE LOWER(TRIM(department)) = LOWER(TRIM(?))
                         AND exam_id = ?
                         AND filled < capacity
                         AND (exam_date > ? OR (exam_date = ? AND end_time > ?))
                       ORDER BY exam_date ASC, slot_time ASC',
            'params' => [$dept, $activeExamId, $today, $today, $nowTime],
        ];
    }
    if ($dept !== '') {
        $queries[] = [
            'sql' => 'SELECT id FROM exam_slot_schedule
                       WHERE LOWER(TRIM(department)) = LOWER(TRIM(?))
                         AND filled < capacity
                         AND (exam_date > ? OR (exam_date = ? AND end_time > ?))
                       ORDER BY exam_date ASC, slot_time ASC',
            'params' => [$dept, $today, $today, $nowTime],
        ];
    }
    if ($dept === '' && $activeExamId !== null) {
        $queries[] = [
            'sql' => 'SELECT id FROM exam_slot_schedule
                       WHERE (department IS NULL OR department = \'\')
                         AND exam_id = ?
                         AND filled < capacity
                         AND (exam_date > ? OR (exam_date = ? AND end_time > ?))
                       ORDER BY exam_date ASC, slot_time ASC',
            'params' => [$activeExamId, $today, $today, $nowTime],
        ];
    }
    if ($dept === '') {
        $queries[] = [
            'sql' => 'SELECT id FROM exam_slot_schedule
                       WHERE (department IS NULL OR department = \'\')
                         AND filled < capacity
                         AND (exam_date > ? OR (exam_date = ? AND end_time > ?))
                       ORDER BY exam_date ASC, slot_time ASC',
            'params' => [$today, $today, $nowTime],
        ];
    }

    foreach ($queries as $qi => $q) {
        $stmt = $pdo->prepare($q['sql']);
        $stmt->execute($q['params']);
        $rows = $stmt->fetchAll();
        error_log(
            "[auto_assign #{$applicantId}] query #{$qi} params=" . json_encode($q['params'])
            . " found=" . count($rows)
        );
        if ($rows) {
            $candidates = $rows;
            break;
        }
    }

    if (!$candidates) {
        // Log ALL slots in the DB so we can see what's there vs what we searched for
        $allSlots = $pdo->query(
            'SELECT id, department, exam_date, slot_time, filled, capacity '
            . 'FROM exam_slot_schedule ORDER BY exam_date ASC LIMIT 20'
        )->fetchAll();
        error_log("[auto_assign #{$applicantId}] NO CANDIDATES. All slots in DB: " . json_encode($allSlots));
        error_log("[auto_assign #{$applicantId}] today=" . date('Y-m-d') . " now=" . date('H:i:s'));
        return null;
    }

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
                    && !empty($slot['end_time'])
                    && (string)$slot['end_time'] <= $nowTime)) {
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
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
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
              WHERE a.overall_status = \'exam\'
                AND aes.id IS NULL
              ORDER BY a.documents_approved_at ASC NULLS FIRST, a.id ASC'
        );
        $waiting = $stmt->fetchAll();
    } catch (\Throwable $e) {
        error_log('backfill_exam_slot_assignments query failed: ' . $e->getMessage());
        return 0;
    }
    foreach ($waiting as $r) {
        $newSlot = auto_assign_exam_slot((int)$r['id']);
        if ($newSlot !== null) {
            $assigned++;
        }
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
                                   AND q.interview_status IN (\'pending\',\'completed\')
              WHERE a.overall_status = \'interview\'
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
            error_log("backfill_interview_slot_assignments: applicant #{$aid} failed - " . $e->getMessage());
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
        audit_log('auto_release_results',
            "Auto-released results: {$counts['accepted']} accepted, {$counts['rejected']} declined");
    }

    return $counts;
}

// ----------------------------------------------------------------
// BATCH INTERVIEW SESSION CREATION
// ----------------------------------------------------------------

/**
 * Yield each 'Y-m-d' from $config['start_date'] to $config['end_date'] (inclusive)
 * whose ISO weekday (1=Mon .. 7=Sun) is in $config['days'] (default Mon-Fri).
 */
function iterate_dates(array $config): Generator
{
    $current = new DateTime($config['start_date']);
    $end     = new DateTime($config['end_date']);
    $days    = $config['days'] ?? [1, 2, 3, 4, 5];

    while ($current <= $end) {
        if (in_array((int) $current->format('N'), $days, true)) {
            yield $current->format('Y-m-d');
        }
        $current->modify('+1 day');
    }
}

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

    $startTime = $config['start_time'] ?? '09:00';
    $endTime   = $config['end_time']   ?? '16:00';
    $capacity  = max(1, (int)($config['capacity'] ?? 30));

    // After the desk/session merge, sessions carry interviewer + location
    // directly. Callers can pass any of these in $config.
    $assignedTo    = isset($config['assigned_to']) && (int)$config['assigned_to'] > 0
        ? (int)$config['assigned_to']
        : null;
    $locationLabel = trim((string)($config['location_label'] ?? ''));
    $locationNotes = isset($config['location_notes']) ? (string)$config['location_notes'] : null;

    $departments = $department ? [$department] : departments_list();

    foreach (iterate_dates($config) as $dateStr) {
        foreach ($departments as $dept) {
            // Skip if a session already exists for this date/department.
            $stmt = $pdo->prepare(
                'SELECT id FROM interview_slots
                 WHERE slot_date = ? AND department = ?
                 LIMIT 1'
            );
            $stmt->execute([$dateStr, $dept]);
            if ($stmt->fetch()) {
                continue;
            }

            $cols   = ['slot_date', 'slot_time', 'end_time', 'capacity', 'department', 'created_by',
                       'assigned_to', 'location_label', 'location_notes'];
            $values = [$dateStr, $startTime . ':00', $endTime . ':00', $capacity, $dept, $staffId,
                       $assignedTo, $locationLabel, $locationNotes];

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

// ----------------------------------------------------------------
// EMAIL VERIFICATION
// ----------------------------------------------------------------

/**
 * Generate a fresh token + 6-digit code for the email-verification flow,
 * persist them on the user row, and return both so the caller can email them.
 */
function generate_verify_credentials(int $userId): array
{
    $token = bin2hex(random_bytes(16));
    $code  = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

    db()->prepare(
        'UPDATE users
            SET email_verify_token            = ?,
                email_verify_code             = ?,
                email_verify_code_expires_at  = NOW() + (CAST(? AS integer) * INTERVAL \'1 second\'),
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
    $stmt = db()->prepare(
        'SELECT GREATEST(0, CAST(? AS integer) - TRUNC(EXTRACT(EPOCH FROM (NOW() - email_verify_last_sent_at))))
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
    if ($token === '') {
        return null;
    }
    $stmt = db()->prepare('SELECT * FROM users WHERE email_verify_token = ? AND is_active = 1 LIMIT 1');
    $stmt->execute([$token]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function mark_user_email_verified(int $userId): void
{
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

    $body  = '<p>Hello, <strong>' . e($name) . '</strong>!</p>'
        . '<p>Please verify your email address to activate your account.</p>';

    if ($code !== null && $code !== '') {
        $body .= email_muted('Verification code:', 'margin:16px 0 6px 0;')
            . email_code((string) $code);
    }

    $body .= email_button($verifyUrl, 'Verify Email', null, true)
        . email_muted('If the button doesn\'t work, copy this link:<br>'
            . email_link($verifyUrl), 'margin-top:16px;');

    send_email(
        $email,
        'Verify Your Email - ' . school_setting('school_name', 'PLP Admissions'),
        email_template('Verify Your Email', $body),
        $name
    );
}

// ----------------------------------------------------------------
// PASSWORD RESET CODE
// ----------------------------------------------------------------

/**
 * Make a fresh 6-digit reset code. Older codes for the user stop working.
 * Only the sha256 hash is stored (column `token`). Returns the plain code
 * so the caller can email it. Never show or log it.
 * ponytail: sha256 of a 6-digit code is brute-forceable offline if the DB
 * leaks. Expiry + 5 tries cover online guessing. Upgrade: HMAC with a secret.
 */
function create_reset_code(int $userId): string
{
    db()->prepare('UPDATE password_resets SET used = 1 WHERE user_id = ?')->execute([$userId]);

    $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

    db()->prepare(
        'INSERT INTO password_resets (user_id, token, expires_at, attempts)
         VALUES (?, ?, NOW() + (CAST(? AS integer) * INTERVAL \'1 second\'), 0)'
    )->execute([$userId, hash('sha256', $code), (int) RESET_CODE_TTL_SECS]);

    return $code;
}

/** Seconds left before a new reset code may be sent (0 = allowed). */
function reset_cooldown(int $userId): int
{
    $stmt = db()->prepare(
        'SELECT GREATEST(0, CAST(? AS integer) - TRUNC(EXTRACT(EPOCH FROM (NOW() - created_at))))
           FROM password_resets
          WHERE user_id = ?
          ORDER BY id DESC
          LIMIT 1'
    );
    $stmt->execute([(int) RESET_RESEND_SECS, $userId]);
    return (int) ($stmt->fetchColumn() ?: 0);
}

/**
 * Pure check of a reset row against a typed code. No DB.
 * $row: token (sha256 hash), expires_at (timestamp or string), used, attempts.
 * Returns 'ok', 'expired', 'used', 'locked' or 'wrong'.
 */
function reset_code_check(array $row, string $input, int $now): string
{
    if (!empty($row['used'])) {
        return 'used';
    }
    if ((int) ($row['attempts'] ?? 0) >= RESET_MAX_ATTEMPTS) {
        return 'locked';
    }

    $exp = is_numeric($row['expires_at'] ?? null)
        ? (int) $row['expires_at']
        : (int) strtotime((string) ($row['expires_at'] ?? ''));
    if ($exp <= $now) {
        return 'expired';
    }

    return hash_equals((string) ($row['token'] ?? ''), hash('sha256', $input)) ? 'ok' : 'wrong';
}

function send_reset_code_email(string $email, string $code): bool
{
    $body = '<p>We got a request to reset the password for your account.</p>'
        . email_muted('Your password reset code:', 'margin:16px 0 6px 0;')
        . email_code($code)
        . email_muted('This code expires in 15 minutes. '
            . 'If you did not ask for it, ignore this email. Your password stays the same.');

    return send_email($email, 'Your PLP password reset code', email_template('Password Reset Code', $body));
}

// ----------------------------------------------------------------
// LOGIN RATE LIMITING
// ----------------------------------------------------------------

function record_failed_login(string $email): void
{
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
    if (str_contains($ip, ',')) {
        $ip = trim(explode(',', $ip)[0]);
    }
    db()->prepare('INSERT INTO login_attempts (email, ip_address) VALUES (?, ?)')->execute([$email, $ip]);
}

function clear_login_attempts(string $email): void
{
    db()->prepare('DELETE FROM login_attempts WHERE email = ?')->execute([$email]);
}

function is_login_locked(string $email): bool
{
    $stmt = db()->prepare(
        'SELECT COUNT(*) FROM login_attempts WHERE email = ? AND attempted_at > NOW() - INTERVAL \'15 minutes\''
    );
    $stmt->execute([$email]);
    return (int) $stmt->fetchColumn() >= 5;
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
        if (!$user) {
            return;
        }

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

        notify_student((int)$user['user_id'], 'reschedule_' . $decision, $title, $msg, $link);
    } catch (\Throwable $e) {
        error_log('reschedule decision notify failed: ' . $e->getMessage());
    }
}

// ----------------------------------------------------------------
// A3: STAFF NOTIFICATIONS
// ----------------------------------------------------------------

/**
 * Notify every active user holding one of the given roles. Pass ROLE_* constants.
 * Returns how many users were notified (0 on failure or no match).
 */
function notify_roles(array $roles, string $type, string $title, string $message = '', string $link = ''): int
{
    $roles = array_values(array_unique(array_map('strval', $roles)));
    if (!$roles) {
        return 0;
    }
    try {
        [$ph, $params] = sql_in($roles);
        $stmt = db()->prepare("SELECT id FROM users WHERE role IN ($ph) AND is_active = 1");
        $stmt->execute($params);
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        foreach ($ids as $userId) {
            create_notification((int)$userId, $type, $title, $message, $link);
        }
        return count($ids);
    } catch (\Throwable $e) {
        error_log('notify_roles error: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Notify all staff/admin users about an event.
 */
function notify_staff(string $type, string $title, string $message = '', string $link = ''): void
{
    notify_roles([ROLE_STAFF, ROLE_ADMIN], $type, $title, $message, $link);
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
    if (school_setting('auto_reschedule_noshows', '1') !== '1') {
        return null;
    }

    $pdo = db();

    // If somehow the row is still 'pending', normalise it to the
    // canonical absent state. This is a no-op when the caller (mark_no_show,
    // record_interview_evaluation, auto_detect_interview_no_shows) already
    // did it.
    $pdo->prepare(
        'UPDATE interview_queue
            SET status            = \'no_show\',
                interview_status  = \'absent\',
                attendance_status = \'absent\',
                evaluated_at      = COALESCE(evaluated_at, NOW())
          WHERE applicant_id = ?
            AND interview_status = \'pending\''
    )->execute([$applicantId]);

    // Ensure applicant stays in interview stage
    $pdo->prepare(
        'UPDATE applicants SET overall_status = \'interview\' '
        . 'WHERE id = ? AND overall_status IN (\'interview\',\'result\')'
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

            audit_log(
                'auto_reschedule_noshow',
                "Auto-rescheduled no-show applicant {$applicantId} to slot {$newSlotId}",
                'applicant',
                $applicantId
            );
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

    $time      = $config['slot_time']   ?? '08:00';
    $endTime   = $config['end_time']    ?? '';
    $room      = $config['room_label']  ?? '';
    $capacity  = max(1, (int)($config['capacity'] ?? 35));

    // Default close time = open + 90 min if caller didn't specify one.
    if ($endTime === '') {
        $endTime = date('H:i', strtotime($time . ' +90 minutes'));
    }

    $schoolYear = school_setting('current_school_year', date('Y') . '-' . (date('Y') + 1));
    $activeExam = $pdo->query('SELECT id FROM exams WHERE is_active=1 LIMIT 1')->fetch();
    $examId     = $activeExam ? (int)$activeExam['id'] : null;

    foreach (iterate_dates($config) as $dateStr) {
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
            )->execute([
                $examId,
                $dateStr,
                $time . ':00',
                $endTime . ':00',
                $room,
                $department,
                $capacity,
                $schoolYear,
                $staffId,
            ]);
            $created++;
        }
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
               s.slot_date < CURRENT_DATE
               OR (s.slot_date = CURRENT_DATE AND s.end_time IS NOT NULL AND s.end_time < LOCALTIME)
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
        $pdo->prepare('UPDATE interview_slots SET status = \'closed\' WHERE id = ?')
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
        audit_log('auto_expire_accepted', "Auto-expired {$expired} accepted applicant(s) past deadline");
    }

    return $expired;
}

// ----------------------------------------------------------------
// EXAM FINALIZER
// One place that scores an exam, stores the result and moves the
// applicant on. The exam page POST and the closed-exam sweep both call it.
// ----------------------------------------------------------------

const EXAM_AUTO_NOTE = 'Auto-submitted, connection lost';

/**
 * Log a warning when the database session is not on Asia/Manila.
 * Slot close times and draft saved_at are compared in SQL, so they must share one clock.
 * ponytail: log only, once per request. It does not block the exam.
 */
function warn_if_db_timezone_off(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $tz = (string) db()->query('SHOW timezone')->fetchColumn();
        if ($tz !== 'Asia/Manila') {
            error_log("DB timezone is '{$tz}', expected Asia/Manila. Exam close times will be wrong.");
        }
    } catch (\Throwable $e) {}
}

/**
 * SQL expression for when a slot closes, as a timestamp. $alias is the
 * exam_slot_schedule alias in the calling query. Same rules as slot_window():
 * end_time wraps past midnight to the next day; no end_time means 90 minutes.
 * Use it inside any query that must decide "is this slot still open" so no
 * PHP clock is ever compared with a database time.
 */
function slot_close_sql(string $alias = 's'): string
{
    return "(CASE WHEN {$alias}.end_time IS NULL THEN {$alias}.exam_date + {$alias}.slot_time + interval '90 minutes'
      WHEN {$alias}.end_time <= {$alias}.slot_time THEN {$alias}.exam_date + {$alias}.end_time + interval '1 day'
      ELSE {$alias}.exam_date + {$alias}.end_time END)";
}

/** True while the database clock is at or before the slot close (plus an optional grace). */
function slot_is_open(int $slotId, int $graceSeconds = 0): bool
{
    $grace = max(0, $graceSeconds);
    $stmt  = db()->prepare(
        'SELECT now() <= (' . slot_close_sql('s') . " + interval '{$grace} seconds')
           FROM exam_slot_schedule s WHERE s.id = ?"
    );
    $stmt->execute([$slotId]);
    return (bool) $stmt->fetchColumn();
}

/** Seconds after close in which a normal submit is still accepted. School setting, default 30. */
function exam_submit_grace_seconds(): int
{
    return max(0, (int) school_setting('exam_submit_grace_seconds', '30'));
}

/**
 * Turn a saved draft (JSON text) into the same shape as $_POST['answers']:
 * [questionId => value]. The browser stores keys as "answers[12]" or
 * "answers[12][]"; plain "12" keys work too.
 */
function exam_answers_from_draft(string $json): array
{
    $out = [];
    foreach ((array) json_decode($json, true) as $k => $v) {
        if (preg_match('/^(?:answers\[)?(\d+)/', (string) $k, $m)) {
            $out[(int) $m[1]] = $v;
        }
    }
    return $out;
}

/**
 * Score answers against questions. No database.
 * Returns [score, savedAnswers] where savedAnswers is the cleaned answer set.
 */
function score_exam_answers(array $questions, array $answers): array
{
    $score        = 0;
    $savedAnswers = [];

    foreach ($questions as $q) {
        $qId   = $q['id'];
        $qType = $q['question_type'] ?? 'multiple_choice';

        switch ($qType) {
            case 'multiple_choice':
            case 'dropdown':
                $chosen = isset($answers[$qId]) ? (int)$answers[$qId] : -1;
                $savedAnswers[$qId] = $chosen;
                if ($chosen === (int)$q['correct_index']) {
                    $score += (int)$q['points'];
                }
                break;

            case 'checkboxes':
                $chosen = isset($answers[$qId]) && is_array($answers[$qId])
                    ? array_map('intval', $answers[$qId]) : [];
                sort($chosen);
                $savedAnswers[$qId] = $chosen;
                $correctIndices = $q['correct_answer'] ? json_decode($q['correct_answer'], true) : [];
                sort($correctIndices);
                if (!empty($correctIndices) && $chosen === $correctIndices) {
                    $score += (int)$q['points'];
                } elseif (empty($correctIndices) && !empty($chosen)) {
                    // No correct answer set: give credit for any answer
                    $score += (int)$q['points'];
                }
                break;

            case 'identification':
            case 'short_answer':
                $text = trim($answers[$qId] ?? '');
                $savedAnswers[$qId] = $text;
                if ($q['correct_answer'] !== null && $q['correct_answer'] !== '') {
                    if (mb_strtolower($text) === mb_strtolower(trim($q['correct_answer']))) {
                        $score += (int)$q['points'];
                    }
                }
                // No expected answer: score stays 0 (manual grading)
                break;

            case 'paragraph':
                $savedAnswers[$qId] = trim($answers[$qId] ?? '');
                // Always 0: manual grading
                break;

            case 'linear_scale':
                $chosen = isset($answers[$qId]) ? (int)$answers[$qId] : -1;
                $savedAnswers[$qId] = $chosen;
                // Credit for any selection in range
                if ($chosen >= $q['scale_min'] && $chosen <= $q['scale_max']) {
                    $score += (int)$q['points'];
                }
                break;
        }
    }

    return [$score, $savedAnswers];
}

/**
 * Score, store and advance one applicant's exam. $answers has the same shape
 * as $_POST['answers']. $note goes to exam_results.submit_note.
 *
 * Safe against double submit: the unique (applicant_id, exam_id) index plus
 * ON CONFLICT DO NOTHING. Returns null when a result already exists (or the
 * applicant is missing), otherwise ['score','total','rank','passed','pct'].
 * On success the server draft is deleted.
 */
function finalize_exam_result(int $applicantId, int $examId, array $answers, ?string $note = null): ?array
{
    $pdo = db();
    warn_if_db_timezone_off();

    $stmt = $pdo->prepare('SELECT user_id, course_applied FROM applicants WHERE id = ?');
    $stmt->execute([$applicantId]);
    $applicant = $stmt->fetch();
    if (!$applicant) {
        return null;
    }

    $stmt = $pdo->prepare('SELECT * FROM questions WHERE exam_id = ?');
    $stmt->execute([$examId]);
    $questions = $stmt->fetchAll();

    [$score, $savedAnswers] = score_exam_answers($questions, $answers);
    $total  = array_sum(array_column($questions, 'points')) ?: count($questions);
    $rank   = score_to_rank($score, $total);
    $passed = exam_passed($score, $total, $applicant['course_applied']);

    $ins = $pdo->prepare(
        'INSERT INTO exam_results (applicant_id, exam_id, score, total_items, answers, rank_score, passed, submit_note)
         VALUES (?,?,?,?,?,?,?,?)
         ON CONFLICT (applicant_id, exam_id) DO NOTHING'
    );
    $ins->execute([$applicantId, $examId, $score, $total, json_encode($savedAnswers), $rank, $passed ? 1 : 0, $note]);
    if ($ins->rowCount() === 0) {
        return null;
    }

    // Passed advances to interview; failed stays at exam so staff can review
    // and suggest an alternative course.
    $pdo->prepare('UPDATE applicants SET overall_status=? WHERE id=?')
        ->execute([$passed ? 'interview' : 'exam', $applicantId]);

    $pdo->prepare('DELETE FROM exam_drafts WHERE applicant_id = ? AND exam_id = ?')
        ->execute([$applicantId, $examId]);

    // Auto-assign an interview slot the moment they pass. Returns null when no
    // compatible slot is open; bulk_assign_pending_applicants() picks them up later.
    if ($passed) {
        try {
            assign_interview_slot($applicantId, (int) $applicant['user_id']);
        } catch (\Throwable $e) {
            error_log('Auto-assign interview after exam pass failed: ' . $e->getMessage());
        }
    }

    return [
        'score'  => $score,
        'total'  => $total,
        'rank'   => $rank,
        'passed' => $passed,
        'pct'    => $total > 0 ? round(($score / $total) * 100) : 0,
    ];
}

/**
 * Sweep: finalize every draft whose slot has closed and that has no result yet.
 * Uses the server draft and the "Auto-submitted, connection lost" note, then
 * finalize_exam_result() deletes the draft. Not limited to the current student.
 * Applicants with no draft are skipped (staff handle them, not scored 0).
 * Returns how many results were created.
 *
 * ponytail: one cheap EXISTS first, so a page load with no work costs one tiny query.
 * Drafts are only written while the slot is open, so no second time check is needed here.
 */
function finalize_closed_exam_drafts(): int
{
    static $ran = false;            // once per request
    if ($ran) {
        return 0;
    }
    $ran = true;

    $pdo  = db();
    $from = 'FROM exam_drafts d
             JOIN exam_slot_schedule s ON s.id = d.slot_id
            WHERE now() > ' . slot_close_sql('s') . '
              AND NOT EXISTS (SELECT 1 FROM exam_results r
                               WHERE r.applicant_id = d.applicant_id AND r.exam_id = d.exam_id)';

    try {
        if (!$pdo->query("SELECT EXISTS (SELECT 1 {$from})")->fetchColumn()) {
            return 0;
        }

        $rows = $pdo->query("SELECT d.applicant_id, d.exam_id, d.answers {$from}")->fetchAll();
        $done = 0;
        foreach ($rows as $row) {
            try {
                $res = finalize_exam_result(
                    (int) $row['applicant_id'],
                    (int) $row['exam_id'],
                    exam_answers_from_draft((string) $row['answers']),
                    EXAM_AUTO_NOTE
                );
                if ($res !== null) {
                    $done++;
                }
            } catch (\Throwable $e) {
                error_log('Auto-submit of closed exam draft failed: ' . $e->getMessage());
            }
        }
        return $done;
    } catch (\Throwable $e) {
        error_log('finalize_closed_exam_drafts failed: ' . $e->getMessage());
        return 0;
    }
}
