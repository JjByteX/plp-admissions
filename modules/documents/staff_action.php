<?php
// ============================================================
// modules/documents/staff_action.php
// M3 — Staff: POST handler for document approve/reject
// Router passes {id} as the document ID OR applicant ID
// ============================================================

require_once CORE_PATH . '/bootstrap.php';
Auth::requireRole(ROLE_STAFF, ROLE_ADMIN);
csrf_check();

$db     = db();
$id     = (int)($_GET['id'] ?? 0);
$action = $_POST['action'] ?? '';
$staffId = Auth::id();

switch ($action) {

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
                'UPDATE applicants SET overall_status="exam" WHERE id=? AND overall_status NOT IN ("exam","interview","result")'
            )->execute([$applicantId]);
        }

        Session::flash('success', 'Document approved.');
        redirect('/staff/applicants/' . $applicantId);
        break;

    case 'reject':
        $remarks = trim($_POST['remarks'] ?? '');
        if (!$remarks) {
            Session::flash('error', 'Please provide a rejection reason.');
            $stmt = $db->prepare('SELECT applicant_id FROM documents WHERE id=?');
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            redirect('/staff/applicants/' . ($row['applicant_id'] ?? 0));
        }
        $stmt = $db->prepare(
            'UPDATE documents SET status="rejected", staff_remarks=?, reviewed_by=? WHERE id=?'
        );
        $stmt->execute([$remarks, $staffId, $id]);
        Session::flash('success', 'Document rejected with remarks.');
        $stmt = $db->prepare('SELECT applicant_id FROM documents WHERE id=?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        redirect('/staff/applicants/' . ($row['applicant_id'] ?? 0));
        break;

    case 'advance_to_exam':
        // $id here is the applicant_id (see route: POST /staff/documents/{id})
        $stmt = $db->prepare(
            'UPDATE applicants SET overall_status="exam" WHERE id=? AND overall_status IN ("pending","documents")'
        );
        $stmt->execute([$id]);
        Session::flash('success', 'Applicant advanced to entrance exam stage.');
        redirect('/staff/applicants/' . $id);
        break;

    default:
        redirect('/staff/applicants');
}