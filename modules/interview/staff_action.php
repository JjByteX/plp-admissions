<?php
// ============================================================
// modules/interview/staff_action.php
// M5 — Staff: POST handler for interview actions
//
// Queue actions (mark_completed, mark_no_show, start_interview,
//               save_notes) use {id} as interview_queue.id
//
// Slot actions (delete_slot, close_slot, open_slot)
//               use {id} as interview_slots.id
// ============================================================

require_once CORE_PATH . '/bootstrap.php';
Auth::requireRole(ROLE_STAFF, ROLE_SSO, ROLE_ADMIN);
csrf_check();

$db      = db();
$staffId = Auth::id();
$id      = (int)($_GET['id'] ?? 0);
$action  = $_POST['action'] ?? '';

switch ($action) {

    // ----------------------------------------------------------------
    // Queue: mark completed — advance applicant to result stage
    // ----------------------------------------------------------------
    case 'mark_completed':
        // Fetch applicant WITHOUT ownership filter — queue completion and
        // applicant status advancement must always be atomic together,
        // regardless of which staff member clicks the button.
        $stmt = $db->prepare(
            'SELECT q.applicant_id FROM interview_queue q WHERE q.id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        if (!$row) {
            Session::flash('error', 'Interview queue entry not found.');
            redirect('/staff/interviews/queue');
        }

        $db->prepare('UPDATE interview_queue SET status="completed" WHERE id=?')
           ->execute([$id]);

        // Interview completion no longer auto-creates an admission_results
        // row — SSO releases manually from the Results page after seeing
        // the Pass/Reject eval. We still leave the applicant in 'interview'
        // so the Results page bucket logic picks it up correctly.
        audit_log('interview_completed', "Marked interview queue ID {$id} as completed", 'interview_queue', $id);
        Session::flash('success', 'Interview marked as completed.');
        redirect('/staff/interviews/queue');
        break;

    // ----------------------------------------------------------------
    // Queue: complete with inline evaluation (Pass/Reject + notes)
    // ----------------------------------------------------------------
    case 'complete_with_evaluation':
        $evalResult = strtolower(trim($_POST['evaluation_result'] ?? ''));
        $evalNotes  = trim($_POST['interview_notes'] ?? '');

        if ($evalResult !== 'pass' && $evalResult !== 'reject') {
            Session::flash('error', 'Please select Pass or Reject before completing.');
            redirect('/staff/interviews/queue');
        }

        $stmt = $db->prepare('SELECT q.applicant_id FROM interview_queue q WHERE q.id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        if (!$row) {
            Session::flash('error', 'Interview queue entry not found.');
            redirect('/staff/interviews/queue');
        }

        // Update queue: status, notes, evaluation, attendance
        $db->prepare(
            'UPDATE interview_queue
             SET status = "completed",
                 interview_notes = ?,
                 evaluation_result = ?,
                 interview_status = "completed",
                 attendance_status = "present",
                 evaluated_at = NOW()
             WHERE id = ?'
        )->execute([$evalNotes ?: null, $evalResult, $id]);

        // Two-gate flow: the Pass/Reject evaluation here is Gate 1 (the
        // interviewer's call). The applicant moves to 'released' stage
        // so they appear on the Results page. SSO performs Gate 2 (Release)
        // on the Results page — the final confirmation that actually
        // creates an admission_results row and emails the applicant.
        $db->prepare(
            'UPDATE applicants SET overall_status = "released"
              WHERE id = ? AND overall_status IN ("interview","exam")'
        )->execute([$row['applicant_id']]);

        audit_log('interview_completed_with_eval',
            "Completed interview queue ID {$id}: {$evalResult}",
            'interview_queue', $id);
        Session::flash('success', 'Interview completed — ' . ucfirst($evalResult) . '.');
        redirect('/staff/interviews/queue');
        break;

    // ----------------------------------------------------------------
    // Queue: mark no-show
    // ----------------------------------------------------------------
    case 'mark_no_show':
        // After the desk/session merge, an interviewer is identified by
        // assigned_to with created_by fallback for legacy rows.
        //
        // Set the full canonical absent state (status='no_show',
        // interview_status='absent', attendance_status='absent',
        // evaluated_at=NOW) so the row shows up on the Absent Students
        // tab — the previous version only set q.status, which left the
        // absent_tab query (WHERE q.interview_status='absent') missing
        // this row.
        $db->prepare(
            'UPDATE interview_queue q
             JOIN   interview_slots s ON s.id = q.slot_id
             SET    q.status            = "no_show",
                    q.interview_status  = "absent",
                    q.attendance_status = "absent",
                    q.evaluated_at      = NOW()
             WHERE  q.id = ? AND COALESCE(s.assigned_to, s.created_by) = ?'
        )->execute([$id, $staffId]);
        audit_log('interview_no_show', "Marked interview queue ID {$id} as no-show", 'interview_queue', $id);

        // B1: Auto-reschedule no-show to next available slot
        $qRow = $db->prepare('SELECT applicant_id FROM interview_queue WHERE id = ?');
        $qRow->execute([$id]);
        $noShowApplicantId = (int)($qRow->fetchColumn() ?: 0);
        $rescheduledMsg = '';
        if ($noShowApplicantId > 0) {
            // Notify staff
            $nameStmt = $db->prepare('SELECT u.name FROM applicants a JOIN users u ON u.id=a.user_id WHERE a.id=?');
            $nameStmt->execute([$noShowApplicantId]);
            $noShowName = $nameStmt->fetchColumn() ?: 'Applicant';
            notify_staff_no_show($noShowApplicantId, $noShowName);

            $newSlot = auto_reschedule_noshow($noShowApplicantId, $staffId);
            if ($newSlot) {
                $rescheduledMsg = ' Auto-rescheduled to a new slot.';
            }
        }
        Session::flash('success', 'Marked as no-show.' . $rescheduledMsg);
        redirect('/staff/interviews/queue');
        break;

    // ----------------------------------------------------------------
    // (Legacy 'staff_checkin' action removed — students are now
    // auto-checked-in at slot assignment time, see
    // core/interview_scheduler.php :: assign_interview_slot(). The
    // live queue UI no longer renders a Check In button.)
    // ----------------------------------------------------------------

    // ----------------------------------------------------------------
    // Queue: start interview (checked_in → in_progress)
    // ----------------------------------------------------------------
    case 'start_interview':
        $db->prepare(
            'UPDATE interview_queue q
             JOIN   interview_slots s ON s.id = q.slot_id
             SET    q.status = "in_progress"
             WHERE  q.id = ? AND q.status = "checked_in"
               AND  COALESCE(s.assigned_to, s.created_by) = ?'
        )->execute([$id, $staffId]);
        audit_log('interview_started', "Started interview for queue ID {$id}", 'interview_queue', $id);
        Session::flash('success', 'Interview started.');
        redirect('/staff/interviews/queue');
        break;

    // ----------------------------------------------------------------
    // Queue: save evaluation notes
    // ----------------------------------------------------------------
    case 'save_notes':
        $notes = trim($_POST['interview_notes'] ?? '');
        $db->prepare(
            'UPDATE interview_queue q
             JOIN   interview_slots s ON s.id = q.slot_id
             SET    q.interview_notes = ?
             WHERE  q.id = ? AND COALESCE(s.assigned_to, s.created_by) = ?'
        )->execute([$notes ?: null, $id, $staffId]);
        audit_log('interview_notes_saved', "Saved notes for interview queue ID {$id}", 'interview_queue', $id);
        Session::flash('success', 'Notes saved.');
        redirect('/staff/interviews/queue');
        break;

    // ----------------------------------------------------------------
    // Slot: delete (only if no queue entries)
    // ----------------------------------------------------------------
    case 'delete_slot':
        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM interview_queue WHERE slot_id = ?'
        );
        $stmt->execute([$id]);
        if ((int)$stmt->fetchColumn() > 0) {
            Session::flash('error', 'Cannot delete a session that already has bookings.');
            redirect('/staff/interviews');
        }
        // Owner = the assigned interviewer (with created_by fallback for legacy rows)
        $db->prepare(
            'DELETE FROM interview_slots
             WHERE id = ? AND COALESCE(assigned_to, created_by) = ?'
        )->execute([$id, $staffId]);
        audit_log('interview_slot_deleted', "Deleted interview slot ID {$id}", 'interview_slot', $id);
        Session::flash('success', 'Session deleted.');
        redirect('/staff/interviews');
        break;

    // ----------------------------------------------------------------
    // Slot: close
    // ----------------------------------------------------------------
    case 'close_slot':
        // Verify ownership (or admin) before touching anything.
        // After the desk/session merge, the "owner" is the assigned interviewer
        // (with created_by fallback for legacy rows).
        $own = $db->prepare('SELECT COALESCE(assigned_to, created_by) FROM interview_slots WHERE id = ?');
        $own->execute([$id]);
        $ownerId = (int)($own->fetchColumn() ?: 0);
        $isAdmin = (Auth::user()['role'] ?? '') === ROLE_ADMIN;
        if ($ownerId !== $staffId && !$isAdmin) {
            Session::flash('error', 'You can only close your own sessions.');
            redirect('/staff/interviews');
        }

        // Closing a slot means booked applicants need a new one —
        // cancel_interview_slot() closes the slot AND auto-reschedules
        // every 'scheduled' row into another open slot (same dept first).
        try {
            $rebooked = cancel_interview_slot($id, $staffId);
            if ($rebooked > 0) {
                Session::flash('success',
                    "Session closed. {$rebooked} applicant(s) were automatically rescheduled.");
            } else {
                Session::flash('success', 'Session closed. Students can no longer book it.');
            }
        } catch (Throwable $e) {
            error_log('close_slot failed: ' . $e->getMessage());
            Session::flash('error', 'Could not close the session. Please try again.');
        }
        redirect('/staff/interviews');
        break;

    // ----------------------------------------------------------------
    // Slot: reopen
    // ----------------------------------------------------------------
    case 'open_slot':
        $db->prepare(
            'UPDATE interview_slots SET status="open"
             WHERE id=? AND COALESCE(assigned_to, created_by) = ?'
        )->execute([$id, $staffId]);
        audit_log('interview_slot_reopened', "Reopened interview slot ID {$id}", 'interview_slot', $id);
        Session::flash('success', 'Session reopened.');
        redirect('/staff/interviews');
        break;

    // ----------------------------------------------------------------
    // Queue: reassign student to a different session
    // (legacy action name 'reassign_desk' kept for backward-compat URLs)
    // ----------------------------------------------------------------
    case 'reassign_desk':
    case 'reassign_session':
        $isAdmin = (Auth::user()['role'] ?? '') === ROLE_ADMIN;
        $targetSlotId = (int)($_POST['target_slot_id'] ?? 0);

        if (!$isAdmin) {
            Session::flash('error', 'Only admins can reassign students.');
            redirect('/staff/interviews/queue');
        }

        // Load queue entry
        $qStmt = $db->prepare(
            'SELECT q.*, s.department FROM interview_queue q
             JOIN interview_slots s ON s.id = q.slot_id
             WHERE q.id = ? AND q.status IN ("scheduled","checked_in")'
        );
        $qStmt->execute([$id]);
        $qEntry = $qStmt->fetch();

        if (!$qEntry) {
            Session::flash('error', 'Queue entry not found or not in a reassignable state.');
            redirect('/staff/interviews/queue');
        }

        // Verify target slot is in the same department and has capacity
        $tStmt = $db->prepare(
            'SELECT s.id, s.department, s.capacity,
                    (SELECT COUNT(*) FROM interview_queue q2 WHERE q2.slot_id = s.id
                     AND q2.interview_status IN ("pending","completed")) AS booked
             FROM interview_slots s WHERE s.id = ? AND s.status = "open"'
        );
        $tStmt->execute([$targetSlotId]);
        $target = $tStmt->fetch();

        if (!$target || $target['department'] !== $qEntry['department']) {
            Session::flash('error', 'Target slot invalid or not in the same college.');
            redirect('/staff/interviews/queue');
        }

        if ((int)$target['booked'] >= (int)$target['capacity']) {
            Session::flash('error', 'Target slot is full.');
            redirect('/staff/interviews/queue');
        }

        $db->prepare('UPDATE interview_queue SET slot_id = ? WHERE id = ?')
           ->execute([$targetSlotId, $id]);

        audit_log('interview_reassigned',
            "Reassigned queue #{$id} from slot #{$qEntry['slot_id']} to slot #{$targetSlotId}",
            'interview_queue', $id);
        Session::flash('success', 'Student reassigned to new session.');
        redirect('/staff/interviews/queue');
        break;

    default:
        redirect('/staff/interviews');
}
