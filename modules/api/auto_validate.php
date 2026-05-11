<?php
// ============================================================
// modules/api/auto_validate.php
// AJAX endpoint: auto-validate a document (file checks)
//                or save AI validation result from client-side Puter SDK
// ============================================================

require_once CORE_PATH . '/bootstrap.php';
Auth::requireRole(ROLE_STUDENT, ROLE_STAFF, ROLE_PROCTOR, ROLE_SSO, ROLE_DEAN, ROLE_ADMIN);

header('Content-Type: application/json');

$action = $_POST['action'] ?? $_GET['action'] ?? 'validate';
$documentId = (int)($_POST['document_id'] ?? $_GET['document_id'] ?? 0);

if (!$documentId) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing document_id']);
    exit;
}

// Verify the document exists and user has access
$pdo = db();
$stmt = $pdo->prepare(
    'SELECT d.id, d.applicant_id, d.doc_type, d.file_path, d.status
     FROM documents d
     JOIN applicants a ON a.id = d.applicant_id
     WHERE d.id = ?'
);
$stmt->execute([$documentId]);
$doc = $stmt->fetch();

if (!$doc) {
    http_response_code(404);
    echo json_encode(['error' => 'Document not found']);
    exit;
}

if (Auth::role() === ROLE_STUDENT) {
    $stmt = $pdo->prepare('SELECT user_id FROM applicants WHERE id = ?');
    $stmt->execute([$doc['applicant_id']]);
    $owner = $stmt->fetch();
    if (!$owner || (int)$owner['user_id'] !== Auth::id()) {
        http_response_code(403);
        echo json_encode(['error' => 'Access denied']);
        exit;
    }
}

// ── action=ai_result — save result from client-side Puter AI ──
if ($action === 'ai_result') {
    Auth::requireRole(ROLE_SSO, ROLE_ADMIN);
    csrf_check();

    $aiStatus   = $_POST['status']     ?? 'uncertain';
    $confidence = (float)($_POST['confidence'] ?? 50);
    $reason     = trim($_POST['reason'] ?? '');

    if (!in_array($aiStatus, ['passed', 'failed', 'uncertain'], true)) {
        $aiStatus = 'uncertain';
    }

    save_ai_validation($documentId, $aiStatus, $confidence, $reason);

    // If AI validated, also check if all docs approved → auto-advance
    $autoAdvanced = false;
    if ($aiStatus === 'passed') {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM documents WHERE applicant_id = ? AND status != ?');
        $stmt->execute([$doc['applicant_id'], 'approved']);
        $remaining = (int) $stmt->fetchColumn();

        if ($remaining === 0) {
            $pdo->prepare(
                'UPDATE applicants SET overall_status = ?, documents_approved_at = COALESCE(documents_approved_at, NOW())
                 WHERE id = ? AND overall_status NOT IN (?,?,?)'
            )->execute(['exam', $doc['applicant_id'], 'exam', 'interview', 'released']);

            notify_stage_transition((int)$doc['applicant_id'], 'exam');
            auto_assign_exam_slot((int)$doc['applicant_id']);
            $autoAdvanced = true;
        }

        audit_log('document_ai_approved', "AI-approved document ID {$documentId} ({$confidence}%: {$reason})", 'document', $documentId);
    }

    echo json_encode([
        'ok'            => true,
        'result'        => $aiStatus,
        'confidence'    => $confidence,
        'reason'        => $reason,
        'auto_approved' => ($aiStatus === 'passed'),
        'auto_advanced' => $autoAdvanced,
    ]);
    exit;
}

// ── action=validate — server-side file checks ─────────────────
$result = auto_validate_document($documentId);

// If passed, auto-approve the document
if ($result === 'passed') {
    $pdo->prepare('UPDATE documents SET status = ?, staff_remarks = ? WHERE id = ? AND status IN (?,?)')
        ->execute(['approved', 'Auto-validated', $documentId, 'uploaded', 'under_review']);

    // Check if all docs are now approved -> auto-advance
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM documents WHERE applicant_id = ? AND status != ?');
    $stmt->execute([$doc['applicant_id'], 'approved']);
    $remaining = (int) $stmt->fetchColumn();

    if ($remaining === 0) {
        $pdo->prepare(
            'UPDATE applicants SET overall_status = ?, documents_approved_at = COALESCE(documents_approved_at, NOW())
             WHERE id = ? AND overall_status NOT IN (?,?,?)'
        )->execute(['exam', $doc['applicant_id'], 'exam', 'interview', 'released']);

        notify_stage_transition((int)$doc['applicant_id'], 'exam');
        auto_assign_exam_slot((int)$doc['applicant_id']);
    }

    audit_log('document_auto_approved', "Auto-approved document ID {$documentId} (validation: {$result})", 'document', $documentId);
}

$statusLabel = [
    'passed'    => 'Auto-validated',
    'failed'    => 'Validation failed - flagged for review',
    'uncertain' => 'Flagged for manual review',
];

echo json_encode([
    'ok'      => true,
    'result'  => $result,
    'message' => $statusLabel[$result] ?? $result,
    'auto_approved' => ($result === 'passed'),
]);
