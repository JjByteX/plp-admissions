<?php
// ============================================================
// core/automation/slots.php
// Exam and interview slots: auto-assign, backfill, batch creation,
// no-show rescheduling, closing expired sessions.
// Loaded by core/automation.php; plain functions, unchanged.
// ============================================================

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
    $today   = now_date();
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
        error_log("[auto_assign #{$applicantId}] today=" . now_date() . " now=" . date('H:i:s'));
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
                AuditAction::EXAM_SLOT_AUTO_ASSIGNED,
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
        audit_log(AuditAction::BATCH_INTERVIEW_SESSIONS,
            "Batch-created {$created} interview session(s) for " . ($department ?: 'all departments'),
            'interview_slot');
    }

    return $created;
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
                AuditAction::AUTO_RESCHEDULE_NOSHOW,
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
        audit_log(AuditAction::BATCH_EXAM_SLOTS,
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

        audit_log(AuditAction::AUTO_CLOSE_SESSION,
            "Auto-closed expired session #{$slot['id']} ({$slot['slot_date']}). {$noShows} marked as absent.",
            'interview_slot', $slot['id']);

        $closed++;
    }

    return $closed;
}
