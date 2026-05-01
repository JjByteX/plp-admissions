<?php
// ============================================================
// core/interview_scheduler.php
// Interview slot auto-assignment + department helpers
// ------------------------------------------------------------
// Exposes:
//   course_to_department(string $course): string
//   user_department(int $userId): string
//   assign_interview_slot(int $applicantId, ?int $actorUserId = null, ?int $forceSlotId = null): ?int
//   reschedule_interview(int $applicantId, ?int $actorUserId = null): ?int
//   cancel_interview_slot(int $slotId, int $actorUserId): int
//   bulk_assign_pending_applicants(?string $department, ?int $actorUserId = null): int
//   record_interview_evaluation(int $queueId, bool $absent, ?string $result, int $actorUserId): bool
//   reschedule_absent_applicant(int $applicantId, ?int $targetSlotId, int $actorUserId): ?int
//   slot_is_available(array $slotRow): bool
//   departments_list(): array
// ============================================================

/**
 * Resolve the department (college) for a given course.
 * Checks the `course_departments` table first, falls back to the
 * COURSE_DEPARTMENT_MAP config constant.
 *
 * Returns '' when the course is unknown (caller decides how to
 * handle an empty department — usually leave it blank).
 */
function course_to_department(string $course): string
{
    static $cache = [];
    $key = trim($course);
    if ($key === '') return '';
    if (array_key_exists($key, $cache)) return $cache[$key];

    try {
        $stmt = db()->prepare(
            'SELECT d.name
               FROM course_departments cd
               JOIN departments d ON d.id = cd.department_id
              WHERE cd.course_name = ?
              LIMIT 1'
        );
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        if ($row && !empty($row['name'])) {
            return $cache[$key] = (string)$row['name'];
        }
    } catch (\Throwable) {
        // Table may not exist yet (migration pending) — fall through.
    }

    return $cache[$key] = COURSE_DEPARTMENT_MAP[$key] ?? '';
}

/**
 * Return the department currently stored on a user row, or '' if none.
 */
function user_department(int $userId): string
{
    if ($userId <= 0) return '';
    static $cache = [];
    if (isset($cache[$userId])) return $cache[$userId];
    try {
        $stmt = db()->prepare('SELECT department FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $v = $stmt->fetchColumn();
        return $cache[$userId] = is_string($v) ? $v : '';
    } catch (\Throwable) {
        return $cache[$userId] = '';
    }
}

/**
 * Return the full list of department names (canonical order).
 * DB-first so admin-added departments show up; config fallback.
 */
function departments_list(): array
{
    try {
        $rows = db()->query('SELECT name FROM departments ORDER BY name')->fetchAll();
        if ($rows) return array_column($rows, 'name');
    } catch (\Throwable) {}
    return PLP_DEPARTMENTS;
}

/**
 * Is a given interview_slots row currently bookable?
 * Row must include: status, slot_date, end_time (nullable), capacity, booked.
 */
function slot_is_available(array $slot): bool
{
    if (($slot['status'] ?? '') !== 'open') return false;

    $today   = date('Y-m-d');
    $nowTime = date('H:i:s');
    $date    = (string)($slot['slot_date'] ?? '');
    if ($date === '' || $date < $today) return false;
    if ($date === $today
        && !empty($slot['end_time'])
        && (string)$slot['end_time'] <= $nowTime) {
        return false;
    }

    $cap    = (int)($slot['capacity'] ?? 0);
    $booked = (int)($slot['booked']   ?? 0);
    return $cap > 0 && $booked < $cap;
}

/**
 * Auto-assign the best available interview slot to an applicant.
 *
 * Algorithm:
 *   1. Resolve applicant's department from their course_applied.
 *   2. Inside a transaction, lock all open future slots matching
 *      that department (or any department when the applicant has
 *      none yet) and pick the one with the lowest booked count —
 *      earliest date as tie-breaker, earliest time next.  This is
 *      the "fair distribution" rule.
 *   3. Reject if the applicant already has an active queue row
 *      (prevents double-booking).
 *   4. Insert an interview_queue row and advance the applicant's
 *      overall_status.  Audit log under `interview_slot_auto_assigned`.
 *
 * Returns the chosen slot_id, or NULL when no slot is available.
 * Throws on unrecoverable DB errors so callers can surface messages.
 */
function assign_interview_slot(int $applicantId, ?int $actorUserId = null, ?int $forceSlotId = null): ?int
{
    $pdo = db();

    // Load the applicant and current department eagerly so that we can
    // fall back gracefully if the department is missing.
    $stmt = $pdo->prepare(
        'SELECT a.id, a.user_id, a.course_applied, u.department
           FROM applicants a
           JOIN users u ON u.id = a.user_id
          WHERE a.id = ?
          LIMIT 1'
    );
    $stmt->execute([$applicantId]);
    $applicant = $stmt->fetch();
    if (!$applicant) return null;

    $department = (string)($applicant['department'] ?? '');
    if ($department === '') {
        $department = course_to_department((string)$applicant['course_applied']);
        // Opportunistically backfill the user row so future queries are fast.
        if ($department !== '') {
            try {
                $pdo->prepare('UPDATE users SET department = ? WHERE id = ? AND (department = "" OR department IS NULL)')
                    ->execute([$department, (int)$applicant['user_id']]);
            } catch (\Throwable) {}
        }
    }

    $pdo->beginTransaction();
    try {
        // Reject duplicate active booking up front.
        $dup = $pdo->prepare(
            'SELECT id FROM interview_queue
              WHERE applicant_id = ?
                AND interview_status IN ("pending","completed")
              LIMIT 1 FOR UPDATE'
        );
        $dup->execute([$applicantId]);
        if ($dup->fetch()) {
            $pdo->rollBack();
            return null;
        }

        $today   = date('Y-m-d');
        $nowTime = date('H:i:s');

        if ($forceSlotId !== null && $forceSlotId > 0) {
            // Staff-directed reschedule to a specific slot — still
            // verify availability and capacity under FOR UPDATE.
            $q = $pdo->prepare(
                'SELECT s.id, s.capacity, s.department, s.status, s.slot_date, s.end_time,
                        (SELECT COUNT(*) FROM interview_queue q
                          WHERE q.slot_id = s.id
                            AND q.interview_status IN ("pending","completed")) AS booked
                   FROM interview_slots s
                  WHERE s.id = ?
                  LIMIT 1 FOR UPDATE'
            );
            $q->execute([$forceSlotId]);
            $candidate = $q->fetch();
            if (!$candidate
                || $candidate['status'] !== 'open'
                || (int)$candidate['booked'] >= (int)$candidate['capacity']
                || (string)$candidate['slot_date'] < $today
                || ((string)$candidate['slot_date'] === $today
                    && !empty($candidate['end_time'])
                    && (string)$candidate['end_time'] <= $nowTime)) {
                $pdo->rollBack();
                return null;
            }
        } else {
            // Candidate slots: open, not expired, not at capacity.
            // We MATCH the applicant's department; if they have none we
            // allow any department (backward compatibility).
            $params = [$today, $today, $nowTime];
            $sql = 'SELECT s.id, s.capacity,
                           (SELECT COUNT(*) FROM interview_queue q
                              WHERE q.slot_id = s.id
                                AND q.interview_status IN ("pending","completed")) AS booked
                      FROM interview_slots s
                     WHERE s.status = "open"
                       AND s.slot_date >= ?
                       AND NOT (s.slot_date = ? AND s.end_time IS NOT NULL AND s.end_time <= ?)';
            if ($department !== '') {
                $sql .= ' AND (s.department = ? OR s.department = "")';
                $params[] = $department;
            }
            $sql .= ' ORDER BY booked ASC, s.slot_date ASC, s.slot_time ASC, s.id ASC
                      FOR UPDATE';

            $q = $pdo->prepare($sql);
            $q->execute($params);
            $candidate = null;
            while ($row = $q->fetch()) {
                if ((int)$row['booked'] < (int)$row['capacity']) {
                    $candidate = $row;
                    break;
                }
            }

            if (!$candidate) {
                $pdo->rollBack();
                return null;
            }
        }

        $slotId = (int)$candidate['id'];

        $pdo->prepare(
            'INSERT INTO interview_queue
                (slot_id, applicant_id, status, interview_status)
             VALUES (?, ?, "scheduled", "pending")'
        )->execute([$slotId, $applicantId]);

        $pdo->prepare(
            'UPDATE applicants SET overall_status = "interview" WHERE id = ?'
        )->execute([$applicantId]);

        $pdo->commit();
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    _audit_slot_change(
        'interview_slot_auto_assigned',
        "Auto-assigned applicant #{$applicantId} to slot #{$slotId} (dept: " . ($department ?: 'n/a') . ")",
        $slotId,
        $actorUserId
    );

    return $slotId;
}

/**
 * Auto-assign every applicant whose interview is still pending to an
 * open slot — typically called right after staff creates a new slot.
 *
 * "Pending" means: applicants.overall_status = 'interview' (i.e. they
 * passed the exam step) AND they don't already have an active
 * interview_queue row. When $department is non-empty we only pick up
 * applicants whose stored department matches (or whose course maps to
 * that department if the users.department column is still blank).
 *
 * Returns the number of applicants that were successfully assigned.
 */
function bulk_assign_pending_applicants(?string $department = null, ?int $actorUserId = null): int
{
    $pdo = db();
    $department = $department !== null ? trim($department) : '';

    $sql = 'SELECT a.id
              FROM applicants a
              JOIN users u ON u.id = a.user_id
              LEFT JOIN interview_queue q
                     ON q.applicant_id = a.id
                    AND q.interview_status IN ("pending","completed")
             WHERE a.overall_status = "interview"
               AND q.id IS NULL';
    $params = [];
    if ($department !== '') {
        // Match on users.department first; fall back to the course mapping
        // so freshly-registered applicants without a backfilled column
        // still get picked up.
        $sql .= ' AND (u.department = ?
                       OR (u.department = ""
                           AND a.course_applied IN (
                               SELECT cd.course_name FROM course_departments cd
                               JOIN departments d ON d.id = cd.department_id
                               WHERE d.name = ?)))';
        $params[] = $department;
        $params[] = $department;
    }
    $sql .= ' ORDER BY a.id ASC';

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $applicantIds = array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN) ?: []);
    } catch (\Throwable $e) {
        error_log('bulk_assign_pending_applicants query failed: ' . $e->getMessage());
        return 0;
    }

    $assigned = 0;
    foreach ($applicantIds as $aid) {
        try {
            if (assign_interview_slot($aid, $actorUserId)) {
                $assigned++;
            } else {
                // No open slot left — stop early rather than spinning
                // through every remaining applicant.
                break;
            }
        } catch (\Throwable $e) {
            error_log("bulk_assign_pending_applicants: applicant #{$aid} failed — " . $e->getMessage());
        }
    }

    if ($assigned > 0) {
        _audit_slot_change(
            'interview_bulk_assigned',
            "Auto-assigned {$assigned} pending applicant(s)"
                . ($department !== '' ? " in department '{$department}'" : ''),
            null,
            $actorUserId
        );
    }

    return $assigned;
}

/**
 * Record staff's attendance + evaluation decision for a single
 * interview_queue row.
 *
 * Validation rules (mirrored in the UI):
 *   - If $absent is true  → evaluation_result MUST be NULL
 *   - If $absent is false → evaluation_result MUST be 'pass' or 'fail'
 *
 * Side-effects on success:
 *   - interview_queue columns are updated (attendance_status,
 *     evaluation_result, interview_status, status, evaluated_by,
 *     evaluated_at).
 *   - applicants.overall_status is bumped to 'released' for present+graded
 *     (so the results step lights up), left alone for absent rows.
 *   - Audit row (`interview_marked_absent` | `interview_evaluation_recorded`).
 *
 * Returns true on success, false on validation failure.
 */
function record_interview_evaluation(
    int    $queueId,
    bool   $absent,
    ?string $result,
    int    $actorUserId
): bool {
    $result = $result !== null ? strtolower(trim($result)) : null;

    if ($absent) {
        $result = null; // ignore anything staff sent through for absent rows
    } else {
        if ($result !== 'pass' && $result !== 'fail') {
            return false; // missing / invalid evaluation — UI should prevent
        }
    }

    $pdo = db();
    $stmt = $pdo->prepare(
        'SELECT id, slot_id, applicant_id FROM interview_queue WHERE id = ? LIMIT 1'
    );
    $stmt->execute([$queueId]);
    $row = $stmt->fetch();
    if (!$row) return false;

    $applicantId = (int)$row['applicant_id'];
    $attendance  = $absent ? 'absent' : 'present';
    $interviewSt = $absent ? 'absent' : 'completed';
    $queueStatus = $absent ? 'no_show' : 'completed';

    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            'UPDATE interview_queue
                SET attendance_status = ?,
                    evaluation_result = ?,
                    interview_status  = ?,
                    status            = ?,
                    evaluated_by      = ?,
                    evaluated_at      = NOW()
              WHERE id = ?'
        )->execute([$attendance, $result, $interviewSt, $queueStatus, $actorUserId, $queueId]);

        if (!$absent) {
            // Present + graded → let the results step unlock.
            $pdo->prepare(
                'UPDATE applicants SET overall_status = "released"
                  WHERE id = ? AND overall_status = "interview"'
            )->execute([$applicantId]);
        }

        $pdo->commit();
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('record_interview_evaluation failed: ' . $e->getMessage());
        return false;
    }

    if ($absent) {
        _audit_slot_change(
            'interview_marked_absent',
            "Marked applicant #{$applicantId} as absent (queue #{$queueId})",
            $queueId,
            $actorUserId,
            'interview_queue'
        );
    } else {
        _audit_slot_change(
            'interview_evaluation_recorded',
            "Evaluated applicant #{$applicantId} as '{$result}' (queue #{$queueId})",
            $queueId,
            $actorUserId,
            'interview_queue'
        );
    }

    return true;
}

/**
 * Reschedule an applicant who was marked absent.
 *
 * Steps (in one transaction):
 *   1. Read the existing queue row (must have interview_status='absent').
 *   2. Write a reschedule_logs entry capturing from_slot_id + date/time.
 *   3. Flip the old queue row to interview_status='rescheduled' so it
 *      no longer counts as "active" (the uq_applicant_active index
 *      filter at the application layer relies on interview_status).
 *      We DELETE the row so the UNIQUE index on applicant_id stays
 *      satisfied when the new row is inserted by assign_interview_slot.
 *   4. Call assign_interview_slot($applicantId, $actorUserId, $targetSlotId)
 *      which inserts the new queue row.
 *
 * Returns the new slot_id on success, NULL when no slot could be booked
 * (in which case the old row is left intact and the transaction rolls
 * back).
 */
function reschedule_absent_applicant(int $applicantId, ?int $targetSlotId, int $actorUserId): ?int
{
    $pdo = db();

    $stmt = $pdo->prepare(
        'SELECT q.id, q.slot_id, s.slot_date, s.slot_time
           FROM interview_queue q
      LEFT JOIN interview_slots s ON s.id = q.slot_id
          WHERE q.applicant_id = ?
            AND q.interview_status = "absent"
          LIMIT 1'
    );
    $stmt->execute([$applicantId]);
    $oldRow = $stmt->fetch();
    if (!$oldRow) return null;

    $fromSlotId   = $oldRow['slot_id']   !== null ? (int)$oldRow['slot_id']   : null;
    $fromSlotDate = $oldRow['slot_date'] ?? null;
    $fromSlotTime = $oldRow['slot_time'] ?? null;
    $oldQueueId   = (int)$oldRow['id'];

    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            'INSERT INTO reschedule_logs
                (applicant_id, from_slot_id, to_slot_id, from_slot_date, from_slot_time, reason, rescheduled_by)
             VALUES (?, ?, NULL, ?, ?, "absent", ?)'
        )->execute([$applicantId, $fromSlotId, $fromSlotDate, $fromSlotTime, $actorUserId]);
        $logId = (int)$pdo->lastInsertId();

        // Remove the old absent row so assign_interview_slot can insert
        // a fresh one (uq_applicant_active is a UNIQUE index).
        $pdo->prepare('DELETE FROM interview_queue WHERE id = ?')
            ->execute([$oldQueueId]);

        // Reset applicant state so the pending-queue filter picks them up.
        $pdo->prepare(
            'UPDATE applicants SET overall_status = "interview"
              WHERE id = ? AND overall_status IN ("interview","released")'
        )->execute([$applicantId]);

        $pdo->commit();
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('reschedule_absent_applicant (pre-assign) failed: ' . $e->getMessage());
        return null;
    }

    $newSlotId = null;
    try {
        $newSlotId = assign_interview_slot($applicantId, $actorUserId, $targetSlotId);
    } catch (\Throwable $e) {
        error_log('reschedule_absent_applicant (assign) failed: ' . $e->getMessage());
    }

    if ($newSlotId) {
        try {
            $pdo->prepare('UPDATE reschedule_logs SET to_slot_id = ? WHERE id = ?')
                ->execute([$newSlotId, $logId]);
        } catch (\Throwable) {}
    }

    _audit_slot_change(
        'interview_rescheduled',
        "Rescheduled absent applicant #{$applicantId} from slot #"
            . ($fromSlotId ?: 'none')
            . ' to slot #' . ($newSlotId ?: 'unassigned'),
        $newSlotId ?: $fromSlotId,
        $actorUserId
    );

    return $newSlotId;
}

/**
 * Re-assign an applicant whose current slot has become unavailable
 * (e.g. the staff cancelled it, the session closed, or the slot was
 * deleted).  Keeps their previous slot_id in the audit trail.
 *
 * Returns the new slot_id, or NULL when no alternative is available.
 */
function reschedule_interview(int $applicantId, ?int $actorUserId = null): ?int
{
    $pdo = db();

    $stmt = $pdo->prepare(
        'SELECT q.id, q.slot_id, s.status AS slot_status
           FROM interview_queue q
      LEFT JOIN interview_slots s ON s.id = q.slot_id
          WHERE q.applicant_id = ?
          LIMIT 1'
    );
    $stmt->execute([$applicantId]);
    $row = $stmt->fetch();

    $previousSlotId = $row ? (int)$row['slot_id'] : 0;

    if ($row) {
        // Drop the old row so assign_interview_slot can issue a new one.
        $pdo->prepare(
            'DELETE FROM interview_queue
              WHERE id = ?
                AND interview_status = "pending"
                AND status = "scheduled"'
        )->execute([(int)$row['id']]);
    }

    $newSlotId = assign_interview_slot($applicantId, $actorUserId);

    _audit_slot_change(
        'interview_slot_rescheduled',
        "Rescheduled applicant #{$applicantId} from slot #"
            . ($previousSlotId ?: 'none')
            . " to slot #" . ($newSlotId ?: 'none'),
        $newSlotId ?: $previousSlotId,
        $actorUserId
    );

    return $newSlotId;
}

/**
 * Cancel (close) an interview slot and auto-reschedule every applicant
 * currently booked into it.  Completed/in-progress rows are left alone.
 *
 * Returns the number of applicants that were rescheduled successfully.
 */
function cancel_interview_slot(int $slotId, int $actorUserId): int
{
    $pdo = db();

    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE interview_slots SET status = "closed" WHERE id = ?')
            ->execute([$slotId]);

        // Only reschedule people who have not been evaluated yet.
        $stmt = $pdo->prepare(
            'SELECT applicant_id FROM interview_queue
              WHERE slot_id = ?
                AND interview_status = "pending"
                AND status = "scheduled"'
        );
        $stmt->execute([$slotId]);
        $applicants = array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN) ?: []);

        // Detach all pending-scheduled rows from the slot before rebooking.
        $pdo->prepare(
            'DELETE FROM interview_queue
              WHERE slot_id = ?
                AND interview_status = "pending"
                AND status = "scheduled"'
        )->execute([$slotId]);

        $pdo->commit();
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    _audit_slot_change(
        'interview_slot_cancelled',
        "Closed slot #{$slotId}, rebooking " . count($applicants) . ' applicant(s)',
        $slotId,
        $actorUserId
    );

    $rebooked = 0;
    foreach ($applicants as $aid) {
        try {
            $newSlot = assign_interview_slot($aid, $actorUserId);
            if ($newSlot) $rebooked++;
        } catch (\Throwable $e) {
            error_log("reschedule_interview failed for applicant #{$aid}: " . $e->getMessage());
        }
    }

    return $rebooked;
}

/**
 * Update a user's department and write an audit entry.
 */
function set_user_department(int $userId, string $department, ?int $actorUserId = null): void
{
    $department = trim($department);
    if ($userId <= 0) return;

    $pdo = db();
    $stmt = $pdo->prepare('SELECT department FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $previous = (string)($stmt->fetchColumn() ?: '');
    if ($previous === $department) return;

    $pdo->prepare('UPDATE users SET department = ? WHERE id = ?')
        ->execute([$department, $userId]);

    _audit_slot_change(
        'user_department_updated',
        "User #{$userId} department: '" . ($previous ?: 'n/a') . "' → '" . ($department ?: 'n/a') . "'",
        $userId,
        $actorUserId,
        'user'
    );
}

/**
 * Internal — thin audit_log() wrapper that tolerates calls made before
 * Auth::check() is meaningful (e.g. during registration).
 */
function _audit_slot_change(
    string  $action,
    string  $description,
    ?int    $entityId,
    ?int    $actorUserId = null,
    string  $entityType  = 'interview_slot'
): void {
    try {
        audit_log($action, $description, $entityType, $entityId);
    } catch (\Throwable) {
        // audit_log already swallows its own errors; belt-and-braces here.
    }
    // Always emit to error_log so ops has a trail even when audit table
    // is unavailable (slot assignment should never be silent).
    error_log(sprintf(
        '[interview] %s actor=%s entity=%s#%s — %s',
        $action,
        $actorUserId !== null ? (string)$actorUserId : 'system',
        $entityType,
        $entityId !== null ? (string)$entityId : 'n/a',
        $description
    ));
}
