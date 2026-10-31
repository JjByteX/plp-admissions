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
// Staff / Proctor: read-only.
// SSO / Admin:     can approve and deny.
// Dean:            no access. Reschedules are a scheduling /
//                  registrar action owned by SSO, not an
//                  academic-oversight one. Symmetric with the
//                  interview-side reschedule page, where the
//                  "Reschedule Requests" tab is also hidden
//                  from Dean.
//
// URL:
//   GET  /staff/exam/reschedule
//   POST /staff/exam/reschedule   (action=approve_reschedule|deny_reschedule)
// ============================================================

require_once CORE_PATH . '/bootstrap.php';
Auth::requireRole(ROLE_STAFF, ROLE_PROCTOR, ROLE_SSO, ROLE_ADMIN);

$db      = db();
$staffId = Auth::id();
$role    = Auth::role();
$canReschedule = in_array($role, [ROLE_SSO, ROLE_ADMIN], true);

$errors  = [];

// ----------------------------------------------------------------
// POST — only SSO / Admin may approve or deny
// ----------------------------------------------------------------
if (($action = require_post_action()) !== null) {

    if (!$canReschedule) {
        fail('Only SSO and Admin can approve or deny exam reschedule requests.', '/staff/exam/reschedule');
    }

    // ── Approve a reschedule request ────────────────────────────
    if ($action === 'approve_reschedule') {
        $requestId    = post_int('request_id');
        $targetSlotId = post_int('target_slot_id');

        $rr = $db->prepare('SELECT * FROM exam_reschedule_requests WHERE id = ? AND status = \'pending\' LIMIT 1');
        $rr->execute([$requestId]);
        $req = $rr->fetch();

        if (!$req) {
            fail('Reschedule request not found or already processed.', '/staff/exam/reschedule');
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
        $today = now_date();

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
                'SELECT id, exam_date, capacity, filled, exam_id,
                        (CASE WHEN now() > ' . slot_close_sql('exam_slot_schedule') . ' THEN 1 ELSE 0 END) AS ended
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
                  && (int)$cand['ended'] === 0
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
                       AND now() <= ' . slot_close_sql('exam_slot_schedule') . '
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

            // No cross-department fallback. If nothing matches the
            // applicant's own department/college we surface a clear
            // error so SSO creates a slot for that college instead of
            // silently moving the student into a foreign college's
            // room. (Was previously falling back to "any open slot",
            // which is how BSA/BSIT applicants ended up in a CAS
            // proctor's roster.)
            if (!$candidateSlotId) {
                $deptLabel = $department !== '' ? " for {$department}" : '';
                $slotErr   = "Cannot approve - there's no open exam slot{$deptLabel} yet. "
                           . 'Please create a slot first, then come back and approve this request.';
            }
        }

        if ($slotErr !== null) {
            fail($slotErr, '/staff/exam/reschedule');
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
                    SET status = \'approved\', reviewed_by = ?, reviewed_at = NOW()
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
                AuditAction::EXAM_RESCHEDULE_REQUEST_APPROVED,
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

            Session::flash('success', 'Exam reschedule approved - student moved to a new slot.');
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
        $requestId  = post_int('request_id');
        $denyReason = post_str('deny_reason');

        $rr = $db->prepare('SELECT * FROM exam_reschedule_requests WHERE id = ? AND status = \'pending\' LIMIT 1');
        $rr->execute([$requestId]);
        $req = $rr->fetch();

        if (!$req) {
            Session::flash('error', 'Reschedule request not found or already processed.');
        } else {
            $db->prepare(
                'UPDATE exam_reschedule_requests
                    SET status = \'denied\', reviewed_by = ?, reviewed_at = NOW(), deny_reason = ?
                  WHERE id = ?'
            )->execute([$staffId, $denyReason !== '' ? $denyReason : null, $requestId]);
            audit_log(
                AuditAction::EXAM_RESCHEDULE_REQUEST_DENIED,
                "Denied exam reschedule request #{$requestId} for applicant #{$req['applicant_id']}"
                . ($denyReason !== '' ? " - {$denyReason}" : ''),
                'applicant',
                (int)$req['applicant_id']
            );

            $extra = $denyReason !== ''
                   ? "Reason: {$denyReason}"
                   : 'Your original slot stays the same.';
            notify_reschedule_decision((int)$req['applicant_id'], 'exam', 'denied', $extra);

            Session::flash('success', 'Exam reschedule denied - student keeps their current slot.');
        }
        redirect('/staff/exam/reschedule');
    }
}

// ----------------------------------------------------------------
// Filters + pagination — same auto-fit scheme as the audit log:
// AutoPageSize (app.js) measures how many fixed-height rows fit the
// table and reloads with ?per_page=; see auto_per_page() in helpers.php.
// ----------------------------------------------------------------
$search     = get_str('q');
$filterDept = get_str('dept');
$filterDate = get_str('date');
if ($filterDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterDate)) {
    $filterDate = '';
}
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = auto_per_page('exam-reschedule');

$where  = ["rr.status = 'pending'", "COALESCE(a.overall_status, '') <> 'withdrawn'"];
$params = [];

if ($search !== '') {
    // Distinct placeholder per column (emulated prepares + the pooler).
    $cols = ['u.name', 'u.first_name', 'u.last_name', 'u.email',
             'a.course_applied', 'rr.reason', 's.room_label'];
    $likes = [];
    foreach ($cols as $i => $col) {
        $likes[]            = "{$col} ILIKE :q{$i}";
        $params[":q{$i}"]   = '%' . $search . '%';
    }
    $where[] = '(' . implode(' OR ', $likes) . ')';
}
if ($filterDept !== '') {
    $where[]          = 'u.department = :dept';
    $params[':dept']  = $filterDept;
}
if ($filterDate !== '') {
    $where[]         = 'CAST(rr.created_at AS date) = :sub_date';
    $params[':sub_date'] = $filterDate;
}
$whereStr = implode(' AND ', $where);

$fromSql = 'FROM exam_reschedule_requests rr
            JOIN applicants a ON a.id = rr.applicant_id
            JOIN users u      ON u.id = a.user_id
       LEFT JOIN exam_slot_schedule s ON s.id = rr.slot_id';

// ----------------------------------------------------------------
// Load pending reschedule requests with applicant + current-slot info
// ----------------------------------------------------------------
$result = paginate(
    $db,
    "SELECT COUNT(*) {$fromSql} WHERE {$whereStr}",
    "SELECT rr.*, a.course_applied,
            u.name AS student_name, u.email AS student_email,
            u.first_name, u.middle_name, u.last_name, u.suffix,
            u.department AS student_department,
            s.exam_date  AS cur_date,
            s.slot_time  AS cur_time,
            s.end_time   AS cur_end_time,
            s.room_label AS cur_room,
            s.department AS slot_department
       {$fromSql}
      WHERE {$whereStr}
      ORDER BY rr.created_at ASC",
    $params, $page, $perPage
);
$reschedRequests = $result['data'];
$page            = $result['current_page'];

// Department filter options — only colleges that have a pending request.
$deptOptions = $db->query(
    "SELECT DISTINCT u.department
       FROM exam_reschedule_requests rr
       JOIN applicants a ON a.id = rr.applicant_id
       JOIN users u      ON u.id = a.user_id
      WHERE rr.status = 'pending'
        AND COALESCE(a.overall_status, '') <> 'withdrawn'
        AND COALESCE(u.department, '') <> ''
      ORDER BY u.department ASC"
)->fetchAll(PDO::FETCH_COLUMN);

// ----------------------------------------------------------------
// Load open future slots (used as reschedule targets in the dropdown)
// ----------------------------------------------------------------
$today = now_date();
$stmt = $db->prepare(
    'SELECT id, exam_date, slot_time, end_time, room_label, department, capacity, filled
       FROM exam_slot_schedule
      WHERE exam_date >= ?
        AND filled < capacity
      ORDER BY exam_date ASC, slot_time ASC'
);
$stmt->execute([$today]);
$openSlots = $stmt->fetchAll();

// URL helper — keeps every current filter + per_page while overriding just
// the given keys (page links, the Clear link). Same shape as the audit log's
// auditUrl().
$reschedUrl = function (array $merge = []) use ($search, $filterDept, $filterDate, $perPage): string {
    $base = [
        'q'        => $search,
        'dept'     => $filterDept,
        'date'     => $filterDate,
        'per_page' => $perPage,
        'page'     => 1,
    ];
    return url('/staff/exam/reschedule') . '?' . http_build_query(array_filter(
        array_merge($base, $merge),
        fn($v) => $v !== '' && $v !== null
    ));
};
$hasFilters = ($search !== '' || $filterDept !== '' || $filterDate !== '');

ob_start();
?>


<?php
// Same structure as the audit log (and lakbay-pasig's AdminDataTable): one
// .auto-table-wrap that AutoPageSize measures, the card with the toolbar and
// the fixed-height table fused together, and the pagination bar under the card.
// Each row is ONE line: the slot picker, the deny reason and the buttons sit in
// their own columns, tied to that row's two <form>s with the form="" attribute.
?>
<div class="auto-table-wrap" data-auto-page-size="exam-reschedule" data-current-per-page="<?= (int)$perPage ?>">

<div class="auto-table-card">

    <!-- Toolbar: search + filters, one row, inside the card -->
    <form method="GET" action="<?= url('/staff/exam/reschedule') ?>" class="auto-table-toolbar">
        <input type="hidden" name="per_page" value="<?= (int)$perPage ?>">

        <div class="auto-table-search">
            <?= icon('ic_fluent_search_24_filled', 14) ?>
            <input type="text" id="resched_q" name="q" aria-label="Search" class="form-input" placeholder="Search student, course, reason…" value="<?= e($search) ?>">
        </div>

        <?php if ($deptOptions): ?>
        <select name="dept" class="form-input wpx-200" onchange="this.form.submit()" aria-label="Department">
            <option value="">All departments</option>
            <?php foreach ($deptOptions as $d): ?>
                <option value="<?= e($d) ?>" <?= $filterDept === $d ? 'selected' : '' ?>><?= e($d) ?></option>
            <?php endforeach; ?>
        </select>
        <?php endif; ?>

        <input type="date" name="date" class="form-input wpx-160" value="<?= e($filterDate) ?>"
               onchange="this.form.submit()" aria-label="Submitted on">

        <?php if ($hasFilters): ?>
            <a href="<?= e(url('/staff/exam/reschedule') . '?' . http_build_query(['per_page' => $perPage])) ?>" class="btn btn-ghost btn-sm">Clear</a>
        <?php endif; ?>
    </form>

    <!-- Table body — sized so the rows AutoPageSize picks fit exactly; it
         only scrolls as a safety net (e.g. a very narrow window) -->
    <div class="auto-table-body">
        <table class="auto-table">
            <thead>
                <tr>
                    <th class="wpx-170">Student</th>
                    <th class="wpx-190">Course / Dept</th>
                    <th style="width:210px">Current Slot</th>
                    <th style="min-width:140px">Reason</th>
                    <th class="wpx-140">Submitted</th>
                    <?php if ($canReschedule): ?>
                    <th class="wpx-190">Move to</th>
                    <th class="wpx-160">Deny reason</th>
                    <th class="wpx-180">Action</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <?php if (!empty($reschedRequests)): ?>
            <tbody>
            <?php foreach ($reschedRequests as $rr):
                // Single-line current-slot label; the full text is also a tooltip.
                $curSlotLabel = '-';
                if ($rr['cur_date']) {
                    $parts = [format_date($rr['cur_date'])];
                    if ($rr['cur_time']) {
                        $t = format_time($rr['cur_time']);
                        if ($rr['cur_end_time']) $t .= '–' . format_time($rr['cur_end_time']);
                        $parts[] = $t;
                    }
                    if ($rr['cur_room']) $parts[] = $rr['cur_room'];
                    $curSlotLabel = implode(' · ', $parts);
                }
                $courseLabel = ($rr['course_applied'] ?: '-')
                             . ($rr['student_department'] ? ' · ' . $rr['student_department'] : '');
                $approveFormId = 'resched-approve-' . (int)$rr['id'];
                $denyFormId    = 'resched-deny-'    . (int)$rr['id'];
            ?>
                <tr>
                    <td class="text-sm">
                        <span class="auto-table-clip fw-medium"
                              title="<?= e($rr['student_email']) ?>"><?= e(format_full_name($rr)) ?></span>
                    </td>
                    <td class="text-sm" title="<?= e($courseLabel) ?>">
                        <span class="auto-table-clip"><?= e($rr['course_applied'] ?: '-') ?><?php if ($rr['student_department']): ?><span class="text-tertiary"> · <?= e($rr['student_department']) ?></span><?php endif; ?></span>
                    </td>
                    <td class="text-sm" title="<?= e($curSlotLabel) ?>">
                        <span class="auto-table-clip"><?= e($curSlotLabel) ?></span>
                    </td>
                    <td class="text-sm text-secondary" title="<?= e($rr['reason']) ?>">
                        <span class="auto-table-clip"><?= e($rr['reason']) ?></span>
                    </td>
                    <td class="text-sm">
                        <span class="auto-table-clip"><?= date('M j, g:i A', strtotime($rr['created_at'])) ?></span>
                    </td>
                    <?php if ($canReschedule): ?>
                    <td>
                        <select id="resched_target_<?= (int)$rr['id'] ?>" name="target_slot_id" form="<?= $approveFormId ?>" class="form-control" aria-label="Target slot">
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
                    </td>
                    <td>
                        <input type="text" id="resched_deny_<?= (int)$rr['id'] ?>" name="deny_reason" form="<?= $denyFormId ?>" aria-label="Deny reason"
                               placeholder="Optional, shown to student"
                               title="Reason shown to the student if you deny"
                               maxlength="500"
                               class="form-control text-xs h-control mh-control py-0 px-2"
                              >
                    </td>
                    <td>
                        <div class="flex gap-2 align-items-center">
                            <form id="<?= $approveFormId ?>" method="POST" class="m-0">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="approve_reschedule">
                                <input type="hidden" name="request_id" value="<?= (int)$rr['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-primary h-control mh-control py-0 px-3 text-xs"
                                       
                                        title="Approve and assign new slot">Approve</button>
                            </form>
                            <form id="<?= $denyFormId ?>" method="POST" class="m-0"
                                  data-confirm="Deny this exam reschedule request? The student keeps their current slot." data-confirm-danger data-confirm-ok="Deny">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="deny_reschedule">
                                <input type="hidden" name="request_id" value="<?= (int)$rr['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-ghost h-control mh-control py-0 px-3 text-xs text-error"
                                       
                                        title="Deny request">Deny</button>
                            </form>
                        </div>
                    </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <?php endif; ?>
        </table>

        <?php if (empty($reschedRequests)): ?>
        <div class="auto-table-empty">
            <?= icon('ic_fluent_calendar_sync_24_regular', 32) ?>
            <div><?= $hasFilters ? 'No reschedule requests match your filters.' : 'No pending exam reschedule requests.' ?></div>
        </div>
        <?php endif; ?>
    </div>
</div><!-- /.auto-table-card -->

<!-- Pagination bar — under the card, inside the wrap. Its 40px is reserved in
     AutoPageSize's math whether or not it renders. -->
<?php if ($result['last_page'] > 1): ?>
    <?= auto_table_footer($result, fn(int $p): string => $reschedUrl(['page' => $p])) ?>
<?php endif; ?>

</div><!-- /.auto-table-wrap -->

<?php
$content   = ob_get_clean();
$pageTitle = 'Exam Reschedule Requests';
$pageBreadcrumb = [['label' => 'Exam Slots', 'to' => '/staff/exam/slots']];
$pageActions = ($canReschedule ? ui_button('Cancel a slot (bulk move)', ['href' => url('/staff/exam/cancel-slot'), 'variant' => 'ghost', 'icon' => 'ic_fluent_arrow_right_24_regular', 'icon_pos' => 'after']) : '');
$activeNav = 'exam-reschedule';
$pageWide  = true; // table-heavy page - match staff_slots / staff_review / staff_manage
include VIEWS_PATH . '/layouts/app.php';
