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
$id      = get_int('id');
$action  = post_str('action');

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
            fail('Interview queue entry not found.', '/staff/interviews/queue');
        }

        $db->prepare("UPDATE interview_queue SET status='completed' WHERE id=?")
           ->execute([$id]);

        // Interview completion no longer auto-creates an admission_results
        // row — SSO releases manually from the Results page after seeing
        // the Pass/Reject eval. We still leave the applicant in 'interview'
        // so the Results page bucket logic picks it up correctly.
        audit_log(AuditAction::INTERVIEW_COMPLETED, "Marked interview queue ID {$id} as completed", 'interview_queue', $id);
        ok('Interview marked as completed.', '/staff/interviews/queue');
        break;

    // ----------------------------------------------------------------
    // Queue: complete with inline evaluation (Pass/Reject + notes)
    // ----------------------------------------------------------------
    case 'complete_with_evaluation':
        $evalResult = strtolower(post_str('evaluation_result'));
        $evalNotes  = post_str('interview_notes');

        if ($evalResult !== 'pass' && $evalResult !== 'reject') {
            fail('Please select Pass or Decline before completing.', '/staff/interviews/queue');
        }

        $stmt = $db->prepare('SELECT q.applicant_id FROM interview_queue q WHERE q.id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        if (!$row) {
            fail('Interview queue entry not found.', '/staff/interviews/queue');
        }

        // Update queue: status, notes, evaluation, attendance
        $db->prepare(
            "UPDATE interview_queue
             SET status = 'completed',
                 interview_notes = ?,
                 evaluation_result = ?,
                 interview_status = 'completed',
                 attendance_status = 'present',
                 evaluated_at = NOW()
             WHERE id = ?"
        )->execute([$evalNotes ?: null, $evalResult, $id]);

        // Two-gate flow: the Pass/Reject evaluation here is Gate 1 (the
        // interviewer's call). The applicant moves to 'released' stage
        // so they appear on the Results page. SSO performs Gate 2 (Release)
        // on the Results page — the final confirmation that actually
        // creates an admission_results row and emails the applicant.
        $db->prepare(
            "UPDATE applicants SET overall_status = 'released'
              WHERE id = ? AND overall_status IN ('interview','exam')"
        )->execute([$row['applicant_id']]);

        audit_log(AuditAction::INTERVIEW_COMPLETED_WITH_EVAL,
            "Completed interview queue ID {$id}: {$evalResult}",
            'interview_queue', $id);
        ok('Interview completed - ' . ucfirst($evalResult) . '.', '/staff/interviews/queue');
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
            "UPDATE interview_queue q
             SET    status            = 'no_show',
                    interview_status  = 'absent',
                    attendance_status = 'absent',
                    evaluated_at      = NOW()
             FROM   interview_slots s
             WHERE  s.id = q.slot_id
               AND  q.id = ? AND COALESCE(s.assigned_to, s.created_by) = ?"
        )->execute([$id, $staffId]);
        audit_log(AuditAction::INTERVIEW_NO_SHOW, "Marked interview queue ID {$id} as no-show", 'interview_queue', $id);

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
        ok('Marked as no-show.' . $rescheduledMsg, '/staff/interviews/queue');
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
            "UPDATE interview_queue q
             SET    status = 'in_progress'
             FROM   interview_slots s
             WHERE  s.id = q.slot_id
               AND  q.id = ? AND q.status = 'checked_in'
               AND  COALESCE(s.assigned_to, s.created_by) = ?"
        )->execute([$id, $staffId]);
        audit_log(AuditAction::INTERVIEW_STARTED, "Started interview for queue ID {$id}", 'interview_queue', $id);
        ok('Interview started.', '/staff/interviews/queue');
        break;

    // ----------------------------------------------------------------
    // Queue: save evaluation notes
    // ----------------------------------------------------------------
    case 'save_notes':
        $notes = post_str('interview_notes');
        $db->prepare(
            'UPDATE interview_queue q
             SET    interview_notes = ?
             FROM   interview_slots s
             WHERE  s.id = q.slot_id
               AND  q.id = ? AND COALESCE(s.assigned_to, s.created_by) = ?'
        )->execute([$notes ?: null, $id, $staffId]);
        audit_log(AuditAction::INTERVIEW_NOTES_SAVED, "Saved notes for interview queue ID {$id}", 'interview_queue', $id);
        ok('Notes saved.', '/staff/interviews/queue');
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
            fail('Cannot delete a session that already has bookings.', '/staff/interviews');
        }
        // Owner = the assigned interviewer (with created_by fallback for legacy rows)
        $db->prepare(
            'DELETE FROM interview_slots
             WHERE id = ? AND COALESCE(assigned_to, created_by) = ?'
        )->execute([$id, $staffId]);
        audit_log(AuditAction::INTERVIEW_SLOT_DELETED, "Deleted interview slot ID {$id}", 'interview_slot', $id);
        ok('Session deleted.', '/staff/interviews');
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
            fail('You can only close your own sessions.', '/staff/interviews');
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
            "UPDATE interview_slots SET status='open'
             WHERE id=? AND COALESCE(assigned_to, created_by) = ?"
        )->execute([$id, $staffId]);
        audit_log(AuditAction::INTERVIEW_SLOT_REOPENED, "Reopened interview slot ID {$id}", 'interview_slot', $id);
        ok('Session reopened.', '/staff/interviews');
        break;

    // ----------------------------------------------------------------
    // Queue: reassign student to a different session
    // (legacy action name 'reassign_desk' kept for backward-compat URLs)
    // ----------------------------------------------------------------
    case 'reassign_desk':
    case 'reassign_session':
        $isAdmin = (Auth::user()['role'] ?? '') === ROLE_ADMIN;
        $targetSlotId = post_int('target_slot_id');

        if (!$isAdmin) {
            fail('Only admins can reassign students.', '/staff/interviews/queue');
        }

        // Load queue entry
        $qStmt = $db->prepare(
            "SELECT q.*, s.department FROM interview_queue q
             JOIN interview_slots s ON s.id = q.slot_id
             WHERE q.id = ? AND q.status IN ('scheduled','checked_in')"
        );
        $qStmt->execute([$id]);
        $qEntry = $qStmt->fetch();

        if (!$qEntry) {
            fail('Queue entry not found or not in a reassignable state.', '/staff/interviews/queue');
        }

        // Verify target slot is in the same department and has capacity
        $tStmt = $db->prepare(
            "SELECT s.id, s.department, s.capacity,
                    (SELECT COUNT(*) FROM interview_queue q2 WHERE q2.slot_id = s.id
                     AND q2.interview_status IN ('pending','completed')) AS booked
             FROM interview_slots s WHERE s.id = ? AND s.status = 'open'"
        );
        $tStmt->execute([$targetSlotId]);
        $target = $tStmt->fetch();

        if (!$target || $target['department'] !== $qEntry['department']) {
            fail('Target slot invalid or not in the same college.', '/staff/interviews/queue');
        }

        if ((int)$target['booked'] >= (int)$target['capacity']) {
            fail('Target slot is full.', '/staff/interviews/queue');
        }

        $db->prepare('UPDATE interview_queue SET slot_id = ? WHERE id = ?')
           ->execute([$targetSlotId, $id]);

        audit_log(AuditAction::INTERVIEW_REASSIGNED,
            "Reassigned queue #{$id} from slot #{$qEntry['slot_id']} to slot #{$targetSlotId}",
            'interview_queue', $id);
        ok('Student reassigned to new session.', '/staff/interviews/queue');
        break;

    default:
        redirect('/staff/interviews');
}
