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
Auth::requireRole(ROLE_STAFF, ROLE_ADMIN);

$db      = db();
$staffId = Auth::id();
$isAdmin = Auth::role() === ROLE_ADMIN;
$errors  = [];

// ── Graceful schema upgrade: ensure new columns exist on interview_slots ──
foreach ([
    ['assigned_to',    'INT(10) UNSIGNED DEFAULT NULL AFTER created_by'],
    ['location_label', 'VARCHAR(120) NOT NULL DEFAULT "" AFTER assigned_to'],
    ['location_notes', 'TEXT DEFAULT NULL AFTER location_label'],
] as $col) {
    try { $db->query("SELECT {$col[0]} FROM interview_slots LIMIT 0"); }
    catch (\Throwable $e) {
        try { $db->exec("ALTER TABLE interview_slots ADD COLUMN {$col[0]} {$col[1]}"); }
        catch (\Throwable $e2) {}
    }
}

// One-time backfill from legacy interview_desks if it still exists.
try {
    $db->query("SELECT id FROM interview_desks LIMIT 0");
    $db->exec(
        'UPDATE interview_slots s
            LEFT JOIN interview_desks d
              ON d.id = s.desk_id OR (s.desk_id IS NULL AND d.department = s.department)
            SET s.assigned_to    = COALESCE(s.assigned_to, d.assigned_to, d.created_by, s.created_by),
                s.location_label = IF(s.location_label = "" AND d.desk_label IS NOT NULL,
                                      d.desk_label, s.location_label),
                s.location_notes = COALESCE(s.location_notes, d.desk_notes)'
    );
} catch (\Throwable $e) {}

$staffDept   = user_department($staffId);
$departments = departments_list();
$today       = date('Y-m-d');

// ── Routing ──────────────────────────────────────────────────
$college    = trim($_GET['college'] ?? '');
$legacyDesk = (int)($_GET['desk'] ?? 0);

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

// Staff without admin role: skip college selector, go to their own college
if (!$isAdmin && !$college) {
    $college = $staffDept;
}

// ── POST handlers ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    // ─── Add Session ──────────────────────────────────────
    if ($action === 'add_session' || $action === 'create_slot') {
        $dept       = $isAdmin ? trim($_POST['department'] ?? $college) : $staffDept;
        $assignedTo = (int)($_POST['assigned_to'] ?? 0);
        $label      = trim($_POST['location_label'] ?? '');
        $notes      = trim($_POST['location_notes'] ?? '');
        $date       = trim($_POST['slot_date']      ?? '');
        $time       = trim($_POST['slot_time']      ?? '') ?: null;
        $endTime    = trim($_POST['slot_end_time']  ?? '') ?: null;
        $capacity   = max(1, (int)($_POST['capacity'] ?? 30));

        if (!$dept)       $errors[] = 'College / department is required.';
        if (!$assignedTo) $errors[] = 'Please assign an interviewer to this session.';
        if (!$label)      $errors[] = 'Location label is required (e.g. "Room 201" or "Desk A").';
        if (!$date)       $errors[] = 'Date is required.';
        elseif ($date < $today) $errors[] = 'Date cannot be in the past.';
        if (!$time || !$endTime)   $errors[] = 'Start and end time are required.';
        elseif ($endTime <= $time) $errors[] = 'End time must be after start time.';

        if (!$errors) {
            try {
                $db->prepare(
                    'INSERT INTO interview_slots
                        (slot_date, slot_time, end_time, capacity, department,
                         created_by, assigned_to, location_label, location_notes)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
                )->execute([
                    $date, $time, $endTime, $capacity, $dept,
                    $staffId, $assignedTo, $label, ($notes ?: null),
                ]);
                $newSlotId = (int)$db->lastInsertId();
                audit_log(
                    'interview_slot_created',
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
        $slotId     = (int)($_POST['slot_id']      ?? 0);
        $assignedTo = (int)($_POST['assigned_to']  ?? 0);
        $label      = trim($_POST['location_label'] ?? '');
        $notes      = trim($_POST['location_notes'] ?? '');
        $date       = trim($_POST['slot_date']      ?? '');
        $time       = trim($_POST['slot_time']      ?? '') ?: null;
        $endTime    = trim($_POST['slot_end_time']  ?? '') ?: null;
        $capacity   = max(1, (int)($_POST['capacity'] ?? 30));

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
                audit_log('interview_slot_edited', "Edited session #{$slotId}", 'interview_slot', $slotId);
                Session::flash('success', 'Session updated.');
                redirect('/staff/interviews/setup?college=' . urlencode($deptVal));
            }
        }
    }

    // ─── Delete Session ───────────────────────────────────
    if ($action === 'delete_session' || $action === 'delete_slot') {
        $slotId = (int)($_POST['slot_id'] ?? 0);
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
                audit_log('interview_slot_deleted', "Deleted session #{$slotId}");
                Session::flash('success', 'Session removed.');
            }
            redirect('/staff/interviews/setup?college=' . urlencode($deptVal));
        }
    }

    // ─── Batch Create Sessions ────────────────────────────
    if ($action === 'batch_create') {
        $dept       = $isAdmin ? trim($_POST['department'] ?? $college) : $staffDept;
        $assignedTo = (int)($_POST['assigned_to']     ?? 0);
        $label      = trim($_POST['location_label']   ?? '');
        $notes      = trim($_POST['location_notes']   ?? '');
        $startDate  = trim($_POST['start_date'] ?? '');
        $endDate    = trim($_POST['end_date']   ?? '');
        $startTime  = trim($_POST['start_time'] ?? '09:00');
        $endTime    = trim($_POST['end_time']   ?? '16:00');
        $capacity   = (int)($_POST['capacity']  ?? 30);
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
    $showPast = isset($_GET['past']);

    if ($showPast) {
        $slotsStmt = $db->prepare(
            'SELECT s.*, u.name AS interviewer_name,
                    COUNT(q.id) AS booked
               FROM interview_slots s
               LEFT JOIN users           u ON u.id = s.assigned_to
               LEFT JOIN interview_queue q ON q.slot_id = s.id
              WHERE s.department = ?
                AND s.slot_date < ?
              GROUP BY s.id
              ORDER BY s.slot_date DESC, s.slot_time DESC
              LIMIT 200'
        );
        $slotsStmt->execute([$college, $today]);
    } else {
        $slotsStmt = $db->prepare(
            'SELECT s.*, u.name AS interviewer_name,
                    COUNT(q.id) AS booked
               FROM interview_slots s
               LEFT JOIN users           u ON u.id = s.assigned_to
               LEFT JOIN interview_queue q ON q.slot_id = s.id
              WHERE s.department = ?
                AND s.slot_date >= ?
              GROUP BY s.id
              ORDER BY s.slot_date ASC, s.slot_time ASC
              LIMIT 400'
        );
        $slotsStmt->execute([$college, $today]);
    }

    $slots  = $slotsStmt->fetchAll();
    $byDate = [];
    foreach ($slots as $s) {
        $byDate[$s['slot_date']][] = $s;
    }

    // Staff dropdown (interviewers in this college, plus admins)
    $staffStmt = $db->prepare(
        "SELECT id, name FROM users
          WHERE role IN ('staff','admin') AND is_active = 1
            AND (department = ? OR ? = '' OR role = 'admin')
          ORDER BY name"
    );
    $staffStmt->execute([$college, $college]);
    $staffList = $staffStmt->fetchAll();

    ob_start();
    include __DIR__ . '/_setup_sessions.php';
    $content   = ob_get_clean();
    $pageTitle = e($college) . ' — Interview Sessions';
    $activeNav = 'interviews';
    include VIEWS_PATH . '/layouts/app.php';
    exit;
}

// ── VIEW: College selector (admin only) ──────────────────────
$collegeCounts = [];
foreach ($departments as $dept) {
    $cStmt = $db->prepare(
        'SELECT
             SUM(slot_date >= ?) AS upcoming,
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
$activeNav = 'interviews';
include VIEWS_PATH . '/layouts/app.php';
