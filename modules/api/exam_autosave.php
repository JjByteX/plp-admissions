<?php
// ============================================================
// modules/api/exam_autosave.php
// AJAX endpoint — auto-save exam draft answers every 60s
// ============================================================

require_once CORE_PATH . '/bootstrap.php';
Auth::requireRole(ROLE_STUDENT);

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST only']);
    exit;
}

$userId = Auth::id();
$db = db();

$stmt = $db->prepare('SELECT id FROM applicants WHERE user_id = ? ORDER BY id DESC LIMIT 1');
$stmt->execute([$userId]);
$applicant = $stmt->fetch();
if (!$applicant) {
    echo json_encode(['ok' => false, 'error' => 'No applicant']);
    exit;
}

$examId = (int)($_POST['exam_id'] ?? 0);
$answers = $_POST['answers'] ?? '{}';

if (!$examId) {
    echo json_encode(['ok' => false, 'error' => 'Missing exam_id']);
    exit;
}

// Check not already submitted
$stmt = $db->prepare('SELECT id FROM exam_results WHERE applicant_id = ? AND exam_id = ? LIMIT 1');
$stmt->execute([$applicant['id'], $examId]);
if ($stmt->fetch()) {
    echo json_encode(['ok' => false, 'error' => 'Already submitted']);
    exit;
}

ensure_exam_drafts_table();

$db->prepare(
    'INSERT INTO exam_drafts (applicant_id, exam_id, answers)
     VALUES (?, ?, ?)
     ON DUPLICATE KEY UPDATE answers = VALUES(answers), saved_at = NOW()'
)->execute([$applicant['id'], $examId, $answers]);

echo json_encode(['ok' => true, 'saved_at' => date('H:i:s')]);
