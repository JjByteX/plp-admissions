<?php
// ============================================================
// modules/interview/staff_absent.php
//
// Absent Students + Reschedule Requests.
//
// Two tabs:
//   1. Absent Students — applicants marked absent by interviewers.
//   2. Reschedule Requests — student-initiated reschedule requests.
//
// Staff/Prof: read-only (can view Absent tab, cannot reschedule).
// Dean:       read-only on the Absent tab. The Reschedule Requests
//             tab is hidden from Dean entirely — reschedules are an
//             SSO/registrar action, not an academic-oversight one.
// SSO/Admin:  can reschedule absent students and approve/deny
//             reschedule requests.
//
// URL:
//   GET  /staff/interviews/absent
//   POST /staff/interviews/absent   (action=reschedule|approve_reschedule|deny_reschedule)
// ============================================================

require_once CORE_PATH . '/bootstrap.php';
Auth::requireRole(ROLE_STAFF, ROLE_SSO, ROLE_DEAN, ROLE_ADMIN);

$db      = db();
$staffId = Auth::id();
$role    = Auth::role();
$isDean  = ($role === ROLE_DEAN);
$canReschedule = in_array($role, [ROLE_SSO, ROLE_ADMIN], true);

$errors  = [];
$success = [];

// Ensure the reschedule_requests table exists.
ensure_reschedule_requests_table();

// Active tab: absent (default) or requests. Dean is forced onto the
// absent tab — the Reschedule Requests tab is hidden from them and
// any direct ?tab=requests link from a bookmark is silently snapped
// back rather than 403'd, so navigation feels seamless.
$activeTab = ($_GET['tab'] ?? 'absent') === 'requests' ? 'requests' : 'absent';
if ($isDean) {
    $activeTab = 'absent';
}

// ----------------------------------------------------------------
// POST — only SSO/Admin may perform scheduling actions
// ----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if (!$canReschedule) {
        $errors[] = 'Only SSO and Admin can perform scheduling actions.';
        $action = '';
    }

    // ── Approve a reschedule request ────────────────────────────
    if ($action === 'approve_reschedule') {
        $requestId    = (int)($_POST['request_id'] ?? 0);
        $targetSlotId = (int)($_POST['target_slot_id'] ?? 0);

        $rr = $db->prepare('SELECT * FROM reschedule_requests WHERE id = ? AND status = "pending" LIMIT 1');
        $rr->execute([$requestId]);
        $req = $rr->fetch();

        if (!$req) {
            Session::flash('error', 'Reschedule request not found or already processed.');
            redirect('/staff/interviews/absent?tab=requests');
        }

        $applicantId = (int)$req['applicant_id'];
        $queueId     = (int)$req['queue_id'];

        // Determine the applicant's department for "auto-assign" matching.
        $deptStmt = $db->prepare(
            'SELECT u.department, a.course_applied
               FROM applicants a JOIN users u ON u.id = a.user_id
              WHERE a.id = ? LIMIT 1'
        );
        $deptStmt->execute([$applicantId]);
        $deptRow = $deptStmt->fetch() ?: [];
        $department = (string)($deptRow['department'] ?? '');
        if ($department === '' && function_exists('course_to_department')) {
            $department = course_to_department((string)($deptRow['course_applied'] ?? ''));
        }

        $today   = date('Y-m-d');
        $nowTime = date('H:i:s');

        // ------------------------------------------------------------
        // Do the entire swap inside ONE transaction with FOR UPDATE
        // locks on both the request and the target slot. This protects
        // against two admins approving two students into the same last
        // open slot at the same moment.
        // ------------------------------------------------------------
        $approveErr = null;
        $newSlot    = null;
        $newSlotInfo = null;

        try {
            $db->beginTransaction();

            // Re-check the request inside the transaction.
            $rrLock = $db->prepare(
                'SELECT id, status FROM reschedule_requests WHERE id = ? FOR UPDATE'
            );
            $rrLock->execute([$requestId]);
            $reqLock = $rrLock->fetch();
            if (!$reqLock || $reqLock['status'] !== 'pending') {
                $approveErr = 'Reschedule request already processed.';
                throw new \RuntimeException($approveErr);
            }

            // Pick the candidate slot under FOR UPDATE so capacity is
            // accurate even with concurrent admin approvals.
            $candidate = null;
            if ($targetSlotId > 0) {
                $q = $db->prepare(
                    'SELECT s.id, s.capacity, s.status, s.slot_date, s.slot_time, s.end_time, s.department, s.location_label,
                            (SELECT COUNT(*) FROM interview_queue q
                               WHERE q.slot_id = s.id
                                 AND q.interview_status IN ("pending","completed")) AS booked
                       FROM interview_slots s
                      WHERE s.id = ?
                      LIMIT 1 FOR UPDATE'
                );
                $q->execute([$targetSlotId]);
                $cand = $q->fetch();
                $valid = $cand
                      && $cand['status'] === 'open'
                      && (int)$cand['booked'] < (int)$cand['capacity']
                      && (string)$cand['slot_date'] >= $today
                      && !((string)$cand['slot_date'] === $today
                           && !empty($cand['end_time'])
                           && (string)$cand['end_time'] <= $nowTime);
                if (!$valid) {
                    $approveErr = 'The slot you picked is no longer available. Please create a new slot or pick another one, then try again.';
                    throw new \RuntimeException($approveErr);
                }
                $candidate = $cand;
            } else {
                $params = [$today, $today, $nowTime];
                $sql = 'SELECT s.id, s.capacity, s.slot_date, s.slot_time, s.end_time, s.department, s.location_label,
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
                $sql .= ' ORDER BY s.slot_date ASC, s.slot_time ASC, s.id ASC
                          FOR UPDATE';
                $st = $db->prepare($sql);
                $st->execute($params);
                while ($row = $st->fetch()) {
                    if ((int)$row['booked'] < (int)$row['capacity']) {
                        $candidate = $row;
                        break;
                    }
                }
                if (!$candidate) {
                    $deptLabel = $department !== '' ? " for {$department}" : '';
                    $approveErr = "Cannot approve — there's no open interview slot{$deptLabel} yet. "
                                . 'Please create a slot first, then come back and approve this request.';
                    throw new \RuntimeException($approveErr);
                }
            }

            $newSlotId = (int)$candidate['id'];

            // Delete the old queue row (uniqueness on applicant_id forces
            // delete-before-insert; both happen inside the same tx so
            // rollback restores the original state on any failure).
            $db->prepare(
                'DELETE FROM interview_queue WHERE id = ? AND applicant_id = ?'
            )->execute([$queueId, $applicantId]);

            // Next queue number for the target slot's interviewer/day.
            $nextNumStmt = $db->prepare(
                'SELECT COALESCE(MAX(q.queue_number), 0) + 1
                   FROM interview_queue q
                   JOIN interview_slots s2 ON s2.id = q.slot_id
                  WHERE s2.slot_date = (SELECT slot_date FROM interview_slots WHERE id = ?)
                    AND COALESCE(s2.assigned_to, s2.created_by) = (
                        SELECT COALESCE(assigned_to, created_by)
                          FROM interview_slots WHERE id = ?
                    )
                    AND q.queue_number IS NOT NULL'
            );
            $nextNumStmt->execute([$newSlotId, $newSlotId]);
            $nextNum = (int)$nextNumStmt->fetchColumn();

            $db->prepare(
                'INSERT INTO interview_queue
                    (slot_id, applicant_id, status, interview_status, queue_number, checked_in_at)
                 VALUES (?, ?, "checked_in", "pending", ?, NOW())'
            )->execute([$newSlotId, $applicantId, $nextNum]);

            $db->prepare(
                'UPDATE applicants SET overall_status = "interview" WHERE id = ?'
            )->execute([$applicantId]);

            $db->prepare(
                'UPDATE reschedule_requests
                    SET status = "approved", reviewed_by = ?, reviewed_at = NOW()
                  WHERE id = ?'
            )->execute([$staffId, $requestId]);

            $db->commit();
            $newSlot     = $newSlotId;
            $newSlotInfo = $candidate;
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            if (!$approveErr) {
                error_log('approve_reschedule (interview) failed: ' . $e->getMessage());
                $approveErr = 'Something went wrong while approving. Please try again.';
            }
        }

        if ($newSlot && $newSlotInfo) {
            audit_log(
                'reschedule_request_approved',
                "Approved reschedule request #{$requestId} for applicant #{$applicantId} → slot #{$newSlot}",
                'applicant', $applicantId
            );

            // In-app notification + branded email to the student.
            $when = trim(
                format_date((string)$newSlotInfo['slot_date'])
                . (!empty($newSlotInfo['slot_time']) ? ', ' . format_time((string)$newSlotInfo['slot_time']) : '')
            );
            $extra = "Your new slot is {$when}"
                   . (!empty($newSlotInfo['location_label']) ? ' at ' . $newSlotInfo['location_label'] : '')
                   . '.';
            notify_reschedule_decision($applicantId, 'interview', 'approved', $extra);

            Session::flash('success', 'Reschedule request approved — student assigned to a new slot.');
        } else {
            Session::flash('error', $approveErr ?? 'Reschedule could not be approved.');
        }
        redirect('/staff/interviews/absent?tab=requests');
    }

    // ── Deny a reschedule request ──────────────────────────────
    if ($action === 'deny_reschedule') {
        $requestId  = (int)($_POST['request_id'] ?? 0);
        $denyReason = trim($_POST['deny_reason'] ?? '');

        $rr = $db->prepare('SELECT * FROM reschedule_requests WHERE id = ? AND status = "pending" LIMIT 1');
        $rr->execute([$requestId]);
        $req = $rr->fetch();

        if (!$req) {
            Session::flash('error', 'Reschedule request not found or already processed.');
        } else {
            $db->prepare(
                'UPDATE reschedule_requests
                    SET status = "denied", reviewed_by = ?, reviewed_at = NOW(), deny_reason = ?
                  WHERE id = ?'
            )->execute([$staffId, $denyReason !== '' ? $denyReason : null, $requestId]);

            audit_log('reschedule_request_denied',
                "Denied reschedule request #{$requestId} for applicant #{$req['applicant_id']}"
                . ($denyReason !== '' ? " — {$denyReason}" : ''),
                'applicant', (int)$req['applicant_id']);

            // In-app notification + email — surface the deny reason if
            // staff provided one.
            $extra = $denyReason !== ''
                   ? "Reason: {$denyReason}"
                   : 'Your original slot stays the same.';
            notify_reschedule_decision((int)$req['applicant_id'], 'interview', 'denied', $extra);

            Session::flash('success', 'Reschedule request denied — student keeps their current slot.');
        }
        redirect('/staff/interviews/absent?tab=requests');
    }

    // ── Reschedule absent students ─────────────────────────────
    if ($action === 'reschedule') {
        $applicantIds = $_POST['applicant_ids'] ?? [];
        if (!is_array($applicantIds)) { $applicantIds = []; }
        $applicantIds = array_values(array_unique(array_map('intval', $applicantIds)));
        $applicantIds = array_filter($applicantIds, fn($v) => $v > 0);

        $targetSlotId = (int)($_POST['target_slot_id'] ?? 0);

        if (empty($applicantIds)) {
            $errors[] = 'Please select at least one absent applicant to reschedule.';
        } else {
            $ok = 0;
            $fail = 0;
            foreach ($applicantIds as $aid) {
                try {
                    $newSlot = reschedule_absent_applicant(
                        $aid,
                        $targetSlotId > 0 ? $targetSlotId : null,
                        $staffId
                    );
                    if ($newSlot) $ok++; else $fail++;
                } catch (Throwable $e) {
                    error_log('reschedule_absent_applicant failed: ' . $e->getMessage());
                    $fail++;
                }
            }
            if ($ok > 0) {
                Session::flash('success',
                    "Rescheduled {$ok} applicant(s)."
                    . ($fail > 0 ? " {$fail} could not be rescheduled (no open slot)." : ''));
            } else {
                Session::flash('error',
                    'No applicants could be rescheduled — make sure there are open slots for their department.');
            }
            redirect('/staff/interviews/absent');
        }
    }
}

// ----------------------------------------------------------------
// Load pending reschedule requests
// ----------------------------------------------------------------
$reschedRequests = $db->query(
    'SELECT rr.*, a.course_applied,
            u.name AS student_name, u.email AS student_email,
            u.first_name, u.middle_name, u.last_name, u.suffix,
            u.department AS student_department,
            s.slot_date AS cur_date,
            s.slot_time AS cur_time,
            s.end_time  AS cur_end_time,
            s.department AS slot_department
       FROM reschedule_requests rr
       JOIN interview_queue q ON q.id = rr.queue_id
       JOIN applicants a      ON a.id = rr.applicant_id
       JOIN users u           ON u.id = a.user_id
  LEFT JOIN interview_slots s ON s.id = q.slot_id
      WHERE rr.status = "pending"
        AND COALESCE(a.overall_status, "") <> "withdrawn"
      ORDER BY rr.created_at ASC'
)->fetchAll();

// ----------------------------------------------------------------
// Load absent applicants + their previous slot
// ----------------------------------------------------------------
// Auto-detect no-shows on page load: any pending queue row whose slot
// has already ended is flipped to status='no_show',
// interview_status='absent', attendance_status='absent', so unmarked
// students show up here automatically without an interviewer having
// to manually mark them.  Idempotent — already-absent rows are
// skipped.
if (function_exists('auto_detect_interview_no_shows')) {
    try {
        auto_detect_interview_no_shows(null, $staffId);
    } catch (\Throwable $e) {
        error_log('auto_detect_interview_no_shows on staff_absent load failed: ' . $e->getMessage());
    }
}

$absent = $db->query(
    'SELECT q.id            AS queue_id,
            q.applicant_id,
            q.evaluated_at,
            s.id             AS slot_id,
            s.slot_date      AS missed_date,
            s.slot_time      AS missed_time,
            s.department     AS missed_department,
            a.course_applied,
            u.name           AS student_name,
            u.first_name, u.middle_name, u.last_name, u.suffix,
            u.email          AS student_email,
            u.department     AS student_department
       FROM interview_queue q
       JOIN applicants a ON a.id = q.applicant_id
       JOIN users u      ON u.id = a.user_id
  LEFT JOIN interview_slots s ON s.id = q.slot_id
      WHERE q.interview_status = "absent"
      ORDER BY q.evaluated_at DESC, q.id DESC'
)->fetchAll();

// ----------------------------------------------------------------
// Load open slots (used as reschedule targets)
// ----------------------------------------------------------------
$today   = date('Y-m-d');
$nowTime = date('H:i:s');
$stmt = $db->prepare(
    'SELECT s.id, s.slot_date, s.slot_time, s.end_time, s.department, s.capacity,
            (SELECT COUNT(*) FROM interview_queue q
              WHERE q.slot_id = s.id
                AND q.interview_status IN ("pending","completed")) AS booked
       FROM interview_slots s
      WHERE s.status = "open"
        AND s.slot_date >= ?
        AND NOT (s.slot_date = ? AND s.end_time IS NOT NULL AND s.end_time <= ?)
      ORDER BY s.slot_date ASC, s.slot_time ASC'
);
$stmt->execute([$today, $today, $nowTime]);
$openSlots = array_filter(
    $stmt->fetchAll(),
    fn($s) => (int)$s['booked'] < (int)$s['capacity']
);

// ----------------------------------------------------------------
// Also load reschedule history per absent applicant (most recent 3)
// to surface "this is their 2nd miss" info for context.
// ----------------------------------------------------------------
$historyByApplicant = [];
if (!empty($absent)) {
    $ids  = array_map(fn($r) => (int)$r['applicant_id'], $absent);
    $in   = implode(',', array_fill(0, count($ids), '?'));
    $rows = $db->prepare(
        "SELECT applicant_id, from_slot_date, from_slot_time, rescheduled_at
           FROM reschedule_logs
          WHERE applicant_id IN ($in)
          ORDER BY rescheduled_at DESC"
    );
    $rows->execute($ids);
    foreach ($rows->fetchAll() as $h) {
        $historyByApplicant[(int)$h['applicant_id']][] = $h;
    }
}

// Check for today's sessions (needed for Live Queue tab)
$todayStmt = $db->prepare(
    'SELECT COUNT(*) FROM interview_slots WHERE created_by = ? AND slot_date = ?'
);
$todayStmt->execute([$staffId, $today]);
$hasToday = (int)$todayStmt->fetchColumn() > 0;

ob_start();
?>

<style>
.page:has(.sa-table-card) { display:flex; flex-direction:column; }
.sa-table-card { flex:1; min-height:300px; display:flex; flex-direction:column; }
.sa-table-card table { flex:0 0 auto; }
.sa-table-card .sa-filler { flex:1; border-top:1px solid var(--border); }
</style>

<?php foreach ($errors as $err): ?>
    <div class="alert alert-error" style="margin-bottom:var(--space-4)"><?= e($err) ?></div>
<?php endforeach; ?>
<?php foreach ($success as $s): ?>
    <div class="alert alert-success" style="margin-bottom:var(--space-4)"><?= e($s) ?></div>
<?php endforeach; ?>

<div style="margin-bottom:var(--space-5);display:flex;justify-content:space-between;gap:var(--space-3);flex-wrap:wrap">
    <a href="<?= url('/staff/interviews') ?>" class="btn btn-ghost btn-sm">← Back</a>
    <?php if ($canReschedule): ?>
        <a href="<?= url('/staff/interviews/cancel-slot') ?>" class="btn btn-ghost btn-sm">
            Cancel a slot (bulk move) →
        </a>
    <?php endif; ?>
</div>

<!-- ============================================================
     TAB NAVIGATION
============================================================ -->
<div style="display:flex;gap:var(--space-1);margin-bottom:var(--space-5);border-bottom:1.5px solid var(--border)">
    <a href="<?= url('/staff/interviews/absent?tab=absent') ?>"
       style="padding:var(--space-2) var(--space-4);font-size:var(--text-sm);font-weight:var(--weight-medium);
              text-decoration:none;border-bottom:2px solid <?= $activeTab === 'absent' ? 'var(--accent)' : 'transparent' ?>;
              color:<?= $activeTab === 'absent' ? 'var(--accent)' : 'var(--text-secondary)' ?>;
              margin-bottom:-1.5px">
        Absent Students
        <?php if (!empty($absent)): ?>
            <span style="background:var(--bg-subtle);padding:1px 7px;border-radius:999px;font-size:var(--text-xs);
                          margin-left:var(--space-1)"><?= count($absent) ?></span>
        <?php endif; ?>
    </a>
    <?php if (!$isDean): ?>
    <a href="<?= url('/staff/interviews/absent?tab=requests') ?>"
       style="padding:var(--space-2) var(--space-4);font-size:var(--text-sm);font-weight:var(--weight-medium);
              text-decoration:none;border-bottom:2px solid <?= $activeTab === 'requests' ? 'var(--accent)' : 'transparent' ?>;
              color:<?= $activeTab === 'requests' ? 'var(--accent)' : 'var(--text-secondary)' ?>;
              margin-bottom:-1.5px">
        Reschedule Requests
        <?php if (!empty($reschedRequests)): ?>
            <span style="background:var(--warning-bg);color:var(--warning);padding:1px 7px;border-radius:999px;
                          font-size:var(--text-xs);margin-left:var(--space-1)"><?= count($reschedRequests) ?></span>
        <?php endif; ?>
    </a>
    <?php endif; ?>
</div>

<?php if ($activeTab === 'absent'): ?>
<!-- ============================================================
     TAB 1: ABSENT STUDENTS
============================================================ -->
<?php if (empty($absent)): ?>
    <div class="card" style="padding:var(--space-8);text-align:left;color:var(--text-tertiary)">
        No absent applicants right now.
    </div>
<?php else: ?>
    <?php if ($canReschedule): ?>
    <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="reschedule">
    <?php endif; ?>

        <div class="card sa-table-card" style="padding:0;overflow:hidden;margin-bottom:var(--space-4)">
            <table class="table" style="width:100%;border-collapse:collapse">
                <thead>
                    <tr style="background:var(--bg-subtle);text-align:left;font-size:var(--text-xs);
                                color:var(--text-tertiary);text-transform:uppercase;letter-spacing:.06em">
                        <?php if ($canReschedule): ?>
                        <th style="padding:var(--space-3) var(--space-4);width:36px">
                            <input type="checkbox" id="select-all">
                        </th>
                        <?php endif; ?>
                        <th style="padding:var(--space-3) var(--space-4)">Student</th>
                        <th style="padding:var(--space-3) var(--space-4)">Course / Dept</th>
                        <th style="padding:var(--space-3) var(--space-4)">Missed</th>
                        <th style="padding:var(--space-3) var(--space-4)">History</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($absent as $row):
                    $hist = $historyByApplicant[(int)$row['applicant_id']] ?? [];
                ?>
                    <tr style="border-top:1px solid var(--border);font-size:var(--text-sm)">
                        <?php if ($canReschedule): ?>
                        <td style="padding:var(--space-3) var(--space-4)">
                            <input type="checkbox"
                                   name="applicant_ids[]"
                                   value="<?= (int)$row['applicant_id'] ?>"
                                   class="js-select-row">
                        </td>
                        <?php endif; ?>
                        <td style="padding:var(--space-3) var(--space-4)">
                            <div style="font-weight:var(--weight-medium)"><?= e(format_full_name($row)) ?></div>
                            <div style="color:var(--text-tertiary);font-size:var(--text-xs)">
                                <?= e($row['student_email']) ?>
                            </div>
                        </td>
                        <td style="padding:var(--space-3) var(--space-4)">
                            <div><?= e($row['course_applied'] ?: '—') ?></div>
                            <div style="color:var(--text-tertiary);font-size:var(--text-xs)">
                                <?= e($row['student_department'] ?: 'no department') ?>
                            </div>
                        </td>
                        <td style="padding:var(--space-3) var(--space-4)">
                            <?php if ($row['missed_date']): ?>
                                <?= format_date($row['missed_date']) ?>
                                <?php if ($row['missed_time']): ?>
                                    <div style="color:var(--text-tertiary);font-size:var(--text-xs)">
                                        <?= format_time($row['missed_time']) ?>
                                    </div>
                                <?php endif; ?>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                        <td style="padding:var(--space-3) var(--space-4)">
                            <?php if (empty($hist)): ?>
                                <span style="color:var(--text-tertiary);font-size:var(--text-xs)">
                                    First miss
                                </span>
                            <?php else: ?>
                                <span style="font-size:var(--text-xs);color:var(--text-tertiary)">
                                    <?= count($hist) ?> previous reschedule<?= count($hist) === 1 ? '' : 's' ?>
                                </span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($canReschedule): ?>
        <div class="card" style="padding:var(--space-4) var(--space-5);display:flex;align-items:center;gap:var(--space-3);flex-wrap:wrap">
            <label style="font-size:var(--text-sm);font-weight:var(--weight-medium)">Reschedule target:</label>
            <select name="target_slot_id" class="form-control" style="max-width:420px">
                <option value="0">Auto-assign (earliest matching slot)</option>
                <?php foreach ($openSlots as $s):
                    $spotsLeft = (int)$s['capacity'] - (int)$s['booked'];
                ?>
                    <option value="<?= (int)$s['id'] ?>">
                        <?= format_date($s['slot_date']) ?>
                        <?php if ($s['slot_time']): ?>
                            at <?= format_time($s['slot_time']) ?>
                        <?php endif; ?>
                        &nbsp;·&nbsp; <?= e($s['department'] ?: 'any dept') ?>
                        (<?= $spotsLeft ?> spot<?= $spotsLeft !== 1 ? 's' : '' ?> left)
                    </option>
                <?php endforeach; ?>
            </select>
            <div style="flex:1"></div>
            <button type="submit" class="btn btn-primary">Reschedule selected</button>
        </div>
        <p style="font-size:var(--text-xs);color:var(--text-tertiary);margin-top:var(--space-2)">
            Choosing "Auto-assign" will pick the earliest slot that matches each applicant's department.
            Reschedules are recorded in <code>reschedule_logs</code> for audit.
        </p>
    </form>
        <?php else: ?>
        <div style="font-size:var(--text-xs);color:var(--text-tertiary);margin-top:var(--space-3)">
            Only SSO and Admin can reschedule students.
        </div>
        <?php endif; ?>

    <script>
        const selectAll = document.getElementById('select-all');
        if (selectAll) {
            selectAll.addEventListener('change', function () {
                document.querySelectorAll('.js-select-row').forEach(function (cb) {
                    cb.checked = selectAll.checked;
                });
            });
        }
    </script>
<?php endif; ?>

<?php else: ?>
<!-- ============================================================
     TAB 2: RESCHEDULE REQUESTS
============================================================ -->
<?php if (empty($reschedRequests)): ?>
    <div class="card sa-table-card" style="padding:var(--space-8);color:var(--text-tertiary);align-items:center;justify-content:center;text-align:center">
        No pending reschedule requests.
    </div>
<?php else: ?>
    <div class="card sa-table-card" style="padding:0;overflow:hidden">
        <table class="table" style="width:100%;border-collapse:collapse">
            <thead>
                <tr style="background:var(--bg-subtle);text-align:left;font-size:var(--text-xs);
                            color:var(--text-tertiary);text-transform:uppercase;letter-spacing:.06em">
                    <th style="padding:var(--space-3) var(--space-4)">Student</th>
                    <th style="padding:var(--space-3) var(--space-4)">Course / Dept</th>
                    <th style="padding:var(--space-3) var(--space-4)">Current Slot</th>
                    <th style="padding:var(--space-3) var(--space-4)">Reason</th>
                    <th style="padding:var(--space-3) var(--space-4)">Submitted</th>
                    <?php if ($canReschedule): ?>
                    <th style="padding:var(--space-3) var(--space-4)">Action</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($reschedRequests as $rr): ?>
                <tr style="border-top:1px solid var(--border);font-size:var(--text-sm)">
                    <td style="padding:var(--space-3) var(--space-4)">
                        <div style="font-weight:var(--weight-medium)"><?= e(format_full_name($rr)) ?></div>
                        <div style="color:var(--text-tertiary);font-size:var(--text-xs)">
                            <?= e($rr['student_email']) ?>
                        </div>
                    </td>
                    <td style="padding:var(--space-3) var(--space-4)">
                        <div><?= e($rr['course_applied'] ?: '—') ?></div>
                        <div style="color:var(--text-tertiary);font-size:var(--text-xs)">
                            <?= e($rr['student_department'] ?: 'no department') ?>
                        </div>
                    </td>
                    <td style="padding:var(--space-3) var(--space-4)">
                        <?php if ($rr['cur_date']): ?>
                            <?= format_date($rr['cur_date']) ?>
                            <?php if ($rr['cur_time']): ?>
                                <div style="color:var(--text-tertiary);font-size:var(--text-xs)">
                                    <?= format_time($rr['cur_time']) ?><?= $rr['cur_end_time'] ? ' – ' . format_time($rr['cur_end_time']) : '' ?>
                                </div>
                            <?php endif; ?>
                        <?php else: ?>
                            —
                        <?php endif; ?>
                    </td>
                    <td style="padding:var(--space-3) var(--space-4);max-width:280px">
                        <div style="white-space:pre-line;word-break:break-word"><?= e($rr['reason']) ?></div>
                    </td>
                    <td style="padding:var(--space-3) var(--space-4);white-space:nowrap">
                        <?= date('M j, g:i A', strtotime($rr['created_at'])) ?>
                    </td>
                    <?php if ($canReschedule): ?>
                    <td style="padding:var(--space-3) var(--space-4)">
                        <div style="display:flex;gap:var(--space-2);align-items:center">
                            <form method="POST" style="display:flex;gap:var(--space-2);align-items:center">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="approve_reschedule">
                                <input type="hidden" name="request_id" value="<?= (int)$rr['id'] ?>">
                                <select name="target_slot_id" class="form-control"
                                        style="font-size:var(--text-xs);height:28px;min-height:28px;max-width:200px;padding:0 var(--space-2)">
                                    <option value="0">Auto-assign</option>
                                    <?php foreach ($openSlots as $s):
                                        $spotsLeft = (int)$s['capacity'] - (int)$s['booked'];
                                    ?>
                                        <option value="<?= (int)$s['id'] ?>">
                                            <?= format_date($s['slot_date']) ?>
                                            <?php if ($s['slot_time']): ?> <?= format_time($s['slot_time']) ?><?php endif; ?>
                                            · <?= e($s['department'] ?: 'any') ?>
                                            (<?= $spotsLeft ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="submit" class="btn btn-sm btn-primary"
                                        style="height:28px;min-height:28px;padding:0 var(--space-3);font-size:var(--text-xs)"
                                        title="Approve and assign new slot">Approve</button>
                            </form>
                            <form method="POST" style="display:flex;gap:var(--space-2);align-items:center"
                                  onsubmit="return confirm('Deny this reschedule request? The student keeps their current slot.')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="deny_reschedule">
                                <input type="hidden" name="request_id" value="<?= (int)$rr['id'] ?>">
                                <input type="text" name="deny_reason"
                                       placeholder="Reason (optional, shown to student)"
                                       maxlength="500"
                                       class="form-control"
                                       style="font-size:var(--text-xs);height:28px;min-height:28px;max-width:240px;padding:0 var(--space-2)">
                                <button type="submit" class="btn btn-sm btn-ghost"
                                        style="height:28px;min-height:28px;padding:0 var(--space-3);font-size:var(--text-xs);
                                               color:var(--error)" title="Deny request">Deny</button>
                            </form>
                        </div>
                    </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <div class="sa-filler"></div>
    </div>
    <?php if (!$canReschedule): ?>
    <div style="font-size:var(--text-xs);color:var(--text-tertiary);margin-top:var(--space-3)">
        Only SSO and Admin can approve or deny reschedule requests.
    </div>
    <?php endif; ?>
<?php endif; ?>
<?php endif; /* end activeTab */ ?>

<?php
$content   = ob_get_clean();
$pageTitle = $activeTab === 'requests' ? 'Reschedule Requests' : 'Absent Students';
$activeNav = $activeTab === 'requests' ? 'reschedule' : 'interviews';
$pageWide  = true; // table-heavy page — match staff_slots / staff_review / staff_manage
include VIEWS_PATH . '/layouts/app.php';
