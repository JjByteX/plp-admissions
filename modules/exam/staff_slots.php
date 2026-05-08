<?php
// ============================================================
// modules/exam/staff_slots.php
//
// Three-mode page for the exam-room scheduler. The whole page
// lives at /staff/exam/slots; mode is selected by query string:
//
//   (no params)            → College selector card grid (admin only;
//                            staff are redirected to their own college).
//   ?college=COLLEGE_NAME  → Card grid of exam-day slots for that
//                            college, with a "+ Add Slot" dashed
//                            card and the awaiting-applicants list
//                            scoped to the same college below.
//   ?slot=SLOT_ID          → Roster table for one slot (full-page
//                            .card + .table style matching the
//                            Documents / Results / Interview Queue
//                            pages), with unassign + edit actions.
//
// Layout intentionally mirrors modules/interview/staff_setup.php
// so colleges, slots, and rosters all share the same family of
// card-grids and tables.
// ============================================================

require_once CORE_PATH . '/bootstrap.php';
Auth::requireRole(ROLE_STAFF, ROLE_SSO, ROLE_DEAN, ROLE_ADMIN);

$db          = db();
$staffId     = Auth::id();
$role        = Auth::role();
$isAdmin     = $role === ROLE_ADMIN;
$isSSO       = $role === ROLE_SSO;
$isProfessor = $role === ROLE_STAFF;
// Admin and SSO can manage slots across all departments. Dean is
// read-only oversight. Professor proctors — they can only generate
// access codes for slots in their own department (per the role
// redesign).
$canManage = $isAdmin || $isSSO;
$staffDept = $canManage ? '' : (string) user_department($staffId);
// Per-slot "can generate code" check. Admin and SSO can do it for
// any room. Professors can do it only for slots in their own
// department — they're the proctor for those rooms.
$canGenerateCodeFor = function (string $slotDept) use ($isAdmin, $isSSO, $isProfessor, $staffDept): bool {
    if ($isAdmin || $isSSO) return true;
    if ($isProfessor && $slotDept !== '' && $slotDept === $staffDept) return true;
    return false;
};
$errors    = [];
$success   = [];

// Auto-add department column if missing (graceful upgrade)
try {
    $db->query("SELECT department FROM exam_slot_schedule LIMIT 0");
} catch (\Throwable $e) {
    $db->exec("ALTER TABLE exam_slot_schedule ADD COLUMN department VARCHAR(120) NOT NULL DEFAULT '' COMMENT 'College/department this slot is for' AFTER room_label");
}
// Auto-add end_time column if missing (graceful upgrade for older DBs;
// fresh installs get it from schema.sql).
try {
    $db->query("SELECT end_time FROM exam_slot_schedule LIMIT 0");
} catch (\Throwable $e) {
    $db->exec("ALTER TABLE exam_slot_schedule ADD COLUMN end_time TIME NOT NULL DEFAULT '09:30:00' COMMENT 'When this slot closes' AFTER slot_time");
}

$schoolYear   = school_setting('current_school_year', date('Y') . '-' . (date('Y') + 1));
$activeExam   = $db->query('SELECT * FROM exams WHERE is_active=1 LIMIT 1')->fetch();
$activeExamId = $activeExam ? (int)$activeExam['id'] : null;

$examRoomCap  = (int) school_setting('exam_room_capacity', defined('EXAM_ROOM_CAPACITY') ? EXAM_ROOM_CAPACITY : 35);
$examDailyCap = (int) school_setting('exam_daily_cap',     defined('EXAM_DAILY_CAP')     ? EXAM_DAILY_CAP     : 3000);

// As of Chunk 7 the exam itself no longer has a scheduled start —
// scheduling lives on each slot. Default date for the Add/Batch
// modals is just "tomorrow".
$defaultDate = date('Y-m-d', strtotime('+1 day'));

// ----------------------------------------------------------------
// Routing — read URL params before POST handling so we redirect
// back to the right URL after every action.
// ----------------------------------------------------------------
$collegeParam = trim($_GET['college'] ?? '');
$slotIdParam  = (int)($_GET['slot']    ?? 0);

// Admin and SSO see the cross-college selector; Dean and Professor
// land directly on their own college's slot grid (no point showing
// them a selector — they can only manage one college).
// $staffDept is computed above (needed for $canGenerateCodeFor).

// ----------------------------------------------------------------
// POST handlers — same as before, except every redirect now goes
// back to the appropriate ?college= page so the user lands on the
// view they were operating on.
// ----------------------------------------------------------------
function _slots_redirect_back(string $college = '', int $slotId = 0): void {
    if ($slotId > 0) {
        redirect('/staff/exam/slots?slot=' . $slotId);
    }
    if ($college !== '') {
        redirect('/staff/exam/slots?college=' . urlencode($college));
    }
    redirect('/staff/exam/slots');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    // Most write actions redirect after success; pick the redirect target
    // up front based on the form's hidden context fields (with the URL
    // params as fallback).
    $ctxCollege = trim((string)($_POST['ctx_college'] ?? $collegeParam));
    $ctxSlotId  = (int)($_POST['ctx_slot'] ?? $slotIdParam);

    // ── Generate / extend per-room access code ───────────────
    //
    // Each room's proctor can independently issue an 8-character
    // code valid for EXAM_PASSWORD_EXPIRY_SECONDS (5 minutes). Admin
    // and SSO can do this for any room; Professors only for slots
    // in their own department. Handled before the canManage gate so
    // Professors are not redirected away.
    if ($action === 'generate_slot_code' || $action === 'extend_slot_code') {
        $slotId = (int)($_POST['slot_id'] ?? 0);
        $stmt   = $db->prepare(
            'SELECT id, department, room_label, access_password
               FROM exam_slot_schedule WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$slotId]);
        $slotRow = $stmt->fetch();

        $isAjax = is_ajax_request();
        $respond = function (bool $ok, string $msg = '', array $extra = []) use ($isAjax, $ctxCollege, $ctxSlotId) {
            if ($isAjax) {
                while (ob_get_level()) ob_end_clean();
                header('Content-Type: application/json');
                echo json_encode(array_merge(['ok' => $ok, 'error' => $ok ? null : $msg], $extra));
                exit;
            }
            Session::flash($ok ? 'success' : 'error', $msg);
            _slots_redirect_back($ctxCollege, $ctxSlotId);
        };

        if (!$slotRow) {
            $respond(false, 'Slot not found.');
        }
        if (!$canGenerateCodeFor((string) $slotRow['department'])) {
            $respond(false, 'You can only generate codes for rooms in your own college.');
        }

        if ($action === 'extend_slot_code') {
            if (empty($slotRow['access_password'])) {
                $respond(false, 'No code set yet — generate one first.');
            }
            $db->prepare('UPDATE exam_slot_schedule SET password_issued_at = NOW() WHERE id = ?')
               ->execute([$slotId]);
            audit_log('exam_slot_code_extended',
                "Extended access code timer for slot {$slotId} ({$slotRow['room_label']})");
            $respond(true, 'Code timer reset.', [
                'password'   => $slotRow['access_password'],
                'expires_in' => EXAM_PASSWORD_EXPIRY_SECONDS,
            ]);
        } else {
            $newPwd = generate_exam_password();
            $db->prepare(
                'UPDATE exam_slot_schedule
                    SET access_password = ?, password_issued_at = NOW()
                  WHERE id = ?'
            )->execute([$newPwd, $slotId]);
            audit_log('exam_slot_code_generated',
                "Generated access code for slot {$slotId} ({$slotRow['room_label']})");
            $respond(true, 'New code issued.', [
                'password'   => $newPwd,
                'expires_in' => EXAM_PASSWORD_EXPIRY_SECONDS,
            ]);
        }
    }

    // ── Inline edit of a slot's room label ──────────────────
    // (Admin/SSO only — same as the rest of the slot mutators.)
    if ($action === 'update_room_label') {
        if (!$canManage) {
            if (is_ajax_request()) {
                while (ob_get_level()) ob_end_clean();
                header('Content-Type: application/json');
                echo json_encode(['ok' => false, 'error' => 'Read-only access.']);
                exit;
            }
            Session::flash('error', 'Read-only access — only SSO and Admin can rename rooms.');
            _slots_redirect_back($ctxCollege, $ctxSlotId);
        }
        $slotId  = (int)($_POST['slot_id']    ?? 0);
        $newRoom = trim((string)($_POST['room_label'] ?? ''));
        if (!$slotId || $newRoom === '') {
            if (is_ajax_request()) {
                while (ob_get_level()) ob_end_clean();
                header('Content-Type: application/json');
                echo json_encode(['ok' => false, 'error' => 'Room label cannot be empty.']);
                exit;
            }
            $errors[] = 'Room label cannot be empty.';
        } else {
            $db->prepare('UPDATE exam_slot_schedule SET room_label = ? WHERE id = ?')
               ->execute([$newRoom, $slotId]);
            audit_log('exam_slot_room_renamed', "Slot {$slotId} room label → {$newRoom}");
            if (is_ajax_request()) {
                while (ob_get_level()) ob_end_clean();
                header('Content-Type: application/json');
                echo json_encode(['ok' => true, 'room_label' => $newRoom]);
                exit;
            }
            Session::flash('success', 'Room label updated.');
            _slots_redirect_back($ctxCollege, $ctxSlotId);
        }
    }

    // Only Admin and SSO can mutate exam slots from this point on.
    // Dean and Professor reach this page in read-only mode — if any
    // other action posts through, reject it.
    if (!$canManage) {
        Session::flash('error', 'Read-only access — only SSO and Admin can modify exam slots.');
        _slots_redirect_back($ctxCollege, $ctxSlotId);
    }

    // ── Update exam config (room default + daily cap) ────────────
    if ($action === 'update_exam_config') {
        $upsert = 'INSERT INTO school_settings (setting_key, setting_value) VALUES (?,?)
                   ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)';
        $roomCap  = max(1, (int)($_POST['exam_room_capacity'] ?? 35));
        $dailyCap = max(1, (int)($_POST['exam_daily_cap']     ?? 3000));
        $db->prepare($upsert)->execute(['exam_room_capacity', $roomCap]);
        $db->prepare($upsert)->execute(['exam_daily_cap',     $dailyCap]);
        audit_log('exam_config_updated', "Room cap={$roomCap}, Daily cap={$dailyCap}");
        Session::flash('success', 'Exam config saved.');
        _slots_redirect_back($ctxCollege, $ctxSlotId);
    }

    // ── Add a new exam slot ──────────────────────────────────────
    if ($action === 'add_slot') {
        $date     = trim($_POST['exam_date']  ?? '');
        $time     = trim($_POST['slot_time']  ?? '08:00');
        $room     = trim($_POST['room_label'] ?? '');
        $capacity = (int)($_POST['capacity']  ?? 35);
        $slotDept = $canManage
            ? trim($_POST['department'] ?? '')
            : user_department($staffId);

        if (!$date)              $errors[] = 'Exam date is required.';
        if (!$room)              $errors[] = 'Room label is required.';
        if (!$slotDept)          $errors[] = 'College / Department is required.';
        if ($capacity < 1)       $errors[] = 'Capacity must be at least 1.';
        if ($capacity > 500)     $errors[] = 'Capacity above 500 is unrealistic.';

        if (!$errors) {
            $db->prepare(
                'INSERT INTO exam_slot_schedule
                    (exam_id, exam_date, slot_time, room_label, department, capacity, school_year, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([$activeExamId, $date, $time . ':00', $room, $slotDept, $capacity, $schoolYear, $staffId]);
            audit_log('exam_slot_added',
                "Added slot: {$date} {$time} {$room} [{$slotDept}] (cap {$capacity})");
            Session::flash('success', "Slot added: {$room} ({$slotDept}) on " . date('M j, Y', strtotime($date)) . " at {$time}.");
            _slots_redirect_back($slotDept ?: $ctxCollege);
        }
    }

    // ── Edit an existing slot ───────────────────────────────────
    if ($action === 'edit_slot') {
        $slotId   = (int)($_POST['slot_id']   ?? 0);
        $date     = trim($_POST['exam_date']  ?? '');
        $time     = trim($_POST['slot_time']  ?? '08:00');
        $room     = trim($_POST['room_label'] ?? '');
        $capacity = (int)($_POST['capacity']  ?? 35);
        $slotDept = $canManage
            ? trim($_POST['department'] ?? '')
            : user_department($staffId);

        if (!$slotId)          $errors[] = 'Invalid slot.';
        if (!$date)            $errors[] = 'Exam date is required.';
        if (!$room)            $errors[] = 'Room label is required.';
        if (!$slotDept)        $errors[] = 'College / Department is required.';
        if ($capacity < 1)     $errors[] = 'Capacity must be at least 1.';
        if ($capacity > 500)   $errors[] = 'Capacity above 500 is unrealistic.';

        if (!$errors) {
            $stmt = $db->prepare('SELECT COUNT(*) FROM applicant_exam_slots WHERE slot_id=?');
            $stmt->execute([$slotId]);
            $filled = (int)$stmt->fetchColumn();
            if ($capacity < $filled) {
                $errors[] = "Cannot shrink capacity below {$filled} (currently filled).";
            } else {
                $db->prepare(
                    'UPDATE exam_slot_schedule
                        SET exam_date=?, slot_time=?, room_label=?, department=?, capacity=?
                      WHERE id=?'
                )->execute([$date, $time . ':00', $room, $slotDept, $capacity, $slotId]);
                audit_log('exam_slot_edited',
                    "Edited slot {$slotId}: {$date} {$time} {$room} [{$slotDept}] (cap {$capacity})");
                Session::flash('success', 'Slot updated.');
                // If we were on the roster page, stay on it; otherwise return to the college grid.
                if ($ctxSlotId > 0) {
                    _slots_redirect_back('', $ctxSlotId);
                }
                _slots_redirect_back($slotDept);
            }
        }
    }

    // ── Edit slot capacity inline ───────────────────────────────
    if ($action === 'edit_capacity') {
        $slotId = (int)($_POST['slot_id'] ?? 0);
        $cap    = max(1, (int)($_POST['capacity'] ?? 35));
        if ($slotId) {
            $stmt = $db->prepare(
                'SELECT (SELECT COUNT(*) FROM applicant_exam_slots WHERE slot_id=?) AS filled'
            );
            $stmt->execute([$slotId]);
            $filled = (int)$stmt->fetchColumn();
            if ($cap < $filled) {
                $errors[] = "Cannot shrink capacity below {$filled} (currently filled).";
            } else {
                $db->prepare('UPDATE exam_slot_schedule SET capacity=? WHERE id=?')
                   ->execute([$cap, $slotId]);
                audit_log('exam_slot_capacity_changed', "Slot {$slotId} capacity → {$cap}");
                Session::flash('success', 'Capacity updated.');
                _slots_redirect_back($ctxCollege, $ctxSlotId);
            }
        }
    }

    // ── Delete a slot (only if empty) ───────────────────────────
    if ($action === 'delete_slot') {
        $slotId = (int)($_POST['slot_id'] ?? 0);
        if ($slotId) {
            $stmt = $db->prepare('SELECT COUNT(*) FROM applicant_exam_slots WHERE slot_id=?');
            $stmt->execute([$slotId]);
            if ((int)$stmt->fetchColumn() > 0) {
                $errors[] = 'Cannot delete a slot that has applicants assigned. Unassign them first.';
            } else {
                $db->prepare('DELETE FROM exam_slot_schedule WHERE id=?')->execute([$slotId]);
                audit_log('exam_slot_deleted', "Deleted slot {$slotId}");
                Session::flash('success', 'Slot deleted.');
                _slots_redirect_back($ctxCollege);
            }
        }
    }

    // ── Bulk delete slots (from select-mode bar) ────────────────
    if ($action === 'delete_slots_bulk') {
        $rawIds = trim($_POST['ids'] ?? '');
        $ids    = array_values(array_filter(array_map('intval', explode(',', $rawIds)), fn($v) => $v > 0));
        if (empty($ids)) {
            Session::flash('error', 'No slots selected.');
            _slots_redirect_back($ctxCollege);
        }

        $ph     = implode(',', array_fill(0, count($ids), '?'));
        // Skip slots that have at least one applicant assigned — same rule
        // the single-row delete enforces. Only the empty ones are removed.
        $bkStmt = $db->prepare(
            "SELECT s.id, COUNT(aes.id) AS filled
               FROM exam_slot_schedule s
               LEFT JOIN applicant_exam_slots aes ON aes.slot_id = s.id
              WHERE s.id IN ({$ph})
              GROUP BY s.id"
        );
        $bkStmt->execute($ids);
        $deletable = [];
        $skipped   = 0;
        foreach ($bkStmt->fetchAll() as $row) {
            if ((int)$row['filled'] > 0) { $skipped++; continue; }
            $deletable[] = (int)$row['id'];
        }

        $deletedCount = 0;
        if (!empty($deletable)) {
            $delPh = implode(',', array_fill(0, count($deletable), '?'));
            $del   = $db->prepare("DELETE FROM exam_slot_schedule WHERE id IN ({$delPh})");
            $del->execute($deletable);
            $deletedCount = $del->rowCount();
            audit_log('exam_slots_bulk_deleted',
                "Bulk-deleted {$deletedCount} slot(s): " . implode(',', $deletable));
        }

        if ($deletedCount > 0 && $skipped > 0) {
            Session::flash('success', "{$deletedCount} slot(s) removed; {$skipped} skipped (have applicants).");
        } elseif ($deletedCount > 0) {
            Session::flash('success', "{$deletedCount} slot(s) removed.");
        } elseif ($skipped > 0) {
            Session::flash('error', "Nothing removed — all {$skipped} selected slot(s) have applicants.");
        }
        _slots_redirect_back($ctxCollege);
    }

    // ── Assign applicant to slot ────────────────────────────────
    if ($action === 'assign') {
        $applicantId = (int)($_POST['applicant_id'] ?? 0);
        $slotId      = (int)($_POST['slot_id']      ?? 0);
        if (!$applicantId || !$slotId) {
            $errors[] = 'Applicant and slot are both required.';
        } else {
            $stmt = $db->prepare(
                'SELECT s.capacity, s.exam_date, s.department,
                        (SELECT COUNT(*) FROM applicant_exam_slots WHERE slot_id=s.id) AS filled
                   FROM exam_slot_schedule s WHERE s.id=?'
            );
            $stmt->execute([$slotId]);
            $slot = $stmt->fetch();
            if (!$slot) {
                $errors[] = 'Slot not found.';
            } elseif ((int)$slot['filled'] >= (int)$slot['capacity']) {
                $errors[] = 'Slot is full.';
            } else {
                $dailyCap = (int) school_setting('exam_daily_cap', defined('EXAM_DAILY_CAP') ? EXAM_DAILY_CAP : 3000);
                $stmt2 = $db->prepare(
                    'SELECT COUNT(*) FROM applicant_exam_slots aes
                       JOIN exam_slot_schedule s ON s.id = aes.slot_id
                      WHERE s.exam_date = ? AND s.school_year = ?'
                );
                $stmt2->execute([$slot['exam_date'], $schoolYear]);
                $dayCount = (int)$stmt2->fetchColumn();
                if ($dayCount >= $dailyCap) {
                    $errors[] = "Daily exam cap of {$dailyCap} reached for "
                              . date('M j, Y', strtotime($slot['exam_date']))
                              . ". Add more slots on another day or increase the daily cap in Exam Config.";
                } else {
                    $stmt = $db->prepare('SELECT overall_status FROM applicants WHERE id=?');
                    $stmt->execute([$applicantId]);
                    $st = $stmt->fetchColumn();
                    if ($st !== 'exam') {
                        $errors[] = "Applicant is not at exam stage (currently: {$st}).";
                    } else {
                        $db->prepare(
                            'INSERT INTO applicant_exam_slots (applicant_id, slot_id, assigned_at)
                             VALUES (?, ?, NOW())
                             ON DUPLICATE KEY UPDATE slot_id=VALUES(slot_id), assigned_at=NOW()'
                        )->execute([$applicantId, $slotId]);
                        audit_log('exam_slot_assigned',
                            "Assigned applicant {$applicantId} → slot {$slotId}",
                            'applicant', $applicantId);
                        Session::flash('success', 'Applicant assigned.');
                        _slots_redirect_back($slot['department'] ?: $ctxCollege, $ctxSlotId);
                    }
                }
            }
        }
    }

    // ── B3: Batch create exam slots ────────────────────────────
    if ($action === 'batch_create_slots') {
        $bStartDate = trim($_POST['batch_start_date'] ?? '');
        $bEndDate   = trim($_POST['batch_end_date']   ?? '');
        $bTime      = trim($_POST['batch_time']       ?? '08:00');
        $bEndTime   = trim($_POST['batch_end_time']   ?? '');
        $bRooms     = $_POST['batch_rooms'] ?? [];
        if (!is_array($bRooms)) $bRooms = [$bRooms];
        $bRooms     = array_filter(array_map('trim', $bRooms));
        $bCapacity  = max(1, (int)($_POST['batch_capacity'] ?? $examRoomCap ?? 35));
        $bDept      = $canManage ? trim($_POST['batch_department'] ?? '') : user_department($staffId);
        $bDays      = array_map('intval', $_POST['batch_days'] ?? [1,2,3,4,5]);

        if ($bEndTime === '') {
            $bEndTime = date('H:i', strtotime($bTime . ' +90 minutes'));
        }

        if (!$bStartDate || !$bEndDate) $errors[] = 'Start and end dates are required.';
        elseif ($bEndDate < $bStartDate) $errors[] = 'End date must be after start date.';
        elseif (empty($bRooms)) $errors[] = 'At least one room label is required.';
        elseif (!$bDept) $errors[] = 'Department is required.';
        elseif (strtotime($bEndTime) <= strtotime($bTime)) $errors[] = 'Close time must be after the start time.';
        else {
            $totalCreated = 0;
            foreach ($bRooms as $bRoom) {
                $created = batch_create_exam_slots([
                    'start_date' => $bStartDate,
                    'end_date'   => $bEndDate,
                    'slot_time'  => $bTime,
                    'end_time'   => $bEndTime,
                    'room_label' => $bRoom,
                    'capacity'   => $bCapacity,
                    'days'       => $bDays,
                ], $bDept, $staffId);
                $totalCreated += $created;
            }
            Session::flash('success', "Created {$totalCreated} exam slot(s) in batch across " . count($bRooms) . " room(s).");
            _slots_redirect_back($bDept);
        }
    }

    // ── Unassign applicant from their slot ──────────────────────
    if ($action === 'unassign') {
        $applicantId = (int)($_POST['applicant_id'] ?? 0);
        if ($applicantId) {
            $db->prepare('DELETE FROM applicant_exam_slots WHERE applicant_id=?')
               ->execute([$applicantId]);
            audit_log('exam_slot_unassigned', "Unassigned applicant {$applicantId} from their slot",
                'applicant', $applicantId);
            Session::flash('success', 'Applicant unassigned.');
            _slots_redirect_back($ctxCollege, $ctxSlotId);
        }
    }
}

// ----------------------------------------------------------------
// Routing — pick which view to render.
// ----------------------------------------------------------------
$mode = 'colleges';
if ($slotIdParam > 0)              $mode = 'roster';
elseif ($collegeParam !== '')      $mode = 'slots';
elseif (!$canManage && $staffDept) { $collegeParam = $staffDept; $mode = 'slots'; }

// ----------------------------------------------------------------
// Mode-specific data loading.
// ----------------------------------------------------------------
$departments = departments_list();

$slotsForCollege = [];
$rosterBySlot    = [];
$unassignedApplicants = [];
$slotDetail      = null;
$slotRoster      = [];

if ($mode === 'slots') {
    // College must be valid (or scoped user's own)
    if (!$canManage && $collegeParam !== $staffDept) {
        $collegeParam = $staffDept;
    }

    // Slots in this college for the active school year. The
    // pw_secs_left is computed in MySQL so PHP / MySQL timezone
    // differences can't skew the countdown.
    $stmt = $db->prepare(
        "SELECT s.id, s.exam_date, s.slot_time, s.end_time,
                s.room_label, s.department, s.capacity,
                s.access_password, s.password_issued_at,
                GREATEST(
                    0,
                    " . (int) EXAM_PASSWORD_EXPIRY_SECONDS . "
                        - TIMESTAMPDIFF(SECOND, COALESCE(s.password_issued_at, '1970-01-01 00:00:00'), NOW())
                ) AS pw_secs_left,
                (SELECT COUNT(*) FROM applicant_exam_slots WHERE slot_id = s.id) AS filled
           FROM exam_slot_schedule s
          WHERE s.school_year = ?
            AND s.department  = ?
          ORDER BY s.exam_date ASC, s.slot_time ASC, s.room_label ASC"
    );
    $stmt->execute([$schoolYear, $collegeParam]);
    $slotsForCollege = $stmt->fetchAll();

    // Awaiting-slot applicants — only those whose course maps to this college,
    // ordered FCFS by docs_approved_at.
    $stmt = $db->prepare(
        "SELECT a.id, a.course_applied, a.applicant_type, a.documents_approved_at,
                u.name AS student_name,
                u.first_name, u.middle_name, u.last_name, u.suffix
           FROM applicants a
           JOIN users u ON u.id = a.user_id
      LEFT JOIN applicant_exam_slots aes ON aes.applicant_id = a.id
          WHERE a.school_year   = ?
            AND a.overall_status = 'exam'
            AND aes.id IS NULL
       ORDER BY a.course_applied ASC, a.documents_approved_at IS NULL,
                a.documents_approved_at ASC, a.id ASC"
    );
    $stmt->execute([$schoolYear]);
    foreach ($stmt->fetchAll() as $row) {
        $applicantDept = course_to_department($row['course_applied']);
        if ($applicantDept && $applicantDept !== $collegeParam) continue;
        $unassignedApplicants[] = $row;
    }
}

if ($mode === 'roster') {
    $stmt = $db->prepare(
        "SELECT s.*,
                GREATEST(
                    0,
                    " . (int) EXAM_PASSWORD_EXPIRY_SECONDS . "
                        - TIMESTAMPDIFF(SECOND, COALESCE(s.password_issued_at, '1970-01-01 00:00:00'), NOW())
                ) AS pw_secs_left,
                (SELECT COUNT(*) FROM applicant_exam_slots WHERE slot_id = s.id) AS filled
           FROM exam_slot_schedule s
          WHERE s.id = ?
          LIMIT 1"
    );
    $stmt->execute([$slotIdParam]);
    $slotDetail = $stmt->fetch();

    if (!$slotDetail) {
        Session::flash('error', 'Slot not found.');
        redirect('/staff/exam/slots');
    }

    // Staff can only see slots in their own college (unless they can
    // manage across colleges — admin or SSO).
    if (!$canManage && $slotDetail['department'] !== $staffDept) {
        Session::flash('error', 'You can only view slots in your own college.');
        redirect('/staff/exam/slots');
    }

    // ── Auto-issue access code at the slot's start time ──────────
    //
    // The proctor for this room shouldn't have to click "Generate" —
    // the moment the slot's scheduled start time arrives (and the slot
    // hasn't already ended) the system issues a fresh code and starts
    // the 5-minute window. Manual control is preserved through the
    // New / Extend buttons rendered below.
    //
    // Window: slot_opens ≤ now ≤ slot_closes. Earlier than slot_opens
    // and the panel sits at "No active code" so a proctor previewing
    // the page during the morning doesn't burn a code at midnight.
    $slotOpensTs      = strtotime($slotDetail['exam_date'] . ' ' . $slotDetail['slot_time']);
    $slotClosesTs     = !empty($slotDetail['end_time'])
        ? strtotime($slotDetail['exam_date'] . ' ' . $slotDetail['end_time'])
        : ($slotOpensTs + 5400); // legacy fallback: 90-minute window
    if ($slotClosesTs <= $slotOpensTs) $slotClosesTs += 86400; // wrap past midnight
    $nowTs            = time();
    $slotIsLive       = ($nowTs >= $slotOpensTs && $nowTs <= $slotClosesTs);

    $hasValidCode     = !empty($slotDetail['access_password'])
                          && (int)($slotDetail['pw_secs_left'] ?? 0) > 0;
    $canIssueForRoom  = $canGenerateCodeFor((string) $slotDetail['department']);

    if ($slotIsLive && $canIssueForRoom && !$hasValidCode) {
        $autoPwd = generate_exam_password();
        $db->prepare(
            'UPDATE exam_slot_schedule
                SET access_password = ?, password_issued_at = NOW()
              WHERE id = ?'
        )->execute([$autoPwd, (int) $slotDetail['id']]);
        audit_log('exam_slot_code_auto_generated',
            "Auto-issued access code for slot {$slotDetail['id']} ({$slotDetail['room_label']})");

        // Reflect the freshly-issued code in the in-memory row so the
        // panel below renders with a full 5-minute countdown immediately.
        $slotDetail['access_password']    = $autoPwd;
        $slotDetail['password_issued_at'] = date('Y-m-d H:i:s');
        $slotDetail['pw_secs_left']       = EXAM_PASSWORD_EXPIRY_SECONDS;
    }

    $stmt = $db->prepare(
        "SELECT aes.applicant_id, aes.assigned_at,
                u.name AS student_name, a.course_applied, a.applicant_type,
                u.email AS student_email,
                u.first_name, u.middle_name, u.last_name, u.suffix
           FROM applicant_exam_slots aes
           JOIN applicants a ON a.id = aes.applicant_id
           JOIN users u      ON u.id = a.user_id
          WHERE aes.slot_id = ?
          ORDER BY u.last_name ASC, u.first_name ASC, u.name ASC"
    );
    $stmt->execute([$slotIdParam]);
    $slotRoster = $stmt->fetchAll();
}

if ($mode === 'colleges') {
    // Per-college counts for the card grid.
    $collegeCounts = [];
    foreach ($departments as $dept) {
        $stmt = $db->prepare(
            "SELECT
                 COUNT(*)                                     AS total_slots,
                 SUM(s.exam_date >= CURDATE())                AS upcoming_slots,
                 COALESCE(SUM(s.capacity), 0)                 AS total_seats,
                 COALESCE(SUM(
                     (SELECT COUNT(*) FROM applicant_exam_slots
                       WHERE slot_id = s.id)
                 ), 0)                                        AS filled_seats
               FROM exam_slot_schedule s
              WHERE s.department = ?
                AND s.school_year = ?"
        );
        $stmt->execute([$dept, $schoolYear]);
        $row = $stmt->fetch() ?: [];
        $collegeCounts[$dept] = [
            'total_slots'    => (int)($row['total_slots']    ?? 0),
            'upcoming_slots' => (int)($row['upcoming_slots'] ?? 0),
            'total_seats'    => (int)($row['total_seats']    ?? 0),
            'filled_seats'   => (int)($row['filled_seats']   ?? 0),
        ];
    }
}

ob_start();
?>

<style>
/* Generic flash-message colors handled by the layout. */

/* Card grid for colleges + slots (mirrors the interview setup styles). */
.es-college-grid,
.es-slot-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
    gap: var(--space-4);
    margin-top: var(--space-5);
}
.es-college-card,
.es-slot-card {
    display: flex;
    flex-direction: column;
    gap: var(--space-3);
    padding: var(--space-5);
    background: var(--bg-elevated);
    border: 1.5px solid var(--border);
    border-radius: var(--radius-lg);
    text-decoration: none;
    color: var(--text-primary);
    transition: border-color .15s, box-shadow .15s, transform .12s;
    position: relative;
    cursor: pointer;
    min-height: 200px;
}
.es-college-card:hover,
.es-slot-card:hover {
    border-color: var(--accent);
    box-shadow: 0 6px 20px rgba(0,0,0,.07);
    transform: translateY(-2px);
}
/* Red dot top-right on a college card with no upcoming exam slots. */
.es-college-card-dot {
    position:absolute;top:var(--space-3);right:var(--space-3);
    width:8px;height:8px;border-radius:50%;background:var(--error);
}
.es-slot-card.is-today  { border-color: var(--accent); background: var(--accent-muted); }
.es-slot-card.is-past   { opacity: .55; }
.es-slot-card.is-full   { border-color: var(--error); }

/* Active access-code badge on a slot card */
.es-card-code-badge {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 2px 8px;
    border-radius: var(--radius-sm);
    background: var(--success-muted, #e6f4ec);
    color: var(--success, #2d6a4f);
    font-family: monospace;
    font-size: var(--text-xs);
    font-weight: var(--weight-semibold);
    letter-spacing: .08em;
}
.es-card-code-badge.is-warn { background: #fef3c7; color: #92400e; }

/* Roster code panel (proctor-facing) */
.es-code-panel {
    display: flex; align-items: center; gap: var(--space-3);
    padding: var(--space-3) var(--space-4);
    background: var(--bg-elevated);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    margin-bottom: var(--space-4);
    flex-wrap: wrap;
}
.es-code-panel-label {
    display: inline-flex; align-items: center; gap: 6px;
    font-size: var(--text-sm); color: var(--text-secondary);
    flex-shrink: 0;
}
.es-code-display {
    font-family: monospace;
    font-size: var(--text-lg);
    font-weight: var(--weight-semibold);
    letter-spacing: .15em;
    padding: 4px 12px;
    background: var(--bg-subtle);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    color: var(--text-primary);
}
.es-code-display.is-empty {
    color: var(--text-tertiary);
    font-style: italic;
    font-weight: normal;
    letter-spacing: normal;
}
.es-code-timer {
    font-size: var(--text-sm);
    font-weight: var(--weight-medium);
    color: var(--success, #2d6a4f);
}
.es-code-timer.is-warn   { color: #d97706; }
.es-code-timer.is-expired { color: var(--error); }

.es-card-icon {
    width: 44px; height: 44px; border-radius: var(--radius-lg);
    background: var(--accent-muted); color: var(--accent);
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
}
.es-card-title {
    font-size: var(--text-base);
    font-weight: var(--weight-semibold);
    color: var(--text-primary);
    line-height: 1.3;
    padding-right: 64px; /* room for the absolute status badge */
}
.es-card-meta {
    display: flex; flex-direction: column; gap: 4px;
    font-size: var(--text-xs); color: var(--text-tertiary);
}
.es-card-meta-row { display: flex; align-items: center; gap: 5px; }
.es-card-footer {
    display: flex; align-items: center; justify-content: space-between;
    margin-top: auto; padding-top: var(--space-3);
    border-top: 1px solid var(--border);
}
.es-card-edit-btn {
    position: absolute; top: var(--space-3); right: var(--space-3);
    width: 28px; height: 28px;
    display: flex; align-items: center; justify-content: center;
    border-radius: var(--radius-sm);
    background: var(--bg-subtle); color: var(--text-secondary);
    border: 1px solid transparent;
    cursor: pointer;
}
.es-card-edit-btn:hover { border-color: var(--border); color: var(--accent); }

/* ── Bulk-select mode for slot cards ────────────────────────── */
.es-select-checkbox {
    position:absolute;top:var(--space-3);left:var(--space-3);
    width:18px;height:18px;accent-color:var(--accent);
    cursor:pointer;z-index:2;display:none;
}
.es-slot-grid.is-selecting .es-select-checkbox { display:inline-block; }
.es-slot-grid.is-selecting .es-slot-card.is-selected {
    border-color:var(--accent);background:var(--accent-muted);
}
.es-slot-grid.is-selecting .es-slot-card.is-undeletable { opacity:.55; }
/* Hide the per-card edit pencil while selecting so the click is unambiguous. */
.es-slot-grid.is-selecting .es-card-edit-btn { display:none !important; }

.es-bulk-bar {
    position:fixed;left:50%;bottom:24px;transform:translateX(-50%);
    background:var(--bg-elevated);border:1px solid var(--border);
    border-radius:var(--radius-lg);box-shadow:var(--shadow-lg);
    padding:var(--space-3) var(--space-4);
    display:none;align-items:center;gap:var(--space-3);z-index:50;
    font-size:var(--text-sm);
}
.es-bulk-bar.is-visible { display:flex; }

/* Add Slot dashed card (matches interview Add Session card) */
.es-add-card {
    display: flex; flex-direction: column;
    align-items: center; justify-content: center;
    gap: var(--space-2);
    padding: var(--space-5);
    background: transparent;
    border: 1.5px dashed var(--border);
    border-radius: var(--radius-lg);
    color: var(--accent);
    cursor: pointer;
    min-height: 200px;
}
.es-add-card:hover {
    background: var(--accent-muted);
    border-color: var(--accent);
}
.es-add-card-circle {
    width: 48px; height: 48px; border-radius: 50%;
    background: var(--accent-muted);
    display: flex; align-items: center; justify-content: center;
}

/* Roster page — full-page card+table shell, same as Documents/Results/Queue */
.page:has(.es-roster-card) { display: flex; flex-direction: column; }
.es-roster-card { flex: 1; min-height: 300px; }
</style>

<?php if (!empty($errors)): ?>
    <?php foreach ($errors as $err): ?>
        <div class="alert alert-error" style="margin-bottom:var(--space-3)"><?= e($err) ?></div>
    <?php endforeach; ?>
<?php endif; ?>
<?php foreach ($success as $s): ?>
    <div class="alert alert-success" style="margin-bottom:var(--space-3)"><?= e($s) ?></div>
<?php endforeach; ?>

<?php if ($mode === 'colleges'): ?>

    <!-- ============================================================
         MODE 1 — COLLEGE SELECTOR
         (Admins land here. Staff are auto-routed to their college.)
    ============================================================ -->
    <div style="margin-bottom:var(--space-3)">
        <a href="<?= e(url('/staff/exam')) ?>" class="btn btn-ghost btn-sm">← Back</a>
    </div>

    <div class="page-header" style="text-align:center;margin-bottom:var(--space-4)">
        <h1 class="page-title" style="margin:0 0 var(--space-1) 0">Exam Room Slots</h1>
        <p class="page-subtitle" style="text-align:center">
            Pick a college to manage its exam slots and rooms.
        </p>
    </div>

    <?php if (empty($departments)): ?>
        <div style="text-align:center;padding:var(--space-16);color:var(--text-tertiary);font-size:var(--text-sm)">
            No colleges / departments configured in the system.
        </div>
    <?php else: ?>
        <div class="es-college-grid" style="max-width:900px;margin:0 auto">
            <?php foreach ($departments as $dept):
                $info = $collegeCounts[$dept] ?? [
                    'total_slots' => 0, 'upcoming_slots' => 0,
                    'total_seats' => 0, 'filled_seats' => 0,
                ];
                $deptNeedsSetup = ((int)$info['upcoming_slots']) === 0;
            ?>
                <a href="<?= e(url('/staff/exam/slots') . '?college=' . urlencode($dept)) ?>"
                   class="es-college-card" style="align-items:center;text-align:center">
                    <?php if ($deptNeedsSetup): ?>
                        <span class="es-college-card-dot" title="No upcoming slots — needs setup"></span>
                    <?php endif; ?>
                    <div class="es-card-icon"><?= icon('ic_fluent_building_bank_24_regular', 22) ?></div>
                    <div class="es-card-title" style="padding-right:0;text-align:center">
                        <?= e($dept) ?>
                    </div>
                    <div class="es-card-meta" style="align-items:center">
                        <div class="es-card-meta-row">
                            <?= icon('ic_fluent_calendar_ltr_24_regular', 12) ?>
                            <?= (int)$info['upcoming_slots'] ?> upcoming slot<?= (int)$info['upcoming_slots'] === 1 ? '' : 's' ?>
                        </div>
                        <div class="es-card-meta-row">
                            <?= icon('ic_fluent_people_24_regular', 12) ?>
                            <?= (int)$info['filled_seats'] ?> / <?= (int)$info['total_seats'] ?> seats filled
                        </div>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

<?php elseif ($mode === 'slots'): ?>

    <!-- ============================================================
         MODE 2 — SLOT CARD GRID FOR ONE COLLEGE
    ============================================================ -->
    <div style="display:flex;align-items:center;margin-bottom:var(--space-3);gap:var(--space-2);flex-wrap:wrap">
        <?php if ($canManage): ?>
            <a href="<?= e(url('/staff/exam/slots')) ?>" class="btn btn-ghost btn-sm" style="margin-right:auto">← Back</a>
        <?php endif; ?>

        <?php if ($canManage): ?>
            <a href="<?= e(url('/staff/exam/export-rooms')) ?>"
               class="btn btn-sm" target="_blank" rel="noopener"
               title="Printable per-room sheets to post on the school board"
               style="font-size:var(--text-xs)">
                <?= icon('ic_fluent_document_24_regular', 14) ?> Export Lists
            </a>
            <button type="button" class="btn btn-ghost btn-sm"
                    onclick="document.getElementById('exam-config-modal').style.display='flex'"
                    style="font-size:var(--text-xs);color:var(--text-secondary)">
                <?= icon('ic_fluent_settings_24_regular', 14) ?> Config
            </button>
        <?php else: ?>
            <span class="badge badge-neutral" style="font-size:var(--text-xs)">
                <?= icon('ic_fluent_eye_24_regular', 12) ?> Read-only
            </span>
        <?php endif; ?>
    </div>

    <div class="page-header" style="text-align:center;margin-bottom:var(--space-4)">
        <h1 class="page-title" style="margin:0 0 var(--space-1) 0"><?= e($collegeParam) ?></h1>
        <p class="page-subtitle" style="text-align:center">Exam Room Slots</p>
    </div>

    <?php
    $today      = date('Y-m-d');
    $totalCap   = array_sum(array_column($slotsForCollege, 'capacity'));
    $totalFil   = array_sum(array_column($slotsForCollege, 'filled'));
    ?>
    <div style="display:flex;align-items:center;justify-content:center;gap:var(--space-3);
                color:var(--text-tertiary);font-size:var(--text-xs);margin-bottom:var(--space-3);flex-wrap:wrap">
        <span>
            <?= count($slotsForCollege) ?> slot<?= count($slotsForCollege) === 1 ? '' : 's' ?>
            &nbsp;·&nbsp; <?= $totalFil ?> / <?= $totalCap ?> seats filled
        </span>
        <?php if ($canManage && !empty($slotsForCollege)): ?>
            <button type="button" id="es-select-toggle"
                    class="btn btn-ghost btn-sm" style="margin-left:auto;font-size:var(--text-xs)"
                    onclick="toggleEsSelectMode()">Select</button>
        <?php endif; ?>
    </div>

    <div class="es-slot-grid">
        <?php foreach ($slotsForCollege as $slot):
            $sid       = (int)$slot['id'];
            $filled    = (int)$slot['filled'];
            $cap       = (int)$slot['capacity'];
            $isFull    = $filled >= $cap;
            $isPast    = $slot['exam_date'] < $today;
            $isToday   = $slot['exam_date'] === $today;

            $cardClass = 'es-slot-card';
            if ($isToday) $cardClass .= ' is-today';
            if ($isPast)  $cardClass .= ' is-past';
            if ($isFull)  $cardClass .= ' is-full';

            $editPayload = json_encode([
                'id'         => $sid,
                'exam_date'  => $slot['exam_date'],
                'slot_time'  => substr($slot['slot_time'] ?? '', 0, 5),
                'end_time'   => substr($slot['end_time']  ?? '', 0, 5),
                'room_label' => $slot['room_label'] ?? '',
                'department' => $slot['department'] ?? '',
                'capacity'   => $cap,
                'filled'     => $filled,
            ], JSON_HEX_APOS | JSON_HEX_QUOT);
            // Slots with applicants assigned can't be deleted in bulk either —
            // mirrors the single-row delete rule.
            $esUndeletable = $filled > 0;
            if ($esUndeletable) $cardClass .= ' is-undeletable';
        ?>
            <a href="<?= e(url('/staff/exam/slots') . '?slot=' . $sid) ?>"
               class="<?= $cardClass ?>"
               data-slot-id="<?= $sid ?>"
               onclick="return onEsCardClick(event, this)">
                <?php if ($canManage): ?>
                    <input type="checkbox" class="es-select-checkbox"
                           value="<?= $sid ?>"
                           <?= $esUndeletable ? 'disabled title="Has applicants — cannot remove"' : '' ?>
                           onclick="event.stopPropagation();onEsCheckboxChange(this)">
                <?php endif; ?>

                <!-- Edit pencil — stops navigation to the roster -->
                <?php if ($canManage && !$isPast): ?>
                    <button type="button" class="es-card-edit-btn"
                            title="Edit slot"
                            onclick='event.preventDefault();event.stopPropagation();openEditExamSlot(<?= $editPayload ?>)'>
                        <?= icon('ic_fluent_edit_24_regular', 14) ?>
                    </button>
                <?php endif; ?>

                <!-- Status badge top-right (offset to leave room for edit button) -->
                <div style="position:absolute;top:var(--space-3);right:<?= ($isPast || !$canManage) ? 'var(--space-3)' : '44px' ?>">
                    <?php if ($isPast): ?>
                        <span class="badge badge-neutral" style="font-size:10px">Ended</span>
                    <?php elseif ($isFull): ?>
                        <span class="badge badge-rejected" style="font-size:10px">Full</span>
                    <?php elseif ($isToday): ?>
                        <span class="badge badge-info" style="font-size:10px">Today</span>
                    <?php else: ?>
                        <span class="badge badge-approved" style="font-size:10px">Open</span>
                    <?php endif; ?>
                </div>

                <div class="es-card-title">
                    <?= e($slot['room_label'] ?? '') ?>
                </div>

                <div class="es-card-meta">
                    <div class="es-card-meta-row">
                        <?= icon('ic_fluent_calendar_ltr_24_regular', 12) ?>
                        <?= e(format_date($slot['exam_date'], 'D, M j, Y')) ?>
                    </div>
                    <div class="es-card-meta-row">
                        <?= icon('ic_fluent_clock_24_regular', 12) ?>
                        <?= e(format_time($slot['slot_time'])) ?>
                        <?php if (!empty($slot['end_time'])): ?>
                            &ndash; <?= e(format_time($slot['end_time'])) ?>
                        <?php endif; ?>
                    </div>
                    <div class="es-card-meta-row">
                        <?= icon('ic_fluent_people_24_regular', 12) ?>
                        <strong style="<?= $isFull ? 'color:var(--error)' : 'color:var(--text-secondary)' ?>">
                            <?= $filled ?> / <?= $cap ?>
                        </strong>
                        seats filled
                    </div>
                    <?php
                        $cardSecsLeft = (int)($slot['pw_secs_left'] ?? 0);
                        $cardCodeActive = !empty($slot['access_password']) && $cardSecsLeft > 0;
                    ?>
                    <?php if ($cardCodeActive): ?>
                        <div class="es-card-meta-row" style="margin-top:2px">
                            <?= icon('ic_fluent_lock_closed_24_regular', 12) ?>
                            <span class="es-card-code-badge<?= $cardSecsLeft <= 60 ? ' is-warn' : '' ?>"
                                  data-card-pw-end="<?= time() + $cardSecsLeft ?>">
                                <?= e($slot['access_password']) ?>
                                · <span class="card-pw-timer"><?= gmdate('i:s', $cardSecsLeft) ?></span>
                            </span>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="es-card-footer">
                    <span style="font-size:var(--text-xs);color:var(--text-tertiary)">
                        <?= e($slot['department']) ?>
                    </span>
                    <span style="font-size:var(--text-xs);font-weight:var(--weight-medium);
                                 color:var(--accent);display:flex;align-items:center;gap:4px">
                        View
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none">
                            <path stroke="currentColor" stroke-width="2.2" stroke-linecap="round" d="M5 12h14M13 6l6 6-6 6"/>
                        </svg>
                    </span>
                </div>
            </a>
        <?php endforeach; ?>

        <?php if ($canManage): ?>
            <!-- Add Slot dashed card -->
            <div class="es-add-card"
                 role="button" tabindex="0"
                 onclick="document.getElementById('add-slot-modal').style.display='flex'"
                 onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();this.click();}">
                <div class="es-add-card-circle">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none">
                        <path stroke="var(--accent)" stroke-width="2.2" stroke-linecap="round" d="M12 5v14M5 12h14"/>
                    </svg>
                </div>
                <div style="font-size:var(--text-sm);font-weight:var(--weight-medium);color:var(--accent)">
                    Add Slot
                </div>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($canManage && !empty($slotsForCollege)): ?>
    <!-- Floating bar for bulk delete (visible while in select mode). -->
    <div id="es-bulk-bar" class="es-bulk-bar">
        <span id="es-bulk-count" style="font-weight:var(--weight-medium)">0 selected</span>
        <form method="POST" id="es-bulk-form"
              style="display:flex;gap:var(--space-2);align-items:center;margin:0">
            <?= csrf_field() ?>
            <input type="hidden" name="action"      value="delete_slots_bulk">
            <input type="hidden" name="ctx_college" value="<?= e($collegeParam) ?>">
            <input type="hidden" name="ids"         id="es-bulk-ids" value="">
            <button type="button" class="btn btn-ghost btn-sm"
                    onclick="cancelEsSelectMode()">Cancel</button>
            <button type="submit" class="btn btn-sm" id="es-bulk-delete-btn"
                    style="background:var(--error);color:#fff;border-color:var(--error)"
                    onclick="return confirmEsBulkDelete()">
                Delete Selected
            </button>
        </form>
    </div>
    <?php endif; ?>

    <!-- ── Awaiting-slot list, filtered to this college ─────────── -->
    <?php if ($unassignedApplicants): ?>
        <div class="card" style="padding:0;overflow:hidden;margin-top:var(--space-6)">
            <div style="padding:var(--space-4) var(--space-5);border-bottom:1px solid var(--border);
                        display:flex;justify-content:space-between;align-items:center">
                <div>
                    <div style="font-weight:var(--weight-semibold)">
                        Awaiting Slot (<?= count($unassignedApplicants) ?>)
                    </div>
                    <div style="font-size:var(--text-xs);color:var(--text-tertiary);margin-top:2px">
                        Documents approved · earliest-approved first.
                    </div>
                </div>
            </div>
            <table class="data-table" style="margin:0;width:100%">
                <thead>
                    <tr>
                        <th>Applicant</th>
                        <th>Course</th>
                        <th>Type</th>
                        <th>Approved</th>
                        <th style="width:340px">Assign to Slot</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($unassignedApplicants as $u): ?>
                    <tr>
                        <td><?= e(format_full_name($u)) ?></td>
                        <td style="font-size:var(--text-sm)"><?= e($u['course_applied']) ?></td>
                        <td><span class="badge badge-neutral"><?= e(ucfirst($u['applicant_type'])) ?></span></td>
                        <td style="font-size:var(--text-xs);color:var(--text-tertiary)">
                            <?= $u['documents_approved_at']
                                ? e(date('M j, g:i A', strtotime($u['documents_approved_at'])))
                                : '<em>—</em>' ?>
                        </td>
                        <td>
                            <?php if ($canManage): ?>
                                <form method="POST"
                                      style="display:flex;gap:var(--space-2);align-items:center;margin:0">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action"        value="assign">
                                    <input type="hidden" name="ctx_college"   value="<?= e($collegeParam) ?>">
                                    <input type="hidden" name="applicant_id"  value="<?= (int)$u['id'] ?>">
                                    <select name="slot_id" required class="form-input"
                                            style="flex:1;font-size:var(--text-xs);padding:4px 8px">
                                        <option value="">— Choose slot —</option>
                                        <?php foreach ($slotsForCollege as $s):
                                            if ((int)$s['filled'] >= (int)$s['capacity']) continue;
                                            if ($s['exam_date'] < $today) continue;
                                        ?>
                                            <option value="<?= (int)$s['id'] ?>">
                                                <?= e(format_date($s['exam_date'], 'M j')) ?>
                                                · <?= e(format_time($s['slot_time'])) ?><?php if (!empty($s['end_time'])): ?>–<?= e(format_time($s['end_time'])) ?><?php endif; ?>
                                                · <?= e($s['room_label']) ?>
                                                (<?= (int)$s['filled'] ?>/<?= (int)$s['capacity'] ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="submit" class="btn btn-primary btn-sm"
                                            style="font-size:var(--text-xs)">Assign</button>
                                </form>
                            <?php else: ?>
                                <span style="font-size:var(--text-xs);color:var(--text-tertiary)">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

<?php elseif ($mode === 'roster'): ?>

    <!-- ============================================================
         MODE 3 — SLOT ROSTER (full-page table)
    ============================================================ -->
    <?php
    $sid       = (int)$slotDetail['id'];
    $filled    = (int)$slotDetail['filled'];
    $cap       = (int)$slotDetail['capacity'];
    $isPast    = $slotDetail['exam_date'] < date('Y-m-d');

    $editPayload = json_encode([
        'id'         => $sid,
        'exam_date'  => $slotDetail['exam_date'],
        'slot_time'  => substr($slotDetail['slot_time'] ?? '', 0, 5),
        'end_time'   => substr($slotDetail['end_time']  ?? '', 0, 5),
        'room_label' => $slotDetail['room_label'] ?? '',
        'department' => $slotDetail['department'] ?? '',
        'capacity'   => $cap,
        'filled'     => $filled,
    ], JSON_HEX_APOS | JSON_HEX_QUOT);
    ?>

    <div style="display:flex;align-items:center;margin-bottom:var(--space-4);gap:var(--space-2);flex-wrap:wrap">
        <a href="<?= e(url('/staff/exam/slots') . '?college=' . urlencode($slotDetail['department'])) ?>"
           class="btn btn-ghost btn-sm" style="margin-right:auto">← Back to <?= e($slotDetail['department']) ?> slots</a>

        <?php if ($canManage && !$isPast): ?>
            <button type="button" class="btn btn-sm"
                    onclick='openEditExamSlot(<?= $editPayload ?>)'>
                <?= icon('ic_fluent_edit_24_regular', 14) ?> Edit slot
            </button>
        <?php endif; ?>
    </div>

    <!-- Slot summary strip -->
    <div style="display:flex;align-items:center;gap:var(--space-3);padding:var(--space-3) var(--space-4);
                margin-bottom:var(--space-4);background:var(--bg-elevated);border:1px solid var(--border);
                border-radius:var(--radius-md);font-size:var(--text-sm);flex-wrap:wrap">
        <?= icon('ic_fluent_location_24_regular', 14, 'color:var(--text-tertiary);flex-shrink:0') ?>

        <?php if ($canManage && !$isPast): ?>
            <!-- Inline-editable room label (Admin/SSO only). Click the pencil to rename. -->
            <span id="room-label-view" style="display:inline-flex;align-items:center;gap:6px">
                <span id="room-label-text" style="font-weight:var(--weight-medium)"><?= e($slotDetail['room_label']) ?></span>
                <button type="button" class="btn-icon" title="Rename room"
                        onclick="startRoomLabelEdit()"
                        style="padding:2px;color:var(--text-tertiary)">
                    <?= icon('ic_fluent_edit_24_regular', 12) ?>
                </button>
            </span>
            <span id="room-label-edit" style="display:none;align-items:center;gap:4px">
                <input type="text" id="room-label-input" class="form-control"
                       value="<?= e($slotDetail['room_label']) ?>"
                       maxlength="80"
                       style="font-size:var(--text-sm);padding:2px 8px;height:auto;min-height:0;width:240px">
                <button type="button" class="btn btn-primary btn-sm"
                        onclick="saveRoomLabel(<?= $sid ?>)"
                        style="font-size:var(--text-xs);padding:2px 8px;height:auto;min-height:0">Save</button>
                <button type="button" class="btn btn-ghost btn-sm"
                        onclick="cancelRoomLabelEdit()"
                        style="font-size:var(--text-xs);padding:2px 8px;height:auto;min-height:0">Cancel</button>
            </span>
        <?php else: ?>
            <span style="font-weight:var(--weight-medium)"><?= e($slotDetail['room_label']) ?></span>
        <?php endif; ?>

        <span style="color:var(--text-tertiary)">
            <?= e(format_date($slotDetail['exam_date'], 'D, M j, Y')) ?>
            · <?= e(format_time($slotDetail['slot_time'])) ?><?php if (!empty($slotDetail['end_time'])): ?>–<?= e(format_time($slotDetail['end_time'])) ?><?php endif; ?>
            · <?= e($slotDetail['department']) ?>
        </span>
        <span style="margin-left:auto;font-size:var(--text-xs);color:var(--text-tertiary)">
            <strong style="<?= $filled >= $cap ? 'color:var(--error)' : '' ?>">
                <?= $filled ?> / <?= $cap ?>
            </strong>
            seats filled
            <?php if ($isPast): ?>
                <span class="badge badge-neutral" style="font-size:10px;margin-left:var(--space-2)">Ended</span>
            <?php endif; ?>
        </span>
    </div>

    <?php
        $rosterCanGenerate = $canGenerateCodeFor((string) $slotDetail['department']);
        $rosterSecsLeft    = (int)($slotDetail['pw_secs_left'] ?? 0);
        $rosterCodeActive  = !empty($slotDetail['access_password']) && $rosterSecsLeft > 0;
    ?>

    <!-- ============================================================
         Per-room access code panel
         (proctor-facing: announce the code to the room and start the
         5-minute window. Read-only for Dean and out-of-dept staff.)
    ============================================================ -->
    <?php if (!$isPast): ?>
    <div class="es-code-panel" id="slot-code-panel" data-slot-id="<?= $sid ?>">
        <span class="es-code-panel-label">
            <?= icon('ic_fluent_lock_closed_24_regular', 14) ?>
            Access Code
        </span>

        <span id="slot-code-display"
              class="es-code-display<?= $rosterCodeActive ? '' : ' is-empty' ?>">
            <?= $rosterCodeActive ? e($slotDetail['access_password']) : 'No active code' ?>
        </span>

        <span id="slot-code-timer" class="es-code-timer"
              style="<?= $rosterCodeActive ? '' : 'display:none' ?>"
              data-secs-left="<?= $rosterSecsLeft ?>">
            Expires in <?= gmdate('i:s', $rosterSecsLeft) ?>
        </span>

        <?php if ($rosterCanGenerate): ?>
            <div style="margin-left:auto;display:flex;gap:var(--space-2)">
                <button type="button" id="slot-code-extend-btn"
                        class="btn btn-ghost btn-sm"
                        onclick="extendSlotCode()"
                        style="<?= $rosterCodeActive ? '' : 'display:none' ?>;font-size:var(--text-xs)">
                    <?= icon('ic_fluent_clock_24_regular', 12) ?> Extend
                </button>
                <button type="button" id="slot-code-generate-btn"
                        class="btn btn-primary btn-sm"
                        onclick="generateSlotCode()"
                        style="<?= $rosterCodeActive ? '' : 'display:none' ?>;font-size:var(--text-xs)">
                    <?= icon('ic_fluent_arrow_sync_24_regular', 12) ?> New
                </button>
            </div>
        <?php else: ?>
            <span style="margin-left:auto;font-size:var(--text-xs);color:var(--text-tertiary)">
                <?= icon('ic_fluent_eye_24_regular', 12) ?> Read-only
            </span>
        <?php endif; ?>
    </div>
    <div style="font-size:var(--text-xs);color:var(--text-tertiary);margin:-2px 0 var(--space-4) 2px">
        <?php if ($rosterCodeActive): ?>
            Codes are valid for 5 minutes. Use <strong>Extend</strong> to give late
            but legitimate applicants another 5 minutes from now, or <strong>New</strong>
            to issue a fresh password and invalidate the current one.
        <?php elseif ($rosterCanGenerate): ?>
            A fresh access code is issued automatically the moment you open this
            page on the slot's exam date. After that, use <strong>New</strong> or
            <strong>Extend</strong> to manage the 5-minute window.
        <?php else: ?>
            Codes are valid for 5 minutes and are issued by this room's proctor.
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="card es-roster-card" style="padding:0;overflow:hidden;display:flex;flex-direction:column">
        <table class="table">
            <thead>
                <tr>
                    <th>Applicant</th>
                    <th>Course</th>
                    <th style="width:120px">Type</th>
                    <th style="width:160px">Assigned</th>
                    <?php if ($canManage && !$isPast): ?>
                        <th style="width:100px">Action</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($slotRoster as $row): ?>
                <tr>
                    <td>
                        <div style="font-weight:var(--weight-medium)"><?= e(format_full_name($row)) ?></div>
                        <?php if (!empty($row['student_email'])): ?>
                            <div style="font-size:var(--text-xs);color:var(--text-tertiary)">
                                <?= e($row['student_email']) ?>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td style="font-size:var(--text-sm)"><?= e($row['course_applied'] ?: '—') ?></td>
                    <td>
                        <span class="badge badge-neutral" style="font-size:var(--text-xs)">
                            <?= e(ucfirst($row['applicant_type'] ?? '')) ?>
                        </span>
                    </td>
                    <td style="font-size:var(--text-xs);color:var(--text-tertiary)">
                        <?= $row['assigned_at']
                            ? e(date('M j, g:i A', strtotime($row['assigned_at'])))
                            : '—' ?>
                    </td>
                    <?php if ($canManage && !$isPast): ?>
                        <td>
                            <form method="POST" style="margin:0"
                                  onsubmit="return confirm('Remove this applicant from the slot?')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action"       value="unassign">
                                <input type="hidden" name="ctx_slot"     value="<?= $sid ?>">
                                <input type="hidden" name="applicant_id" value="<?= (int)$row['applicant_id'] ?>">
                                <button type="submit" class="btn btn-ghost btn-sm"
                                        style="color:var(--error);font-size:var(--text-xs)">
                                    Remove
                                </button>
                            </form>
                        </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <?php if (empty($slotRoster)): ?>
            <div class="empty-state"
                 style="flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;
                        gap:var(--space-3);color:var(--text-tertiary);padding:var(--space-8)">
                <?= icon('ic_fluent_people_24_regular', 32) ?>
                <div style="text-align:center;max-width:420px">
                    No applicants assigned to this slot yet. Use the
                    <a href="<?= e(url('/staff/exam/slots') . '?college=' . urlencode($slotDetail['department'])) ?>">
                        college view
                    </a> to assign applicants from the awaiting list.
                </div>
            </div>
        <?php else: ?>
            <div style="flex:1;border-top:1px solid var(--border)"></div>
        <?php endif; ?>
    </div>

<?php endif; ?>


<!-- ============================================================
     MODALS — shared across all modes that may need them.
============================================================ -->

<?php if ($mode !== 'colleges'): ?>

    <!-- ── Add Slot Modal (Single + Recurring) ────────────────── -->
    <div id="add-slot-modal" class="modal-backdrop" style="display:none">
        <div class="modal" style="max-width:480px">
            <div class="modal-header">
                <div class="modal-title">Add Slot</div>
                <button class="btn-icon" type="button"
                        onclick="document.getElementById('add-slot-modal').style.display='none'">
                    <?= icon('ic_fluent_dismiss_24_regular', 16) ?>
                </button>
            </div>
            <form method="POST" id="add-slot-form">
                <?= csrf_field() ?>
                <input type="hidden" name="action"      id="add-slot-action" value="add_slot">
                <input type="hidden" name="ctx_college" value="<?= e($collegeParam) ?>">
                <div class="modal-body" style="display:flex;flex-direction:column;gap:var(--space-4)">

                    <!-- Mode toggle -->
                    <div class="seg-control" style="align-self:flex-start">
                        <button type="button" id="add-slot-mode-single"
                                class="seg-control-item active"
                                onclick="setSlotMode('single')">Single slot</button>
                        <button type="button" id="add-slot-mode-batch"
                                class="seg-control-item"
                                onclick="setSlotMode('batch')">Recurring (multiple)</button>
                    </div>

                    <!-- College is taken from the page context (?college=…). The
                         JS toggle swaps this hidden input's `name` between
                         `department` and `batch_department` so the two POST
                         handlers stay unchanged. -->
                    <input type="hidden" id="add-slot-dept" name="department"
                           value="<?= e($canManage ? $collegeParam : $staffDept) ?>">

                    <!-- ── Single slot fields ─────────────────── -->
                    <div id="add-slot-single-fields" style="display:flex;flex-direction:column;gap:var(--space-4)">
                        <div>
                            <label class="form-label">Exam Date <span style="color:var(--error)">*</span></label>
                            <input type="date" name="exam_date" class="form-control" required
                                   value="<?= e($defaultDate) ?>" min="<?= date('Y-m-d') ?>">
                        </div>
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--space-3)">
                            <div>
                                <label class="form-label">Opens <span style="color:var(--error)">*</span></label>
                                <input type="time" name="slot_time" id="add-slot-time"
                                       class="form-control" value="08:00" required>
                            </div>
                            <div>
                                <label class="form-label">Closes <span style="color:var(--error)">*</span></label>
                                <input type="time" name="end_time" id="add-slot-end"
                                       class="form-control" value="09:30" required>
                            </div>
                        </div>
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--space-3)">
                            <div>
                                <label class="form-label">Capacity <span style="color:var(--error)">*</span></label>
                                <input type="number" name="capacity" class="form-control"
                                       value="<?= $examRoomCap ?>" min="1" max="500" required>
                            </div>
                            <div>
                                <label class="form-label">Room Label <span style="color:var(--error)">*</span></label>
                                <input type="text" name="room_label" class="form-control" required
                                       placeholder="e.g. Room 101" maxlength="80">
                            </div>
                        </div>
                    </div>

                    <!-- ── Recurring (batch) fields ───────────── -->
                    <div id="add-slot-batch-fields" style="display:none;flex-direction:column;gap:var(--space-4)">
                        <p style="font-size:var(--text-sm);color:var(--text-secondary);margin:0">
                            One slot is created per room per selected weekday in the date range.
                        </p>
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--space-3)">
                            <div>
                                <label class="form-label">Start Date <span style="color:var(--error)">*</span></label>
                                <input type="date" name="batch_start_date" class="form-control"
                                       value="<?= e($defaultDate) ?>" required>
                            </div>
                            <div>
                                <label class="form-label">End Date <span style="color:var(--error)">*</span></label>
                                <input type="date" name="batch_end_date" class="form-control"
                                       value="<?= e(date('Y-m-d', strtotime($defaultDate . ' +6 days'))) ?>" required>
                            </div>
                        </div>
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--space-3)">
                            <div>
                                <label class="form-label">Opens <span style="color:var(--error)">*</span></label>
                                <input type="time" name="batch_time" id="batch-time"
                                       class="form-control" value="08:00" required>
                            </div>
                            <div>
                                <label class="form-label">Closes <span style="color:var(--error)">*</span></label>
                                <input type="time" name="batch_end_time" id="batch-end-time"
                                       class="form-control" value="09:30" required>
                            </div>
                        </div>
                        <div>
                            <label class="form-label">Seats per Slot</label>
                            <input type="number" name="batch_capacity" class="form-control"
                                   value="<?= $examRoomCap ?>" min="1" max="500">
                        </div>
                        <div>
                            <label class="form-label">Rooms</label>
                            <div id="batch-rooms-list" style="display:flex;flex-direction:column;gap:var(--space-2)">
                                <input type="text" name="batch_rooms[]" class="form-control"
                                       placeholder="e.g. Room 101" required>
                            </div>
                            <button type="button" class="btn btn-ghost btn-sm"
                                    style="margin-top:var(--space-2);font-size:var(--text-xs)"
                                    onclick="addBatchRoom()">+ Add another room</button>
                        </div>
                        <div>
                            <label class="form-label">Days of Week</label>
                            <div style="display:flex;flex-wrap:wrap;gap:var(--space-2)">
                                <?php foreach ([1=>'Mon',2=>'Tue',3=>'Wed',4=>'Thu',5=>'Fri',6=>'Sat',0=>'Sun'] as $dv => $dl): ?>
                                    <label style="display:flex;align-items:center;gap:4px;font-size:var(--text-sm)">
                                        <input type="checkbox" name="batch_days[]" value="<?= $dv ?>"
                                            <?= $dv >= 1 && $dv <= 5 ? 'checked' : '' ?>>
                                        <?= $dl ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <div style="font-size:var(--text-xs);color:var(--text-tertiary);margin-top:-2px">
                        Exam closes at <strong>Closes</strong>. Duration is the difference between the two.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-ghost"
                            onclick="document.getElementById('add-slot-modal').style.display='none'">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="add-slot-submit">Add Slot</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ── Edit Slot Modal ────────────────────────────────────── -->
    <div id="edit-slot-modal" class="modal-backdrop" style="display:none">
        <div class="modal" style="max-width:420px">
            <div class="modal-header">
                <div class="modal-title">Edit Slot</div>
                <button class="btn-icon" type="button"
                        onclick="document.getElementById('edit-slot-modal').style.display='none'">
                    <?= icon('ic_fluent_dismiss_24_regular', 16) ?>
                </button>
            </div>
            <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="action"      value="edit_slot">
                <input type="hidden" name="ctx_college" value="<?= e($collegeParam) ?>">
                <input type="hidden" name="ctx_slot"    id="edit-slot-ctx-slot" value="<?= $slotIdParam ?: '' ?>">
                <input type="hidden" name="slot_id"     id="edit-slot-id">
                <div class="modal-body" style="display:flex;flex-direction:column;gap:var(--space-4)">
                    <div>
                        <label class="form-label">College / Department <span style="color:var(--error)">*</span></label>
                        <?php if ($canManage): ?>
                            <select name="department" id="edit-slot-dept" class="form-control" required>
                                <option value="">— Select college —</option>
                                <?php foreach ($departments as $dept): ?>
                                    <option value="<?= e($dept) ?>"><?= e($dept) ?></option>
                                <?php endforeach; ?>
                            </select>
                        <?php else: ?>
                            <input type="text" class="form-control" value="<?= e($staffDept ?: 'Not assigned') ?>" disabled>
                            <input type="hidden" name="department" value="<?= e($staffDept) ?>">
                        <?php endif; ?>
                    </div>
                    <div>
                        <label class="form-label">Exam Date <span style="color:var(--error)">*</span></label>
                        <input type="date" name="exam_date" id="edit-slot-date"
                               class="form-control" required min="<?= date('Y-m-d') ?>">
                    </div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--space-3)">
                        <div>
                            <label class="form-label">Opens <span style="color:var(--error)">*</span></label>
                            <input type="time" name="slot_time" id="edit-slot-time" class="form-control" required>
                        </div>
                        <div>
                            <label class="form-label">Closes <span style="color:var(--error)">*</span></label>
                            <input type="time" name="end_time" id="edit-slot-end" class="form-control" required>
                        </div>
                    </div>
                    <div style="font-size:var(--text-xs);color:var(--text-tertiary);margin-top:-2px">
                        Exam closes at <strong>Closes</strong>. Duration is the difference between the two.
                    </div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--space-3)">
                        <div>
                            <label class="form-label">Capacity <span style="color:var(--error)">*</span></label>
                            <input type="number" name="capacity" id="edit-slot-cap"
                                   class="form-control" min="1" max="500" required>
                        </div>
                        <div>
                            <label class="form-label">Room Label <span style="color:var(--error)">*</span></label>
                            <input type="text" name="room_label" id="edit-slot-room"
                                   class="form-control" required maxlength="80">
                        </div>
                    </div>
                </div>
                <div class="modal-footer" style="justify-content:space-between">
                    <?php // Inline delete (only when slot is empty — server still validates) ?>
                    <button type="submit" form="delete-slot-form" class="btn btn-ghost"
                            id="edit-slot-delete-btn"
                            style="color:var(--error)"
                            onclick="return confirm('Delete this slot? Only allowed if it has no applicants.')">
                        Delete
                    </button>
                    <div style="display:flex;gap:var(--space-2)">
                        <button type="button" class="btn btn-ghost"
                                onclick="document.getElementById('edit-slot-modal').style.display='none'">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save Changes</button>
                    </div>
                </div>
            </form>
            <!-- Out-of-form delete: just submits action=delete_slot for the same slot id -->
            <form method="POST" id="delete-slot-form" style="display:none">
                <?= csrf_field() ?>
                <input type="hidden" name="action"      value="delete_slot">
                <input type="hidden" name="ctx_college" value="<?= e($collegeParam) ?>">
                <input type="hidden" name="slot_id"     id="delete-slot-id">
            </form>
        </div>
    </div>

    <!-- ── Exam Config Modal ──────────────────────────────────── -->
    <div id="exam-config-modal" class="modal-backdrop" style="display:none">
        <div class="modal" style="max-width:420px">
            <div class="modal-header">
                <div class="modal-title">Exam Config</div>
                <button class="btn-icon" type="button"
                        onclick="document.getElementById('exam-config-modal').style.display='none'">
                    <?= icon('ic_fluent_dismiss_24_regular', 16) ?>
                </button>
            </div>
            <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="action"      value="update_exam_config">
                <input type="hidden" name="ctx_college" value="<?= e($collegeParam) ?>">
                <div class="modal-body" style="display:flex;flex-direction:column;gap:var(--space-4)">
                    <div>
                        <label class="form-label">Default Seats per Room</label>
                        <input type="number" name="exam_room_capacity" class="form-control"
                               value="<?= $examRoomCap ?>" min="1" max="200">
                    </div>
                    <div>
                        <label class="form-label">Max Applicants per Exam Day</label>
                        <input type="number" name="exam_daily_cap" class="form-control"
                               value="<?= $examDailyCap ?>" min="1" max="10000">
                    </div>
                    <?php $roomsNeeded = $examRoomCap > 0 ? ceil($examDailyCap / $examRoomCap) : '—'; ?>
                    <div style="background:var(--bg-subtle);border-radius:var(--radius-md);
                                padding:var(--space-3) var(--space-4);font-size:var(--text-xs);
                                color:var(--text-secondary)">
                        <?= $examDailyCap ?> applicants &divide; <?= $examRoomCap ?>/room
                        = <strong>~<?= $roomsNeeded ?> rooms/day</strong>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-ghost"
                            onclick="document.getElementById('exam-config-modal').style.display='none'">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Config</button>
                </div>
            </form>
        </div>
    </div>


<?php endif; ?>

<script>
['add-slot-modal','exam-config-modal','edit-slot-modal'].forEach(function(id){
    var m = document.getElementById(id);
    if (m) m.addEventListener('click', function (e) { if (e.target === this) this.style.display = 'none'; });
});

// ── Bulk select mode for exam slot cards ─────────────────────
function _esGrid()  { return document.querySelector('.es-slot-grid'); }
function _esBar()   { return document.getElementById('es-bulk-bar'); }
function _esTBtn()  { return document.getElementById('es-select-toggle'); }

function toggleEsSelectMode() {
    var grid = _esGrid(); if (!grid) return;
    if (grid.classList.contains('is-selecting')) {
        cancelEsSelectMode();
    } else {
        grid.classList.add('is-selecting');
        var btn = _esTBtn();  if (btn) btn.textContent = 'Done';
        var bar = _esBar();   if (bar) bar.classList.add('is-visible');
        updateEsBulkCount();
    }
}
function cancelEsSelectMode() {
    var grid = _esGrid(); if (!grid) return;
    grid.classList.remove('is-selecting');
    grid.querySelectorAll('.es-select-checkbox').forEach(function(cb){ cb.checked = false; });
    grid.querySelectorAll('.es-slot-card').forEach(function(c){ c.classList.remove('is-selected'); });
    var btn = _esTBtn(); if (btn) btn.textContent = 'Select';
    var bar = _esBar();  if (bar) bar.classList.remove('is-visible');
}
function onEsCheckboxChange(cb) {
    var card = cb.closest('.es-slot-card');
    if (card) card.classList.toggle('is-selected', cb.checked);
    updateEsBulkCount();
}
function updateEsBulkCount() {
    var grid = _esGrid(); if (!grid) return;
    var n = grid.querySelectorAll('.es-select-checkbox:checked').length;
    var c = document.getElementById('es-bulk-count');
    if (c) c.textContent = n + ' selected';
    var btn = document.getElementById('es-bulk-delete-btn');
    if (btn) btn.disabled = (n === 0);
}
function onEsCardClick(event, link) {
    var grid = _esGrid();
    if (grid && grid.classList.contains('is-selecting')) {
        // While selecting, the card toggles its checkbox instead of navigating
        // to the roster. Disabled (filled) cards do nothing.
        event.preventDefault();
        var cb = link.querySelector('.es-select-checkbox');
        if (cb && !cb.disabled) {
            cb.checked = !cb.checked;
            onEsCheckboxChange(cb);
        }
        return false;
    }
    return true;
}
function confirmEsBulkDelete() {
    var grid = _esGrid(); if (!grid) return false;
    var ids = Array.from(grid.querySelectorAll('.es-select-checkbox:checked'))
                  .map(function(cb){ return cb.value; });
    if (ids.length === 0) return false;
    if (!confirm('Remove ' + ids.length + ' slot(s)? This cannot be undone.')) return false;
    document.getElementById('es-bulk-ids').value = ids.join(',');
    return true;
}

// When the user changes the open time, auto-bump the close time to
// open + 90 minutes — but only if they haven't manually moved the
// close field yet (so we don't overwrite an explicit close on edit).
function _autoEnd(openId, closeId) {
    var openEl  = document.getElementById(openId);
    var closeEl = document.getElementById(closeId);
    if (!openEl || !closeEl) return;
    closeEl.addEventListener('input', function () { closeEl.dataset.userset = '1'; });
    openEl.addEventListener('change', function () {
        if (closeEl.dataset.userset === '1') return;
        var parts = (openEl.value || '').split(':');
        if (parts.length !== 2) return;
        var d = new Date();
        d.setHours(parseInt(parts[0], 10) || 0, (parseInt(parts[1], 10) || 0) + 90, 0, 0);
        var hh = String(d.getHours()).padStart(2, '0');
        var mm = String(d.getMinutes()).padStart(2, '0');
        closeEl.value = hh + ':' + mm;
    });
}
_autoEnd('add-slot-time',   'add-slot-end');
_autoEnd('edit-slot-time',  'edit-slot-end');
_autoEnd('batch-time',      'batch-end-time');

// ── Add Slot modal: Single / Recurring toggle ────────────────
function setSlotMode(mode) {
    var single = document.getElementById('add-slot-single-fields');
    var batch  = document.getElementById('add-slot-batch-fields');
    var btnS   = document.getElementById('add-slot-mode-single');
    var btnB   = document.getElementById('add-slot-mode-batch');
    var action = document.getElementById('add-slot-action');
    var submit = document.getElementById('add-slot-submit');
    var dept   = document.getElementById('add-slot-dept');
    if (!single || !batch) return;

    var isBatch = mode === 'batch';
    single.style.display = isBatch ? 'none' : 'flex';
    batch.style.display  = isBatch ? 'flex' : 'none';
    if (btnS) btnS.classList.toggle('active', !isBatch);
    if (btnB) btnB.classList.toggle('active',  isBatch);
    if (action) action.value = isBatch ? 'batch_create_slots' : 'add_slot';
    if (submit) submit.textContent = isBatch ? 'Create Slots' : 'Add Slot';

    // Disable inputs in the inactive block so they don't post and don't
    // block submission via HTML5 `required` validation.
    document.querySelectorAll('#add-slot-single-fields [name]').forEach(function(el){ el.disabled = isBatch; });
    document.querySelectorAll('#add-slot-batch-fields  [name]').forEach(function(el){ el.disabled = !isBatch; });

    // The shared College/Department field posts as `department` for the
    // single-slot handler and `batch_department` for the batch handler.
    if (dept) dept.name = isBatch ? 'batch_department' : 'department';
}
document.addEventListener('DOMContentLoaded', function(){ setSlotMode('single'); });

function addBatchRoom() {
    var list = document.getElementById('batch-rooms-list');
    if (!list) return;
    var wrapper = document.createElement('div');
    wrapper.style.cssText = 'display:flex;gap:var(--space-2);align-items:center';

    var input = document.createElement('input');
    input.type        = 'text';
    input.name        = 'batch_rooms[]';
    input.className   = 'form-control';
    input.placeholder = 'e.g. Room 102';
    input.required    = true;
    input.style.flex  = '1';

    var removeBtn = document.createElement('button');
    removeBtn.type      = 'button';
    removeBtn.className = 'btn-icon';
    removeBtn.title     = 'Remove';
    removeBtn.style.cssText = 'color:var(--error);padding:var(--space-1);flex-shrink:0';
    removeBtn.innerHTML = '&times;';
    removeBtn.onclick   = function () { wrapper.remove(); };

    wrapper.appendChild(input);
    wrapper.appendChild(removeBtn);
    list.appendChild(wrapper);
    input.focus();
}

function openEditExamSlot(payload) {
    if (!payload) return;
    document.getElementById('edit-slot-id').value   = payload.id;
    document.getElementById('edit-slot-date').value = payload.exam_date;
    document.getElementById('edit-slot-time').value = payload.slot_time || '';
    document.getElementById('edit-slot-end').value  = payload.end_time  || '';
    document.getElementById('edit-slot-room').value = payload.room_label || '';
    document.getElementById('edit-slot-cap').value  = payload.capacity || '';

    var deptSel = document.getElementById('edit-slot-dept');
    if (deptSel) deptSel.value = payload.department || '';

    // Wire the inline delete button to the same slot id
    var delIn = document.getElementById('delete-slot-id');
    if (delIn) delIn.value = payload.id;
    var delBtn = document.getElementById('edit-slot-delete-btn');
    if (delBtn) {
        delBtn.disabled = (parseInt(payload.filled, 10) || 0) > 0;
        delBtn.title    = delBtn.disabled
            ? 'Cannot delete a slot with applicants assigned. Unassign them first.'
            : '';
    }

    document.getElementById('edit-slot-modal').style.display = 'flex';
}

// ── Per-room access code (Chunk 7) ────────────────────────────
//
// Drives the proctor-facing code panel on the slot roster page,
// plus the small "code active" badge on the college slot grid.

function _csrfPair() {
    var i = document.querySelector('input[name^="_csrf"]');
    return i ? { name: i.name, value: i.value } : null;
}

function _formatMMSS(s) {
    if (s < 0) s = 0;
    var m = Math.floor(s / 60);
    var r = s % 60;
    return (m < 10 ? '0' : '') + m + ':' + (r < 10 ? '0' : '') + r;
}

// Roster countdown — single slot, controls visible if you can generate
var _slotCodeTimer = null;
function startSlotCodeCountdown(secsLeft) {
    if (_slotCodeTimer) clearInterval(_slotCodeTimer);
    var timerEl   = document.getElementById('slot-code-timer');
    var displayEl = document.getElementById('slot-code-display');
    var extendBtn = document.getElementById('slot-code-extend-btn');
    var genBtn    = document.getElementById('slot-code-generate-btn');
    if (!timerEl) return;

    function render(s) {
        timerEl.classList.remove('is-warn', 'is-expired');
        if (s > 0) {
            timerEl.style.display = '';
            timerEl.textContent = 'Expires in ' + _formatMMSS(s);
            if (s <= 60) timerEl.classList.add('is-warn');
            if (extendBtn) extendBtn.style.display = 'inline-flex';
            if (genBtn)    genBtn.style.display    = 'inline-flex';
        } else {
            timerEl.style.display = '';
            timerEl.textContent = 'Expired — reissuing…';
            timerEl.classList.add('is-expired');
            if (displayEl) {
                displayEl.classList.add('is-empty');
                displayEl.textContent = 'No active code';
            }
            if (extendBtn) extendBtn.style.display = 'none';
            if (genBtn)    genBtn.style.display    = 'none';
        }
    }
    render(secsLeft);
    if (secsLeft > 0) {
        _slotCodeTimer = setInterval(function () {
            secsLeft--;
            render(secsLeft);
            if (secsLeft <= 0) {
                clearInterval(_slotCodeTimer); _slotCodeTimer = null;
                // Auto-refresh so the server-side auto-issue logic on
                // staff_slots.php picks up where we left off and rolls
                // a fresh 5-minute code without proctor intervention.
                setTimeout(function () { location.reload(); }, 800);
            }
        }, 1000);
    }
}

// Auto-start the roster countdown if the page rendered with an active code.
(function () {
    var t = document.getElementById('slot-code-timer');
    if (!t) return;
    var s = parseInt(t.getAttribute('data-secs-left') || '0', 10);
    if (s > 0) startSlotCodeCountdown(s);
})();

async function _postSlotCodeAction(action) {
    var panel = document.getElementById('slot-code-panel');
    if (!panel) return;
    var slotId = panel.getAttribute('data-slot-id');
    var csrf   = _csrfPair();
    if (!csrf) return alert('Missing CSRF token. Please reload the page.');

    var fd = new FormData();
    fd.append('action', action);
    fd.append('slot_id', slotId);
    fd.append(csrf.name, csrf.value);

    try {
        var resp = await fetch(location.href, {
            method: 'POST', body: fd,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        var d = await resp.json();
        if (!d.ok) { alert(d.error || 'Could not update code.'); return; }

        var displayEl = document.getElementById('slot-code-display');
        if (displayEl) {
            displayEl.classList.remove('is-empty');
            displayEl.textContent = d.password;
        }
        startSlotCodeCountdown(d.expires_in);
    } catch (e) {
        alert('Network error. Try again.');
    }
}

function generateSlotCode() {
    if (document.getElementById('slot-code-display') &&
        !document.getElementById('slot-code-display').classList.contains('is-empty')) {
        if (!confirm('Issue a new code? The current code will be replaced immediately and applicants who have not yet entered the old code will be locked out.')) return;
    }
    _postSlotCodeAction('generate_slot_code');
}
function extendSlotCode() { _postSlotCodeAction('extend_slot_code'); }

// Inline edit of room label on the roster page
function startRoomLabelEdit() {
    var v = document.getElementById('room-label-view');
    var e = document.getElementById('room-label-edit');
    if (!v || !e) return;
    v.style.display = 'none';
    e.style.display = 'inline-flex';
    var inp = document.getElementById('room-label-input');
    if (inp) { inp.focus(); inp.select(); }
}
function cancelRoomLabelEdit() {
    var v = document.getElementById('room-label-view');
    var e = document.getElementById('room-label-edit');
    if (!v || !e) return;
    e.style.display = 'none';
    v.style.display = 'inline-flex';
}
async function saveRoomLabel(slotId) {
    var inp = document.getElementById('room-label-input');
    if (!inp) return;
    var newLabel = inp.value.trim();
    if (!newLabel) { alert('Room label cannot be empty.'); return; }

    var csrf = _csrfPair();
    if (!csrf) return alert('Missing CSRF token. Please reload the page.');

    var fd = new FormData();
    fd.append('action', 'update_room_label');
    fd.append('slot_id', slotId);
    fd.append('room_label', newLabel);
    fd.append(csrf.name, csrf.value);

    try {
        var resp = await fetch(location.href, {
            method: 'POST', body: fd,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        var d = await resp.json();
        if (!d.ok) { alert(d.error || 'Could not rename room.'); return; }
        var t = document.getElementById('room-label-text');
        if (t) t.textContent = d.room_label;
        cancelRoomLabelEdit();
    } catch (e) {
        alert('Network error. Try again.');
    }
}

// Card-grid badges — per-card lightweight countdowns, no buttons.
(function () {
    var badges = document.querySelectorAll('[data-card-pw-end]');
    if (!badges.length) return;
    function tick() {
        var now = Math.floor(Date.now() / 1000);
        badges.forEach(function (b) {
            var endTs = parseInt(b.getAttribute('data-card-pw-end') || '0', 10);
            var left  = Math.max(0, endTs - now);
            var t = b.querySelector('.card-pw-timer');
            if (t) t.textContent = _formatMMSS(left);
            if (left <= 60) b.classList.add('is-warn');
            if (left <= 0)  b.style.display = 'none';
        });
    }
    tick();
    setInterval(tick, 1000);
})();
</script>

<?php
$content   = ob_get_clean();
$pageTitle = 'Exam Room Slots';
$activeNav = 'exam';
$pageWide  = true; // all three modes use the wide-page container
include VIEWS_PATH . '/layouts/app.php';
