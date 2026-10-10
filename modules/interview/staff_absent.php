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

        $rr = $db->prepare("SELECT * FROM reschedule_requests WHERE id = ? AND status = 'pending' LIMIT 1");
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
                // Lock the slot row first, then count bookings in a second
                // query (Postgres: lock base row, count separately).
                $q = $db->prepare(
                    "SELECT s.id, s.capacity, s.status, s.slot_date, s.slot_time, s.end_time, s.department, s.location_label
                       FROM interview_slots s
                      WHERE s.id = ?
                      LIMIT 1 FOR UPDATE"
                );
                $q->execute([$targetSlotId]);
                $cand = $q->fetch();
                if ($cand) {
                    $bk = $db->prepare(
                        "SELECT COUNT(*) FROM interview_queue
                          WHERE slot_id = ?
                            AND interview_status IN ('pending','completed')"
                    );
                    $bk->execute([(int)$cand['id']]);
                    $cand['booked'] = (int)$bk->fetchColumn();
                }
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
                $sql = "SELECT s.id, s.capacity, s.slot_date, s.slot_time, s.end_time, s.department, s.location_label
                          FROM interview_slots s
                         WHERE s.status = 'open'
                           AND s.slot_date >= ?
                           AND NOT (s.slot_date = ? AND s.end_time IS NOT NULL AND s.end_time <= ?)";
                if ($department !== '') {
                    $sql .= " AND (s.department = ? OR s.department = '')";
                    $params[] = $department;
                }
                $sql .= ' ORDER BY s.slot_date ASC, s.slot_time ASC NULLS FIRST, s.id ASC
                          FOR UPDATE';
                $st = $db->prepare($sql);
                $st->execute($params);
                $bkAuto = $db->prepare(
                    "SELECT COUNT(*) FROM interview_queue
                      WHERE slot_id = ?
                        AND interview_status IN ('pending','completed')"
                );
                foreach ($st->fetchAll() as $row) {
                    $bkAuto->execute([(int)$row['id']]);
                    $row['booked'] = (int)$bkAuto->fetchColumn();
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
                "INSERT INTO interview_queue
                    (slot_id, applicant_id, status, interview_status, queue_number, checked_in_at)
                 VALUES (?, ?, 'checked_in', 'pending', ?, NOW())"
            )->execute([$newSlotId, $applicantId, $nextNum]);

            $db->prepare(
                "UPDATE applicants SET overall_status = 'interview' WHERE id = ?"
            )->execute([$applicantId]);

            $db->prepare(
                "UPDATE reschedule_requests
                    SET status = 'approved', reviewed_by = ?, reviewed_at = NOW()
                  WHERE id = ?"
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

        $rr = $db->prepare("SELECT * FROM reschedule_requests WHERE id = ? AND status = 'pending' LIMIT 1");
        $rr->execute([$requestId]);
        $req = $rr->fetch();

        if (!$req) {
            Session::flash('error', 'Reschedule request not found or already processed.');
        } else {
            $db->prepare(
                "UPDATE reschedule_requests
                    SET status = 'denied', reviewed_by = ?, reviewed_at = NOW(), deny_reason = ?
                  WHERE id = ?"
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
// Auto-detect no-shows on page load: any pending queue row whose slot
// has already ended is flipped to status='no_show',
// interview_status='absent', attendance_status='absent', so unmarked
// students show up here automatically without an interviewer having
// to manually mark them.  Idempotent — already-absent rows are
// skipped.
// ----------------------------------------------------------------
if (function_exists('auto_detect_interview_no_shows')) {
    try {
        auto_detect_interview_no_shows(null, $staffId);
    } catch (\Throwable $e) {
        error_log('auto_detect_interview_no_shows on staff_absent load failed: ' . $e->getMessage());
    }
}

// ----------------------------------------------------------------
// Filters + pagination — same auto-fit scheme as the audit log:
// AutoPageSize (app.js) measures how many fixed-height rows fit the
// table and reloads with ?per_page=; see auto_per_page() in helpers.php.
// Each tab has its own table and its own remembered page size.
// ----------------------------------------------------------------
$search     = trim($_GET['q']    ?? '');
$filterDept = trim($_GET['dept'] ?? '');
$filterDate = trim($_GET['date'] ?? '');
if ($filterDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterDate)) {
    $filterDate = '';
}
$page       = max(1, (int)($_GET['page'] ?? 1));
$perPage    = auto_per_page($activeTab === 'requests' ? 'interview-reschedule' : 'interview-absent');
$hasFilters = ($search !== '' || $filterDept !== '' || $filterDate !== '');

// ILIKE across several columns; a distinct placeholder per column
// (emulated prepares + the pooler).
$searchClause = function (array $cols, string $term, array &$params): string {
    $likes = [];
    foreach ($cols as $i => $col) {
        $likes[]           = "{$col} ILIKE :q{$i}";
        $params[":q{$i}"]  = '%' . $term . '%';
    }
    return '(' . implode(' OR ', $likes) . ')';
};

$absentFrom = 'FROM interview_queue q
               JOIN applicants a ON a.id = q.applicant_id
               JOIN users u      ON u.id = a.user_id
          LEFT JOIN interview_slots s ON s.id = q.slot_id';
$absentBase = "q.interview_status = 'absent'";

$reschedFrom = 'FROM reschedule_requests rr
                JOIN interview_queue q ON q.id = rr.queue_id
                JOIN applicants a      ON a.id = rr.applicant_id
                JOIN users u           ON u.id = a.user_id
           LEFT JOIN interview_slots s ON s.id = q.slot_id';
$reschedBase = "rr.status = 'pending' AND COALESCE(a.overall_status, '') <> 'withdrawn'";

// Unfiltered totals for the two tab badges.
$absentTotal  = (int)$db->query("SELECT COUNT(*) {$absentFrom} WHERE {$absentBase}")->fetchColumn();
$reschedTotal = (int)$db->query("SELECT COUNT(*) {$reschedFrom} WHERE {$reschedBase}")->fetchColumn();

$absent          = [];
$reschedRequests = [];
$deptOptions     = [];
$result          = ['data' => [], 'total' => 0, 'current_page' => 1, 'last_page' => 1, 'per_page' => $perPage];

if ($activeTab === 'absent') {
    // ── Absent applicants + their previous slot ─────────────────
    $where  = [$absentBase];
    $params = [];
    if ($search !== '') {
        $where[] = $searchClause(['u.name', 'u.first_name', 'u.last_name', 'u.email', 'a.course_applied'], $search, $params);
    }
    if ($filterDept !== '') {
        $where[]              = 'u.department = :dept';
        $params[':dept']      = $filterDept;
    }
    if ($filterDate !== '') {
        $where[]                  = 's.slot_date = :flt_date';
        $params[':flt_date']      = $filterDate;
    }
    $whereStr = implode(' AND ', $where);

    $result = paginate(
        $db,
        "SELECT COUNT(*) {$absentFrom} WHERE {$whereStr}",
        "SELECT q.id            AS queue_id,
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
           {$absentFrom}
          WHERE {$whereStr}
          ORDER BY q.evaluated_at DESC NULLS LAST, q.id DESC",
        $params, $page, $perPage
    );
    $absent = $result['data'];

    $deptOptions = $db->query(
        "SELECT DISTINCT u.department {$absentFrom}
          WHERE {$absentBase} AND COALESCE(u.department, '') <> ''
          ORDER BY u.department ASC"
    )->fetchAll(PDO::FETCH_COLUMN);
} else {
    // ── Pending reschedule requests ─────────────────────────────
    $where  = [$reschedBase];
    $params = [];
    if ($search !== '') {
        $where[] = $searchClause(['u.name', 'u.first_name', 'u.last_name', 'u.email', 'a.course_applied', 'rr.reason'], $search, $params);
    }
    if ($filterDept !== '') {
        $where[]              = 'u.department = :dept';
        $params[':dept']      = $filterDept;
    }
    if ($filterDate !== '') {
        $where[]                  = 'CAST(rr.created_at AS date) = :flt_date';
        $params[':flt_date']      = $filterDate;
    }
    $whereStr = implode(' AND ', $where);

    $result = paginate(
        $db,
        "SELECT COUNT(*) {$reschedFrom} WHERE {$whereStr}",
        "SELECT rr.*, a.course_applied,
                u.name AS student_name, u.email AS student_email,
                u.first_name, u.middle_name, u.last_name, u.suffix,
                u.department AS student_department,
                s.slot_date AS cur_date,
                s.slot_time AS cur_time,
                s.end_time  AS cur_end_time,
                s.department AS slot_department
           {$reschedFrom}
          WHERE {$whereStr}
          ORDER BY rr.created_at ASC",
        $params, $page, $perPage
    );
    $reschedRequests = $result['data'];

    $deptOptions = $db->query(
        "SELECT DISTINCT u.department {$reschedFrom}
          WHERE {$reschedBase} AND COALESCE(u.department, '') <> ''
          ORDER BY u.department ASC"
    )->fetchAll(PDO::FETCH_COLUMN);
}
$page = $result['current_page'];

// ----------------------------------------------------------------
// Load open slots (used as reschedule targets)
// ----------------------------------------------------------------
$today   = date('Y-m-d');
$nowTime = date('H:i:s');
$stmt = $db->prepare(
    "SELECT s.id, s.slot_date, s.slot_time, s.end_time, s.department, s.capacity,
            (SELECT COUNT(*) FROM interview_queue q
              WHERE q.slot_id = s.id
                AND q.interview_status IN ('pending','completed')) AS booked
       FROM interview_slots s
      WHERE s.status = 'open'
        AND s.slot_date >= ?
        AND NOT (s.slot_date = ? AND s.end_time IS NOT NULL AND s.end_time <= ?)
      ORDER BY s.slot_date ASC, s.slot_time ASC NULLS FIRST"
);
$stmt->execute([$today, $today, $nowTime]);
$openSlots = array_filter(
    $stmt->fetchAll(),
    fn($s) => (int)$s['booked'] < (int)$s['capacity']
);

// ----------------------------------------------------------------
// Also load reschedule history per absent applicant (most recent 3)
// to surface "this is their 2nd miss" info for context. Only for the
// rows on the current page.
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

// URL helper — keeps the tab + every current filter + per_page while overriding
// just the given keys (page links, the Clear link). Same shape as the audit
// log's auditUrl().
$absUrl = function (array $merge = []) use ($activeTab, $search, $filterDept, $filterDate, $perPage): string {
    $base = [
        'tab'      => $activeTab,
        'q'        => $search,
        'dept'     => $filterDept,
        'date'     => $filterDate,
        'per_page' => $perPage,
        'page'     => 1,
    ];
    return url('/staff/interviews/absent') . '?' . http_build_query(array_filter(
        array_merge($base, $merge),
        fn($v) => $v !== '' && $v !== null
    ));
};

ob_start();
?>

<?php foreach ($errors as $err): ?>
    <div class="alert alert-error" style="margin-bottom:var(--space-4);flex-shrink:0"><?= e($err) ?></div>
<?php endforeach; ?>
<?php foreach ($success as $s): ?>
    <div class="alert alert-success" style="margin-bottom:var(--space-4);flex-shrink:0"><?= e($s) ?></div>
<?php endforeach; ?>

<div style="margin-bottom:var(--space-5);display:flex;justify-content:space-between;align-items:center;gap:var(--space-3);flex-wrap:wrap;flex-shrink:0">
    <a href="<?= url('/staff/interviews') ?>" class="btn btn-ghost btn-sm">← Back</a>
    <?php if ($canReschedule): ?>
        <a href="<?= url('/staff/interviews/cancel-slot') ?>" class="btn btn-ghost btn-sm">
            Cancel a slot (bulk move) →
        </a>
    <?php else: ?>
        <span style="font-size:var(--text-xs);color:var(--text-tertiary)">
            <?= $activeTab === 'requests'
                ? 'Only SSO and Admin can approve or deny reschedule requests.'
                : 'Only SSO and Admin can reschedule students.' ?>
        </span>
    <?php endif; ?>
</div>

<!-- ============================================================
     TAB NAVIGATION
============================================================ -->
<div style="display:flex;gap:var(--space-1);margin-bottom:var(--space-5);border-bottom:1.5px solid var(--border);flex-shrink:0">
    <a href="<?= url('/staff/interviews/absent?tab=absent') ?>"
       style="padding:var(--space-2) var(--space-4);font-size:var(--text-sm);font-weight:var(--weight-medium);
              text-decoration:none;border-bottom:2px solid <?= $activeTab === 'absent' ? 'var(--accent)' : 'transparent' ?>;
              color:<?= $activeTab === 'absent' ? 'var(--accent)' : 'var(--text-secondary)' ?>;
              margin-bottom:-1.5px">
        Absent Students
        <?php if ($absentTotal > 0): ?>
            <span style="background:var(--bg-subtle);padding:1px 7px;border-radius:999px;font-size:var(--text-xs);
                          margin-left:var(--space-1)"><?= $absentTotal ?></span>
        <?php endif; ?>
    </a>
    <?php if (!$isDean): ?>
    <a href="<?= url('/staff/interviews/absent?tab=requests') ?>"
       style="padding:var(--space-2) var(--space-4);font-size:var(--text-sm);font-weight:var(--weight-medium);
              text-decoration:none;border-bottom:2px solid <?= $activeTab === 'requests' ? 'var(--accent)' : 'transparent' ?>;
              color:<?= $activeTab === 'requests' ? 'var(--accent)' : 'var(--text-secondary)' ?>;
              margin-bottom:-1.5px">
        Reschedule Requests
        <?php if ($reschedTotal > 0): ?>
            <span style="background:var(--warning-bg);color:var(--warning);padding:1px 7px;border-radius:999px;
                          font-size:var(--text-xs);margin-left:var(--space-1)"><?= $reschedTotal ?></span>
        <?php endif; ?>
    </a>
    <?php endif; ?>
</div>

<?php if ($activeTab === 'absent'): ?>
<!-- ============================================================
     TAB 1: ABSENT STUDENTS

     Same structure as the audit log (and lakbay-pasig's AdminDataTable): one
     .auto-table-wrap that AutoPageSize measures, the card with the toolbar and
     the fixed-height table fused together, and the pagination bar under the
     card. The bulk-reschedule POST form is a standalone <form>; the row
     checkboxes and the target picker / button in the toolbar are tied to it
     with the form="" attribute, so the toolbar's GET form isn't nested in it.
============================================================ -->
<?php if ($canReschedule): ?>
<form method="POST" id="absent-resched-form" style="display:none">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="reschedule">
</form>
<?php endif; ?>

<div class="auto-table-wrap" data-auto-page-size="interview-absent" data-current-per-page="<?= (int)$perPage ?>">

<div class="auto-table-card">

    <!-- Toolbar: search + filters (+ reschedule action), inside the card -->
    <form method="GET" action="<?= url('/staff/interviews/absent') ?>" class="auto-table-toolbar">
        <input type="hidden" name="tab"      value="absent">
        <input type="hidden" name="per_page" value="<?= (int)$perPage ?>">

        <div class="auto-table-search">
            <?= icon('ic_fluent_search_24_filled', 14) ?>
            <input type="text" name="q" class="form-input" placeholder="Search name, email, course…" value="<?= e($search) ?>">
        </div>

        <?php if ($deptOptions): ?>
        <select name="dept" class="form-input" style="width:200px" onchange="this.form.submit()" aria-label="Department">
            <option value="">All departments</option>
            <?php foreach ($deptOptions as $d): ?>
                <option value="<?= e($d) ?>" <?= $filterDept === $d ? 'selected' : '' ?>><?= e($d) ?></option>
            <?php endforeach; ?>
        </select>
        <?php endif; ?>

        <input type="date" name="date" class="form-input" style="width:160px" value="<?= e($filterDate) ?>"
               onchange="this.form.submit()" aria-label="Missed on" title="Missed on">

        <button type="submit" class="btn btn-secondary btn-sm">Filter</button>
        <?php if ($hasFilters): ?>
            <a href="<?= e(url('/staff/interviews/absent') . '?' . http_build_query(['tab' => 'absent', 'per_page' => $perPage])) ?>" class="btn btn-ghost btn-sm">Clear</a>
        <?php endif; ?>

        <?php if ($canReschedule): ?>
        <span class="toolbar-spacer"></span>
        <select name="target_slot_id" form="absent-resched-form" class="form-input" style="width:280px"
                aria-label="Reschedule target"
                title="Auto-assign picks the earliest slot that matches each applicant's department. Reschedules are recorded in reschedule_logs for audit.">
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
        <button type="submit" form="absent-resched-form" class="btn btn-primary btn-sm"
                title="Reschedule the checked applicants on this page">Reschedule selected</button>
        <?php endif; ?>
    </form>

    <!-- Table body — sized so the rows AutoPageSize picks fit exactly; it
         only scrolls as a safety net (e.g. a very narrow window) -->
    <div class="auto-table-body">
        <table class="auto-table">
            <thead>
                <tr>
                    <?php if ($canReschedule): ?>
                    <th class="col-check">
                        <input type="checkbox" id="select-all" title="Select all on this page" aria-label="Select all on this page">
                    </th>
                    <?php endif; ?>
                    <th style="min-width:220px">Student</th>
                    <th style="width:280px">Course / Dept</th>
                    <th style="width:200px">Missed</th>
                    <th style="width:190px">History</th>
                </tr>
            </thead>
            <?php if (!empty($absent)): ?>
            <tbody>
            <?php foreach ($absent as $row):
                $hist = $historyByApplicant[(int)$row['applicant_id']] ?? [];
                $courseDept = ($row['course_applied'] ?: '—') . ' · ' . ($row['student_department'] ?: 'no department');
            ?>
                <tr>
                    <?php if ($canReschedule): ?>
                    <td class="col-check">
                        <input type="checkbox"
                               name="applicant_ids[]"
                               form="absent-resched-form"
                               value="<?= (int)$row['applicant_id'] ?>"
                               class="js-select-row">
                    </td>
                    <?php endif; ?>
                    <td style="font-size:var(--text-sm)" title="<?= e($row['student_email']) ?>">
                        <span class="auto-table-clip" style="font-weight:var(--weight-medium)"><?= e(format_full_name($row)) ?></span>
                    </td>
                    <td style="font-size:var(--text-sm)" title="<?= e($courseDept) ?>">
                        <span class="auto-table-clip"><?= e($row['course_applied'] ?: '—') ?><span style="color:var(--text-tertiary)"> · <?= e($row['student_department'] ?: 'no department') ?></span></span>
                    </td>
                    <td style="font-size:var(--text-sm)">
                        <span class="auto-table-clip">
                        <?php if ($row['missed_date']): ?>
                            <?= format_date($row['missed_date']) ?><?php if ($row['missed_time']): ?><span style="color:var(--text-tertiary)"> · <?= format_time($row['missed_time']) ?></span><?php endif; ?>
                        <?php else: ?>
                            —
                        <?php endif; ?>
                        </span>
                    </td>
                    <td style="font-size:var(--text-xs);color:var(--text-tertiary)">
                        <span class="auto-table-clip">
                        <?php if (empty($hist)): ?>
                            First miss
                        <?php else: ?>
                            <?= count($hist) ?> previous reschedule<?= count($hist) === 1 ? '' : 's' ?>
                        <?php endif; ?>
                        </span>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <?php endif; ?>
        </table>

        <?php if (empty($absent)): ?>
        <div class="auto-table-empty">
            <?= icon('ic_fluent_people_24_regular', 32) ?>
            <div><?= $hasFilters ? 'No absent applicants match your filters.' : 'No absent applicants right now.' ?></div>
        </div>
        <?php endif; ?>
    </div>
</div><!-- /.auto-table-card -->

<!-- Pagination bar — under the card, inside the wrap. Its 40px is reserved in
     AutoPageSize's math whether or not it renders. -->
<?php if ($result['last_page'] > 1): ?>
    <?= auto_table_footer($result, fn(int $p): string => $absUrl(['page' => $p]), 'applicants') ?>
<?php endif; ?>

</div><!-- /.auto-table-wrap -->

<?php if ($canReschedule): ?>
<script>
    (function () {
        var selectAll = document.getElementById('select-all');
        if (selectAll) {
            selectAll.addEventListener('change', function () {
                document.querySelectorAll('.js-select-row').forEach(function (cb) {
                    cb.checked = selectAll.checked;
                });
            });
        }
    })();
</script>
<?php endif; ?>

<?php else: ?>
<!-- ============================================================
     TAB 2: RESCHEDULE REQUESTS

     Same structure as the audit log. Each row is ONE line: the slot picker, the
     deny reason and the buttons sit in their own columns, tied to that row's
     two <form>s with the form="" attribute.
============================================================ -->
<div class="auto-table-wrap" data-auto-page-size="interview-reschedule" data-current-per-page="<?= (int)$perPage ?>">

<div class="auto-table-card">

    <!-- Toolbar: search + filters, one row, inside the card -->
    <form method="GET" action="<?= url('/staff/interviews/absent') ?>" class="auto-table-toolbar">
        <input type="hidden" name="tab"      value="requests">
        <input type="hidden" name="per_page" value="<?= (int)$perPage ?>">

        <div class="auto-table-search">
            <?= icon('ic_fluent_search_24_filled', 14) ?>
            <input type="text" name="q" class="form-input" placeholder="Search student, course, reason…" value="<?= e($search) ?>">
        </div>

        <?php if ($deptOptions): ?>
        <select name="dept" class="form-input" style="width:200px" onchange="this.form.submit()" aria-label="Department">
            <option value="">All departments</option>
            <?php foreach ($deptOptions as $d): ?>
                <option value="<?= e($d) ?>" <?= $filterDept === $d ? 'selected' : '' ?>><?= e($d) ?></option>
            <?php endforeach; ?>
        </select>
        <?php endif; ?>

        <input type="date" name="date" class="form-input" style="width:160px" value="<?= e($filterDate) ?>"
               onchange="this.form.submit()" aria-label="Submitted on" title="Submitted on">

        <button type="submit" class="btn btn-secondary btn-sm">Filter</button>
        <?php if ($hasFilters): ?>
            <a href="<?= e(url('/staff/interviews/absent') . '?' . http_build_query(['tab' => 'requests', 'per_page' => $perPage])) ?>" class="btn btn-ghost btn-sm">Clear</a>
        <?php endif; ?>
    </form>

    <!-- Table body — sized so the rows AutoPageSize picks fit exactly; it
         only scrolls as a safety net (e.g. a very narrow window) -->
    <div class="auto-table-body">
        <table class="auto-table">
            <thead>
                <tr>
                    <th style="width:170px">Student</th>
                    <th style="width:200px">Course / Dept</th>
                    <th style="width:240px">Current Slot</th>
                    <th style="min-width:140px">Reason</th>
                    <th style="width:140px">Submitted</th>
                    <?php if ($canReschedule): ?>
                    <th style="width:190px">Move to</th>
                    <th style="width:160px">Deny reason</th>
                    <th style="width:150px">Action</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <?php if (!empty($reschedRequests)): ?>
            <tbody>
            <?php foreach ($reschedRequests as $rr):
                // Single-line current-slot label; the full text is also a tooltip.
                $curSlotLabel = '—';
                if ($rr['cur_date']) {
                    $curSlotLabel = format_date($rr['cur_date']);
                    if ($rr['cur_time']) {
                        $curSlotLabel .= ' · ' . format_time($rr['cur_time'])
                                      . ($rr['cur_end_time'] ? ' – ' . format_time($rr['cur_end_time']) : '');
                    }
                }
                $courseLabel = ($rr['course_applied'] ?: '—') . ' · ' . ($rr['student_department'] ?: 'no department');
                $approveFormId = 'resched-approve-' . (int)$rr['id'];
                $denyFormId    = 'resched-deny-'    . (int)$rr['id'];
            ?>
                <tr>
                    <td style="font-size:var(--text-sm)" title="<?= e($rr['student_email']) ?>">
                        <span class="auto-table-clip" style="font-weight:var(--weight-medium)"><?= e(format_full_name($rr)) ?></span>
                    </td>
                    <td style="font-size:var(--text-sm)" title="<?= e($courseLabel) ?>">
                        <span class="auto-table-clip"><?= e($rr['course_applied'] ?: '—') ?><span style="color:var(--text-tertiary)"> · <?= e($rr['student_department'] ?: 'no department') ?></span></span>
                    </td>
                    <td style="font-size:var(--text-sm)" title="<?= e($curSlotLabel) ?>">
                        <span class="auto-table-clip"><?= e($curSlotLabel) ?></span>
                    </td>
                    <td style="font-size:var(--text-sm);color:var(--text-secondary)" title="<?= e($rr['reason']) ?>">
                        <span class="auto-table-clip"><?= e($rr['reason']) ?></span>
                    </td>
                    <td style="font-size:var(--text-sm)">
                        <span class="auto-table-clip"><?= date('M j, g:i A', strtotime($rr['created_at'])) ?></span>
                    </td>
                    <?php if ($canReschedule): ?>
                    <td>
                        <select name="target_slot_id" form="<?= $approveFormId ?>" class="form-control"
                                style="font-size:var(--text-xs);height:30px;min-height:30px;padding:0 var(--space-2)">
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
                    </td>
                    <td>
                        <input type="text" name="deny_reason" form="<?= $denyFormId ?>"
                               placeholder="Optional, shown to student"
                               title="Reason shown to the student if you deny"
                               maxlength="500"
                               class="form-control"
                               style="font-size:var(--text-xs);height:30px;min-height:30px;padding:0 var(--space-2)">
                    </td>
                    <td>
                        <div style="display:flex;gap:var(--space-2);align-items:center">
                            <form id="<?= $approveFormId ?>" method="POST" style="margin:0">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="approve_reschedule">
                                <input type="hidden" name="request_id" value="<?= (int)$rr['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-primary"
                                        style="height:30px;min-height:30px;padding:0 var(--space-3);font-size:var(--text-xs)"
                                        title="Approve and assign new slot">Approve</button>
                            </form>
                            <form id="<?= $denyFormId ?>" method="POST" style="margin:0"
                                  onsubmit="return confirm('Deny this reschedule request? The student keeps their current slot.')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="deny_reschedule">
                                <input type="hidden" name="request_id" value="<?= (int)$rr['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-ghost"
                                        style="height:30px;min-height:30px;padding:0 var(--space-3);font-size:var(--text-xs);color:var(--error)"
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
            <div><?= $hasFilters ? 'No reschedule requests match your filters.' : 'No pending reschedule requests.' ?></div>
        </div>
        <?php endif; ?>
    </div>
</div><!-- /.auto-table-card -->

<!-- Pagination bar — under the card, inside the wrap. Its 40px is reserved in
     AutoPageSize's math whether or not it renders. -->
<?php if ($result['last_page'] > 1): ?>
    <?= auto_table_footer($result, fn(int $p): string => $absUrl(['page' => $p])) ?>
<?php endif; ?>

</div><!-- /.auto-table-wrap -->
<?php endif; /* end activeTab */ ?>

<?php
$content   = ob_get_clean();
$pageTitle = $activeTab === 'requests' ? 'Reschedule Requests' : 'Absent Students';
$activeNav = $activeTab === 'requests' ? 'reschedule' : 'interviews';
$pageWide  = true; // table-heavy page — match staff_slots / staff_review / staff_manage
include VIEWS_PATH . '/layouts/app.php';
