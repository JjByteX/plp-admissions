<?php
// ============================================================
// modules/documents/staff_action.php
// M3 — Staff: POST handler for document approve/reject
// Router passes {id} as the document ID OR applicant ID
// ============================================================

require_once CORE_PATH . '/bootstrap.php';
Auth::requireRole(ROLE_SSO, ROLE_ADMIN);
csrf_check();

$db     = db();
$id     = (int)($_GET['id'] ?? 0);
$action = $_POST['action'] ?? '';
$staffId = Auth::id();
$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
    && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

switch ($action) {

    case 'unapprove':
        // Revert an approved document back to uploaded so it can be re-reviewed.
        // Only allowed while the applicant is still in submitted status (not yet advanced to exam).
        $stmt = $db->prepare('SELECT applicant_id FROM documents WHERE id=?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        $applicantId = $row['applicant_id'] ?? 0;

        $stmt = $db->prepare('SELECT overall_status FROM applicants WHERE id=?');
        $stmt->execute([$applicantId]);
        $appl = $stmt->fetch();

        $stmt = $db->prepare('SELECT COUNT(*) FROM exam_results WHERE applicant_id=?');
        $stmt->execute([$applicantId]);
        $examTaken = (int)$stmt->fetchColumn() > 0;

        $undoableStatuses = ['submitted', 'documents', 'exam'];
        if (!in_array($appl['overall_status'] ?? '', $undoableStatuses, true) || $examTaken) {
            Session::flash('error', 'Cannot undo — applicant already in exam stage.');
            redirect('/staff/applicants/' . $applicantId);
        }

        $db->prepare(
            'UPDATE documents SET status="uploaded", staff_remarks=NULL, reviewed_by=NULL WHERE id=?'
        )->execute([$id]);

        // Roll back overall_status to submitted if it was auto-advanced
        $db->prepare(
            'UPDATE applicants SET overall_status="submitted" WHERE id=? AND overall_status="exam"'
        )->execute([$applicantId]);

        audit_log('document_unapproved', "Undid approval for document ID {$id} (applicant {$applicantId})", 'document', $id);
        Session::flash('success', 'Approval reverted.');
        redirect('/staff/applicants/' . $applicantId);
        break;

    case 'approve_all':
        // Approve all uploaded/under_review documents for this applicant in one shot.
        // $id here is the applicant ID (passed via the form action URL).
        $stmt = $db->prepare(
            'UPDATE documents SET status="approved", staff_remarks=NULL, reviewed_by=?
              WHERE applicant_id=? AND status IN ("uploaded","under_review")'
        );
        $stmt->execute([$staffId, $id]);
        $affected = $stmt->rowCount();

        // Auto-advance to exam if all docs are now approved
        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM documents WHERE applicant_id=? AND status != "approved"'
        );
        $stmt->execute([$id]);
        $remaining = (int)$stmt->fetchColumn();

        if ($remaining === 0) {
            $db->prepare(
                'UPDATE applicants
                    SET overall_status = "exam",
                        documents_approved_at = COALESCE(documents_approved_at, NOW())
                  WHERE id = ?
                    AND overall_status NOT IN ("exam","interview","result")'
            )->execute([$id]);

            // Automation: notify student & auto-assign exam slot
            notify_stage_transition($id, 'exam');
            auto_assign_exam_slot($id);
        }

        audit_log('documents_approved_all', "Approved all {$affected} document(s) for applicant {$id}", 'applicant', $id);
        $msg = "{$affected} document" . ($affected != 1 ? 's' : '') . " approved.";

        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => true, 'message' => $msg, 'affected' => $affected]);
            exit;
        }
        Session::flash('success', $msg);
        redirect('/staff/applicants/' . $id);
        break;

    case 'undo_approve_all':
        // Revert all docs that were just approved back to uploaded
        $stmt = $db->prepare(
            'UPDATE documents SET status="uploaded", staff_remarks=NULL, reviewed_by=NULL
              WHERE applicant_id=? AND status="approved"'
        );
        $stmt->execute([$id]);
        $reverted = $stmt->rowCount();

        // Roll back applicant status if it was auto-advanced
        $db->prepare(
            'UPDATE applicants SET overall_status="submitted", documents_approved_at=NULL
              WHERE id=? AND overall_status="exam"'
        )->execute([$id]);

        audit_log('undo_approve_all', "Undid bulk approval for applicant {$id} ({$reverted} docs reverted)", 'applicant', $id);
        $msg = "Reverted {$reverted} document" . ($reverted != 1 ? 's' : '') . ".";

        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => true, 'message' => $msg]);
            exit;
        }
        Session::flash('success', $msg);
        redirect('/staff/applicants/' . $id);
        break;

    case 'approve':
        $stmt = $db->prepare(
            'UPDATE documents SET status="approved", staff_remarks=NULL, reviewed_by=? WHERE id=?'
        );
        $stmt->execute([$staffId, $id]);

        // Get the applicant ID for this document
        $stmt = $db->prepare('SELECT applicant_id FROM documents WHERE id=?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        $applicantId = $row['applicant_id'] ?? 0;

        // Auto-advance to exam stage if all documents are now approved
        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM documents WHERE applicant_id=? AND status != "approved"'
        );
        $stmt->execute([$applicantId]);
        $pendingCount = $stmt->fetchColumn();

        if ($pendingCount == 0) {
            $db->prepare(
                'UPDATE applicants
                    SET overall_status = "exam",
                        documents_approved_at = COALESCE(documents_approved_at, NOW())
                  WHERE id = ?
                    AND overall_status NOT IN ("exam","interview","result")'
            )->execute([$applicantId]);

            // Automation: notify student & auto-assign exam slot
            notify_stage_transition($applicantId, 'exam');
            auto_assign_exam_slot($applicantId);
        }

        Session::flash('success', 'Document approved.');
        audit_log('document_approved', "Approved document ID {$id} for applicant {$applicantId}", 'document', $id);
        redirect('/staff/applicants/' . $applicantId);
        break;

    // 'reject' action removed — see 'request_resubmission' below. Both did
    // the same thing functionally (reset applicant to 'documents', let them
    // re-upload), so we kept the one that actually notifies the student.

    case 'advance_to_exam':
        // Guard: all documents must exist and be approved before advancing
        $stmt = $db->prepare('SELECT COUNT(*) FROM documents WHERE applicant_id=?');
        $stmt->execute([$id]);
        $totalDocs = (int)$stmt->fetchColumn();

        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM documents WHERE applicant_id=? AND status != "approved"'
        );
        $stmt->execute([$id]);
        $pendingCount = (int)$stmt->fetchColumn();

        if ($totalDocs === 0 || $pendingCount > 0) {
            Session::flash('error', 'Approve all documents before advancing.');
            redirect('/staff/applicants/' . $id);
        }

        $stmt = $db->prepare(
            'UPDATE applicants
                SET overall_status = "exam",
                    documents_approved_at = COALESCE(documents_approved_at, NOW())
              WHERE id = ?
                AND overall_status IN ("pending","documents")'
        );
        $stmt->execute([$id]);

        // Manual advance must run the same automation hooks as the
        // approve-all path — otherwise the student lands on /student/exam
        // with no slot until a different code path heals it.
        notify_stage_transition($id, 'exam');
        auto_assign_exam_slot($id);

        audit_log('applicant_advanced_exam', "Manually advanced applicant {$id} to exam stage", 'applicant', $id);
        Session::flash('success', 'Applicant advanced to exam stage.');
        redirect('/staff/applicants/' . $id);
        break;

    // A7: Request document resubmission
    case 'request_resubmission':
        $remarks = trim($_POST['remarks'] ?? '');
        if (!$remarks) {
            Session::flash('error', 'Provide a reason for resubmission.');
            $stmt = $db->prepare('SELECT applicant_id FROM documents WHERE id=?');
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            redirect('/staff/applicants/' . ($row['applicant_id'] ?? 0));
        }
        // status is set to 'rejected' (the only valid "send back for re-upload"
        // value in the documents.status ENUM — 'resubmission_required' was a
        // latent bug, not a real ENUM member). The notify_* call below is what
        // distinguishes this from a silent rejection.
        $stmt = $db->prepare(
            'UPDATE documents SET status="rejected", staff_remarks=?, reviewed_by=? WHERE id=?'
        );
        $stmt->execute([$remarks, $staffId, $id]);

        $stmt = $db->prepare('SELECT applicant_id FROM documents WHERE id=?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        $applicantId = $row['applicant_id'] ?? 0;

        // Reset to documents stage
        $db->prepare(
            'UPDATE applicants SET overall_status = "documents"
              WHERE id = ? AND overall_status = "submitted"'
        )->execute([$applicantId]);

        // Notify student
        $stmt = $db->prepare('SELECT user_id FROM applicants WHERE id = ?');
        $stmt->execute([$applicantId]);
        $studentUserId = (int)$stmt->fetchColumn();
        if ($studentUserId) {
            create_notification(
                $studentUserId,
                'doc_resubmission',
                'Document Resubmission Required',
                "A document requires corrections: {$remarks}. Please upload a corrected version.",
                '/student/documents'
            );
        }

        Session::flash('success', 'Resubmission requested. Student has been notified.');
        audit_log('document_resubmission_requested', "Requested resubmission for document ID {$id} — {$remarks}", 'document', $id);
        redirect('/staff/applicants/' . $applicantId);
        break;

    default:
        redirect('/staff/applicants');
}
