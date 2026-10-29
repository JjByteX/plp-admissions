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
$id     = get_int('id');
$action = post_str('action');
$staffId = Auth::id();
$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
    && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

// Reply for the AI flag actions: JSON for AJAX, flash + redirect otherwise.
$flagReply = function (bool $ok, string $msg, int $applicantId) use ($isAjax) {
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => $ok, 'message' => $msg]);
        exit;
    }
    Session::flash($ok ? 'success' : 'error', $msg);
    redirect($applicantId ? '/staff/applicants/' . $applicantId : '/staff/applicants');
};

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
            fail('Cannot undo - applicant already in exam stage.', '/staff/applicants/' . $applicantId);
        }

        $db->prepare(
            'UPDATE documents SET status=\'uploaded\', staff_remarks=NULL, reviewed_by=NULL WHERE id=?'
        )->execute([$id]);

        // Roll back overall_status to submitted if it was auto-advanced
        $db->prepare(
            'UPDATE applicants SET overall_status=\'submitted\' WHERE id=? AND overall_status=\'exam\''
        )->execute([$applicantId]);

        audit_log(AuditAction::DOCUMENT_UNAPPROVED, "Undid approval for document ID {$id} (applicant {$applicantId})", 'document', $id);
        ok('Approval reverted.', '/staff/applicants/' . $applicantId);
        break;

    case 'approve_all':
        // Approve all uploaded/under_review documents for this applicant in one shot.
        // $id here is the applicant ID (passed via the form action URL).
        // AI-flagged documents are skipped until staff confirm the category.
        // Incomplete submissions (a required document missing or rejected)
        // cannot be bulk-approved; staff review those one by one.
        if (!required_docs_all_submitted($db, (int)$id)) {
            $incMsg = 'Approve All is only available once the student has uploaded all required documents.';
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['ok' => false, 'message' => $incMsg]);
                exit;
            }
            fail($incMsg, '/staff/applicants/' . $id);
        }

        $res      = doc_approve_unflagged($db, (int)$id, $staffId);
        $affected = $res['approved'];
        $skipped  = $res['skipped'];

        // Auto-advance to exam if all required docs are now approved. The
        // student is only notified when the status really changed.
        if (advance_to_exam_if_docs_approved($db, (int)$id)) {
            notify_stage_transition($id, 'exam');
        }
        // Self-guarded: only assigns when the applicant is in the exam stage
        // and has no slot yet, so it also catches exam-stage applicants stuck
        // without a slot.
        if (required_docs_all_approved($db, (int)$id)) {
            auto_assign_exam_slot($id);
        }

        audit_log(AuditAction::DOCUMENTS_APPROVED_ALL, "Approved all {$affected} document(s) for applicant {$id}", 'applicant', $id);
        $msg = "{$affected} document" . ($affected != 1 ? 's' : '') . " approved.";
        if ($skipped > 0) {
            $msg .= " {$skipped} AI-flagged document" . ($skipped != 1 ? 's were' : ' was') . " skipped. Confirm the category first.";
        }

        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => true, 'message' => $msg, 'affected' => $affected, 'skipped' => $skipped, 'ids' => $res['ids']]);
            exit;
        }
        ok($msg, '/staff/applicants/' . $id);
        break;

    case 'undo_approve_all':
        // Revert only the documents the matching approve_all just approved
        // (the client sends their ids). Anything approved earlier by hand is
        // left alone. Refused once the applicant has taken the exam.
        $undoReply = function (bool $ok, string $msg) use ($isAjax, $id) {
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['ok' => $ok, 'message' => $msg]);
                exit;
            }
            Session::flash($ok ? 'success' : 'error', $msg);
            redirect('/staff/applicants/' . $id);
        };

        $docIds = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['doc_ids'] ?? [])))));
        if (!$docIds) {
            $undoReply(false, 'Nothing to undo.');
        }

        $stmt = $db->prepare('SELECT COUNT(*) FROM exam_results WHERE applicant_id=?');
        $stmt->execute([$id]);
        if ((int)$stmt->fetchColumn() > 0) {
            $undoReply(false, 'Cannot undo - applicant already took the exam.');
        }

        [$in] = sql_in($docIds);
        $stmt = $db->prepare(
            "UPDATE documents SET status='uploaded', staff_remarks=NULL, reviewed_by=NULL
              WHERE applicant_id=? AND status='approved' AND id IN ($in)"
        );
        $stmt->execute(array_merge([$id], $docIds));
        $reverted = $stmt->rowCount();

        // Roll back to submitted only if the applicant is in the exam stage and
        // is no longer fully approved. An applicant who was already fully
        // approved before this approval keeps their stage.
        if (!required_docs_all_approved($db, (int)$id)) {
            $db->prepare(
                'UPDATE applicants SET overall_status=\'submitted\', documents_approved_at=NULL
                  WHERE id=? AND overall_status=\'exam\''
            )->execute([$id]);
        }

        audit_log(AuditAction::UNDO_APPROVE_ALL, "Undid bulk approval for applicant {$id} ({$reverted} docs reverted)", 'applicant', $id);
        $undoReply(true, "Reverted {$reverted} document" . ($reverted != 1 ? 's' : '') . ".");
        break;

    case 'approve':
        $stmt = $db->prepare(
            'UPDATE documents SET status=\'approved\', staff_remarks=NULL, reviewed_by=? WHERE id=?'
        );
        $stmt->execute([$staffId, $id]);

        // Get the applicant ID for this document
        $stmt = $db->prepare('SELECT applicant_id FROM documents WHERE id=?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        $applicantId = $row['applicant_id'] ?? 0;

        // Approving a flagged document also confirms its category.
        $aiFlags = doc_ai_flags($db, (int)$applicantId);
        if (isset($aiFlags[$id])) {
            doc_ai_confirm($db, $aiFlags[$id]['validation_id'], 'approved', $staffId);
            audit_log(AuditAction::DOC_CATEGORY_OK, "Category confirmed by approval of document ID {$id} (applicant {$applicantId})", 'document', $id);
        }

        // Auto-advance to exam stage if all documents are now approved
        if (required_docs_all_approved($db, (int)$applicantId)) {
            $db->prepare(
                'UPDATE applicants
                    SET overall_status = \'exam\',
                        documents_approved_at = COALESCE(documents_approved_at, NOW())
                  WHERE id = ?
                    AND overall_status NOT IN (\'exam\',\'interview\',\'result\')'
            )->execute([$applicantId]);

            // Automation: notify student & auto-assign exam slot
            notify_stage_transition($applicantId, 'exam');
            auto_assign_exam_slot($applicantId);
        }

        Session::flash('success', 'Document approved.');
        audit_log(AuditAction::DOCUMENT_APPROVED, "Approved document ID {$id} for applicant {$applicantId}", 'document', $id);
        redirect('/staff/applicants/' . $applicantId);
        break;

    // Phase 6: reviewer confirms the category of an AI-flagged document.
    // $id is the document ID. AI never approves; this only marks the flag reviewed.
    case 'category_ok':
        $stmt = $db->prepare('SELECT applicant_id, doc_type FROM documents WHERE id=?');
        $stmt->execute([$id]);
        $doc = $stmt->fetch();
        if (!$doc) $flagReply(false, 'Document not found.', 0);
        $aid     = (int)$doc['applicant_id'];
        $aiFlags = doc_ai_flags($db, $aid);
        if (!isset($aiFlags[$id])) $flagReply(false, 'This document is not flagged.', $aid);

        doc_ai_confirm($db, $aiFlags[$id]['validation_id'], 'approved', $staffId);
        audit_log(AuditAction::DOC_CATEGORY_OK, "Confirmed category of document ID {$id} ({$doc['doc_type']}) for applicant {$aid}", 'document', $id);
        $flagReply(true, 'Category confirmed.', $aid);
        break;

    // Phase 6: move a flagged file to another empty slot (review_result = corrected).
    case 'change_type':
        $target = post_str('target_slot');
        $stmt = $db->prepare(
            'SELECT d.id, d.applicant_id, d.doc_type, d.file_path, d.status,
                    a.applicant_type, a.doc_flags, a.user_id, a.overall_status
               FROM documents d JOIN applicants a ON a.id = d.applicant_id
              WHERE d.id = ?'
        );
        $stmt->execute([$id]);
        $doc = $stmt->fetch();
        if (!$doc) $flagReply(false, 'Document not found.', 0);
        $aid      = (int)$doc['applicant_id'];
        $aiFlags  = doc_ai_flags($db, $aid);
        $required = docs_for_type($doc['applicant_type'], $doc['doc_flags'] ?? null);

        if (!isset($aiFlags[$id]))                                   $flagReply(false, 'This document is not flagged.', $aid);
        if ($doc['status'] === 'approved')                           $flagReply(false, 'Approved documents cannot be moved. Undo the approval first.', $aid);
        if (!in_array($doc['status'], ['uploaded', 'under_review'], true)) $flagReply(false, 'Only an uploaded document can be moved.', $aid);
        if (!isset($required[$target]))                              $flagReply(false, 'That document type does not apply to this applicant.', $aid);
        if ($target === $doc['doc_type'])                            $flagReply(false, 'Pick a different document type.', $aid);

        $stmt = $db->prepare('SELECT id, file_path, status FROM documents WHERE applicant_id = ? AND doc_type = ?');
        $stmt->execute([$aid, $target]);
        $tRow = $stmt->fetch();
        if ($tRow && ($tRow['file_path'] || $tRow['status'] !== 'pending')) {
            $flagReply(false, 'That slot is not empty.', $aid);
        }

        $srcLabel = $required[$doc['doc_type']] ?? $doc['doc_type'];
        $tgtLabel = $required[$target];
        try {
            $db->beginTransaction();
            if ($tRow) {
                $targetId = (int)$tRow['id'];
                $db->prepare(
                    "UPDATE documents SET file_path = ?, status = 'uploaded', staff_remarks = NULL, reviewed_by = NULL WHERE id = ?"
                )->execute([$doc['file_path'], $targetId]);
            } else {
                $ins = $db->prepare(
                    "INSERT INTO documents (applicant_id, doc_type, file_path, status) VALUES (?, ?, ?, 'uploaded') RETURNING id"
                );
                $ins->execute([$aid, $target, $doc['file_path']]);
                $targetId = (int)$ins->fetchColumn();
            }

            // The old slot is empty now. After submit the applicant can only fill
            // a declined slot, so decline it and reopen the documents stage.
            if ($doc['overall_status'] === 'submitted') {
                $db->prepare(
                    "UPDATE documents SET file_path = NULL, status = 'rejected', staff_remarks = ?, reviewed_by = ? WHERE id = ?"
                )->execute(["Your file was moved to {$tgtLabel}. Please upload the correct {$srcLabel}.", $staffId, $id]);
                $db->prepare("UPDATE applicants SET overall_status = 'documents' WHERE id = ? AND overall_status = 'submitted'")
                   ->execute([$aid]);
            } else {
                $db->prepare(
                    "UPDATE documents SET file_path = NULL, status = 'pending', staff_remarks = NULL, reviewed_by = NULL WHERE id = ?"
                )->execute([$id]);
            }

            doc_ai_confirm($db, $aiFlags[$id]['validation_id'], 'corrected', $staffId, $targetId);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('Change type failed: ' . $e->getMessage());
            $flagReply(false, 'Server error. Please try again.', $aid);
        }

        if (!empty($doc['user_id'])) {
            create_notification(
                (int)$doc['user_id'],
                'doc_type_changed',
                'Document Category Changed',
                "Staff moved your file from \"{$srcLabel}\" to \"{$tgtLabel}\".",
                '/student/documents'
            );
        }
        audit_log(AuditAction::DOC_TYPE_CHANGED, "Moved document ID {$id} from {$doc['doc_type']} to {$target} for applicant {$aid}", 'document', $id);
        $flagReply(true, "Moved to {$tgtLabel}.", $aid);
        break;

    // 'reject' action removed — see 'request_resubmission' below. Both did
    // the same thing functionally (reset applicant to 'documents', let them
    // re-upload), so we kept the one that actually notifies the student.

    case 'advance_to_exam':
        // Guard: all documents must exist and be approved before advancing
        if (!required_docs_all_approved($db, (int)$id)) {
            fail('Approve all documents before advancing.', '/staff/applicants/' . $id);
        }

        $stmt = $db->prepare(
            'UPDATE applicants
                SET overall_status = \'exam\',
                    documents_approved_at = COALESCE(documents_approved_at, NOW())
              WHERE id = ?
                AND overall_status IN (\'pending\',\'documents\')'
        );
        $stmt->execute([$id]);

        // Manual advance must run the same automation hooks as the
        // approve-all path — otherwise the student lands on /student/exam
        // with no slot until a different code path heals it.
        notify_stage_transition($id, 'exam');
        auto_assign_exam_slot($id);

        audit_log(AuditAction::APPLICANT_ADVANCED_EXAM, "Manually advanced applicant {$id} to exam stage", 'applicant', $id);
        ok('Applicant advanced to exam stage.', '/staff/applicants/' . $id);
        break;

    // A7: Request document resubmission
    case 'request_resubmission':
        $remarks = post_str('remarks');
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
            'UPDATE documents SET status=\'rejected\', staff_remarks=?, reviewed_by=? WHERE id=?'
        );
        $stmt->execute([$remarks, $staffId, $id]);

        $stmt = $db->prepare('SELECT applicant_id FROM documents WHERE id=?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        $applicantId = $row['applicant_id'] ?? 0;

        // Reset to documents stage
        $db->prepare(
            'UPDATE applicants SET overall_status = \'documents\'
              WHERE id = ? AND overall_status = \'submitted\''
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
        audit_log(AuditAction::DOCUMENT_RESUBMISSION_REQUESTED, "Requested resubmission for document ID {$id} - {$remarks}", 'document', $id);
        redirect('/staff/applicants/' . $applicantId);
        break;

    default:
        redirect('/staff/applicants');
}
