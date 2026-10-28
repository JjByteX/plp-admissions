<?php
// ============================================================
// modules/api/exam_autosave.php
// AJAX endpoint: save exam draft answers (only while the slot is open)
// ============================================================

require_once CORE_PATH . '/bootstrap.php';
Auth::requireRole(ROLE_STUDENT);

require_method('POST');
csrf_check();

$userId = Auth::id();
$db = db();

$applicant = applicant_for_user((int)$userId, 'id');
if (!$applicant) {
    json_response(['ok' => false, 'error' => 'No applicant']);
}

$examId  = (int)($_POST['exam_id'] ?? 0);
$slotId  = (int)($_POST['slot_id'] ?? 0);
$answers = $_POST['answers'] ?? '{}';

if (!$examId || !$slotId) {
    json_response(['ok' => false, 'error' => 'Missing exam_id or slot_id']);
}
if (!is_string($answers) || !is_array(json_decode($answers, true))) {
    json_response(['ok' => false, 'error' => 'Invalid answers']);
}

// The slot must be this applicant's own
$stmt = $db->prepare('SELECT 1 FROM applicant_exam_slots WHERE applicant_id = ? AND slot_id = ? LIMIT 1');
$stmt->execute([$applicant['id'], $slotId]);
if (!$stmt->fetchColumn()) {
    json_error('Invalid slot', 403);
}

// Check not already submitted
$stmt = $db->prepare('SELECT id FROM exam_results WHERE applicant_id = ? AND exam_id = ? LIMIT 1');
$stmt->execute([$applicant['id'], $examId]);
if ($stmt->fetch()) {
    json_response(['ok' => false, 'error' => 'Already submitted']);
}

// One statement: write only while the slot is open, judged by the database
// clock (see slot_close_sql). No row back means the slot has closed.
$stmt = $db->prepare(
    'INSERT INTO exam_drafts (applicant_id, exam_id, slot_id, answers)
     SELECT CAST(? AS integer), CAST(? AS integer), s.id, ?
       FROM exam_slot_schedule s
      WHERE s.id = ? AND now() <= ' . slot_close_sql('s') . '
     ON CONFLICT (applicant_id, exam_id, slot_id)
     DO UPDATE SET answers = EXCLUDED.answers, saved_at = now()
     RETURNING saved_at'
);
$stmt->execute([$applicant['id'], $examId, $answers, $slotId]);
$savedAt = $stmt->fetchColumn();

if ($savedAt === false) {
    json_response(['ok' => false, 'closed' => true, 'error' => 'Exam closed']);
}

json_response(['ok' => true, 'saved_at' => $savedAt]);
