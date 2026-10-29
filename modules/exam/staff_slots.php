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
//   ?slot=SLOT_ID          → Roster table for one slot (auto-paginated
//                            .auto-table with search + type filter, same
//                            as the audit log), with unassign + edit
//                            actions.
//
// Layout intentionally mirrors modules/interview/staff_setup.php
// so colleges, slots, and rosters all share the same family of
// card-grids and tables.
// ============================================================

require_once CORE_PATH . '/bootstrap.php';
Auth::requireRole(ROLE_PROCTOR, ROLE_SSO, ROLE_DEAN, ROLE_ADMIN);

$db          = db();
$staffId     = Auth::id();
$role        = Auth::role();
$isAdmin     = $role === ROLE_ADMIN;
$isSSO       = $role === ROLE_SSO;
$isProctor = $role === ROLE_PROCTOR;
// Admin and SSO can manage slots across all departments. Dean is
// read-only oversight. Proctor can only generate access codes for
// slots in their own department.
$canManage = $isAdmin || $isSSO;
$staffDept = $canManage ? '' : (string) user_department($staffId);
// Per-slot "can generate code" check. Admin and SSO can do it for
// any room. Proctors can do it only for slots in their own
// department.
$canGenerateCodeFor = function (string $slotDept) use ($isAdmin, $isSSO, $isProctor, $staffDept): bool {
    if ($isAdmin || $isSSO) return true;
    if ($isProctor && $slotDept !== '' && $slotDept === $staffDept) return true;
    return false;
};
$errors    = [];
$success   = [];

$schoolYear   = school_setting('current_school_year', date('Y') . '-' . (date('Y') + 1));
$activeExam   = $db->query('SELECT * FROM exams WHERE is_active=1 LIMIT 1')->fetch();

// ── One-time filled resync ────────────────────────────────────────
// Recalculate filled from actual applicant_exam_slots rows to fix
// any drift caused by manual DB edits, old bugs, or import scripts.
// This is cheap (one UPDATE) and idempotent — safe to run on every page load.
try {
    $db->exec(
        'UPDATE exam_slot_schedule ess
            SET filled = (
                SELECT COUNT(*) FROM applicant_exam_slots aes WHERE aes.slot_id = ess.id
            )'
    );
} catch (\Throwable $e) {
    // Non-fatal — log and continue
    error_log('filled resync failed: ' . $e->getMessage());
}
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
$collegeParam = get_str('college');
$slotIdParam  = get_int('slot');

// Admin and SSO see the cross-college selector; Dean and Proctor
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

// Shared ajax-or-flash-redirect responder. On AJAX requests, sends a
// JSON body and exits. Otherwise flashes a message and redirects back
// to the calling page. Used by every slot mutator below so the
// response pattern isn't retyped per action.
function _slots_respond(bool $ok, string $msg, string $college, int $slotId, array $extra = []): void {
    if (is_ajax_request()) {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        echo json_encode(array_merge(['ok' => $ok, 'error' => $ok ? null : $msg], $extra));
        exit;
    }
    Session::flash($ok ? 'success' : 'error', $msg);
    _slots_redirect_back($college, $slotId);
}

if (($action = require_post_action()) !== null) {

    // Most write actions redirect after success; pick the redirect target
    // up front based on the form's hidden context fields (with the URL
    // params as fallback).
    $ctxCollege = post_str('ctx_college', $collegeParam);
    $ctxSlotId  = post_int('ctx_slot', $slotIdParam);

    // ── Generate / extend per-room access code ───────────────
    //
    // Each room's proctor can independently issue an 8-character
    // code valid for EXAM_PASSWORD_EXPIRY_SECONDS (5 minutes). Admin
    // and SSO can do this for any room; Proctors only for slots
    // in their own department. Handled before the canManage gate so
    // Proctors are not redirected away.
    if ($action === 'generate_slot_code' || $action === 'extend_slot_code') {
        $slotId = post_int('slot_id');
        $stmt   = $db->prepare(
            'SELECT id, department, room_label, access_password
               FROM exam_slot_schedule WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$slotId]);
        $slotRow = $stmt->fetch();

        $isAjax = is_ajax_request();
        $respond = fn(bool $ok, string $msg = '', array $extra = []) =>
            _slots_respond($ok, $msg, $ctxCollege, $ctxSlotId, $extra);

        if (!$slotRow) {
            $respond(false, 'Slot not found.');
        }
        if (!$canGenerateCodeFor((string) $slotRow['department'])) {
            $respond(false, 'You can only generate codes for rooms in your own college.');
        }

        if ($action === 'extend_slot_code') {
            if (empty($slotRow['access_password'])) {
                $respond(false, 'No code set yet - generate one first.');
            }
            $db->prepare('UPDATE exam_slot_schedule SET password_issued_at = NOW() WHERE id = ?')
               ->execute([$slotId]);
            audit_log(AuditAction::EXAM_SLOT_CODE_EXTENDED,
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
            audit_log(AuditAction::EXAM_SLOT_CODE_GENERATED,
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
            _slots_respond(false, 'Read-only access - only SSO and Admin can rename rooms.', $ctxCollege, $ctxSlotId);
        }
        $slotId  = post_int('slot_id');
        $newRoom = post_str('room_label');
        if (!$slotId || $newRoom === '') {
            if (is_ajax_request()) {
                _slots_respond(false, 'Room label cannot be empty.', $ctxCollege, $ctxSlotId);
            }
            $errors[] = 'Room label cannot be empty.';
        } else {
            $db->prepare('UPDATE exam_slot_schedule SET room_label = ? WHERE id = ?')
               ->execute([$newRoom, $slotId]);
            audit_log(AuditAction::EXAM_SLOT_ROOM_RENAMED, "Slot {$slotId} room label → {$newRoom}");
            _slots_respond(true, 'Room label updated.', $ctxCollege, $ctxSlotId, ['room_label' => $newRoom]);
        }
    }

    // Only Admin and SSO can mutate exam slots from this point on.
    // Dean and Proctor reach this page in read-only mode — if any
    // other action posts through, reject it.
    if (!$canManage) {
        Session::flash('error', 'Read-only - only SSO and Admin can modify exam slots.');
        _slots_redirect_back($ctxCollege, $ctxSlotId);
    }

    // ── Update exam config (room default + daily cap) ────────────
    if ($action === 'update_exam_config') {
        $upsert = 'INSERT INTO school_settings (setting_key, setting_value) VALUES (?,?)
                   ON CONFLICT (setting_key) DO UPDATE SET setting_value = EXCLUDED.setting_value';
        $roomCap  = max(1, post_int('exam_room_capacity', 35));
        $dailyCap = max(1, post_int('exam_daily_cap', 3000));
        $db->prepare($upsert)->execute(['exam_room_capacity', $roomCap]);
        $db->prepare($upsert)->execute(['exam_daily_cap',     $dailyCap]);
        audit_log(AuditAction::EXAM_CONFIG_UPDATED, "Room cap={$roomCap}, Daily cap={$dailyCap}");
        Session::flash('success', 'Exam config saved.');
        _slots_redirect_back($ctxCollege, $ctxSlotId);
    }

    // ── Add a new exam slot ──────────────────────────────────────
    if ($action === 'add_slot') {
        $date     = post_str('exam_date');
        $time     = post_str('slot_time', '08:00');
        $endTime  = post_str('end_time');
        $room     = post_str('room_label');
        $capacity = post_int('capacity', 35);
        $slotDept = $canManage
            ? post_str('department')
            : user_department($staffId);

        // Default close time = opens + 90 minutes if the form didn't
        // supply one (older form variants, JS off, etc).
        if ($endTime === '') {
            $endTime = date('H:i', strtotime($time . ' +90 minutes'));
        }

        if (!$date)              $errors[] = 'Exam date is required.';
        if (!$room)              $errors[] = 'Room label is required.';
        if (!$slotDept)          $errors[] = 'College / Department is required.';
        if ($capacity < 1)       $errors[] = 'Capacity must be at least 1.';
        if ($capacity > 500)     $errors[] = 'Capacity above 500 is unrealistic.';
        if (strtotime($endTime) <= strtotime($time)) {
            $errors[] = 'Close time must be after the start time.';
        }

        if (!$errors) {
            $db->prepare(
                'INSERT INTO exam_slot_schedule
                    (exam_id, exam_date, slot_time, end_time, room_label, department, capacity, school_year, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([$activeExamId, $date, $time . ':00', $endTime . ':00', $room, $slotDept, $capacity, $schoolYear, $staffId]);
            audit_log(AuditAction::EXAM_SLOT_ADDED,
                "Added slot: {$date} {$time}-{$endTime} {$room} [{$slotDept}] (cap {$capacity})");

            // Backfill: any applicant who advanced to exam stage
            // before this slot existed should now get auto-assigned.
            // Without this, those students stay on the "Awaiting Slot
            // Assignment" page until someone manually intervenes.
            $assigned = 0;
            if (function_exists('backfill_exam_slot_assignments')) {
                $assigned = backfill_exam_slot_assignments();
            }

            Session::flash(
                'success',
                "Slot added: {$room} ({$slotDept}) on " . date('M j, Y', strtotime($date)) . " at {$time}-{$endTime}."
                . ($assigned > 0 ? " Auto-assigned {$assigned} waiting applicant(s)." : '')
            );
            _slots_redirect_back($slotDept ?: $ctxCollege);
        }
    }

    // ── Edit an existing slot ───────────────────────────────────
    if ($action === 'edit_slot') {
        $slotId   = post_int('slot_id');
        $date     = post_str('exam_date');
        $time     = post_str('slot_time', '08:00');
        $endTime  = post_str('end_time');
        $room     = post_str('room_label');
        $capacity = post_int('capacity', 35);
        $slotDept = $canManage
            ? post_str('department')
            : user_department($staffId);

        // Default close time = opens + 90 minutes if the form didn't
        // supply one (older form variants, JS off, etc).
        if ($endTime === '') {
            $endTime = date('H:i', strtotime($time . ' +90 minutes'));
        }

        if (!$slotId)          $errors[] = 'Invalid slot.';
        if (!$date)            $errors[] = 'Exam date is required.';
        if (!$room)            $errors[] = 'Room label is required.';
        if (!$slotDept)        $errors[] = 'College / Department is required.';
        if ($capacity < 1)     $errors[] = 'Capacity must be at least 1.';
        if ($capacity > 500)   $errors[] = 'Capacity above 500 is unrealistic.';
        if (strtotime($endTime) <= strtotime($time)) {
            $errors[] = 'Close time must be after the start time.';
        }

        if (!$errors) {
            $stmt = $db->prepare('SELECT COUNT(*) FROM applicant_exam_slots WHERE slot_id=?');
            $stmt->execute([$slotId]);
            $filled = (int)$stmt->fetchColumn();
            if ($capacity < $filled) {
                $errors[] = "Cannot shrink capacity below {$filled} (currently filled).";
            } else {
                $db->prepare(
                    'UPDATE exam_slot_schedule
                        SET exam_date=?, slot_time=?, end_time=?, room_label=?, department=?, capacity=?
                      WHERE id=?'
                )->execute([$date, $time . ':00', $endTime . ':00', $room, $slotDept, $capacity, $slotId]);
                audit_log(AuditAction::EXAM_SLOT_EDITED,
                    "Edited slot {$slotId}: {$date} {$time}-{$endTime} {$room} [{$slotDept}] (cap {$capacity})");
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
        $slotId = post_int('slot_id');
        $cap    = max(1, post_int('capacity', 35));
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
                audit_log(AuditAction::EXAM_SLOT_CAPACITY_CHANGED, "Slot {$slotId} capacity → {$cap}");
                Session::flash('success', 'Capacity updated.');
                _slots_redirect_back($ctxCollege, $ctxSlotId);
            }
        }
    }

    // ── Delete a slot (only if empty) ───────────────────────────
    if ($action === 'delete_slot') {
        $slotId = post_int('slot_id');
        if ($slotId) {
            $stmt = $db->prepare('SELECT COUNT(*) FROM applicant_exam_slots WHERE slot_id=?');
            $stmt->execute([$slotId]);
            if ((int)$stmt->fetchColumn() > 0) {
                $errors[] = 'Cannot delete a slot that has applicants assigned. Unassign them first.';
            } else {
                $db->prepare('DELETE FROM exam_slot_schedule WHERE id=?')->execute([$slotId]);
                audit_log(AuditAction::EXAM_SLOT_DELETED, "Deleted slot {$slotId}");
                Session::flash('success', 'Slot deleted.');
                _slots_redirect_back($ctxCollege);
            }
        }
    }

    // ── Bulk delete slots (from select-mode bar) ────────────────
    if ($action === 'delete_slots_bulk') {
        $rawIds = post_str('ids');
        $ids    = parse_id_list($rawIds);
        if (empty($ids)) {
            Session::flash('error', 'No slots selected.');
            _slots_redirect_back($ctxCollege);
        }

        // Skip slots that have at least one applicant assigned — same rule
        // the single-row delete enforces. Only the empty ones are removed.
        $bulkCfg = [
            'table' => 'exam_slot_schedule', 'child' => 'applicant_exam_slots', 'fk' => 'slot_id',
            'noun' => 'slot', 'audit' => 'exam_slots_bulk_deleted',
            'skipNote' => 'have applicants', 'allNote' => 'have applicants',
        ];
        flash_bulk_delete_result(bulk_delete_empty($db, $bulkCfg, $ids), $bulkCfg);
        _slots_redirect_back($ctxCollege);
    }

    // ── Assign applicant to slot ────────────────────────────────
    if ($action === 'assign') {
        $applicantId = post_int('applicant_id');
        $slotId      = post_int('slot_id');
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
                    $stmt = $db->prepare(
                        'SELECT a.overall_status, a.course_applied, u.department
                           FROM applicants a
                           JOIN users u ON u.id = a.user_id
                          WHERE a.id = ?'
                    );
                    $stmt->execute([$applicantId]);
                    $applRow = $stmt->fetch();
                    if (!$applRow) {
                        $errors[] = 'Applicant not found.';
                    } elseif ($applRow['overall_status'] !== 'exam') {
                        $errors[] = "Applicant is not at exam stage (currently: {$applRow['overall_status']}).";
                    } else {
                        // Cross-college guard. An applicant must only land in
                        // a slot for their own college, regardless of how
                        // they got here (manual assign, auto-assign,
                        // reschedule). If the slot's department doesn't
                        // match, refuse with a clear message rather than
                        // booking them into a foreign room.
                        $applDept = trim((string)($applRow['department'] ?? ''))
                                 ?: course_to_department((string)$applRow['course_applied']);
                        $slotDept = trim((string)($slot['department'] ?? ''));
                        if ($applDept !== '' && $slotDept !== '' && strtolower(trim($applDept)) !== strtolower(trim($slotDept))) {
                            $errors[] = "This slot is for {$slotDept}, but the applicant is in {$applDept}. "
                                      . "Create a slot for {$applDept} instead.";
                        } else {
                            $db->prepare(
                                'INSERT INTO applicant_exam_slots (applicant_id, slot_id, assigned_at)
                                 VALUES (?, ?, NOW())
                                 ON CONFLICT (applicant_id) DO UPDATE SET slot_id=EXCLUDED.slot_id, assigned_at=NOW()'
                            )->execute([$applicantId, $slotId]);
                            // Keep filled in sync so auto-assign queries stay accurate
                            $db->prepare('UPDATE exam_slot_schedule SET filled = filled + 1 WHERE id = ?')
                               ->execute([$slotId]);
                            audit_log(AuditAction::EXAM_SLOT_ASSIGNED,
                                "Assigned applicant {$applicantId} → slot {$slotId}",
                                'applicant', $applicantId);
                            Session::flash('success', 'Applicant assigned.');
                            _slots_redirect_back($slot['department'] ?: $ctxCollege, $ctxSlotId);
                        }
                    }
                }
            }
        }
    }

    // ── B3: Batch create exam slots ────────────────────────────
    if ($action === 'batch_create_slots') {
        $bStartDate = post_str('batch_start_date');
        $bEndDate   = post_str('batch_end_date');
        $bTime      = post_str('batch_time', '08:00');
        $bEndTime   = post_str('batch_end_time');
        $bRooms     = $_POST['batch_rooms'] ?? [];
        if (!is_array($bRooms)) $bRooms = [$bRooms];
        $bRooms     = array_filter(array_map('trim', $bRooms));
        $bCapacity  = max(1, (int)($_POST['batch_capacity'] ?? $examRoomCap ?? 35));
        $bDept      = $canManage ? post_str('batch_department') : user_department($staffId);
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
        $applicantId = post_int('applicant_id');
        if ($applicantId) {
            // Get the slot_id before deleting so we can decrement filled
            $slotLookup = $db->prepare('SELECT slot_id FROM applicant_exam_slots WHERE applicant_id = ? LIMIT 1');
            $slotLookup->execute([$applicantId]);
            $unassignSlotId = $slotLookup->fetchColumn();

            $db->prepare('DELETE FROM applicant_exam_slots WHERE applicant_id=?')
               ->execute([$applicantId]);

            // Keep filled in sync so auto-assign can find this freed seat
            if ($unassignSlotId) {
                $db->prepare('UPDATE exam_slot_schedule SET filled = GREATEST(filled - 1, 0) WHERE id = ?')
                   ->execute([$unassignSlotId]);
            }

            audit_log(AuditAction::EXAM_SLOT_UNASSIGNED, "Unassigned applicant {$applicantId} from their slot",
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
$slotDetail      = null;
$slotRoster      = [];

if ($mode === 'slots') {
    // College must be valid (or scoped user's own)
    if (!$canManage && $collegeParam !== $staffDept) {
        $collegeParam = $staffDept;
    }

    // Slots in this college for the active school year. The
    // pw_secs_left is computed in the database so PHP / DB timezone
    // differences can't skew the countdown.
    $stmt = $db->prepare(
        "SELECT s.id, s.exam_date, s.slot_time, s.end_time,
                s.room_label, s.department, s.capacity,
                s.access_password, s.password_issued_at,
                GREATEST(
                    0,
                    " . (int) EXAM_PASSWORD_EXPIRY_SECONDS . "
                        - TRUNC(EXTRACT(EPOCH FROM (NOW() - COALESCE(s.password_issued_at, TIMESTAMP '1970-01-01 00:00:00'))))
                ) AS pw_secs_left,
                (SELECT COUNT(*) FROM applicant_exam_slots WHERE slot_id = s.id) AS filled
           FROM exam_slot_schedule s
          WHERE s.school_year = ?
            AND s.department  = ?
          ORDER BY s.exam_date ASC, s.slot_time ASC, s.room_label ASC"
    );
    $stmt->execute([$schoolYear, $collegeParam]);
    $slotsForCollege = $stmt->fetchAll();

}

if ($mode === 'roster') {
    $stmt = $db->prepare(
        "SELECT s.*,
                GREATEST(
                    0,
                    " . (int) EXAM_PASSWORD_EXPIRY_SECONDS . "
                        - TRUNC(EXTRACT(EPOCH FROM (NOW() - COALESCE(s.password_issued_at, TIMESTAMP '1970-01-01 00:00:00'))))
                ) AS pw_secs_left,
                (SELECT COUNT(*) FROM applicant_exam_slots WHERE slot_id = s.id) AS filled
           FROM exam_slot_schedule s
          WHERE s.id = ?
          LIMIT 1"
    );
    $stmt->execute([$slotIdParam]);
    $slotDetail = $stmt->fetch();

    if (!$slotDetail) {
        fail('Slot not found.', '/staff/exam/slots');
    }

    // Staff can only see slots in their own college (unless they can
    // manage across colleges — admin or SSO).
    if (!$canManage && $slotDetail['department'] !== $staffDept) {
        fail('You can only view slots in your own college.', '/staff/exam/slots');
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
        audit_log(AuditAction::EXAM_SLOT_CODE_AUTO_GENERATED,
            "Auto-issued access code for slot {$slotDetail['id']} ({$slotDetail['room_label']})");

        // Reflect the freshly-issued code in the in-memory row so the
        // panel below renders with a full 5-minute countdown immediately.
        $slotDetail['access_password']    = $autoPwd;
        $slotDetail['password_issued_at'] = date('Y-m-d H:i:s');
        $slotDetail['pw_secs_left']       = EXAM_PASSWORD_EXPIRY_SECONDS;
    }

    // Roster table — search + applicant-type filter, paginated in SQL.
    // Same auto-fit scheme as the audit log: AutoPageSize (app.js) measures how
    // many fixed-height rows fit and reloads with ?per_page=; see
    // auto_per_page() in helpers.php.
    $rosterSearch = get_str('q');
    $rosterType   = get_str('type');
    if (!in_array($rosterType, ['freshman', 'transferee', 'foreign'], true)) {
        $rosterType = '';
    }
    $rosterPerPage = auto_per_page('exam-slot-roster');

    $rWhere  = ['aes.slot_id = :slot_id'];
    $rParams = [':slot_id' => $slotIdParam];
    if ($rosterSearch !== '') {
        // Distinct placeholder per column (emulated prepares + the pooler).
        $rCols  = ['u.name', 'u.first_name', 'u.last_name', 'u.email', 'a.course_applied'];
        $rLikes = [];
        foreach ($rCols as $i => $col) {
            $rLikes[]            = "{$col} ILIKE :q{$i}";
            $rParams[":q{$i}"]   = '%' . $rosterSearch . '%';
        }
        $rWhere[] = '(' . implode(' OR ', $rLikes) . ')';
    }
    if ($rosterType !== '') {
        $rWhere[]            = 'a.applicant_type = :atype';
        $rParams[':atype']   = $rosterType;
    }
    $rWhereStr = implode(' AND ', $rWhere);
    $rFrom     = 'FROM applicant_exam_slots aes
                  JOIN applicants a ON a.id = aes.applicant_id
                  JOIN users u      ON u.id = a.user_id';

    $rosterResult = paginate(
        $db,
        "SELECT COUNT(*) {$rFrom} WHERE {$rWhereStr}",
        "SELECT aes.applicant_id, aes.assigned_at,
                u.name AS student_name, a.course_applied, a.applicant_type,
                u.email AS student_email,
                u.first_name, u.middle_name, u.last_name, u.suffix
           {$rFrom}
          WHERE {$rWhereStr}
          ORDER BY u.last_name ASC, u.first_name ASC, u.name ASC, aes.applicant_id ASC",
        $rParams, max(1, (int)($_GET['page'] ?? 1)), $rosterPerPage
    );
    $slotRoster = $rosterResult['data'];
}

if ($mode === 'colleges') {
    // Per-college counts for the card grid.
    $collegeCounts = [];
    foreach ($departments as $dept) {
        $stmt = $db->prepare(
            "SELECT
                 COUNT(*)                                     AS total_slots,
                 SUM(CASE WHEN s.exam_date >= CURRENT_DATE THEN 1 ELSE 0 END) AS upcoming_slots,
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
include __DIR__ . '/_slots_view.php';
$content   = ob_get_clean();
$pageTitle = 'Exam Room Slots';
// College page: the heading is the college name, no subtitle.
$pageHeading = ($mode === 'slots') ? $collegeParam : (($mode === 'roster') ? 'Room Roster' : 'Exam Slots');
// Breadcrumb: Entrance Exam > Exam Slots > College > Room Roster. Proctors land
// straight on their own college's slots page, so the upper levels are only
// parents for managers.
$pageBreadcrumb = [];
if ($mode === 'colleges' && $canManage) {
    $pageBreadcrumb[] = ['label' => 'Entrance Exam', 'to' => '/staff/exam'];
} elseif ($mode === 'slots' && $canManage) {
    $pageBreadcrumb[] = ['label' => 'Entrance Exam', 'to' => '/staff/exam'];
    $pageBreadcrumb[] = ['label' => 'Exam Slots', 'to' => '/staff/exam/slots'];
} elseif ($mode === 'roster' && !empty($slotDetail)) {
    if ($canManage) {
        $pageBreadcrumb[] = ['label' => 'Entrance Exam', 'to' => '/staff/exam'];
        $pageBreadcrumb[] = ['label' => 'Exam Slots', 'to' => '/staff/exam/slots'];
    }
    $pageBreadcrumb[] = ['label' => (string)$slotDetail['department'], 'to' => '/staff/exam/slots?college=' . urlencode($slotDetail['department'])];
}
$pageActions = ($mode === 'roster' && !empty($slotDetail))
    ? ($canManage && !$isPast ? ui_button('Edit slot', ['icon' => 'ic_fluent_edit_24_regular', 'attrs' => ['onclick' => 'openEditExamSlot(' . $editPayload . ')']]) : '')
    : '';
$activeNav = 'exam';
$pageStyles  = ['css/pages/exam-slots.css'];
$pageScripts = ['js/pages/exam-slots.js'];
$pageWide  = true; // all three modes use the wide-page container
include VIEWS_PATH . '/layouts/app.php';