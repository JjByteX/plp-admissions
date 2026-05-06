<?php
// ============================================================
// modules/interview/staff_setup.php
// Interview Setup — College selector → Desk cards → Per-desk schedule
//
// URL patterns:
//   GET  /staff/interviews/setup               → college list (admin) or redirect to own college (staff)
//   GET  /staff/interviews/setup?college=X      → desk cards for college X
//   GET  /staff/interviews/setup?desk=ID        → schedule for desk #ID
//   POST /staff/interviews/setup               → desk CRUD + session CRUD
// ============================================================

require_once CORE_PATH . '/bootstrap.php';
Auth::requireRole(ROLE_STAFF, ROLE_ADMIN);

$db      = db();
$staffId = Auth::id();
$isAdmin = Auth::role() === ROLE_ADMIN;
$errors  = [];
$success = [];

// Graceful schema upgrade
try { $db->query("SELECT assigned_to FROM interview_desks LIMIT 0"); }
catch (\Throwable $e) {
    $db->exec("ALTER TABLE interview_desks ADD COLUMN assigned_to INT(10) UNSIGNED DEFAULT NULL AFTER department");
    $db->exec("UPDATE interview_desks SET assigned_to = created_by WHERE assigned_to IS NULL");
}
try { $db->query("SELECT desk_id FROM interview_slots LIMIT 0"); }
catch (\Throwable $e) {
    $db->exec("ALTER TABLE interview_slots ADD COLUMN desk_id INT(10) UNSIGNED DEFAULT NULL AFTER created_by");
}

$staffDept   = user_department($staffId);
$departments = departments_list();
$today       = date('Y-m-d');

// ── Routing ──────────────────────────────────────────────────
$college  = trim($_GET['college'] ?? '');
$deskId   = (int)($_GET['desk'] ?? 0);

// Staff without admin role: skip college selector, go to their own college
if (!$isAdmin && !$college && !$deskId) {
    $college = $staffDept;
}

// ── POST handlers ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    // ─── Add Desk ─────────────────────────────────────────
    if ($action === 'add_desk') {
        $dept       = $isAdmin ? trim($_POST['department'] ?? '') : $staffDept;
        $label      = trim($_POST['desk_label'] ?? '');
        $notes      = trim($_POST['desk_notes'] ?? '');
        $assignedTo = (int)($_POST['assigned_to'] ?? 0);

        if (!$dept)  $errors[] = 'College / Department is required.';
        if (!$label) $errors[] = 'Desk label is required.';
        if (!$assignedTo) $errors[] = 'Please assign an interviewer to this desk.';

        if (!$errors) {
            $db->prepare(
                'INSERT INTO interview_desks (department, desk_label, desk_notes, assigned_to, created_by)
                 VALUES (?, ?, ?, ?, ?)'
            )->execute([$dept, $label, $notes ?: null, $assignedTo, $staffId]);
            audit_log('interview_desk_added', "Added desk for {$dept}: {$label}");
            Session::flash('success', "Desk \"{$label}\" added.");
            redirect('/staff/interviews/setup?college=' . urlencode($dept));
        }
    }

    // ─── Edit Desk ────────────────────────────────────────
    if ($action === 'edit_desk') {
        $editDeskId = (int)($_POST['desk_id'] ?? 0);
        $label      = trim($_POST['desk_label'] ?? '');
        $notes      = trim($_POST['desk_notes'] ?? '');
        $assignedTo = (int)($_POST['assigned_to'] ?? 0);

        if (!$editDeskId) $errors[] = 'Invalid desk.';
        if (!$label)      $errors[] = 'Desk label is required.';

        if (!$errors) {
            $params = [$label, $notes ?: null, $assignedTo ?: null, $editDeskId];
            $db->prepare(
                'UPDATE interview_desks SET desk_label=?, desk_notes=?, assigned_to=? WHERE id=?'
            )->execute($params);
            audit_log('interview_desk_updated', "Updated desk #{$editDeskId}: {$label}");
            // Determine college to redirect back
            $deskDept = $db->prepare('SELECT department FROM interview_desks WHERE id=?');
            $deskDept->execute([$editDeskId]);
            $redirectCollege = $deskDept->fetchColumn() ?: '';
            Session::flash('success', 'Desk updated.');
            redirect('/staff/interviews/setup?college=' . urlencode($redirectCollege));
        }
    }

    // ─── Delete Desk ──────────────────────────────────────
    if ($action === 'delete_desk') {
        $delDeskId = (int)($_POST['desk_id'] ?? 0);
        if ($delDeskId) {
            $deptStmt = $db->prepare('SELECT department FROM interview_desks WHERE id=?');
            $deptStmt->execute([$delDeskId]);
            $redirectCollege = $deptStmt->fetchColumn() ?: '';
            $db->prepare('DELETE FROM interview_desks WHERE id=?')->execute([$delDeskId]);
            audit_log('interview_desk_deleted', "Deleted desk #{$delDeskId}");
            Session::flash('success', 'Desk removed.');
            redirect('/staff/interviews/setup?college=' . urlencode($redirectCollege));
        }
    }

    // ─── Create Session (for a desk) ──────────────────────
    if ($action === 'create_slot') {
        $forDeskId = (int)($_POST['desk_id'] ?? 0);
        $date      = trim($_POST['slot_date']     ?? '');
        $time      = trim($_POST['slot_time']     ?? '') ?: null;
        $endTime   = trim($_POST['slot_end_time'] ?? '') ?: null;
        $capacity  = max(1, (int)($_POST['capacity'] ?? 30));

        // Load desk info
        $deskStmt = $db->prepare('SELECT * FROM interview_desks WHERE id=?');
        $deskStmt->execute([$forDeskId]);
        $deskRow  = $deskStmt->fetch();

        if (!$deskRow) {
            $errors[] = 'Invalid desk.';
        } else {
            $slotDept  = $deskRow['department'];
            $slotOwner = (int)($deskRow['assigned_to'] ?: $deskRow['created_by']);
        }

        if (!$date) $errors[] = 'Date is required.';
        elseif ($date < date('Y-m-d')) $errors[] = 'Date cannot be in the past.';
        if (!$time || !$endTime) $errors[] = 'Start and end time required.';
        elseif ($endTime && $time && $endTime <= $time) $errors[] = 'End time must be after start time.';

        if (!$errors) {
            try {
                $db->prepare(
                    'INSERT INTO interview_slots
                        (slot_date, slot_time, end_time, capacity, department, created_by, desk_id)
                     VALUES (?, ?, ?, ?, ?, ?, ?)'
                )->execute([$date, $time, $endTime, $capacity, $slotDept, $slotOwner, $forDeskId]);
                $newSlotId = (int)$db->lastInsertId();
                audit_log('interview_slot_created',
                    "Created slot #{$newSlotId} on {$date} for desk #{$forDeskId}",
                    'interview_slot', $newSlotId);

                $assigned = 0;
                try {
                    $assigned = bulk_assign_pending_applicants($slotDept, $slotOwner);
                } catch (Throwable $e) {
                    error_log('bulk_assign after create_slot: ' . $e->getMessage());
                }

                Session::flash('success', 'Session added for ' . format_date($date) . '.'
                    . ($assigned > 0 ? " {$assigned} applicant(s) auto-assigned." : ''));
            } catch (PDOException) {
                $errors[] = 'Unable to create session.';
            }
            if (!$errors) redirect('/staff/interviews/setup?desk=' . $forDeskId);
        }
    }

    // ─── Edit Session ─────────────────────────────────────
    if ($action === 'edit_slot') {
        $slotId   = (int)($_POST['slot_id']      ?? 0);
        $date     = trim($_POST['slot_date']     ?? '');
        $time     = trim($_POST['slot_time']     ?? '') ?: null;
        $endTime  = trim($_POST['slot_end_time'] ?? '') ?: null;
        $capacity = max(1, (int)($_POST['capacity'] ?? 30));
        $forDeskId = (int)($_POST['desk_id'] ?? 0);

        if (!$slotId) $errors[] = 'Invalid session.';
        if (!$date)   $errors[] = 'Date is required.';
        if (!$time || !$endTime) $errors[] = 'Start and end time required.';
        elseif ($endTime <= $time) $errors[] = 'End time must be after start time.';

        if (!$errors) {
            $stmt = $db->prepare('SELECT COUNT(*) FROM interview_queue WHERE slot_id=?');
            $stmt->execute([$slotId]);
            $booked = (int)$stmt->fetchColumn();
            if ($capacity < $booked) {
                $errors[] = "Cannot shrink capacity below {$booked} (currently booked).";
            } else {
                $db->prepare(
                    'UPDATE interview_slots SET slot_date=?, slot_time=?, end_time=?, capacity=? WHERE id=?'
                )->execute([$date, $time, $endTime, $capacity, $slotId]);
                audit_log('interview_slot_edited', "Edited slot #{$slotId}", 'interview_slot', $slotId);
                Session::flash('success', 'Session updated.');
            }
        }
        if (!$errors) redirect('/staff/interviews/setup?desk=' . $forDeskId);
    }

    // ─── Batch Create Sessions ────────────────────────────
    if ($action === 'batch_create') {
        $forDeskId = (int)($_POST['desk_id'] ?? 0);
        $startDate = trim($_POST['start_date'] ?? '');
        $endDate   = trim($_POST['end_date']   ?? '');
        $startTime = trim($_POST['start_time'] ?? '09:00');
        $endTime   = trim($_POST['end_time']   ?? '16:00');
        $capacity  = (int)($_POST['capacity']  ?? 30);
        $days      = $_POST['days'] ?? [1,2,3,4,5];

        $deskStmt = $db->prepare('SELECT * FROM interview_desks WHERE id=?');
        $deskStmt->execute([$forDeskId]);
        $deskRow = $deskStmt->fetch();

        if (!$deskRow) $errors[] = 'Invalid desk.';
        if (!$startDate) $errors[] = 'Start date is required.';
        if (!$endDate) $errors[] = 'End date is required.';
        if ($startDate && $endDate && $startDate > $endDate) $errors[] = 'End date must be after start date.';

        if (!$errors) {
            $slotOwner = (int)($deskRow['assigned_to'] ?: $deskRow['created_by']);
            $created = batch_create_interview_sessions([
                'start_date' => $startDate,
                'end_date'   => $endDate,
                'start_time' => $startTime,
                'end_time'   => $endTime,
                'capacity'   => $capacity,
                'days'       => array_map('intval', $days),
                'desk_id'    => $forDeskId,
            ], $deskRow['department'], $slotOwner);

            Session::flash('success', $created > 0
                ? "{$created} session(s) created."
                : 'No new sessions created (may already exist for those dates).');
            redirect('/staff/interviews/setup?desk=' . $forDeskId);
        }
    }

    // If errors, fall through to re-render
}

// ── Determine which view to show ─────────────────────────────

// VIEW 3: Desk schedule (specific desk ID)
if ($deskId > 0) {
    $deskStmt = $db->prepare(
        'SELECT d.*, u.name AS interviewer_name
         FROM   interview_desks d
         LEFT JOIN users u ON u.id = d.assigned_to
         WHERE  d.id = ?'
    );
    $deskStmt->execute([$deskId]);
    $desk = $deskStmt->fetch();

    if (!$desk) {
        Session::flash('error', 'Desk not found.');
        redirect('/staff/interviews/setup');
    }

    $showPast = isset($_GET['past']);

    if ($showPast) {
        $slotsStmt = $db->prepare(
            'SELECT s.*,
                    COUNT(q.id)                   AS booked,
                    SUM(q.status = "completed")   AS completed,
                    SUM(q.status = "no_show")     AS no_show
             FROM   interview_slots s
             LEFT JOIN interview_queue q ON q.slot_id = s.id
             WHERE  (s.desk_id = ? OR (s.desk_id IS NULL AND s.created_by = ?))
               AND  s.slot_date < ?
             GROUP BY s.id
             ORDER BY s.slot_date DESC, s.slot_time DESC
             LIMIT 60'
        );
        $slotsStmt->execute([$deskId, (int)($desk['assigned_to'] ?: $desk['created_by']), $today]);
    } else {
        $slotsStmt = $db->prepare(
            'SELECT s.*,
                    COUNT(q.id)                    AS booked,
                    SUM(q.status = "checked_in")   AS waiting,
                    SUM(q.status = "in_progress")  AS in_progress,
                    SUM(q.status = "completed")    AS completed,
                    SUM(q.status = "no_show")      AS no_show
             FROM   interview_slots s
             LEFT JOIN interview_queue q ON q.slot_id = s.id
             WHERE  (s.desk_id = ? OR (s.desk_id IS NULL AND s.created_by = ?))
               AND  s.slot_date >= ?
             GROUP BY s.id
             ORDER BY s.slot_date ASC, s.slot_time ASC
             LIMIT 200'
        );
        $slotsStmt->execute([$deskId, (int)($desk['assigned_to'] ?: $desk['created_by']), $today]);
    }

    $slots  = $slotsStmt->fetchAll();
    $byDate = [];
    foreach ($slots as $slot) {
        $byDate[$slot['slot_date']][] = $slot;
    }

    // Render desk schedule view
    ob_start();
    include __DIR__ . '/_setup_desk_schedule.php';
    $content   = ob_get_clean();
    $pageTitle = 'Desk Schedule — ' . e($desk['desk_label']);
    $activeNav = 'interviews';
    include VIEWS_PATH . '/layouts/app.php';
    exit;
}

// VIEW 2: Desk cards for a college
if ($college !== '') {
    // Load desks for this college
    $desksStmt = $db->prepare(
        'SELECT d.*, u.name AS interviewer_name,
                (SELECT COUNT(*) FROM interview_slots s WHERE s.desk_id = d.id AND s.slot_date >= ?) AS upcoming
         FROM   interview_desks d
         LEFT JOIN users u ON u.id = d.assigned_to
         WHERE  d.department = ? AND d.is_active = 1
         ORDER BY d.desk_label ASC'
    );
    $desksStmt->execute([$today, $college]);
    $desks = $desksStmt->fetchAll();

    // Load staff users in this department for the assignment dropdown
    $staffStmt = $db->prepare(
        "SELECT id, name FROM users
         WHERE role IN ('staff','admin') AND is_active=1
           AND (department = ? OR ? = '')
         ORDER BY name"
    );
    $staffStmt->execute([$college, $college]);
    $staffList = $staffStmt->fetchAll();

    ob_start();
    include __DIR__ . '/_setup_desks.php';
    $content   = ob_get_clean();
    $pageTitle = e($college) . ' — Interview Desks';
    $activeNav = 'interviews';
    include VIEWS_PATH . '/layouts/app.php';
    exit;
}

// VIEW 1: College selector (admin only — staff was redirected above)
$collegeCounts = [];
foreach ($departments as $dept) {
    $cStmt = $db->prepare(
        'SELECT COUNT(*) FROM interview_desks WHERE department=? AND is_active=1'
    );
    $cStmt->execute([$dept]);
    $deskCount = (int)$cStmt->fetchColumn();

    $uStmt = $db->prepare(
        'SELECT COUNT(*) FROM interview_slots s
         JOIN interview_desks d ON d.id = s.desk_id
         WHERE d.department=? AND s.slot_date >= ?'
    );
    $uStmt->execute([$dept, $today]);
    $upCount = (int)$uStmt->fetchColumn();

    // Also count legacy slots without desk_id
    $uStmt2 = $db->prepare(
        'SELECT COUNT(*) FROM interview_slots
         WHERE department=? AND slot_date >= ? AND desk_id IS NULL'
    );
    $uStmt2->execute([$dept, $today]);
    $upCount += (int)$uStmt2->fetchColumn();

    $collegeCounts[$dept] = ['desks' => $deskCount, 'upcoming' => $upCount];
}

ob_start();
include __DIR__ . '/_setup_colleges.php';
$content   = ob_get_clean();
$pageTitle = 'Interview Setup';
$activeNav = 'interviews';
include VIEWS_PATH . '/layouts/app.php';
