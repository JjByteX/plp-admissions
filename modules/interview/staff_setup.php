<?php
// ============================================================
// modules/interview/staff_setup.php
// Interview Setup — College selector → flat Session list
// (desks have been merged into sessions; one row per session
// carries date, time, capacity, assigned interviewer, location.)
//
// URL patterns:
//   GET  /staff/interviews/setup               → college list (admin)
//                                                or jump straight to own college (staff)
//   GET  /staff/interviews/setup?college=X      → sessions for college X
//   GET  /staff/interviews/setup?desk=ID        → legacy redirect to ?college=
//   POST /staff/interviews/setup               → session CRUD + batch create
// ============================================================

require_once CORE_PATH . '/bootstrap.php';
Auth::requireRole(ROLE_SSO, ROLE_ADMIN);

$db      = db();
$staffId = Auth::id();
$role    = Auth::role();
$isAdmin = $role === ROLE_ADMIN;
$isSSO   = $role === ROLE_SSO;
// SSO and Admin both manage interview sessions across colleges; the
// requireRole gate above only lets these two roles through, so they
// should behave identically here.
$canManage = $isAdmin || $isSSO;
$errors  = [];

$staffDept   = user_department($staffId);
$departments = departments_list();
$today       = now_date();

// ── Routing ──────────────────────────────────────────────────
$college    = get_str('college');
$legacyDesk = get_int('desk');

// Legacy desk URL → resolve a department from any session linked to that user
// so old bookmarks still land on the right college.
if ($legacyDesk > 0 && !$college) {
    try {
        $stmt = $db->prepare(
            'SELECT department FROM interview_slots
              WHERE assigned_to = ? OR created_by = ?
              ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$legacyDesk, $legacyDesk]);
        $college = (string)($stmt->fetchColumn() ?: '');
    } catch (\Throwable $e) {}
}

// Users who can't manage across colleges (none reach this page today
// thanks to requireRole, but keep the guard for forward-compat) are
// dropped straight into their own college's session list.
if (!$canManage && !$college) {
    $college = $staffDept;
}

// ── POST handlers ────────────────────────────────────────────
if (($action = require_post_action()) !== null) {

    // ─── Add Session ──────────────────────────────────────
    if ($action === 'add_session' || $action === 'create_slot') {
        $dept       = $canManage ? post_str('department', $college) : $staffDept;
        $assignedTo = post_int('assigned_to');
        $label      = post_str('location_label');
        $notes      = post_str('location_notes');
        $date       = post_str('slot_date');
        $time       = post_str('slot_time') ?: null;
        $endTime    = post_str('slot_end_time') ?: null;
        $capacity   = max(1, post_int('capacity', 30));

        if (!$dept)       $errors[] = 'College / department is required.';
        if (!$assignedTo) $errors[] = 'Please assign an interviewer to this session.';
        if (!$label)      $errors[] = 'Location label is required (e.g. "Room 201" or "Desk A").';
        if (!$date)       $errors[] = 'Date is required.';
        elseif ($date < $today) $errors[] = 'Date cannot be in the past.';
        if (!$time || !$endTime)   $errors[] = 'Start and end time are required.';
        elseif ($endTime <= $time) $errors[] = 'End time must be after start time.';

        if (!$errors) {
            try {
                $insSlot = $db->prepare(
                    'INSERT INTO interview_slots
                        (slot_date, slot_time, end_time, capacity, department,
                         created_by, assigned_to, location_label, location_notes)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                     RETURNING id'
                );
                $insSlot->execute([
                    $date, $time, $endTime, $capacity, $dept,
                    $staffId, $assignedTo, $label, ($notes ?: null),
                ]);
                $newSlotId = (int)$insSlot->fetchColumn();
                audit_log(
                    AuditAction::INTERVIEW_SLOT_CREATED,
                    "Created session #{$newSlotId} on {$date} for {$dept} (assigned to user #{$assignedTo})",
                    'interview_slot',
                    $newSlotId
                );

                $assigned = 0;
                try {
                    $assigned = bulk_assign_pending_applicants($dept, $assignedTo);
                } catch (\Throwable $e) {
                    error_log('bulk_assign after add_session: ' . $e->getMessage());
                }

                Session::flash(
                    'success',
                    'Session added for ' . format_date($date) . '.'
                    . ($assigned > 0 ? " {$assigned} applicant(s) auto-assigned." : '')
                );
                redirect('/staff/interviews/setup?college=' . urlencode($dept));
            } catch (\PDOException $e) {
                error_log('add_session: ' . $e->getMessage());
                $errors[] = 'Unable to create session.';
            }
        }
    }

    // ─── Edit Session ─────────────────────────────────────
    if ($action === 'edit_session' || $action === 'edit_slot') {
        $slotId     = post_int('slot_id');
        $assignedTo = post_int('assigned_to');
        $label      = post_str('location_label');
        $notes      = post_str('location_notes');
        $date       = post_str('slot_date');
        $time       = post_str('slot_time') ?: null;
        $endTime    = post_str('slot_end_time') ?: null;
        $capacity   = max(1, post_int('capacity', 30));

        if (!$slotId)     $errors[] = 'Invalid session.';
        if (!$assignedTo) $errors[] = 'Please assign an interviewer.';
        if (!$label)      $errors[] = 'Location label is required.';
        if (!$date)       $errors[] = 'Date is required.';
        if (!$time || !$endTime)   $errors[] = 'Start and end time are required.';
        elseif ($endTime <= $time) $errors[] = 'End time must be after start time.';

        if (!$errors) {
            $bookedStmt = $db->prepare('SELECT COUNT(*) FROM interview_queue WHERE slot_id=?');
            $bookedStmt->execute([$slotId]);
            $booked = (int)$bookedStmt->fetchColumn();
            if ($capacity < $booked) {
                $errors[] = "Cannot shrink capacity below {$booked} (currently booked).";
            } else {
                $deptStmt = $db->prepare('SELECT department FROM interview_slots WHERE id=?');
                $deptStmt->execute([$slotId]);
                $deptVal = (string)($deptStmt->fetchColumn() ?: $college);

                $db->prepare(
                    'UPDATE interview_slots
                        SET slot_date=?, slot_time=?, end_time=?, capacity=?,
                            assigned_to=?, location_label=?, location_notes=?
                      WHERE id=?'
                )->execute([
                    $date, $time, $endTime, $capacity,
                    $assignedTo, $label, ($notes ?: null), $slotId,
                ]);
                audit_log(AuditAction::INTERVIEW_SLOT_EDITED, "Edited session #{$slotId}", 'interview_slot', $slotId);
                ok('Session updated.', '/staff/interviews/setup?college=' . urlencode($deptVal));
            }
        }
    }

    // ─── Bulk Delete Sessions (from select-mode bar) ───────
    if ($action === 'delete_sessions_bulk') {
        $rawIds  = post_str('ids');
        $deptCtx = post_str('college', $college);
        $ids     = parse_id_list($rawIds);

        if (empty($ids)) {
            Session::flash('error', 'No sessions selected.');
        } else {
            // Skip sessions that have at least one booked applicant — same
            // rule the single-row delete enforces. Only the empty ones are
            // safe to remove.
            $bulkCfg = [
                'table' => 'interview_slots', 'child' => 'interview_queue', 'fk' => 'slot_id',
                'noun' => 'session', 'audit' => 'interview_slots_bulk_deleted',
                'skipNote' => 'had bookings', 'allNote' => 'have bookings',
            ];
            flash_bulk_delete_result(bulk_delete_empty($db, $bulkCfg, $ids), $bulkCfg);
            redirect('/staff/interviews/setup?college=' . urlencode($deptCtx));
        }
    }

    // ─── Delete Session ───────────────────────────────────
    if ($action === 'delete_session' || $action === 'delete_slot') {
        $slotId = post_int('slot_id');
        if ($slotId) {
            $deptStmt = $db->prepare('SELECT department FROM interview_slots WHERE id=?');
            $deptStmt->execute([$slotId]);
            $deptVal = (string)($deptStmt->fetchColumn() ?: $college);

            $bookedStmt = $db->prepare('SELECT COUNT(*) FROM interview_queue WHERE slot_id=?');
            $bookedStmt->execute([$slotId]);
            $booked = (int)$bookedStmt->fetchColumn();

            if ($booked > 0) {
                Session::flash('error', "Cannot remove a session with {$booked} booked applicant(s).");
            } else {
                $db->prepare('DELETE FROM interview_slots WHERE id=?')->execute([$slotId]);
                audit_log(AuditAction::INTERVIEW_SLOT_DELETED, "Deleted session #{$slotId}");
                Session::flash('success', 'Session removed.');
            }
            redirect('/staff/interviews/setup?college=' . urlencode($deptVal));
        }
    }

    // ─── Batch Create Sessions ────────────────────────────
    if ($action === 'batch_create') {
        $dept       = $canManage ? post_str('department', $college) : $staffDept;
        $assignedTo = post_int('assigned_to');
        $label      = post_str('location_label');
        $notes      = post_str('location_notes');
        $startDate  = post_str('start_date');
        $endDate    = post_str('end_date');
        $startTime  = post_str('start_time', '09:00');
        $endTime    = post_str('end_time', '16:00');
        $capacity   = post_int('capacity', 30);
        $days       = $_POST['days'] ?? [1, 2, 3, 4, 5];

        if (!$dept)       $errors[] = 'College / department is required.';
        if (!$assignedTo) $errors[] = 'Please assign an interviewer.';
        if (!$label)      $errors[] = 'Location label is required.';
        if (!$startDate)  $errors[] = 'Start date is required.';
        if (!$endDate)    $errors[] = 'End date is required.';
        if ($startDate && $endDate && $startDate > $endDate) {
            $errors[] = 'End date must be after start date.';
        }

        if (!$errors) {
            $created = batch_create_interview_sessions([
                'start_date'     => $startDate,
                'end_date'       => $endDate,
                'start_time'     => $startTime,
                'end_time'       => $endTime,
                'capacity'       => $capacity,
                'days'           => array_map('intval', $days),
                'assigned_to'    => $assignedTo,
                'location_label' => $label,
                'location_notes' => $notes,
            ], $dept, $staffId);

            Session::flash('success', $created > 0
                ? "{$created} session(s) created."
                : 'No new sessions created (may already exist for those dates).');
            redirect('/staff/interviews/setup?college=' . urlencode($dept));
        }
    }

    // If errors, fall through to re-render
}

// ── VIEW: Sessions list for a college ────────────────────────
if ($college !== '') {
    // Setup is for setting up. We always show upcoming sessions —
    // past sessions are history, not setup material.
    $slotsStmt = $db->prepare(
        'SELECT s.*, u.name AS interviewer_name,
                COUNT(q.id) AS booked
           FROM interview_slots s
           LEFT JOIN users           u ON u.id = s.assigned_to
           LEFT JOIN interview_queue q ON q.slot_id = s.id
          WHERE s.department = ?
            AND s.slot_date >= ?
          GROUP BY s.id, u.name
          ORDER BY s.slot_date ASC, s.slot_time ASC NULLS FIRST
          LIMIT 400'
    );
    $slotsStmt->execute([$college, $today]);

    $slots  = $slotsStmt->fetchAll();
    $byDate = [];
    foreach ($slots as $s) {
        $byDate[$s['slot_date']][] = $s;
    }

    // Staff dropdown (interviewers in this college, plus admins).
    // Deans only appear for their own college, same as staff.
    $staffStmt = $db->prepare(
        "SELECT id, name FROM users
          WHERE role IN ('staff','dean','admin') AND is_active = 1
            AND (department = ? OR ? = '' OR role = 'admin')
          ORDER BY name"
    );
    $staffStmt->execute([$college, $college]);
    $staffList = $staffStmt->fetchAll();

    ob_start();
    include __DIR__ . '/_setup_sessions.php';
    $content   = ob_get_clean();
    $pageTitle   = e($college) . ' - Interview Sessions';
    $pageHeading = $college;
    // SSO has no Interview Sessions landing (it redirects to setup), so start at Setup.
    $pageBreadcrumb = $isSSO
        ? [['label' => 'Interview Setup', 'to' => '/staff/interviews/setup']]
        : ($canManage
            ? [
                ['label' => 'Interview Sessions', 'to' => '/staff/interviews'],
                ['label' => 'Interview Setup',    'to' => '/staff/interviews/setup'],
            ]
            : [['label' => 'Interview Sessions', 'to' => '/staff/interviews']]);
    $activeNav = 'interviews';
    $pageWide  = true;
    $pageScripts = ['js/pages/interview-setup-sessions.js'];
    include VIEWS_PATH . '/layouts/app.php';
    exit;
}

// ── VIEW: College selector (admin only) ──────────────────────
$collegeCounts = [];
foreach ($departments as $dept) {
    $cStmt = $db->prepare(
        'SELECT
             SUM(CASE WHEN slot_date >= ? THEN 1 ELSE 0 END) AS upcoming,
             COUNT(*)             AS total
           FROM interview_slots
          WHERE department = ?'
    );
    $cStmt->execute([$today, $dept]);
    $row = $cStmt->fetch() ?: ['upcoming' => 0, 'total' => 0];
    $collegeCounts[$dept] = [
        'sessions' => (int)($row['total']    ?? 0),
        'upcoming' => (int)($row['upcoming'] ?? 0),
    ];
}

ob_start();
include __DIR__ . '/_setup_colleges.php';
$content   = ob_get_clean();
$pageTitle = 'Interview Setup';
$pageBreadcrumb = $isSSO ? [] : [['label' => 'Interview Sessions', 'to' => '/staff/interviews']];
$activeNav = 'interviews';
$pageWide  = true;
include VIEWS_PATH . '/layouts/app.php';
