<?php
// ============================================================
// modules/interview/staff_action.php
// M5 — Staff: POST handler for interview slot actions
// ============================================================

require_once CORE_PATH . '/bootstrap.php';
Auth::requireRole(ROLE_STAFF, ROLE_ADMIN);
csrf_check();

$db     = db();
$slotId = (int)($routeParams['id'] ?? 0);
$action = $_POST['action'] ?? '';

switch ($action) {

    case 'mark_completed':
        $db->prepare('UPDATE interview_slots SET status="completed" WHERE id=?')->execute([$slotId]);
        // Advance applicant to released/results stage
        $stmt = $db->prepare('SELECT assigned_applicant_id FROM interview_slots WHERE id=?');
        $stmt->execute([$slotId]);
        $row = $stmt->fetch();
        if ($row && $row['assigned_applicant_id']) {
            $db->prepare('UPDATE applicants SET overall_status="released" WHERE id=? AND overall_status="interview"')
               ->execute([$row['assigned_applicant_id']]);
        }
        Session::flash('success', 'Interview marked as completed.');
        redirect('/staff/interviews');
        break;

    case 'mark_no_show':
        $db->prepare('UPDATE interview_slots SET status="no_show" WHERE id=?')->execute([$slotId]);
        Session::flash('success', 'Interview marked as no-show.');
        redirect('/staff/interviews');
        break;

    case 'delete_slot':
        $db->prepare('DELETE FROM interview_slots WHERE id=? AND status="open"')->execute([$slotId]);
        Session::flash('success', 'Slot deleted.');
        redirect('/staff/interviews');
        break;

    default:
        redirect('/staff/interviews');
}
