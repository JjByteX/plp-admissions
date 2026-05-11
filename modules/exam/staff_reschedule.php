<?php
// ============================================================
// modules/exam/staff_reschedule.php
//
// Exam reschedule requests page (admin side).
//
// Mirrors modules/interview/staff_absent.php's "Reschedule
// Requests" tab, but for the exam side. Lists pending exam
// reschedule requests and lets SSO/Admin approve or deny each
// one. Approve auto-assigns the student to a new exam slot
// (specific or earliest matching) by swapping their
// applicant_exam_slots row; if there is no open slot available
// it refuses cleanly and tells the admin to create one first.
//
// Staff / Proctor / Dean: read-only.
// SSO / Admin: can approve and deny.
//
// URL:
//   GET  /staff/exam/reschedule
//   POST /staff/exam/reschedule   (action=approve_reschedule|deny_reschedule)
// ============================================================

require_once CORE_PATH . '/bootstrap.php';
Auth::requireRole(ROLE_STAFF, ROLE_PROCTOR, ROLE_SSO, ROLE_DEAN, ROLE_ADMIN);

$db      = db();
$staffId = Auth::id();
$role    = Auth::role();
$canReschedule = in_array($role, [ROLE_SSO, ROLE_ADMIN], true);

$errors  = [];

// Ensure the exam_reschedule_requests table exists.
ensure_exam_reschedule_requests_table();

// ----------------------------------------------------------------
// POST — only SSO / Admin may approve or deny
// ----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if (!$canReschedule) {
        Session::flash('error', 'Only SSO and Admin can approve or deny exam reschedule requests.');
        redirect('/staff/exam/reschedule');
    }

    // ── Approve a reschedule request ────────────────────────────
    if ($action === 'approve_reschedule') {
        $requestId    = (int)($_POST['request_id'] ?? 0);
        $targetSlotId = (int)($_POST['target_slot_id'] ?? 0);

        $rr = $db->prepare('SELECT * FROM exam_reschedule_requests WHERE id = ? AND status = "pending" LIMIT 1');
        $rr->execute([$requestId]);
        $req = $rr->fetch();

        if (!$req) {
            Session::flash('error', 'Reschedule request not found or already processed.');
            redirect('/staff/exam/reschedule');
        }

        $applicantId = (int)$req['applicant_id'];
        $oldSlotId   = (int)$req['slot_id'];

        // Look up the exam_id on the student's current slot so we can
        // constrain any candidate slot to the same exam (multi-exam
        // orgs only — single-exam installs pass through unchanged).
        $oldSlotExamId = null;
        try {
            $oldSlotExamId = $db->prepare(
                'SELECT exam_id FROM exam_slot_schedule WHERE id = ? LIMIT 1'
            );
            $oldSlotExamId->execute([$oldSlotId]);
            $oldSlotExamId = $oldSlotExamId->fetchColumn();
            $oldSlotExamId = $oldSlotExamId !== false && $oldSlotExamId !== null
                           ? (int)$oldSlotExamId
                           : null;
        } catch (\Throwable) { $oldSlotExamId = null; }

        // ------------------------------------------------------------
        // Pre-flight: make sure there's actually a slot to move them
        // to BEFORE we mutate applicant_exam_slots.
        //
        // If the admin clicked Approve while their dropdown was empty
        // (or only the "Auto-assign" option was visible), tell them to
        // create a slot first instead of failing silently.
        // ------------------------------------------------------------
        $today = date('Y-m-d');

        // Load applicant's department so the auto-assign pre-check
        // matches the same department rule applied at assignment time.
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

        $slotErr = null;
        $candidateSlotId = null;

        if ($targetSlotId > 0) {
            // Admin picked a specific slot — verify it's still usable
            // (open, future, has capacity, and not the same slot they
            // currently sit in). Also enforce same exam_id when the
            // student's current slot has one bound — keeps multi-exam
            // orgs from accidentally moving a student onto a slot for
            // a different exam.
            $chk = $db->prepare(
                'SELECT id, exam_date, capacity, filled, exam_id
                   FROM exam_slot_schedule
                  WHERE id = ? LIMIT 1'
            );
            $chk->execute([$targetSlotId]);
            $cand = $chk->fetch();
            $candExamId  = $cand && $cand['exam_id'] !== null ? (int)$cand['exam_id'] : null;
            $sameExamOk  = $oldSlotExamId === null
                        || $candExamId === null
                        || $candExamId === $oldSlotExamId;
            $valid = $cand
                  && (string)$cand['exam_date'] >= $today
                  && (int)$cand['filled'] < (int)$cand['capacity']
                  && (int)$cand['id'] !== $oldSlotId
                  && $sameExamOk;
            if (!$valid) {
                $slotErr = !$sameExamOk
                         ? 'The slot you picked is for a different exam than the student\'s current slot. Pick a slot for the same exam.'
                         : 'The slot you picked is no longer available. Please create a new slot or pick another one, then try again.';
            } else {
                $candidateSlotId = (int)$cand['id'];
            }
        } else {
            // Auto-assign — pick the earliest matching open slot
            // (department first, then any). Constrain by exam_id when
            // the old slot had one bound; otherwise any exam is ok.
            $params = [$today, $oldSlotId];
            $sql = 'SELECT id FROM exam_slot_schedule
                     WHERE exam_date >= ?
                       AND filled < capacity
                       AND id <> ?';
            if ($oldSlotExamId !== null) {
                $sql .= ' AND (exam_id = ? OR exam_id IS NULL)';
                $params[] = $oldSlotExamId;
            }
            if ($department !== '') {
                $sql .= ' AND department = ?';
                $params[] = $department;
            }
            $sql .= ' ORDER BY exam_date ASC, slot_time ASC LIMIT 1';
            $st = $db->prepare($sql);
            $st->execute($params);
            $candidateSlotId = (int)($st->fetchColumn() ?: 0);

            // If nothing matches the student's department, fall back to
            // any open future slot — same fallback as auto_assign_exam_slot().
            if (!$candidateSlotId && $department !== '') {
                $params2 = [$today, $oldSlotId];
                $sql2 = 'SELECT id FROM exam_slot_schedule
                          WHERE exam_date >= ?
                            AND filled < capacity
                            AND id <> ?';
                if ($oldSlotExamId !== null) {
                    $sql2 .= ' AND (exam_id = ? OR exam_id IS NULL)';
                    $params2[] = $oldSlotExamId;
                }
                $sql2 .= ' ORDER BY exam_date ASC, slot_time ASC LIMIT 1';
                $st2 = $db->prepare($sql2);
                $st2->execute($params2);
                $candidateSlotId = (int)($st2->fetchColumn() ?: 0);
            }

            if (!$candidateSlotId) {
                $deptLabel = $department !== '' ? " for {$department}" : '';
                $slotErr   = "Cannot approve — there's no open exam slot{$deptLabel} yet. "
                           . 'Please create a slot first, then come back and approve this request.';
            }
        }

        if ($slotErr !== null) {
            Session::flash('error', $slotErr);
            redirect('/staff/exam/reschedule');
        }

        // ------------------------------------------------------------
        // Pre-check passed — perform the slot swap in a transaction.
        // ------------------------------------------------------------
        $ok = false;
        try {
            $db->beginTransaction();

            // Decrement the old slot's filled count (clamp at 0).
            $db->prepare(
                'UPDATE exam_slot_schedule
                    SET filled = GREATEST(filled - 1, 0)
                  WHERE id = ?'
            )->execute([$oldSlotId]);

            // Re-check capacity on the new slot under the same
            // transaction to avoid two admins double-booking it.
            $cap = $db->prepare(
                'SELECT capacity, filled FROM exam_slot_schedule WHERE id = ? FOR UPDATE'
            );
            $cap->execute([$candidateSlotId]);
            $row = $cap->fetch();
            if (!$row || (int)$row['filled'] >= (int)$row['capacity']) {
                throw new \RuntimeException('Target slot filled up before we could finish.');
            }

            // Point the applicant at the new slot and increment its count.
            $db->prepare(
                'UPDATE applicant_exam_slots SET slot_id = ?, assigned_at = NOW() WHERE applicant_id = ?'
            )->execute([$candidateSlotId, $applicantId]);

            $db->prepare(
                'UPDATE exam_slot_schedule SET filled = filled + 1 WHERE id = ?'
            )->execute([$candidateSlotId]);

            // Mark the request approved.
            $db->prepare(
                'UPDATE exam_reschedule_requests
                    SET status = "approved", reviewed_by = ?, reviewed_at = NOW()
                  WHERE id = ?'
            )->execute([$staffId, $requestId]);

            $db->commit();
            $ok = true;
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('approve_exam_reschedule failed: ' . $e->getMessage());
        }

        if ($ok) {
            audit_log(
                'exam_reschedule_request_approved',
                "Approved exam reschedule request #{$requestId} for applicant #{$applicantId} → slot #{$candidateSlotId}",
                'applicant',
                $applicantId
            );

            // Notify the student so their /student/exam page flips
            // from "Pending review" to the new slot card right away.
            try {
                $u = $db->prepare(
                    'SELECT u.id FROM applicants a JOIN users u ON u.id = a.user_id WHERE a.id = ? LIMIT 1'
                );
                $u->execute([$applicantId]);
                $studentUserId = (int)($u->fetchColumn() ?: 0);
                if ($studentUserId > 0 && function_exists('create_notification')) {
                    create_notification(
                        $studentUserId,
                        'exam_reschedule_approved',
                        'Exam reschedule approved',
                        'Your exam has been moved to a new slot. See your exam page for details.',
                        '/student/exam'
                    );
                }
            } catch (\Throwable $e) {
                error_log('notify student (exam approve) failed: ' . $e->getMessage());
            }

            // Resolve the new slot's details so the student gets a
            // useful in-app + email message ("new slot: Jan 5, 9 AM,
            // Rm 201").
            $extraInfo = '';
            try {
                $info = $db->prepare(
                    'SELECT exam_date, slot_time, room_label
                       FROM exam_slot_schedule WHERE id = ? LIMIT 1'
                );
                $info->execute([$candidateSlotId]);
                $infoRow = $info->fetch();
                if ($infoRow) {
                    $when = format_date((string)$infoRow['exam_date'])
                          . (!empty($infoRow['slot_time']) ? ', ' . format_time((string)$infoRow['slot_time']) : '');
                    $extraInfo = "Your new exam slot is {$when}"
                               . (!empty($infoRow['room_label']) ? ' at ' . $infoRow['room_label'] : '')
                               . '.';
                }
            } catch (\Throwable) {}

            notify_reschedule_decision($applicantId, 'exam', 'approved', $extraInfo);

            Session::flash('success', 'Exam reschedule approved — student moved to a new slot.');
        } else {
            Session::flash(
                'error',
                'The slot filled up before we could finish. Please create or pick another slot, then try again.'
            );
        }
        redirect('/staff/exam/reschedule');
    }

    // ── Deny a reschedule request ──────────────────────────────
    if ($action === 'deny_reschedule') {
        $requestId  = (int)($_POST['request_id'] ?? 0);
        $denyReason = trim($_POST['deny_reason'] ?? '');

        $rr = $db->prepare('SELECT * FROM exam_reschedule_requests WHERE id = ? AND status = "pending" LIMIT 1');
        $rr->execute([$requestId]);
        $req = $rr->fetch();

        if (!$req) {
            Session::flash('error', 'Reschedule request not found or already processed.');
        } else {
            $db->prepare(
                'UPDATE exam_reschedule_requests
                    SET status = "denied", reviewed_by = ?, reviewed_at = NOW(), deny_reason = ?
                  WHERE id = ?'
            )->execute([$staffId, $denyReason !== '' ? $denyReason : null, $requestId]);
            audit_log(
                'exam_reschedule_request_denied',
                "Denied exam reschedule request #{$requestId} for applicant #{$req['applicant_id']}"
                . ($denyReason !== '' ? " — {$denyReason}" : ''),
                'applicant',
                (int)$req['applicant_id']
            );

            $extra = $denyReason !== ''
                   ? "Reason: {$denyReason}"
                   : 'Your original slot stays the same.';
            notify_reschedule_decision((int)$req['applicant_id'], 'exam', 'denied', $extra);

            Session::flash('success', 'Exam reschedule denied — student keeps their current slot.');
        }
        redirect('/staff/exam/reschedule');
    }
}

// ----------------------------------------------------------------
// Load pending reschedule requests with applicant + current-slot info
// ----------------------------------------------------------------
$reschedRequests = $db->query(
    'SELECT rr.*, a.course_applied,
            u.name AS student_name, u.email AS student_email,
            u.first_name, u.middle_name, u.last_name, u.suffix,
            u.department AS student_department,
            s.exam_date  AS cur_date,
            s.slot_time  AS cur_time,
            s.end_time   AS cur_end_time,
            s.room_label AS cur_room,
            s.department AS slot_department
       FROM exam_reschedule_requests rr
       JOIN applicants a ON a.id = rr.applicant_id
       JOIN users u      ON u.id = a.user_id
  LEFT JOIN exam_slot_schedule s ON s.id = rr.slot_id
      WHERE rr.status = "pending"
        AND COALESCE(a.overall_status, "") <> "withdrawn"
      ORDER BY rr.created_at ASC'
)->fetchAll();

// ----------------------------------------------------------------
// Load open future slots (used as reschedule targets in the dropdown)
// ----------------------------------------------------------------
$today = date('Y-m-d');
$stmt = $db->prepare(
    'SELECT id, exam_date, slot_time, end_time, room_label, department, capacity, filled
       FROM exam_slot_schedule
      WHERE exam_date >= ?
        AND filled < capacity
      ORDER BY exam_date ASC, slot_time ASC'
);
$stmt->execute([$today]);
$openSlots = $stmt->fetchAll();

ob_start();
?>

<style>
.page:has(.sa-table-card) { display:flex; flex-direction:column; }
.sa-table-card { flex:1; min-height:300px; display:flex; flex-direction:column; }
.sa-table-card table { flex:0 0 auto; }
.sa-table-card .sa-filler { flex:1; border-top:1px solid var(--border); }
</style>

<div style="margin-bottom:var(--space-5);display:flex;justify-content:space-between;gap:var(--space-3);flex-wrap:wrap">
    <a href="<?= url('/staff/exam/slots') ?>" class="btn btn-ghost btn-sm">← Back to Exam Slots</a>
    <?php if ($canReschedule): ?>
        <a href="<?= url('/staff/exam/cancel-slot') ?>" class="btn btn-ghost btn-sm">
            Cancel a slot (bulk move) →
        </a>
    <?php endif; ?>
</div>

<?php if (empty($reschedRequests)): ?>
    <div class="card sa-table-card" style="padding:var(--space-8);color:var(--text-tertiary);align-items:center;justify-content:center;text-align:center">
        No pending exam reschedule requests.
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
                            <?php if ($rr['cur_room']): ?>
                                <div style="color:var(--text-tertiary);font-size:var(--text-xs)">
                                    <?= e($rr['cur_room']) ?>
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
                                        style="font-size:var(--text-xs);height:28px;min-height:28px;max-width:220px;padding:0 var(--space-2)">
                                    <option value="0">Auto-assign</option>
                                    <?php foreach ($openSlots as $s):
                                        if ((int)$s['id'] === (int)$rr['slot_id']) continue;
                                        $spotsLeft = (int)$s['capacity'] - (int)$s['filled'];
                                    ?>
                                        <option value="<?= (int)$s['id'] ?>">
                                            <?= format_date($s['exam_date']) ?>
                                            <?php if ($s['slot_time']): ?> <?= format_time($s['slot_time']) ?><?php endif; ?>
                                            · <?= e($s['room_label'] ?: 'room') ?>
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
                                  onsubmit="return confirm('Deny this exam reschedule request? The student keeps their current slot.')">
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
        Only SSO and Admin can approve or deny exam reschedule requests.
    </div>
    <?php endif; ?>
<?php endif; ?>

<?php
$content   = ob_get_clean();
$pageTitle = 'Exam Reschedule Requests';
$activeNav = 'exam-reschedule';
$pageWide  = true; // table-heavy page — match staff_slots / staff_review / staff_manage
include VIEWS_PATH . '/layouts/app.php';
