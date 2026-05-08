<?php
// ============================================================
// modules/exam/staff_manage.php
// M4 — Staff: create/manage exam, add/edit questions
// Enhanced: Exam Sets (dates + password), Inline Editing,
//           Section/Part grouping by answer mode
// ============================================================

require_once CORE_PATH . '/bootstrap.php';
Auth::requireRole(ROLE_SSO, ROLE_ADMIN);

$db = db();
$errors  = [];
$success = [];

// ----------------------------------------------------------------
// Helpers
// ----------------------------------------------------------------
$QUESTION_TYPES = [
    'multiple_choice' => ['label' => 'Multiple Choice', 'icon' => 'radio'],
    'checkboxes'      => ['label' => 'Checkboxes',       'icon' => 'check'],
    'dropdown'        => ['label' => 'Dropdown',          'icon' => 'chevron'],
    'short_answer'    => ['label' => 'Short Answer',      'icon' => 'text'],
    'paragraph'       => ['label' => 'Paragraph',         'icon' => 'paragraph'],
    'linear_scale'    => ['label' => 'Linear Scale',      'icon' => 'scale'],
];
$CHOICE_TYPES = ['multiple_choice', 'checkboxes', 'dropdown'];

$SECTION_COLORS = [
    'multiple_choice' => ['bg' => '#dbeafe', 'text' => '#1d4ed8', 'border' => '#93c5fd'],
    'checkboxes'      => ['bg' => '#d1fae5', 'text' => '#065f46', 'border' => '#6ee7b7'],
    'dropdown'        => ['bg' => '#ede9fe', 'text' => '#5b21b6', 'border' => '#c4b5fd'],
    'short_answer'    => ['bg' => '#fef3c7', 'text' => '#92400e', 'border' => '#fcd34d'],
    'paragraph'       => ['bg' => '#fce7f3', 'text' => '#9d174d', 'border' => '#f9a8d4'],
    'linear_scale'    => ['bg' => '#f3f4f6', 'text' => 'var(--text-secondary)', 'border' => '#d1d5db'],
];

// ----------------------------------------------------------------
// POST handlers
// ----------------------------------------------------------------
// AJAX detection helper now lives in core/helpers.php so every module
// that returns JSON can use the same predicate. The local declaration
// here was kept as a fallback during the migration but has been removed
// to avoid "Cannot redeclare is_ajax_request()" fatals once helpers.php
// is loaded (bootstrap.php already pulls it in).

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    switch ($action) {

        // ── Exam CRUD ────────────────────────────────────────────
        // Schedule and access code live on each room slot
        // (exam_slot_schedule); the exam itself is content-only
        // (description + shuffle flags). The title is auto-derived
        // server-side from the active school year.
        case 'create_exam':
            $description    = trim($_POST['description'] ?? '');
            $shuffleQ       = isset($_POST['shuffle_questions']) ? 1 : 0;
            $shuffleC       = isset($_POST['shuffle_choices'])   ? 1 : 0;
            $autoYear       = (string) school_setting('current_school_year', date('Y') . '-' . (date('Y') + 1));
            $title          = 'PLP Admissions Test (' . $autoYear . ')';
            $db->prepare('UPDATE exams SET is_active=0')->execute();
            $db->prepare(
                'INSERT INTO exams (title, description, shuffle_questions, shuffle_choices, is_active)
                 VALUES (?,?,?,?,1)'
            )->execute([$title, $description ?: null, $shuffleQ, $shuffleC]);
            $newExamId = (int)$db->lastInsertId();
            $success[] = 'Exam created and set as active.';
            audit_log('exam_created', "Created exam: {$title}", 'exam', $newExamId);
            // Redirect to this exam's explicit URL so the page always has ?exam=ID
            header('Location: ' . url('/staff/exam') . '?exam=' . $newExamId . '&created=1');
            exit;

        case 'edit_exam':
            $examId      = (int)($_POST['exam_id'] ?? 0);
            $description = trim($_POST['description'] ?? '');
            $shuffleQ    = isset($_POST['shuffle_questions']) ? 1 : 0;
            $shuffleC    = isset($_POST['shuffle_choices'])   ? 1 : 0;
            // Auto-derived from the active school year.
            $autoYear    = (string) school_setting('current_school_year', date('Y') . '-' . (date('Y') + 1));
            $title       = 'PLP Admissions Test (' . $autoYear . ')';
            $db->prepare(
                'UPDATE exams SET title=?, description=?, shuffle_questions=?, shuffle_choices=? WHERE id=?'
            )->execute([$title, $description ?: null, $shuffleQ, $shuffleC, $examId]);
            // Handle inline-edit AJAX
            if (is_ajax_request()) {
                header('Content-Type: application/json');
                echo json_encode(['ok' => true, 'title' => $title]);
                exit;
            }
            $success[] = 'Exam updated.';
            audit_log('exam_updated', "Updated exam ID {$examId}: {$title}", 'exam', $examId);
            break;

        case 'activate_exam':
            $examId = (int)($_POST['exam_id'] ?? 0);
            $db->prepare('UPDATE exams SET is_active=0')->execute();
            $db->prepare('UPDATE exams SET is_active=1 WHERE id=?')->execute([$examId]);
            $success[] = 'Exam activated.';
            audit_log('exam_activated', "Activated exam ID {$examId}", 'exam', $examId);
            break;

        case 'delete_exam':
            $examId = (int)($_POST['exam_id'] ?? 0);
            if (!$examId) { $errors[] = 'Invalid exam.'; break; }
            // CASCADE constraints on exam_sections, questions, and exam_results
            // handle child rows automatically — just delete the parent.
            $db->prepare('DELETE FROM exams WHERE id=?')->execute([$examId]);
            audit_log('exam_deleted', "Deleted exam ID {$examId}", 'exam', $examId);
            header('Location: ' . url('/staff/exam'));
            exit;

        // ── Section CRUD ─────────────────────────────────────────
        case 'create_section':
            $examId    = (int)($_POST['exam_id'] ?? 0);
            $secTitle  = trim($_POST['section_title'] ?? '');
            $secType   = $_POST['section_type'] ?? 'multiple_choice';
            $secDesc   = trim($_POST['section_desc'] ?? '');
            if (!$secTitle) { $errors[] = 'Section title is required.'; break; }
            if (!array_key_exists($secType, $QUESTION_TYPES)) $secType = 'multiple_choice';
            $maxOrd = $db->prepare('SELECT COALESCE(MAX(sort_order),0) FROM exam_sections WHERE exam_id=?');
            $maxOrd->execute([$examId]);
            $db->prepare(
                'INSERT INTO exam_sections (exam_id, title, description, question_type, sort_order) VALUES (?,?,?,?,?)'
            )->execute([$examId, $secTitle, $secDesc ?: null, $secType, (int)$maxOrd->fetchColumn() + 1]);
            $newSecId = (int)$db->lastInsertId();
            if (is_ajax_request()) {
                header('Content-Type: application/json');
                echo json_encode(['ok' => true, 'section_id' => $newSecId]);
                exit;
            }
            $success[] = 'Section added.';
            break;

        case 'edit_section':
            $secId    = (int)($_POST['section_id'] ?? 0);
            $secTitle = trim($_POST['section_title'] ?? '');
            $secType  = $_POST['section_type'] ?? 'multiple_choice';
            $secDesc  = trim($_POST['section_desc'] ?? '');
            if (!$secTitle) { $errors[] = 'Section title is required.'; break; }
            if (!array_key_exists($secType, $QUESTION_TYPES)) $secType = 'multiple_choice';
            $db->prepare('UPDATE exam_sections SET title=?, description=?, question_type=? WHERE id=?')
               ->execute([$secTitle, $secDesc ?: null, $secType, $secId]);
            // Handle inline AJAX
            if (is_ajax_request()) {
                header('Content-Type: application/json');
                echo json_encode(['ok' => true, 'title' => $secTitle, 'type' => $secType]);
                exit;
            }
            $success[] = 'Section updated.';
            break;

        case 'delete_section':
            $secId = (int)($_POST['section_id'] ?? 0);
            // Delete all questions in section first, then the section itself
            $db->prepare('DELETE FROM questions WHERE section_id=?')->execute([$secId]);
            $db->prepare('DELETE FROM exam_sections WHERE id=?')->execute([$secId]);
            $success[] = 'Section deleted.';
            break;

        // ── Question CRUD ─────────────────────────────────────────
        case 'add_question':
        case 'edit_question':
            $isEdit   = ($action === 'edit_question');
            $examId   = (int)($_POST['exam_id'] ?? 0);
            $qId      = (int)($_POST['question_id'] ?? 0);
            $sectionId= $_POST['section_id'] !== '' ? (int)$_POST['section_id'] : null;
            $qText    = trim($_POST['question_text'] ?? '');
            $qDesc    = trim($_POST['question_desc'] ?? '');
            $qType    = $_POST['question_type'] ?? 'multiple_choice';
            $points   = max(0, (int)($_POST['points'] ?? 1));
            $required = isset($_POST['is_required']) ? 1 : 0;

            if (!$qText) { $errors[] = 'Question text is required.'; break; }
            if (!$sectionId) { $errors[] = 'Every question must belong to a section.'; break; }
            if (!array_key_exists($qType, $QUESTION_TYPES)) $qType = 'multiple_choice';

            $choices = null; $correctIndex = null; $correctAnswer = null;
            $scaleMin = 1; $scaleMax = 5; $scaleMinLabel = null; $scaleMaxLabel = null;

            if (in_array($qType, $CHOICE_TYPES)) {
                $rawChoices = array_map('trim', $_POST['choices'] ?? []);
                $rawChoices = array_values(array_filter($rawChoices, fn($c) => $c !== ''));
                if (count($rawChoices) < 2) { $errors[] = 'At least 2 choices are required.'; break; }
                $choices = json_encode($rawChoices);
                if ($qType === 'checkboxes') {
                    $corrects = array_map('intval', $_POST['correct_indices'] ?? []);
                    $corrects = array_values(array_filter($corrects, fn($i) => $i >= 0 && $i < count($rawChoices)));
                    $correctAnswer = json_encode($corrects);
                } else {
                    $ci = (int)($_POST['correct_index'] ?? 0);
                    $correctIndex = ($ci >= 0 && $ci < count($rawChoices)) ? $ci : 0;
                }
            } elseif ($qType === 'linear_scale') {
                $scaleMin = max(0, min(1, (int)($_POST['scale_min'] ?? 1)));
                $scaleMax = max(2, min(10, (int)($_POST['scale_max'] ?? 5)));
                $scaleMinLabel = trim($_POST['scale_min_label'] ?? '') ?: null;
                $scaleMaxLabel = trim($_POST['scale_max_label'] ?? '') ?: null;
            } elseif ($qType === 'short_answer') {
                $correctAnswer = trim($_POST['expected_answer'] ?? '') ?: null;
            }

            if ($isEdit && $qId) {
                $db->prepare(
                    'UPDATE questions SET question_text=?, question_type=?, description=?, points=?,
                     is_required=?, choices=?, correct_index=?, correct_answer=?, scale_min=?,
                     scale_max=?, scale_min_label=?, scale_max_label=?, section_id=?
                     WHERE id=? AND exam_id=?'
                )->execute([
                    $qText, $qType, $qDesc ?: null, $points, $required,
                    $choices, $correctIndex, $correctAnswer,
                    $scaleMin, $scaleMax, $scaleMinLabel, $scaleMaxLabel,
                    $sectionId, $qId, $examId
                ]);
                $success[] = 'Question updated.';
            } else {
                $maxOrder = $db->prepare('SELECT COALESCE(MAX(sort_order),0) FROM questions WHERE exam_id=?');
                $maxOrder->execute([$examId]);
                $db->prepare(
                    'INSERT INTO questions (exam_id, question_text, question_type, description, points,
                     is_required, choices, correct_index, correct_answer, scale_min, scale_max,
                     scale_min_label, scale_max_label, section_id, sort_order)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
                )->execute([
                    $examId, $qText, $qType, $qDesc ?: null, $points, $required,
                    $choices, $correctIndex, $correctAnswer,
                    $scaleMin, $scaleMax, $scaleMinLabel, $scaleMaxLabel,
                    $sectionId, (int)$maxOrder->fetchColumn() + 1
                ]);
                if (is_ajax_request()) {
                    header('Content-Type: application/json');
                    echo json_encode(['ok' => true, 'id' => (int)$db->lastInsertId()]);
                    exit;
                }
                $success[] = 'Question added.';
            }
            break;

        case 'delete_question':
            $qId = (int)($_POST['question_id'] ?? 0);
            $db->prepare('DELETE FROM questions WHERE id=?')->execute([$qId]);
            $success[] = 'Question deleted.';
            break;

        case 'reorder_questions':
            $order = $_POST['order'] ?? [];
            foreach ($order as $idx => $qId) {
                $db->prepare('UPDATE questions SET sort_order=? WHERE id=?')
                   ->execute([$idx + 1, (int)$qId]);
            }
            header('Content-Type: application/json');
            echo json_encode(['ok' => true]);
            exit;

        // Per-room access code generation lives on the Exam Slots page now
        // (modules/exam/staff_slots.php → action=generate_slot_code).
        // Schedule lives on the slot too — see the slot's exam_date / slot_time.

        case 'inline_edit_question':
            // Quick inline text edit for a single question
            $qId   = (int)($_POST['question_id'] ?? 0);
            $qText = trim($_POST['question_text'] ?? '');
            if ($qText) {
                $db->prepare('UPDATE questions SET question_text=? WHERE id=?')->execute([$qText, $qId]);
            }
            header('Content-Type: application/json');
            echo json_encode(['ok' => true, 'text' => $qText]);
            exit;
    }
}

// ----------------------------------------------------------------
// Load data
// ----------------------------------------------------------------
$exams        = $db->query('SELECT * FROM exams ORDER BY id DESC')->fetchAll();
$activeExam   = $db->query('SELECT * FROM exams WHERE is_active=1 LIMIT 1')->fetch() ?: null;
$schoolYear    = school_setting('current_school_year', date('Y') . '-' . (date('Y') + 1));
$slotCountStmt = $db->prepare('SELECT COUNT(*) FROM exam_slot_schedule WHERE school_year = ?');
$slotCountStmt->execute([$schoolYear]);
$slotCount     = (int) $slotCountStmt->fetchColumn();
$hasRoomSlots  = $slotCount > 0;
$questions    = [];
$sections     = [];
$selectedExamId = (int)($_GET['exam'] ?? 0);
$selectedExam   = null;

if ($selectedExamId) {
    $stmt = $db->prepare('SELECT * FROM exams WHERE id=?');
    $stmt->execute([$selectedExamId]);
    $selectedExam = $stmt->fetch() ?: null;

    if ($selectedExam) {
        $stmt = $db->prepare('SELECT * FROM questions WHERE exam_id=? ORDER BY sort_order, id');
        $stmt->execute([$selectedExamId]);
        $questions = $stmt->fetchAll();

        $stmt = $db->prepare('SELECT * FROM exam_sections WHERE exam_id=? ORDER BY sort_order, id');
        $stmt->execute([$selectedExamId]);
        $sections = $stmt->fetchAll();
    }
}

$resultStats = []; $totalPoints = 0;
if ($selectedExam) {
    $stmt = $db->prepare(
        'SELECT COUNT(*) as total, AVG(score) as avg_score, MAX(score) as max_score, MIN(score) as min_score
         FROM exam_results WHERE exam_id=?'
    );
    $stmt->execute([$selectedExamId]);
    $resultStats = $stmt->fetch();
    $totalPoints = array_sum(array_column($questions, 'points'));
}

// Group questions by section (every question must belong to one)
$questionsBySection = [];
foreach ($sections as $sec) $questionsBySection[$sec['id']] = [];
foreach ($questions as $q) {
    $sid = (int)$q['section_id'];
    if (isset($questionsBySection[$sid])) {
        $questionsBySection[$sid][] = $q;
    }
}

// Type icons helper
function typeIcon($type) {
    $icons = [
        'multiple_choice' => '<circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="2"/><circle cx="12" cy="12" r="4" fill="currentColor"/>',
        'checkboxes'      => '<rect x="3" y="3" width="18" height="18" rx="3" stroke="currentColor" stroke-width="2"/><path stroke="currentColor" stroke-width="2.5" stroke-linecap="round" d="M7 12l4 4 6-6"/>',
        'dropdown'        => '<rect x="3" y="6" width="18" height="12" rx="2" stroke="currentColor" stroke-width="2"/><path stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M8 11l4 4 4-4"/>',
        'short_answer'    => '<path stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M3 8h18M3 12h12"/>',
        'paragraph'       => '<path stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M3 6h18M3 10h18M3 14h12M3 18h8"/>',
        'linear_scale'    => '<path stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M3 12h18M7 8l-4 4 4 4M17 8l4 4-4 4"/>',
    ];
    $d = $icons[$type] ?? $icons['multiple_choice'];
    return "<svg width=\"14\" height=\"14\" viewBox=\"0 0 24 24\" fill=\"none\" style=\"flex-shrink:0\">{$d}</svg>";
}

// Format a datetime for display
function fmtDateTime($dt) {
    if (!$dt) return null;
    try { return (new DateTime($dt))->format('M j, Y g:i A'); } catch(Exception $e) { return $dt; }
}

ob_start();
?>

<style>
/* ── Question rows (no nested card; dividers extend edge-to-edge) ────── */
.q-card.card {
    background: transparent;
    border: none;
    border-radius: 0;
    box-shadow: none;
    padding: var(--space-3) var(--space-4);
    border-bottom: 1px solid var(--border);
    transition: background .12s;
}
.q-card.card:hover { background: var(--bg-subtle); }
.q-card.card.dragging { opacity:.5; }
.section-questions > .q-card.card:last-of-type { border-bottom: none; }
.drag-handle {
    cursor: grab; color: var(--text-tertiary);
    background: transparent; border: none;
    padding: 4px; line-height: 0;
}
.drag-handle:active { cursor: grabbing; }
.drag-handle:hover { color: var(--text-secondary); }

/* ── Section header bar ──────────────────────────── */
.section-header {
    display: flex; align-items: center; gap: var(--space-3);
    padding: var(--space-3) var(--space-4);
    border-radius: var(--radius-md); margin-bottom: var(--space-2);
    border: 1.5px solid transparent;
    position: relative;
}
.section-header .section-actions {
    display: flex; gap: var(--space-1); margin-left: auto;
}

/* ── Inline editing ──────────────────────────────── */
.inline-edit-wrap { position: relative; display: inline-block; }
.inline-editable {
    cursor: pointer; border-radius: var(--radius-sm);
    transition: background .12s;
}
.inline-editable:hover { background: var(--bg-overlay); }
.inline-edit-input {
    font-family: inherit; font-size: inherit; font-weight: inherit;
    border: 2px solid var(--accent); border-radius: var(--radius-sm);
    padding: 2px 6px; background: var(--bg-elevated); color: var(--text-primary);
    outline: none; width: 100%; box-sizing: border-box;
}
.inline-edit-actions {
    display: flex; gap: var(--space-1); margin-top: 4px;
}
.inline-save-btn {
    padding: 2px 10px; font-size: var(--text-xs); border-radius: var(--radius-sm);
    background: var(--accent); color: #fff; border: none; cursor: pointer;
    font-weight: var(--weight-medium);
}
.inline-cancel-btn {
    padding: 2px 10px; font-size: var(--text-xs); border-radius: var(--radius-sm);
    background: transparent; color: var(--text-secondary); border: 1px solid var(--border);
    cursor: pointer;
}

/* ── Type pills ──────────────────────────────────── */
.type-pill {
    display: inline-flex; align-items: center; gap: 4px;
    font-size: var(--text-xs); padding: 2px 8px;
    background: var(--bg-subtle); border-radius: 99px;
    color: var(--text-secondary);
}
.type-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:var(--space-2); }
.type-option {
    display:flex; flex-direction:column; align-items:center; gap:4px;
    padding: var(--space-3); border: 2px solid var(--border);
    border-radius: var(--radius-md); cursor: pointer;
    font-size: var(--text-xs); color: var(--text-secondary);
    text-align: center; transition: border-color .15s, background .15s;
    user-select: none;
}
.type-option:hover { border-color: var(--accent); background: rgba(45,106,79,.05); }
.type-option.selected { border-color: var(--accent); background: rgba(45,106,79,.08); color: var(--accent); }
.type-option.locked { pointer-events: none; opacity: .7; }

/* ── Points badge ────────────────────────────────── */
.pts-badge {
    font-size: var(--text-xs); font-weight: var(--weight-semibold);
    padding: 2px 8px; background: var(--accent); color: #fff;
    border-radius: 99px;
}

/* ── Toggle ──────────────────────────────────────── */
.toggle { position:relative; width:36px; height:20px; }
.toggle input { opacity:0; width:0; height:0; }
.toggle-slider {
    position:absolute; inset:0; border-radius:99px;
    background:var(--border); cursor:pointer; transition:.2s;
}
.toggle-slider:before {
    content:''; position:absolute; width:14px; height:14px;
    left:3px; top:3px; border-radius:50%; background:var(--bg-elevated); transition:.2s;
}
.toggle input:checked + .toggle-slider { background:var(--accent); }
.toggle input:checked + .toggle-slider:before { transform:translateX(16px); }

/* ── Scale preview ───────────────────────────────── */
.scale-preview { display:flex; gap:var(--space-2); flex-wrap:wrap; }
.scale-btn {
    width:36px; height:36px; border:2px solid var(--border);
    border-radius:var(--radius-md); display:flex; align-items:center; justify-content:center;
    font-size:var(--text-sm); font-weight:var(--weight-medium);
    background:var(--surface); color:var(--text-primary);
}

/* ── Section card container ──────────────────────── */
.section-block.card {
    border: 1px solid var(--border);
    box-shadow: var(--shadow-sm);
}
.section-block .section-header {
    border-radius: var(--radius-md) var(--radius-md) 0 0 !important;
    border-bottom: 1px solid rgba(0,0,0,.06);
}
.section-questions { padding: var(--space-4); }

/* ── Inline question creator ─────────────────────── */
.inline-question-creator {
    border: 1.5px solid var(--accent);
    border-radius: var(--radius-md);
    background: var(--bg-elevated);
    overflow: hidden;
    animation: creator-slide-in .15s ease;
}
@keyframes creator-slide-in {
    from { opacity:0; transform: translateY(-6px); }
    to   { opacity:1; transform: translateY(0); }
}
.iq-body { padding: var(--space-4); display: flex; flex-direction: column; gap: var(--space-3); }
.iq-footer { padding: var(--space-3) var(--space-4); border-top: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; background: var(--bg); }
.iq-type-badge { display: inline-flex; align-items: center; gap: 5px; font-size: var(--text-xs); padding: 3px 10px; border-radius: 99px; font-weight: var(--weight-medium); }
.iq-choice-row { display: flex; align-items: center; gap: 8px; }
.iq-choice-row input[type=text] { flex: 1; }
.iq-add-choice { font-size: var(--text-xs); color: var(--accent); background: none; border: none; cursor: pointer; padding: 4px 0; display: flex; align-items: center; gap: 4px; }

/* ── Schedule / password info strip ─────────────── */
.exam-meta-strip {
    display: flex; flex-wrap: wrap; gap: var(--space-3) var(--space-5);
    padding: var(--space-3) var(--space-4);
    background: var(--bg-subtle); border-radius: var(--radius-md);
    font-size: var(--text-xs); color: var(--text-secondary);
    margin-top: var(--space-3);
}
.exam-meta-item { display: flex; align-items: center; gap: 5px; }
.exam-meta-item svg { flex-shrink: 0; color: var(--text-tertiary); }

/* ── LANDING PAGE ────────────────────────────────── */
.exam-landing-wrap {
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: calc(100vh - 200px);
    padding-bottom: var(--space-16);
}
.exam-landing-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: var(--space-6);
    width: 100%;
    max-width: 640px;
}
.exam-landing-card {
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
    gap: var(--space-4);
    padding: var(--space-10) var(--space-6);
    background: var(--bg-elevated);
    border: 1.5px solid var(--border);
    border-radius: var(--radius-lg);
    text-decoration: none;
    color: var(--text-primary);
    transition: border-color .18s, box-shadow .18s, transform .15s;
    cursor: pointer;
}
.exam-landing-card:hover {
    border-color: var(--accent);
    box-shadow: 0 8px 24px rgba(0,0,0,.08);
    transform: translateY(-4px);
}
.exam-landing-icon {
    width: 60px; height: 60px;
    border-radius: var(--radius-lg);
    background: var(--accent-muted);
    display: flex; align-items: center; justify-content: center;
    color: var(--accent);
    flex-shrink: 0;
}
.exam-landing-title {
    font-size: var(--text-lg);
    font-weight: var(--weight-semibold);
    color: var(--text-primary);
    letter-spacing: -0.2px;
    margin-top: var(--space-1);
}
.exam-landing-desc {
    font-size: var(--text-sm);
    color: var(--text-secondary);
    line-height: 1.55;
    margin-top: var(--space-1);
}
.exam-landing-meta {
    font-size: var(--text-xs);
    color: var(--text-tertiary);
    display: flex; align-items: center; gap: var(--space-2);
    justify-content: center;
    flex-wrap: wrap;
    margin-top: var(--space-2);
}
.exam-landing-card--disabled {
    opacity: 0.45;
    pointer-events: none;
    cursor: not-allowed;
}

/* ── EXAM DIRECTORY ──────────────────────────────── */
.exam-dir-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
    gap: var(--space-4);
    margin-top: var(--space-5);
}
.exam-dir-card {
    display: flex;
    flex-direction: column;
    gap: var(--space-3);
    padding: var(--space-5);
    background: var(--bg-elevated);
    border: 1.5px solid var(--border);
    border-radius: var(--radius-lg);
    text-decoration: none;
    color: var(--text-primary);
    transition: border-color .15s, box-shadow .15s;
    position: relative;
}
.exam-dir-card:hover {
    border-color: var(--accent);
    box-shadow: var(--shadow-sm);
}
.exam-dir-card.is-active {
    border-color: var(--accent);
    background: var(--accent-muted);
}
.exam-dir-title {
    font-size: var(--text-base);
    font-weight: var(--weight-semibold);
    color: var(--text-primary);
    line-height: 1.35;
}
.exam-dir-meta {
    display: flex; flex-direction: column; gap: 4px;
    font-size: var(--text-xs); color: var(--text-tertiary);
}
.exam-dir-meta-row {
    display: flex; align-items: center; gap: 5px;
}
.exam-dir-footer {
    display: flex; align-items: center; justify-content: space-between;
    margin-top: auto; padding-top: var(--space-3);
    border-top: 1px solid var(--border);
}

@media (max-width: 640px) {
    .exam-landing-grid { grid-template-columns: 1fr; max-width: 320px; }
}
</style>

<?php foreach ($errors as $e): ?>
    <div class="alert alert-error" style="margin-bottom:var(--space-3)"><?= e($e) ?></div>
<?php endforeach; ?>
<?php if (!empty($_GET['created'])): ?>
    <div class="alert alert-success" style="margin-bottom:var(--space-3)">Exam created and set as active.</div>
<?php endif; ?>
<?php foreach ($success as $s): ?>
    <div class="alert alert-success" style="margin-bottom:var(--space-3)"><?= e($s) ?></div>
<?php endforeach; ?>

<?php
// ── View routing ──────────────────────────────────────────────
$view = 'landing';
if (isset($_GET['view']) && $_GET['view'] === 'exams') $view = 'exams';
elseif ($selectedExamId) $view = 'editor';
?>

<?php /* ════════════════════════════════════════════════════
   LANDING — two big entry-point cards
════════════════════════════════════════════════════ */ ?>
<?php if ($view === 'landing'): ?>

<div class="exam-landing-wrap">
<div class="exam-landing-grid">

    <!-- Exam Builder (left) — always enabled -->
    <a href="<?= url('/staff/exam') ?>?view=exams" class="exam-landing-card">
        <div class="exam-landing-icon">
            <svg width="28" height="28" viewBox="0 0 24 24" fill="none">
                <path stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                      d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8L14 2z"/>
                <polyline stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                          points="14 2 14 8 20 8"/>
                <line stroke="currentColor" stroke-width="2" stroke-linecap="round" x1="8" y1="13" x2="16" y2="13"/>
                <line stroke="currentColor" stroke-width="2" stroke-linecap="round" x1="8" y1="17" x2="13" y2="17"/>
            </svg>
        </div>
        <div class="exam-landing-title">Exam Builder</div>
        <div class="exam-landing-desc">
            Create exams, set questions, and manage schedules.
        </div>
        <div class="exam-landing-meta">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none"><path stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M9 12l2 2 4-4"/><rect x="3" y="4" width="18" height="18" rx="2" stroke="currentColor" stroke-width="2"/></svg>
            <?= count($exams) ?> exam<?= count($exams) !== 1 ? 's' : '' ?> created
            <?php if ($activeExam): ?>
                &nbsp;&middot;&nbsp;
                <span style="color:var(--success);font-weight:var(--weight-medium)"><?= e($activeExam['title']) ?> is active</span>
            <?php endif; ?>
        </div>
    </a>

    <!-- Room Slots (right) — disabled if no exams set up yet -->
    <?php if (!empty($exams)): ?>
    <a href="<?= url('/staff/exam/slots') ?>" class="exam-landing-card">
    <?php else: ?>
    <div class="exam-landing-card exam-landing-card--disabled" title="Create an exam first before setting up room slots">
    <?php endif; ?>
        <div class="exam-landing-icon">
            <svg width="28" height="28" viewBox="0 0 24 24" fill="none">
                <rect x="3" y="3" width="7" height="7" rx="1" stroke="currentColor" stroke-width="2"/>
                <rect x="14" y="3" width="7" height="7" rx="1" stroke="currentColor" stroke-width="2"/>
                <rect x="3" y="14" width="7" height="7" rx="1" stroke="currentColor" stroke-width="2"/>
                <rect x="14" y="14" width="7" height="7" rx="1" stroke="currentColor" stroke-width="2"/>
            </svg>
        </div>
        <div class="exam-landing-title">Room Slots</div>
        <div class="exam-landing-desc">
            Assign rooms, manage seating, and organize exam-day logistics.
        </div>
        <div class="exam-landing-meta">
            <?php if (empty($exams)): ?>
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2"/><path stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M12 8v4M12 16h.01"/></svg>
                Create an exam first
            <?php else: ?>
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none"><path stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M9 12l2 2 4-4"/><rect x="3" y="4" width="18" height="18" rx="2" stroke="currentColor" stroke-width="2"/></svg>
                <?= $slotCount ?> slot<?= $slotCount !== 1 ? 's' : '' ?> configured
            <?php endif; ?>
        </div>
    <?php if (!empty($exams)): ?>
    </a>
    <?php else: ?>
    </div>
    <?php endif; ?>

</div>
</div>

<?php /* ════════════════════════════════════════════════════
   EXAM DIRECTORY — card grid of all exams
════════════════════════════════════════════════════ */ ?>
<?php elseif ($view === 'exams'): ?>

<!-- Directory header -->
<div style="margin-bottom:var(--space-5)">
    <a href="<?= url('/staff/exam') ?>" class="btn btn-ghost btn-sm" style="display:inline-flex;align-items:center;gap:5px">
        <?= icon('ic_fluent_arrow_left_24_regular', 15) ?>
        Back
    </a>
</div>

<?php if (empty($exams)): ?>
    <div class="exam-dir-grid" style="max-width:320px;margin:var(--space-10) auto 0">
        <div class="exam-dir-card" onclick="openModal('create-exam-modal')"
             style="align-items:center;justify-content:center;min-height:220px;border-style:dashed;cursor:pointer">
            <div style="width:56px;height:56px;border-radius:50%;background:var(--accent-muted);
                        display:flex;align-items:center;justify-content:center;color:var(--accent)">
                <svg width="28" height="28" viewBox="0 0 24 24" fill="none"><path stroke="currentColor" stroke-width="2.2" stroke-linecap="round" d="M12 5v14M5 12h14"/></svg>
            </div>
            <div style="font-size:var(--text-base);font-weight:var(--weight-semibold);color:var(--accent);margin-top:var(--space-2)">Create Your First Exam</div>
            <div style="font-size:var(--text-sm);color:var(--text-tertiary);margin-top:var(--space-1)">Get started by creating an entrance exam.</div>
        </div>
    </div>
<?php else: ?>
    <div class="exam-dir-grid">
        <?php foreach ($exams as $ex):
            $qCount = $examMeta[$ex['id']]['question_count'] ?? 0;
            $isActive = (bool)$ex['is_active'];
        ?>
            <a href="<?= url('/staff/exam') ?>?exam=<?= $ex['id'] ?>" class="exam-dir-card <?= $isActive ? 'is-active' : '' ?>">

                <!-- Active badge -->
                <?php if ($isActive): ?>
                    <div style="position:absolute;top:var(--space-4);right:var(--space-4)">
                        <span class="badge badge-success" style="font-size:10px">Active</span>
                    </div>
                <?php endif; ?>

                <!-- Title -->
                <div class="exam-dir-title"><?= e($ex['title']) ?></div>

                <!-- Meta info -->
                <div class="exam-dir-meta">
                    <div class="exam-dir-meta-row">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none"><path stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M9 12l2 2 4-4"/><rect x="3" y="4" width="18" height="18" rx="2" stroke="currentColor" stroke-width="2"/></svg>
                        <?= $qCount ?> question<?= $qCount !== 1 ? 's' : '' ?>
                    </div>
                </div>

                <!-- Footer -->
                <div class="exam-dir-footer">
                    <span style="font-size:var(--text-xs);color:var(--text-tertiary)">
                        Created <?= e(format_date($ex['created_at'] ?? null, 'M j, Y')) ?>
                    </span>
                    <span style="font-size:var(--text-xs);font-weight:var(--weight-medium);color:var(--accent);display:flex;align-items:center;gap:4px">
                        Edit
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none"><path stroke="currentColor" stroke-width="2.2" stroke-linecap="round" d="M5 12h14M13 6l6 6-6 6"/></svg>
                    </span>
                </div>
            </a>
        <?php endforeach; ?>

        <!-- New Exam card (always last in grid) -->
        <div class="exam-dir-card" onclick="openModal('create-exam-modal')"
             style="align-items:center;justify-content:center;min-height:200px;border-style:dashed;cursor:pointer">
            <div style="width:48px;height:48px;border-radius:50%;background:var(--accent-muted);
                        display:flex;align-items:center;justify-content:center;color:var(--accent)">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none"><path stroke="currentColor" stroke-width="2.2" stroke-linecap="round" d="M12 5v14M5 12h14"/></svg>
            </div>
            <div style="font-size:var(--text-sm);font-weight:var(--weight-medium);color:var(--accent);margin-top:var(--space-2)">New Exam</div>
        </div>
    </div>
<?php endif; ?>

<?php /* ════════════════════════════════════════════════════
   EXAM EDITOR — full question builder for a selected exam
════════════════════════════════════════════════════ */ ?>
<?php else: ?>

<!-- Back -->
<div style="margin-bottom:var(--space-5)">
    <a href="<?= url('/staff/exam') ?>?view=exams" class="btn btn-ghost btn-sm" style="display:inline-flex;align-items:center;gap:5px">
        <?= icon('ic_fluent_arrow_left_24_regular', 15) ?>
        Back
    </a>
</div>

<?php if ($selectedExam): ?>

        <!-- Exam header card: read-only auto-derived title + editable description and shuffle flags. -->
        <style>
            #exam-header-card[data-edit-state="read"] .exam-show-edit { display: none !important; }
            #exam-header-card[data-edit-state="edit"] .exam-show-read { display: none !important; }
            .exam-inline {
                width: 100%;
                font: inherit;
                color: inherit;
                background: transparent;
                border: 1px solid transparent;
                border-radius: var(--radius-sm);
                padding: 4px 8px;
                margin: 0 -8px;
                box-sizing: border-box;
                resize: none;
                transition: border-color .15s, background .15s;
            }
            .exam-inline[readonly] { cursor: default; pointer-events: none; }
            .exam-inline:not([readonly]):hover { border-color: var(--border); }
            .exam-inline:not([readonly]):focus {
                outline: none;
                border-color: var(--accent);
                background: var(--bg-elevated);
            }
            .exam-inline--title { font-size: var(--text-lg); font-weight: var(--weight-semibold); }
            .exam-inline--desc  { display: block; font-size: var(--text-sm); color: var(--text-secondary); margin-top: var(--space-2); }
            #exam-header-card[data-edit-state="read"] .exam-inline--desc:placeholder-shown { display: none; }
        </style>

        <form method="POST" id="exam-edit-form">
            <?= csrf_field() ?>
            <input type="hidden" name="action"   value="edit_exam">
            <input type="hidden" name="exam_id"  value="<?= $selectedExam['id'] ?>">

            <div class="card" id="exam-header-card" data-edit-state="read"
                 style="margin-bottom:var(--space-5);padding:var(--space-4)">
                <div style="display:flex;align-items:flex-start;gap:var(--space-4)">
                    <div style="flex:1;min-width:0">

                        <div class="exam-inline exam-inline--title"
                             style="padding:var(--space-2) var(--space-3);border:1px solid transparent">
                            <?= e($selectedExam['title']) ?>
                        </div>
                        <div class="exam-show-edit" style="font-size:var(--text-xs);color:var(--text-tertiary);margin:4px 0 var(--space-2)">
                            Title is set automatically from the active school year. Change it on the
                            <a href="<?= url('/admin/school-year') ?>">School Year</a> page.
                        </div>

                        <textarea class="exam-inline exam-inline--desc" name="description" rows="1"
                                  placeholder="Add description (optional)"
                                  data-original="<?= e($selectedExam['description'] ?? '') ?>"
                                  readonly><?= e($selectedExam['description'] ?? '') ?></textarea>

                        <div class="exam-show-read" style="font-size:var(--text-xs);color:var(--text-tertiary);margin-top:var(--space-2);display:flex;gap:var(--space-3);flex-wrap:wrap;align-items:center">
                            <span><?= count($questions) ?> questions</span>
                            <span aria-hidden="true">·</span>
                            <span><?= $totalPoints ?> total points</span>
                            <?php if ($selectedExam['shuffle_questions']): ?>
                                <span aria-hidden="true">·</span>
                                <span style="display:inline-flex;align-items:center;gap:4px">
                                    <?= icon('ic_fluent_arrow_sync_24_regular', 12) ?>
                                    Shuffled
                                </span>
                            <?php endif; ?>
                        </div>

                        <div class="exam-show-edit" style="display:flex;gap:var(--space-4);flex-wrap:wrap;margin-top:var(--space-3)">
                            <label style="display:inline-flex;align-items:center;gap:6px;cursor:pointer;font-size:var(--text-sm)">
                                <input type="checkbox" name="shuffle_questions"
                                       data-original-checked="<?= $selectedExam['shuffle_questions'] ? '1' : '0' ?>"
                                       style="accent-color:var(--accent)" <?= $selectedExam['shuffle_questions'] ? 'checked' : '' ?>>
                                Shuffle questions
                            </label>
                            <label style="display:inline-flex;align-items:center;gap:6px;cursor:pointer;font-size:var(--text-sm)">
                                <input type="checkbox" name="shuffle_choices"
                                       data-original-checked="<?= $selectedExam['shuffle_choices'] ? '1' : '0' ?>"
                                       style="accent-color:var(--accent)" <?= $selectedExam['shuffle_choices'] ? 'checked' : '' ?>>
                                Shuffle choices
                            </label>
                        </div>
                    </div>

                    <div style="display:flex;gap:var(--space-2);flex-shrink:0;align-items:center;flex-wrap:wrap;justify-content:flex-end">
                        <button type="button" class="btn btn-ghost btn-sm exam-show-read"
                                onclick="enterExamEditMode()">Edit</button>
                        <button type="button" class="btn btn-ghost btn-sm exam-show-edit"
                                onclick="exitExamEditMode()">Cancel</button>
                        <button type="submit" class="btn btn-primary btn-sm exam-show-edit">Save</button>

                        <?php if (!$selectedExam['is_active']): ?>
                            <button type="button" class="btn btn-secondary btn-sm exam-show-read"
                                    onclick="document.getElementById('activate-exam-form').submit()">Set Active</button>
                        <?php endif; ?>
                        <button type="button" class="btn btn-sm exam-show-read"
                                style="color:var(--error);border-color:var(--error);background:transparent"
                                onclick="if(confirm('Delete this exam? All questions will be removed.')) document.getElementById('delete-exam-form').submit();">Delete</button>
                    </div>
                </div>

                <?php if (!empty($resultStats) && $resultStats['total'] > 0): ?>
                    <div style="display:flex;gap:var(--space-6);margin-top:var(--space-4);padding-top:var(--space-4);border-top:1px solid var(--border)">
                        <div><div style="font-size:var(--text-xs);color:var(--text-tertiary)">Takers</div><div style="font-weight:var(--weight-semibold)"><?= $resultStats['total'] ?></div></div>
                        <div><div style="font-size:var(--text-xs);color:var(--text-tertiary)">Avg Score</div><div style="font-weight:var(--weight-semibold)"><?= round($resultStats['avg_score'],1) ?></div></div>
                        <div><div style="font-size:var(--text-xs);color:var(--text-tertiary)">Highest</div><div style="font-weight:var(--weight-semibold)"><?= $resultStats['max_score'] ?></div></div>
                        <div><div style="font-size:var(--text-xs);color:var(--text-tertiary)">Lowest</div><div style="font-weight:var(--weight-semibold)"><?= $resultStats['min_score'] ?></div></div>
                    </div>
                <?php endif; ?>

            </div>
        </form>

        <?php if (!$selectedExam['is_active']): ?>
            <form method="POST" id="activate-exam-form" style="display:none">
                <?= csrf_field() ?>
                <input type="hidden" name="action"  value="activate_exam">
                <input type="hidden" name="exam_id" value="<?= $selectedExam['id'] ?>">
            </form>
        <?php endif; ?>
        <form method="POST" id="delete-exam-form" style="display:none">
            <?= csrf_field() ?>
            <input type="hidden" name="action"  value="delete_exam">
            <input type="hidden" name="exam_id" value="<?= $selectedExam['id'] ?>">
        </form>

        <!-- ── Sectioned question list ── -->
        <div id="questions-list">

        <?php
        $globalQNum = 0;
        function renderQuestionCard($q, &$globalQNum, $QUESTION_TYPES, $CHOICE_TYPES): void {
            global $selectedExam;
            $globalQNum++;
            $choices  = $q['choices'] ? json_decode($q['choices'], true) : [];
            $typeMeta = $QUESTION_TYPES[$q['question_type']] ?? $QUESTION_TYPES['multiple_choice'];
            $qJson    = htmlspecialchars(json_encode($q));
        ?>
            <div class="card q-card" data-qid="<?= $q['id'] ?>" data-q="<?= $qJson ?>">
                <div class="q-view-mode" style="display:flex;gap:var(--space-3);align-items:flex-start">
                        <div style="flex:1;min-width:0">
                            <div style="display:flex;align-items:baseline;gap:var(--space-2);flex-wrap:wrap">
                                <span style="font-weight:var(--weight-medium);color:var(--text-tertiary);min-width:1.5em;text-align:right"><?= $globalQNum ?>.</span>
                                <span style="font-weight:var(--weight-medium);flex:1;min-width:0"><?= e($q['question_text']) ?></span>
                                <span style="font-size:var(--text-xs);color:var(--text-tertiary);white-space:nowrap"><?= $q['points'] ?> pt<?= $q['points']!=1?'s':'' ?></span>
                                <?php if (!$q['is_required']): ?>
                                    <span class="type-pill">Optional</span>
                                <?php endif; ?>
                            </div>
                            <div style="margin-top:var(--space-2);margin-left:calc(1.5em + var(--space-2))">
                            <?php if (in_array($q['question_type'], $CHOICE_TYPES) && !empty($choices)): ?>
                                <div style="display:flex;flex-direction:column;gap:var(--space-1)">
                                <?php
                                $correctIndices = [];
                                if ($q['question_type'] === 'checkboxes' && $q['correct_answer'])
                                    $correctIndices = json_decode($q['correct_answer'], true) ?? [];
                                foreach ($choices as $ci => $choice):
                                    $isCorrect = ($q['question_type'] === 'multiple_choice' && (int)$q['correct_index'] === $ci)
                                              || ($q['question_type'] === 'dropdown' && (int)$q['correct_index'] === $ci)
                                              || ($q['question_type'] === 'checkboxes' && in_array($ci, $correctIndices));
                                ?>
                                    <div style="display:flex;align-items:center;gap:var(--space-2);font-size:var(--text-sm)">
                                        <?php if ($isCorrect): ?>
                                            <?= icon('ic_fluent_checkmark_24_regular', 13, 'color:var(--success);flex-shrink:0') ?>
                                        <?php elseif ($q['question_type'] === 'checkboxes'): ?>
                                            <span style="width:13px;height:13px;border:1.5px solid var(--border);border-radius:3px;display:inline-block;flex-shrink:0"></span>
                                        <?php else: ?>
                                            <span style="width:13px;height:13px;border:1.5px solid var(--border);border-radius:50%;display:inline-block;flex-shrink:0"></span>
                                        <?php endif; ?>
                                        <span style="color:<?= $isCorrect ? 'var(--success)' : 'var(--text-secondary)' ?>"><?= e($choice) ?></span>
                                    </div>
                                <?php endforeach; ?>
                                </div>
                            <?php elseif ($q['question_type'] === 'linear_scale'): ?>
                                <div style="display:flex;align-items:center;gap:var(--space-2)">
                                    <?php if ($q['scale_min_label']): ?><span style="font-size:var(--text-xs);color:var(--text-tertiary)"><?= e($q['scale_min_label']) ?></span><?php endif; ?>
                                    <?php for ($s=$q['scale_min'];$s<=$q['scale_max'];$s++): ?><div class="scale-btn"><?= $s ?></div><?php endfor; ?>
                                    <?php if ($q['scale_max_label']): ?><span style="font-size:var(--text-xs);color:var(--text-tertiary)"><?= e($q['scale_max_label']) ?></span><?php endif; ?>
                                </div>
                            <?php elseif ($q['question_type'] === 'short_answer'): ?>
                                <div style="height:32px;border-bottom:2px solid var(--border);width:60%;font-size:var(--text-sm);color:var(--text-tertiary);display:flex;align-items:flex-end;padding-bottom:4px">
                                    <?= $q['correct_answer'] ? 'Expected: '.e($q['correct_answer']) : 'Short answer text' ?>
                                </div>
                            <?php elseif ($q['question_type'] === 'paragraph'): ?>
                                <div style="height:48px;border-bottom:2px solid var(--border);font-size:var(--text-sm);color:var(--text-tertiary);display:flex;align-items:flex-end;padding-bottom:4px">Paragraph text</div>
                            <?php endif; ?>
                            </div>
                        </div>
                        <div style="display:flex;flex-direction:column;gap:2px;flex-shrink:0;align-items:center">
                            <button type="button" class="drag-handle" title="Drag to reorder">
                                <svg width="14" height="14" fill="currentColor" viewBox="0 0 24 24">
                                    <path d="M8 6a1.5 1.5 0 100-3 1.5 1.5 0 000 3zM8 13.5a1.5 1.5 0 100-3 1.5 1.5 0 000 3zM8 21a1.5 1.5 0 100-3 1.5 1.5 0 000 3zM16 6a1.5 1.5 0 100-3 1.5 1.5 0 000 3zM16 13.5a1.5 1.5 0 100-3 1.5 1.5 0 000 3zM16 21a1.5 1.5 0 100-3 1.5 1.5 0 000 3z"/>
                                </svg>
                            </button>
                            <button class="btn-icon" title="Edit question" onclick="startInlineQFullEdit(<?= $q['id'] ?>)">
                                <?= icon('ic_fluent_edit_24_regular', 14) ?>
                            </button>
                            <form method="POST" onsubmit="return confirm('Remove this question?')" style="display:inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete_question">
                                <input type="hidden" name="question_id" value="<?= $q['id'] ?>">
                                <button class="btn-icon" style="color:var(--error)" title="Delete">
                                    <?= icon('ic_fluent_delete_24_regular', 14) ?>
                                </button>
                            </form>
                        </div>
                </div>
            </div>
        <?php } ?>

        <?php if (empty($sections) && empty($questions)): ?>
            <div class="card" style="padding:var(--space-12);text-align:left;color:var(--text-tertiary)">
                <div style="font-size:var(--text-sm)">Click <strong>Add Section</strong> to create a section and start adding questions.</div>
            </div>
        <?php else: ?>

            <?php foreach ($sections as $sec):
                $sc = $SECTION_COLORS[$sec['question_type']] ?? $SECTION_COLORS['multiple_choice'];
                $secQs    = $questionsBySection[$sec['id']] ?? [];
                $secQCount = count($secQs);
                $secJson   = htmlspecialchars(json_encode($sec));
            ?>
                <div class="card section-block" style="margin-bottom:var(--space-5);padding:0;overflow:visible" data-section-id="<?= $sec['id'] ?>">
                    <div class="section-header" style="background:<?= $sc['bg'] ?>;border-color:<?= $sc['border'] ?>;border-radius:var(--radius-md) var(--radius-md) 0 0;margin-bottom:0;align-items:flex-start">
                        <?php /* Stack title row + description vertically inside one flex
                                 cell so a long description wraps below the title instead
                                 of getting laid out alongside it (which used to push the
                                 title under the description and look like an overlap). */ ?>
                        <div style="flex:1;min-width:0;display:flex;flex-direction:column;gap:2px">
                            <div id="sec-title-view-<?= $sec['id'] ?>" style="display:flex;align-items:center;gap:var(--space-2);min-width:0">
                                <?= typeIcon($sec['question_type']) ?>
                                <span id="sec-title-text-<?= $sec['id'] ?>"
                                      style="font-size:var(--text-sm);font-weight:var(--weight-semibold);color:<?= $sc['text'] ?>;cursor:pointer"
                                      class="inline-editable"
                                      onclick="startInlineSecEdit(<?= $sec['id'] ?>)"
                                      title="Click to rename"><?= e($sec['title']) ?></span>
                                <span style="font-size:var(--text-xs);color:<?= $sc['text'] ?>;opacity:.65" id="sec-count-<?= $sec['id'] ?>"><?= count($secQs) ?> question<?= count($secQs)!==1?'s':'' ?></span>
                            </div>
                            <?php if (!empty($sec['description'])): ?>
                                <div style="font-size:var(--text-xs);color:<?= $sc['text'] ?>;opacity:.7;line-height:1.4;word-break:break-word"><?= e($sec['description']) ?></div>
                            <?php endif; ?>
                            <div id="sec-title-edit-<?= $sec['id'] ?>" style="display:none">
                                <input type="text" id="sec-title-input-<?= $sec['id'] ?>"
                                       class="inline-edit-input"
                                       value="<?= e($sec['title']) ?>"
                                       style="font-size:var(--text-sm);font-weight:var(--weight-semibold)">
                                <div class="inline-edit-actions">
                                    <button class="inline-save-btn" onclick="saveInlineSecTitle(<?= $sec['id'] ?>, '<?= e($sec['question_type']) ?>')">Save</button>
                                    <button class="inline-cancel-btn" onclick="cancelInlineSecEdit(<?= $sec['id'] ?>)">Cancel</button>
                                    <button class="inline-cancel-btn" onclick="openEditSectionModal(<?= $secJson ?>)" style="margin-left:auto">Full edit…</button>
                                </div>
                            </div>
                        </div>
                        <div class="section-actions">
                            <button class="btn-icon" title="Edit section" onclick="openEditSectionModal(<?= $secJson ?>)">
                                <?= icon('ic_fluent_edit_24_regular', 13) ?>
                            </button>
                            <form method="POST" style="display:inline"
                                  onsubmit="return confirmDeleteSection(this, <?= $secQCount ?>, <?= json_encode($sec['title']) ?>)">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete_section">
                                <input type="hidden" name="section_id" value="<?= $sec['id'] ?>">
                                <button class="btn-icon" style="color:var(--error)" title="Delete section" type="submit">
                                    <?= icon('ic_fluent_delete_24_regular', 13) ?>
                                </button>
                            </form>
                        </div>
                    </div>

                    <div class="section-questions" id="sec-questions-<?= $sec['id'] ?>" style="display:flex;flex-direction:column;padding:0">
                        <?php if (empty($secQs)): ?>
                            <div id="sec-empty-<?= $sec['id'] ?>" style="padding:var(--space-4) var(--space-5);border:1.5px dashed var(--border);border-radius:var(--radius-md);text-align:center;color:var(--text-tertiary);font-size:var(--text-sm)">
                                No questions yet — click <strong>Add question</strong> below to get started.
                            </div>
                        <?php else: ?>
                            <?php foreach ($secQs as $q): renderQuestionCard($q, $globalQNum, $QUESTION_TYPES, $CHOICE_TYPES); endforeach; ?>
                        <?php endif; ?>
                        <div id="inline-creator-<?= $sec['id'] ?>" class="inline-question-creator" style="display:none" data-section-id="<?= $sec['id'] ?>" data-section-type="<?= $sec['question_type'] ?>"></div>
                    </div>

                    <div style="border-top:1px solid var(--border);padding:var(--space-3) var(--space-4)">
                        <button class="btn btn-ghost btn-sm" id="sec-add-btn-<?= $sec['id'] ?>"
                                onclick="showInlineCreator(<?= $sec['id'] ?>, '<?= $sec['question_type'] ?>')"
                                style="display:flex;align-items:center;gap:5px;color:var(--accent)">
                            <?= icon('ic_fluent_add_24_regular', 13) ?>
                            Add question
                        </button>
                    </div>
                </div>
            <?php endforeach; ?>

        <?php endif; ?>
        </div><!-- /questions-list -->

        <div style="display:flex;justify-content:center;gap:var(--space-2);margin-top:var(--space-4)">
            <button class="btn btn-ghost btn-sm"
                    onclick="openAddSectionModal()"
                    style="display:flex;align-items:center;gap:5px">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none"><path stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M4 6h16M4 12h16M4 18h8"/><circle cx="19" cy="18" r="3" stroke="currentColor" stroke-width="2"/><path stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M19 16v4M17 18h4"/></svg>
                Add Section
            </button>
            <button class="btn btn-secondary btn-sm" onclick="openAiImportModal()" style="display:flex;align-items:center;gap:5px">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none"><path stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M12 2a7 7 0 017 7c0 2.5-1.3 4.7-3.3 6L12 22l-3.7-7C6.3 13.7 5 11.5 5 9a7 7 0 017-7z"/><circle cx="12" cy="9" r="2.5" fill="currentColor"/></svg>
                Import with AI
            </button>
        </div>

<?php else: ?>
    <div class="card" style="padding:var(--space-16);text-align:left;color:var(--text-tertiary)">
        <div style="font-size:var(--text-2xl);margin-bottom:var(--space-3)">📋</div>
        <p style="font-size:var(--text-sm)">Select an exam from the directory or create a new one.</p>
        <div style="margin-top:var(--space-4)">
            <a href="<?= url('/staff/exam') ?>?view=exams" class="btn btn-primary btn-sm">Go to Exam Builder</a>
        </div>
    </div>
<?php endif; ?>

<?php endif; /* end view routing */ ?>


<!-- ════════════════════════════════════════════════════════════
     CREATE EXAM MODAL
════════════════════════════════════════════════════════════ -->
<div id="create-exam-modal" class="modal-backdrop" style="display:none">
    <div class="modal" style="max-width:520px;max-height:92vh;overflow-y:auto">
        <div class="modal-header">
            <div class="modal-title">New Exam</div>
            <button class="btn-icon" onclick="closeModal('create-exam-modal')">
                <?= icon('ic_fluent_dismiss_24_regular', 18) ?>
            </button>
        </div>
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create_exam">
            <div class="modal-body" style="display:flex;flex-direction:column;gap:var(--space-4)">
                <div style="font-size:var(--text-sm);color:var(--text-secondary);background:var(--bg-subtle);
                            padding:var(--space-3) var(--space-4);border-radius:var(--radius-md)">
                    Title will be set automatically:
                    <strong>PLP Admissions Test (<?= e($schoolYear) ?>)</strong>
                    <div style="font-size:var(--text-xs);color:var(--text-tertiary);margin-top:2px">
                        Pulled from the active school year. Change it on the
                        <a href="<?= url('/admin/school-year') ?>">School Year</a> page.
                    </div>
                </div>
                <div>
                    <label class="form-label">Description</label>
                    <textarea name="description" class="form-control" rows="2" placeholder="Brief instructions shown to students…"></textarea>
                </div>

                <div style="display:flex;flex-direction:column;gap:var(--space-2)">
                    <label style="display:flex;align-items:center;gap:var(--space-3);cursor:pointer">
                        <input type="checkbox" name="shuffle_questions" style="accent-color:var(--accent)">
                        <span style="font-size:var(--text-sm)">Shuffle question order</span>
                    </label>
                    <label style="display:flex;align-items:center;gap:var(--space-3);cursor:pointer">
                        <input type="checkbox" name="shuffle_choices" style="accent-color:var(--accent)">
                        <span style="font-size:var(--text-sm)">Shuffle answer choices</span>
                    </label>
                </div>
                <div class="alert alert-warning" style="font-size:var(--text-sm)">
                    Creating a new exam will deactivate the currently active exam.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-ghost" onclick="closeModal('create-exam-modal')">Cancel</button>
                <button type="submit" class="btn btn-primary">Create Exam</button>
            </div>
        </form>
    </div>
</div>

<!-- ════════════════════════════════════════════════════════════
     EDIT EXAM MODAL
════════════════════════════════════════════════════════════ -->
<div id="edit-exam-modal" class="modal-backdrop" style="display:none">
    <div class="modal" style="max-width:520px;max-height:92vh;overflow-y:auto">
        <div class="modal-header">
            <div class="modal-title">Edit Exam</div>
            <button class="btn-icon" onclick="closeModal('edit-exam-modal')">
                <?= icon('ic_fluent_dismiss_24_regular', 18) ?>
            </button>
        </div>
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="edit_exam">
            <input type="hidden" name="exam_id" id="edit-exam-id">
            <div class="modal-body" style="display:flex;flex-direction:column;gap:var(--space-4)">
                <div style="font-size:var(--text-sm);color:var(--text-secondary);background:var(--bg-subtle);
                            padding:var(--space-3) var(--space-4);border-radius:var(--radius-md)">
                    Title is set automatically:
                    <strong>PLP Admissions Test (<?= e($schoolYear) ?>)</strong>
                    <div style="font-size:var(--text-xs);color:var(--text-tertiary);margin-top:2px">
                        Pulled from the active school year. Change it on the
                        <a href="<?= url('/admin/school-year') ?>">School Year</a> page.
                    </div>
                </div>
                <div>
                    <label class="form-label">Description</label>
                    <textarea name="description" id="edit-exam-desc" class="form-control" rows="2"></textarea>
                </div>

                <div style="display:flex;flex-direction:column;gap:var(--space-2)">
                    <label style="display:flex;align-items:center;gap:var(--space-3);cursor:pointer">
                        <input type="checkbox" name="shuffle_questions" id="edit-exam-shuffleQ" style="accent-color:var(--accent)">
                        <span style="font-size:var(--text-sm)">Shuffle question order</span>
                    </label>
                    <label style="display:flex;align-items:center;gap:var(--space-3);cursor:pointer">
                        <input type="checkbox" name="shuffle_choices" id="edit-exam-shuffleC" style="accent-color:var(--accent)">
                        <span style="font-size:var(--text-sm)">Shuffle answer choices</span>
                    </label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-ghost" onclick="closeModal('edit-exam-modal')">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<!-- ════════════════════════════════════════════════════════════
     ADD / EDIT SECTION MODAL
════════════════════════════════════════════════════════════ -->
<div id="section-modal" class="modal-backdrop" style="display:none">
    <div class="modal" style="max-width:440px">
        <div class="modal-header">
            <div class="modal-title" id="sec-modal-title">Add Section</div>
            <button class="btn-icon" onclick="closeModal('section-modal')">
                <?= icon('ic_fluent_dismiss_24_regular', 18) ?>
            </button>
        </div>
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" id="sec-action" value="create_section">
            <input type="hidden" name="exam_id" value="<?= $selectedExam['id'] ?? 0 ?>">
            <input type="hidden" name="section_id" id="sec-id" value="">
            <div class="modal-body" style="display:flex;flex-direction:column;gap:var(--space-4)">
                <div>
                    <label class="form-label">Section Title <span style="color:var(--error)">*</span></label>
                    <input type="text" name="section_title" id="sec-title-field" class="form-control"
                           placeholder="e.g. Part 1: Multiple Choice" required>
                </div>
                <div>
                    <label class="form-label">Description / Instructions</label>
                    <textarea name="section_desc" id="sec-desc-field" class="form-control" rows="2"
                              placeholder="e.g. Choose the best answer for each question."></textarea>
                </div>
                <div>
                    <label class="form-label">Answer Mode</label>
                    <p style="font-size:var(--text-xs);color:var(--text-tertiary);margin-bottom:var(--space-2)">
                        All questions added to this section will automatically use this type.
                    </p>
                    <div class="type-grid" id="sec-type-grid">
                        <?php foreach ($QUESTION_TYPES as $typeKey => $typeMeta):
                            $svgPaths = [
                                'multiple_choice' => '<circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="2"/><circle cx="12" cy="12" r="4" fill="currentColor"/>',
                                'checkboxes'      => '<rect x="3" y="3" width="18" height="18" rx="3" stroke="currentColor" stroke-width="2"/><path stroke="currentColor" stroke-width="2.5" stroke-linecap="round" d="M7 12l4 4 6-6"/>',
                                'dropdown'        => '<rect x="3" y="6" width="18" height="12" rx="2" stroke="currentColor" stroke-width="2"/><path stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M8 11l4 4 4-4"/>',
                                'short_answer'    => '<path stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M3 8h18M3 12h12"/>',
                                'paragraph'       => '<path stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M3 6h18M3 10h18M3 14h12M3 18h8"/>',
                                'linear_scale'    => '<path stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M3 12h18M7 8l-4 4 4 4M17 8l4 4-4 4"/>',
                            ];
                        ?>
                            <div class="type-option <?= $typeKey==='multiple_choice'?'selected':'' ?>"
                                 data-type="<?= $typeKey ?>"
                                 onclick="selectSectionType('<?= $typeKey ?>')">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none"><?= $svgPaths[$typeKey] ?></svg>
                                <?= $typeMeta['label'] ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <input type="hidden" name="section_type" id="sec-type-val" value="multiple_choice">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-ghost" onclick="closeModal('section-modal')">Cancel</button>
                <button type="submit" class="btn btn-primary" id="sec-submit-btn">Create Section</button>
            </div>
        </form>
    </div>
</div>


<script>
// Per-room access codes are generated on the Exam Slots page — the
// global Generate / Extend / countdown UI used to live here and has
// been removed in Chunk 7.

// ── Modal helpers ─────────────────────────────────────────────
function openModal(id)  { document.getElementById(id).style.display = 'flex'; }
function closeModal(id) { document.getElementById(id).style.display = 'none'; }

// ── Edit exam modal ───────────────────────────────────────────
// Title is auto-derived server-side from the active school year, so
// the form only collects description + shuffle flags.
function openEditExamModal(exam) {
    document.getElementById('edit-exam-id').value       = exam.id;
    document.getElementById('edit-exam-desc').value     = exam.description || '';
    document.getElementById('edit-exam-shuffleQ').checked = exam.shuffle_questions == 1;
    document.getElementById('edit-exam-shuffleC').checked = exam.shuffle_choices == 1;
    openModal('edit-exam-modal');
}

// ── Year → Title auto-generation ──────────────────────────────
(function() {
    // Create exam modal
    var createYear = document.getElementById('create-exam-year');
    var createPreview = document.getElementById('create-year-preview');
    var createHidden = document.getElementById('create-exam-title-hidden');
    if (createYear) {
        createYear.addEventListener('input', function() {
            var y = this.value.trim() || 'Year';
            createPreview.textContent = y;
            createHidden.value = 'PLP Admissions Test (' + this.value.trim() + ')';
        });
        // Set on form submit
        createYear.closest('form').addEventListener('submit', function() {
            createHidden.value = 'PLP Admissions Test (' + createYear.value.trim() + ')';
        });
    }
    // Edit exam modal
    var editYear = document.getElementById('edit-exam-year');
    var editPreview = document.getElementById('edit-year-preview');
    var editHidden = document.getElementById('edit-exam-title');
    if (editYear) {
        editYear.addEventListener('input', function() {
            var y = this.value.trim() || 'Year';
            editPreview.textContent = y;
            editHidden.value = 'PLP Admissions Test (' + this.value.trim() + ')';
        });
        editYear.closest('form').addEventListener('submit', function() {
            editHidden.value = 'PLP Admissions Test (' + editYear.value.trim() + ')';
        });
    }
})();

// ── Exam header edit mode toggle ──────────────────────────────
function enterExamEditMode() {
    document.getElementById('exam-header-card').dataset.editState = 'edit';
}
function exitExamEditMode() {
    document.getElementById('exam-header-card').dataset.editState = 'read';
}

// ── Inline exam title edit ────────────────────────────────────
function startInlineExamEdit() {
    document.getElementById('exam-title-view').style.display = 'none';
    document.getElementById('exam-title-edit').style.display = '';
    document.getElementById('exam-title-input').focus();
    document.getElementById('exam-title-input').select();
}
function cancelInlineExamEdit() {
    document.getElementById('exam-title-edit').style.display = 'none';
    document.getElementById('exam-title-view').style.display = '';
}
function saveInlineExamTitle() {
    const inp = document.getElementById('exam-title-input');
    const newTitle = inp.value.trim();
    if (!newTitle) return;
    const csrfInput = document.querySelector('input[name^="_csrf"]');
    const fd = new FormData();
    fd.append('action', 'edit_exam');
    fd.append('exam_id', <?= $selectedExam['id'] ?? 0 ?>);
    fd.append(csrfInput.name, csrfInput.value);
    fd.append('title', newTitle);
    fetch(location.href, { method:'POST', body: fd, headers: {'X-Requested-With':'XMLHttpRequest'} })
        .then(r => r.json()).then(d => {
            if (d.ok) {
                document.getElementById('exam-title-text').textContent = newTitle;
                cancelInlineExamEdit();
            }
        }).catch(() => { location.reload(); });
}

// ── Inline section title edit ─────────────────────────────────
function startInlineSecEdit(sid) {
    document.getElementById(`sec-title-view-${sid}`).style.display = 'none';
    document.getElementById(`sec-title-edit-${sid}`).style.display = '';
    document.getElementById(`sec-title-input-${sid}`).focus();
    document.getElementById(`sec-title-input-${sid}`).select();
}
function cancelInlineSecEdit(sid) {
    document.getElementById(`sec-title-edit-${sid}`).style.display = 'none';
    document.getElementById(`sec-title-view-${sid}`).style.display = 'flex';
}
function saveInlineSecTitle(sid, secType) {
    const inp = document.getElementById(`sec-title-input-${sid}`);
    const newTitle = inp.value.trim();
    if (!newTitle) return;
    const csrfInput = document.querySelector('input[name^="_csrf"]');
    const fd = new FormData();
    fd.append('action', 'edit_section');
    fd.append('section_id', sid);
    fd.append('section_title', newTitle);
    fd.append('section_type', secType);
    fd.append(csrfInput.name, csrfInput.value);
    fetch(location.href, { method:'POST', body: fd, headers: {'X-Requested-With':'XMLHttpRequest'} })
        .then(r => r.json()).then(d => {
            if (d.ok) {
                document.getElementById(`sec-title-text-${sid}`).textContent = newTitle;
                cancelInlineSecEdit(sid);
            }
        }).catch(() => { location.reload(); });
}

// ── Section modal ─────────────────────────────────────────────
function selectSectionType(type) {
    document.getElementById('sec-type-val').value = type;
    document.querySelectorAll('#sec-type-grid .type-option').forEach(el => {
        el.classList.toggle('selected', el.dataset.type === type);
    });
}
function openAddSectionModal() {
    document.getElementById('sec-modal-title').textContent = 'Add Section';
    document.getElementById('sec-action').value            = 'create_section';
    document.getElementById('sec-id').value                = '';
    document.getElementById('sec-title-field').value       = '';
    document.getElementById('sec-desc-field').value        = '';
    document.getElementById('sec-submit-btn').textContent  = 'Create Section';
    selectSectionType('multiple_choice');
    openModal('section-modal');
}
function confirmDeleteSection(form, qCount, title) {
    if (qCount === 0) return confirm(`Remove "${title}"?`);
    return confirm(`Remove "${title}" and its ${qCount} question${qCount !== 1 ? 's' : ''}?`);
}

function openEditSectionModal(sec) {
    document.getElementById('sec-modal-title').textContent = 'Edit Section';
    document.getElementById('sec-action').value            = 'edit_section';
    document.getElementById('sec-id').value               = sec.id;
    document.getElementById('sec-title-field').value      = sec.title;
    document.getElementById('sec-desc-field').value       = sec.description || '';
    document.getElementById('sec-submit-btn').textContent  = 'Save Section';
    selectSectionType(sec.question_type);
    openModal('section-modal');
}

// ── Full inline question edit ─────────────────────────────────
const IQ_EDIT_CHOICES = {};

function startInlineQFullEdit(qid) {
    const card = document.querySelector(`.q-card[data-qid="${qid}"]`);
    if (!card || card.dataset.editing === '1') return;
    card.dataset.editing = '1';
    card.style.borderColor = 'var(--accent)';

    const q = JSON.parse(card.dataset.q);
    const CHOICE_TYPES = ['multiple_choice','checkboxes','dropdown'];

    // Parse existing choices into the edit state
    let choices = [];
    if (CHOICE_TYPES.includes(q.question_type) && q.choices) {
        const raw = JSON.parse(q.choices);
        const correctIndices = (q.question_type === 'checkboxes' && q.correct_answer)
            ? JSON.parse(q.correct_answer) : [];
        const singleCorrect = parseInt(q.correct_index ?? 0);
        choices = raw.map((text, i) => ({
            text,
            correct: q.question_type === 'checkboxes' ? correctIndices.includes(i) : i === singleCorrect
        }));
    }
    IQ_EDIT_CHOICES[qid] = choices;

    card.querySelector('.q-view-mode').style.display = 'none';
    const editEl = document.createElement('div');
    editEl.className = 'q-edit-mode';
    editEl.style.cssText = 'padding:var(--space-4) var(--space-5);display:flex;flex-direction:column;gap:var(--space-3);animation:creator-slide-in .15s ease';
    editEl.innerHTML = buildQEditHTML(q);
    card.appendChild(editEl);
    card.querySelector(`#qe-text-${qid}`)?.focus();
}

function buildQEditHTML(q) {
    const qid = q.id;
    const CHOICE_TYPES = ['multiple_choice','checkboxes','dropdown'];
    const isChoice = CHOICE_TYPES.includes(q.question_type);
    const choices = IQ_EDIT_CHOICES[qid] || [];

    let answerHTML = '';
    if (isChoice) {
        const isCheckbox = q.question_type === 'checkboxes';
        const rows = choices.map((c, i) => buildEditChoiceRow(c, i, qid, q.question_type)).join('');
        answerHTML = `
            <div>
                <label class="form-label">Answer Choices</label>
                <div id="qe-choices-${qid}" style="display:flex;flex-direction:column;gap:var(--space-2)">${rows}</div>
                <button type="button" class="iq-add-choice" onclick="addEditChoice(${qid},'${q.question_type}')">
                    <?= icon('ic_fluent_add_24_regular', 11) ?>
                    Add choice
                </button>
                <p style="font-size:var(--text-xs);color:var(--text-tertiary);margin-top:4px">${isCheckbox?'Check all correct answers.':'Select the one correct answer.'}</p>
            </div>`;
    } else if (q.question_type === 'short_answer') {
        answerHTML = `
            <div>
                <label class="form-label">Expected Answer <span style="font-size:var(--text-xs);font-weight:400;color:var(--text-tertiary)">(optional)</span></label>
                <input type="text" id="qe-expected-${qid}" class="form-control" value="${escQA(q.correct_answer||'')}" placeholder="Leave blank for manual grading">
            </div>`;
    } else if (q.question_type === 'linear_scale') {
        answerHTML = `
            <div style="display:grid;grid-template-columns:80px 80px 1fr 1fr;gap:var(--space-3);align-items:end">
                <div><label class="form-label">Min</label>
                    <select id="qe-scale-min-${qid}" class="form-control">
                        <option value="0" ${q.scale_min==0?'selected':''}>0</option>
                        <option value="1" ${q.scale_min!=0?'selected':''}>1</option>
                    </select></div>
                <div><label class="form-label">Max</label>
                    <select id="qe-scale-max-${qid}" class="form-control">
                        ${[2,3,4,5,6,7,8,9,10].map(n=>`<option value="${n}"${q.scale_max==n?' selected':''}>${n}</option>`).join('')}
                    </select></div>
                <div><label class="form-label">Min label</label>
                    <input type="text" id="qe-scale-min-label-${qid}" class="form-control" value="${escQA(q.scale_min_label||'')}" placeholder="e.g. Strongly disagree"></div>
                <div><label class="form-label">Max label</label>
                    <input type="text" id="qe-scale-max-label-${qid}" class="form-control" value="${escQA(q.scale_max_label||'')}" placeholder="e.g. Strongly agree"></div>
            </div>`;
    }

    return `
        <div>
            <label class="form-label">Question <span style="color:var(--error)">*</span></label>
            <textarea id="qe-text-${qid}" class="form-control" rows="2">${escQT(q.question_text)}</textarea>
        </div>
        ${answerHTML}
        <div style="display:flex;gap:var(--space-4);align-items:flex-end">
            <div style="width:88px">
                <label class="form-label">Points</label>
                <input type="number" id="qe-points-${qid}" class="form-control" value="${q.points}" min="0" max="100">
            </div>
            <label style="display:flex;align-items:center;gap:var(--space-2);cursor:pointer;padding-bottom:6px;font-size:var(--text-sm)">
                <input type="checkbox" id="qe-required-${qid}" ${q.is_required?'checked':''} style="accent-color:var(--accent)"> Required
            </label>
        </div>
        <div style="display:flex;gap:var(--space-2);padding-top:var(--space-2);border-top:1px solid var(--border)">
            <button type="button" class="btn btn-primary btn-sm" onclick="saveInlineQFullEdit(${qid})">Save changes</button>
            <button type="button" class="btn btn-ghost btn-sm" onclick="cancelInlineQFullEdit(${qid})">Cancel</button>
        </div>`;
}

function buildEditChoiceRow(choice, idx, qid, qType) {
    const isCheckbox = qType === 'checkboxes';
    const inputType = isCheckbox ? 'checkbox' : 'radio';
    const name = isCheckbox ? `qe_cb_${qid}_${idx}` : `qe_rb_${qid}`;
    const delBtn = idx >= 2
        ? `<button type="button" class="btn-icon" style="color:var(--error);flex-shrink:0" onclick="removeEditChoice(${qid},${idx},'${qType}')">
               <?= icon('ic_fluent_dismiss_24_regular', 13) ?>
           </button>`
        : '<div style="width:22px"></div>';
    return `<div class="iq-choice-row" data-choice-idx="${idx}">
        <input type="${inputType}" name="${name}" value="${idx}" ${choice.correct?'checked':''} style="accent-color:var(--accent);flex-shrink:0"
               onchange="syncEditCorrect(${qid},${idx},'${qType}',this)">
        <input type="text" class="form-control" value="${escQA(choice.text)}" placeholder="Choice ${String.fromCharCode(65+idx)}"
               oninput="IQ_EDIT_CHOICES[${qid}][${idx}].text=this.value">
        ${delBtn}
    </div>`;
}

function syncEditCorrect(qid, idx, qType, el) {
    const choices = IQ_EDIT_CHOICES[qid]; if (!choices) return;
    if (qType === 'checkboxes') choices[idx].correct = el.checked;
    else choices.forEach((c,i) => c.correct = (i===idx));
}
function addEditChoice(qid, qType) {
    const choices = IQ_EDIT_CHOICES[qid]; if (!choices) return;
    choices.push({text:'', correct:false});
    const listEl = document.getElementById(`qe-choices-${qid}`);
    const idx = choices.length - 1;
    const div = document.createElement('div');
    div.innerHTML = buildEditChoiceRow(choices[idx], idx, qid, qType);
    listEl.appendChild(div.firstElementChild);
    listEl.lastElementChild.querySelector('input[type=text]')?.focus();
}
function removeEditChoice(qid, idx, qType) {
    const choices = IQ_EDIT_CHOICES[qid]; if (!choices) return;
    choices.splice(idx, 1);
    if (choices.length > 0 && !choices.some(c=>c.correct)) choices[0].correct = true;
    document.getElementById(`qe-choices-${qid}`).innerHTML =
        choices.map((c,i) => buildEditChoiceRow(c, i, qid, qType)).join('');
}
function cancelInlineQFullEdit(qid) {
    const card = document.querySelector(`.q-card[data-qid="${qid}"]`); if (!card) return;
    delete card.dataset.editing; delete IQ_EDIT_CHOICES[qid];
    card.style.borderColor = '';
    card.querySelector('.q-edit-mode')?.remove();
    card.querySelector('.q-view-mode').style.display = 'flex';
}
async function saveInlineQFullEdit(qid) {
    const card = document.querySelector(`.q-card[data-qid="${qid}"]`); if (!card) return;
    const q = JSON.parse(card.dataset.q);
    const qText = document.getElementById(`qe-text-${qid}`)?.value.trim();
    if (!qText) { document.getElementById(`qe-text-${qid}`).focus(); return; }

    const saveBtn = card.querySelector('.q-edit-mode .btn-primary');
    if (saveBtn) { saveBtn.disabled=true; saveBtn.textContent='Saving…'; }

    const examId = <?= $selectedExam['id'] ?? 0 ?>;
    const csrfInput = document.querySelector('input[name^="_csrf"]');
    const CHOICE_TYPES = ['multiple_choice','checkboxes','dropdown'];
    const choices = IQ_EDIT_CHOICES[qid] || [];

    const fd = new FormData();
    fd.append('action','edit_question'); fd.append('exam_id',examId);
    fd.append('question_id',qid); fd.append('section_id',q.section_id||'');
    fd.append(csrfInput.name,csrfInput.value);
    fd.append('question_text',qText);
    fd.append('question_type',q.question_type);
    fd.append('points',document.getElementById(`qe-points-${qid}`)?.value||1);
    if (document.getElementById(`qe-required-${qid}`)?.checked) fd.append('is_required','1');

    if (CHOICE_TYPES.includes(q.question_type)) {
        const texts = choices.map(c=>c.text).filter(t=>t.trim());
        if (texts.length < 2) { alert('Add at least 2 choices.'); if(saveBtn){saveBtn.disabled=false;saveBtn.textContent='Save changes';} return; }
        texts.forEach(t=>fd.append('choices[]',t));
        if (q.question_type==='checkboxes') choices.forEach((c,i)=>{if(c.correct)fd.append('correct_indices[]',i);});
        else { const ci=choices.findIndex(c=>c.correct); fd.append('correct_index',ci>=0?ci:0); }
    } else if (q.question_type==='short_answer') {
        fd.append('expected_answer',document.getElementById(`qe-expected-${qid}`)?.value.trim()||'');
    } else if (q.question_type==='linear_scale') {
        fd.append('scale_min',document.getElementById(`qe-scale-min-${qid}`)?.value||1);
        fd.append('scale_max',document.getElementById(`qe-scale-max-${qid}`)?.value||5);
        fd.append('scale_min_label',document.getElementById(`qe-scale-min-label-${qid}`)?.value.trim()||'');
        fd.append('scale_max_label',document.getElementById(`qe-scale-max-label-${qid}`)?.value.trim()||'');
    }
    try {
        const resp = await fetch(location.href,{method:'POST',body:fd});
        if (resp.ok) window.location.reload();
        else { if(saveBtn){saveBtn.disabled=false;saveBtn.textContent='Save changes';} alert('Unable to save. Try again.'); }
    } catch(e) { window.location.reload(); }
}
function escQT(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}
function escQA(s){return String(s||'').replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}

// ── Inline question creator ───────────────────────────────────
const CHOICE_TYPE_KEYS = ['multiple_choice','checkboxes','dropdown'];

function showInlineCreator(secId, secType) {
    // Hide the "Add question" button while creating
    document.getElementById(`sec-add-btn-${secId}`).style.display = 'none';
    // Hide empty state if present
    const emptyEl = document.getElementById(`sec-empty-${secId}`);
    if (emptyEl) emptyEl.style.display = 'none';

    const container = document.getElementById(`inline-creator-${secId}`);
    container.dataset.choices = JSON.stringify(
        CHOICE_TYPE_KEYS.includes(secType)
            ? [{text:'',correct:true},{text:'',correct:false},{text:'',correct:false},{text:'',correct:false}]
            : []
    );
    renderInlineCreator(container, secId, secType);
    container.style.display = '';
    container.querySelector('textarea')?.focus();
}

function hideInlineCreator(secId) {
    const container = document.getElementById(`inline-creator-${secId}`);
    container.style.display = 'none';
    container.innerHTML = '';
    document.getElementById(`sec-add-btn-${secId}`).style.display = 'flex';
    // Restore empty state if no questions exist
    const emptyEl = document.getElementById(`sec-empty-${secId}`);
    if (emptyEl) emptyEl.style.display = '';
}

function renderInlineCreator(container, secId, secType) {
    const choices = JSON.parse(container.dataset.choices || '[]');
    const isChoice = CHOICE_TYPE_KEYS.includes(secType);
    const isCheckbox = secType === 'checkboxes';
    const typeLabelMap = <?= json_encode(array_map(fn($v) => $v['label'], $QUESTION_TYPES)) ?>;
    const scColors = <?= json_encode($SECTION_COLORS) ?>;
    const sc = scColors[secType] || scColors['multiple_choice'];

    let choicesHtml = '';
    if (isChoice) {
        choicesHtml = `<div>
            <label class="form-label" style="margin-bottom:var(--space-2)">Answer Choices</label>
            <div id="iq-choices-${secId}" style="display:flex;flex-direction:column;gap:var(--space-2)">
                ${choices.map((c, i) => renderChoiceRow(c, i, secId, secType)).join('')}
            </div>
            <button type="button" class="iq-add-choice" onclick="addInlineChoice(${secId}, '${secType}')">
                <?= icon('ic_fluent_add_24_regular', 11) ?>
                Add choice
            </button>
            <p style="font-size:var(--text-xs);color:var(--text-tertiary);margin-top:4px">${isCheckbox ? 'Check all correct answers.' : 'Select the one correct answer.'}</p>
        </div>`;
    } else if (secType === 'short_answer') {
        choicesHtml = `<div>
            <label class="form-label">Expected Answer <span style="font-size:var(--text-xs);font-weight:400;color:var(--text-tertiary)">(optional)</span></label>
            <input type="text" id="iq-expected-${secId}" class="form-control" placeholder="Leave blank for manual grading">
        </div>`;
    } else if (secType === 'linear_scale') {
        choicesHtml = `<div style="display:grid;grid-template-columns:80px 80px 1fr 1fr;gap:var(--space-3);align-items:end">
            <div>
                <label class="form-label">Min</label>
                <select id="iq-scale-min-${secId}" class="form-control"><option value="0">0</option><option value="1" selected>1</option></select>
            </div>
            <div>
                <label class="form-label">Max</label>
                <select id="iq-scale-max-${secId}" class="form-control">${[2,3,4,5,6,7,8,9,10].map(n=>`<option value="${n}"${n===5?' selected':''}>${n}</option>`).join('')}</select>
            </div>
            <div><label class="form-label">Min label</label><input type="text" id="iq-scale-min-label-${secId}" class="form-control" placeholder="e.g. Strongly disagree"></div>
            <div><label class="form-label">Max label</label><input type="text" id="iq-scale-max-label-${secId}" class="form-control" placeholder="e.g. Strongly agree"></div>
        </div>`;
    }

    container.innerHTML = `
        <div class="iq-body">
            <div style="display:flex;align-items:center;gap:var(--space-2)">
                <span class="iq-type-badge" style="background:${sc.bg};color:${sc.text};border:1px solid ${sc.border}">
                    ${typeLabelMap[secType] || secType}
                </span>
                <span style="font-size:var(--text-xs);color:var(--text-tertiary)">New question</span>
            </div>
            <div>
                <label class="form-label">Question <span style="color:var(--error)">*</span></label>
                <textarea id="iq-text-${secId}" class="form-control" rows="2" placeholder="Enter your question…"></textarea>
            </div>
            ${choicesHtml}
            <div style="display:flex;gap:var(--space-4);align-items:flex-end">
                <div style="width:88px">
                    <label class="form-label">Points</label>
                    <input type="number" id="iq-points-${secId}" class="form-control" value="1" min="0" max="100">
                </div>
                <label style="display:flex;align-items:center;gap:var(--space-2);cursor:pointer;padding-bottom:6px;font-size:var(--text-sm)">
                    <input type="checkbox" id="iq-required-${secId}" checked style="accent-color:var(--accent)">
                    Required
                </label>
            </div>
        </div>
        <div class="iq-footer">
            <button type="button" class="btn btn-ghost btn-sm" onclick="hideInlineCreator(${secId})">Cancel</button>
            <button type="button" class="btn btn-primary btn-sm" onclick="saveInlineQuestion(${secId}, '${secType}')">Save question</button>
        </div>`;
}

function renderChoiceRow(choice, idx, secId, secType) {
    const isCheckbox = secType === 'checkboxes';
    const inputType = isCheckbox ? 'checkbox' : 'radio';
    const name = isCheckbox ? `iq_correct_${secId}_${idx}` : `iq_correct_${secId}`;
    const checked = choice.correct ? 'checked' : '';
    const delBtn = idx >= 2
        ? `<button type="button" class="btn-icon" style="color:var(--error);flex-shrink:0"
               onclick="removeInlineChoice(${secId}, ${idx}, '${secType}')">
               <?= icon('ic_fluent_dismiss_24_regular', 13) ?>
           </button>`
        : '<div style="width:22px"></div>';
    return `<div class="iq-choice-row" data-choice-idx="${idx}">
        <input type="${inputType}" name="${name}" value="${idx}" ${checked} style="accent-color:var(--accent);flex-shrink:0"
               onchange="syncChoiceCorrect(${secId}, ${idx}, '${secType}', this)">
        <input type="text" class="form-control" value="${escapeHtmlAttr(choice.text)}"
               placeholder="Choice ${String.fromCharCode(65+idx)}"
               oninput="syncChoiceText(${secId}, ${idx}, this.value)">
        ${delBtn}
    </div>`;
}

function escapeHtmlAttr(s) {
    return String(s||'').replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

function getCreatorChoices(secId) {
    const container = document.getElementById(`inline-creator-${secId}`);
    return JSON.parse(container.dataset.choices || '[]');
}
function setCreatorChoices(secId, choices) {
    document.getElementById(`inline-creator-${secId}`).dataset.choices = JSON.stringify(choices);
}

function syncChoiceText(secId, idx, val) {
    const choices = getCreatorChoices(secId);
    if (choices[idx]) choices[idx].text = val;
    setCreatorChoices(secId, choices);
}
function syncChoiceCorrect(secId, idx, secType, el) {
    const choices = getCreatorChoices(secId);
    if (secType === 'checkboxes') {
        choices[idx].correct = el.checked;
    } else {
        choices.forEach((c,i) => c.correct = (i === idx));
    }
    setCreatorChoices(secId, choices);
}
function addInlineChoice(secId, secType) {
    const choices = getCreatorChoices(secId);
    choices.push({text:'', correct:false});
    setCreatorChoices(secId, choices);
    const listEl = document.getElementById(`iq-choices-${secId}`);
    const idx = choices.length - 1;
    const rowDiv = document.createElement('div');
    rowDiv.innerHTML = renderChoiceRow(choices[idx], idx, secId, secType);
    listEl.appendChild(rowDiv.firstElementChild);
    listEl.lastElementChild.querySelector('input[type=text]')?.focus();
}
function removeInlineChoice(secId, idx, secType) {
    const choices = getCreatorChoices(secId);
    choices.splice(idx, 1);
    // Reset correct if needed
    if (!choices.some(c => c.correct) && choices.length > 0) choices[0].correct = true;
    setCreatorChoices(secId, choices);
    const container = document.getElementById(`inline-creator-${secId}`);
    renderInlineCreator(container, secId, secType);
}

async function saveInlineQuestion(secId, secType) {
    const qText = document.getElementById(`iq-text-${secId}`)?.value.trim();
    if (!qText) {
        document.getElementById(`iq-text-${secId}`).focus();
        document.getElementById(`iq-text-${secId}`).style.borderColor = 'var(--error)';
        return;
    }
    const choices = getCreatorChoices(secId);
    const points  = parseInt(document.getElementById(`iq-points-${secId}`)?.value || 1);
    const required = document.getElementById(`iq-required-${secId}`)?.checked ? 1 : 0;
    const examId  = <?= $selectedExam['id'] ?? 0 ?>;
    const csrfInput = document.querySelector('input[name^="_csrf"]');

    const fd = new FormData();
    fd.append('action', 'add_question');
    fd.append('exam_id', examId);
    fd.append('section_id', secId);
    fd.append('question_text', qText);
    fd.append('question_type', secType);
    fd.append('points', points);
    if (required) fd.append('is_required', '1');
    fd.append(csrfInput.name, csrfInput.value);

    const isChoice = CHOICE_TYPE_KEYS.includes(secType);
    if (isChoice) {
        const texts = choices.map(c => c.text).filter(t => t.trim());
        if (texts.length < 2) {
            alert('Add at least 2 choices.');
            return;
        }
        texts.forEach(t => fd.append('choices[]', t));
        if (secType === 'checkboxes') {
            choices.forEach((c,i) => { if (c.correct) fd.append('correct_indices[]', i); });
        } else {
            const ci = choices.findIndex(c => c.correct);
            fd.append('correct_index', ci >= 0 ? ci : 0);
        }
    } else if (secType === 'short_answer') {
        const exp = document.getElementById(`iq-expected-${secId}`)?.value.trim();
        if (exp) fd.append('expected_answer', exp);
    } else if (secType === 'linear_scale') {
        fd.append('scale_min', document.getElementById(`iq-scale-min-${secId}`)?.value || 1);
        fd.append('scale_max', document.getElementById(`iq-scale-max-${secId}`)?.value || 5);
        const minL = document.getElementById(`iq-scale-min-label-${secId}`)?.value.trim();
        const maxL = document.getElementById(`iq-scale-max-label-${secId}`)?.value.trim();
        if (minL) fd.append('scale_min_label', minL);
        if (maxL) fd.append('scale_max_label', maxL);
    }

    // Disable save button
    const saveBtn = document.querySelector(`#inline-creator-${secId} .btn-primary`);
    if (saveBtn) { saveBtn.disabled = true; saveBtn.textContent = 'Saving…'; }

    try {
        const resp = await fetch(location.href, { method: 'POST', body: fd });
        if (resp.ok) {
            window.location.reload();
        } else {
            if (saveBtn) { saveBtn.disabled = false; saveBtn.textContent = 'Save question'; }
            alert('Unable to save. Try again.');
        }
    } catch(e) {
        window.location.reload();
    }
}

// ── Per-course passing scores panel toggle ────────────────
function togglePassingPanel() {
    const panel   = document.getElementById('passing-edit-panel');
    const summary = document.getElementById('passing-summary');
    const icon    = document.getElementById('passing-toggle-icon');
    const btn     = document.getElementById('passing-toggle-btn');
    const isOpen  = panel.style.display !== 'none';
    panel.style.display   = isOpen ? 'none' : 'block';
    summary.style.display = isOpen ? '' : 'none';
    icon.style.transform  = isOpen ? '' : 'rotate(180deg)';
    btn.lastChild.textContent = isOpen ? ' Edit tiers' : ' Close';
}

// Live-update the Low label and enforce high > avg constraints
(function() {
    document.addEventListener('input', function(e) {
        const el = e.target;
        if (!el.classList.contains('passing-high') && !el.classList.contains('passing-avg')) return;
        const course = el.dataset.course;
        if (!course) return;
        const row   = el.closest('div[style*="grid-template-columns"]');
        if (!row) return;
        const highEl = row.querySelector('.passing-high');
        const avgEl  = row.querySelector('.passing-avg');
        const lowEl  = row.querySelector('.low-label');
        if (!highEl || !avgEl || !lowEl) return;
        const highVal = parseInt(highEl.value, 10) || 7;
        const avgVal  = parseInt(avgEl.value,  10) || 4;
        // Enforce avg < high
        if (el.classList.contains('passing-high') && avgVal >= highVal) {
            avgEl.value = Math.max(1, highVal - 1);
        }
        if (el.classList.contains('passing-avg') && avgVal >= highVal) {
            highEl.value = Math.min(10, avgVal + 1);
        }
        const finalAvg = parseInt(avgEl.value, 10) || 4;
        lowEl.textContent = finalAvg <= 1 ? '—' : '1–' + (finalAvg - 1);
    });
})();

// Section-scoped drag-and-drop (questions stay inside their section)
(function() {
    const sectionContainers = document.querySelectorAll('.section-questions');
    if (!sectionContainers.length) return;
    let dragged = null;
    let sourceContainer = null;

    sectionContainers.forEach(container => {
        container.querySelectorAll('.q-card').forEach(card => {
            card.draggable = true;
            card.addEventListener('dragstart', () => {
                dragged = card;
                sourceContainer = container;
                card.classList.add('dragging');
            });
            card.addEventListener('dragend', () => {
                card.classList.remove('dragging');
                dragged = null;
                saveOrder();
            });
        });

        container.addEventListener('dragover', e => {
            e.preventDefault();
            if (!dragged || sourceContainer !== container) return;
            const after = getDragAfterElement(container, e.clientY);
            if (after === null) container.appendChild(dragged);
            else container.insertBefore(dragged, after);
        });
    });

    function getDragAfterElement(container, y) {
        const els = [...container.querySelectorAll('.q-card:not(.dragging)')];
        return els.reduce((closest, el) => {
            const box = el.getBoundingClientRect();
            const offset = y - box.top - box.height / 2;
            return offset < 0 && offset > closest.offset ? {offset, el} : closest;
        }, {offset: Number.NEGATIVE_INFINITY}).el ?? null;
    }

    function saveOrder() {
        const order = [];
        document.querySelectorAll('.section-questions .q-card').forEach(el => {
            order.push(el.dataset.qid);
        });
        const csrfInput = document.querySelector('input[name^="_csrf"]');
        const fd = new FormData();
        fd.append('action', 'reorder_questions');
        fd.append(csrfInput.name, csrfInput.value);
        order.forEach(id => fd.append('order[]', id));
        fetch(location.href, {method:'POST', body: fd}).catch(()=>{});
    }
})();
</script>

<!-- ════════════════════════════════════════════════════════════
     AI IMPORT MODAL
════════════════════════════════════════════════════════════ -->
<style>
@keyframes ai-spin { to { transform: rotate(360deg); } }
@keyframes ai-pulse { 0%,100%{opacity:1} 50%{opacity:.4} }
.ai-status-bar { display:flex;align-items:center;gap:10px;padding:10px 14px;border-radius:var(--radius-md);font-size:var(--text-sm);border:1px solid; }
.ai-status-bar.connected { background:#f0faf4;border-color:#a7d9b8;color:#1a5c32; }
.ai-status-bar.disconnected { background:#fafafa;border-color:var(--border);color:var(--text-secondary); }
.ai-status-dot { width:8px;height:8px;border-radius:50%;flex-shrink:0; }
.ai-status-bar.connected .ai-status-dot { background:#22c55e; }
.ai-status-bar.disconnected .ai-status-dot { background:var(--neutral-400);animation:ai-pulse 2s ease-in-out infinite; }
.ai-dropzone { border:2px dashed var(--border);border-radius:var(--radius-md);padding:var(--space-6) var(--space-5);text-align:center;cursor:pointer;transition:border-color .15s,background .15s; }
.ai-dropzone:hover,.ai-dropzone.dragover { border-color:var(--accent);background:rgba(45,106,79,.03); }
.ai-dropzone.has-file { border-style:solid;border-color:var(--accent);background:rgba(45,106,79,.04); }
.ai-dropzone-icon { width:40px;height:40px;margin:0 auto var(--space-2);border-radius:var(--radius-md);background:var(--neutral-100);display:flex;align-items:center;justify-content:center; }
.ai-file-tag { display:inline-flex;align-items:center;gap:6px;background:var(--accent);color:#fff;border-radius:var(--radius-sm);padding:4px 10px;font-size:var(--text-xs);font-weight:var(--weight-medium);margin-top:var(--space-2); }
.ai-file-tag .rm { cursor:pointer;opacity:.7;background:none;border:none;color:#fff;padding:0;font-size:14px;display:flex;align-items:center;line-height:1; }
.ai-file-tag .rm:hover { opacity:1; }
.ai-progress-wrap { padding: var(--space-2) 0; }
.ai-progress-track { height:5px;background:var(--border);border-radius:99px;overflow:hidden;margin-bottom:var(--space-3); }
.ai-progress-fill { height:100%;background:var(--accent);border-radius:99px;width:0%;transition:width .4s cubic-bezier(.4,0,.2,1); }
.ai-progress-step { font-size:var(--text-xs);color:var(--text-tertiary);margin-top:2px; }
.ai-q-card { border:1px solid #e5e7eb;border-radius:var(--radius-md);overflow:hidden;background:#fff;flex-shrink:0; }
.ai-q-card-head { display:flex;align-items:center;gap:8px;padding:9px 14px;background:#f9fafb;font-size:var(--text-xs);color:#6b7280; }
.ai-q-card-body { padding:12px 14px;background:#fff; }
.ai-q-text { font-size:var(--text-sm);font-weight:var(--weight-medium);color:#111827;margin-bottom:8px;line-height:1.45; }
.ai-choice-row { display:flex;align-items:center;gap:7px;padding:3px 0;font-size:var(--text-sm);color:#374151; }
.ai-choice-row.correct { color:#16a34a;font-weight:var(--weight-medium); }
.ai-choice-ind { width:14px;height:14px;border-radius:50%;flex-shrink:0;border:1.5px solid var(--border); }
.ai-choice-row.correct .ai-choice-ind { background:#16a34a;border-color:#16a34a;display:flex;align-items:center;justify-content:center; }
.ai-warn-strip { display:flex;align-items:flex-start;gap:8px;background:#fffbeb;border:1px solid #fcd34d;border-radius:var(--radius-md);padding:10px 12px;font-size:var(--text-xs);color:#92400e;line-height:1.5; }
</style>

<div id="ai-import-modal" class="modal-backdrop" style="display:none">
    <div class="modal" style="max-width:500px;max-height:90vh;display:flex;flex-direction:column">
        <?= csrf_field() ?>
        <div class="modal-header" style="padding:var(--space-4) var(--space-5);border-bottom:1px solid var(--border)">
            <div style="display:flex;align-items:center;gap:var(--space-3)">
                <div style="width:34px;height:34px;border-radius:var(--radius-md);background:var(--accent);display:flex;align-items:center;justify-content:center;flex-shrink:0">
                    <svg width="17" height="17" viewBox="0 0 24 24" fill="none"><path stroke="#fff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" d="M12 3c-1.2 5.4-4.5 7.5-9 9 4.5 1.5 7.8 3.6 9 9 1.2-5.4 4.5-7.5 9-9-4.5-1.5-7.8-3.6-9-9z"/></svg>
                </div>
                <div>
                    <div style="font-weight:var(--weight-semibold);font-size:var(--text-base)">Import Exam with AI</div>
                    <div style="font-size:var(--text-xs);color:var(--text-tertiary);margin-top:1px">Upload a file and AI will extract the questions</div>
                </div>
            </div>
            <button class="btn-icon" onclick="closeAiImportModal()">
                <?= icon('ic_fluent_dismiss_24_regular', 18) ?>
            </button>
        </div>
        <div class="modal-body" style="overflow-y:auto;display:flex;flex-direction:column;gap:var(--space-4)">
            <div id="ai-puter-status" class="ai-status-bar disconnected">
                <div class="ai-status-dot"></div>
                <div id="ai-puter-status-text" style="flex:1">Checking Puter connection…</div>
                <a id="ai-puter-link" href="https://puter.com" target="_blank" style="display:none;font-size:var(--text-xs);font-weight:var(--weight-medium);color:var(--accent);text-decoration:none;white-space:nowrap">Create account →</a>
                <button id="ai-puter-signin-btn" onclick="puterSignIn()" style="display:none;font-size:var(--text-xs);font-weight:var(--weight-medium);color:var(--accent);background:none;border:none;cursor:pointer;padding:0;white-space:nowrap">Sign in →</button>
                <button id="ai-puter-signout-btn" onclick="puterSignOut()" style="display:none;font-size:var(--text-xs);color:var(--text-tertiary);background:none;border:none;cursor:pointer;padding:0">Sign out</button>
            </div>
            <div id="ai-step-upload">
                <div class="ai-dropzone" id="ai-drop-zone" onclick="document.getElementById('ai-file-input').click()" ondragover="event.preventDefault();this.classList.add('dragover')" ondragleave="this.classList.remove('dragover')" ondrop="handleAiFileDrop(event)">
                    <div class="ai-dropzone-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path stroke="var(--text-tertiary)" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8L14 2z"/><polyline stroke="var(--text-tertiary)" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" points="14 2 14 8 20 8"/></svg></div>
                    <div id="ai-dropzone-label" style="font-size:var(--text-sm);font-weight:var(--weight-medium);color:var(--text-secondary)">Drop file here or <span style="color:var(--accent)">click to browse</span></div>
                    <div style="font-size:var(--text-xs);color:var(--text-tertiary);margin-top:4px">JPG · PNG · PDF · DOCX · TXT</div>
                    <div id="ai-file-tag-wrap" style="display:none;margin-top:var(--space-3)">
                        <span class="ai-file-tag">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none"><path stroke="#fff" stroke-width="2.2" stroke-linecap="round" d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8L14 2z"/></svg>
                            <span id="ai-file-tag-name"></span>
                            <button class="rm" onclick="event.stopPropagation();clearAiFile()" title="Remove"><?= icon('ic_fluent_dismiss_24_regular', 12) ?></button>
                        </span>
                    </div>
                    <input type="file" id="ai-file-input" accept=".jpg,.jpeg,.png,.pdf,.docx,.txt,.doc" style="display:none" onchange="handleAiFileSelect(this)">
                </div>
                <div style="display:flex;align-items:center;gap:var(--space-2);margin-top:var(--space-3)">
                    <label style="font-size:var(--text-sm);color:var(--text-secondary);white-space:nowrap">Points per question</label>
                    <input type="number" id="ai-default-points" class="form-control" value="1" min="0" max="100" style="width:64px;padding:var(--space-1) var(--space-2);text-align:center">
                </div>
            </div>
            <div id="ai-step-processing" style="display:none;flex-direction:column;gap:var(--space-3);padding:var(--space-6) 0;text-align:center">
                <div style="font-weight:var(--weight-medium);font-size:var(--text-sm)" id="ai-processing-label">AI is processing your file…</div>
                <div class="ai-progress-wrap" style="padding:0">
                    <div class="ai-progress-track">
                        <div class="ai-progress-fill" id="ai-progress-fill"></div>
                    </div>
                </div>
                <div class="ai-progress-step" id="ai-progress-step" style="font-size:var(--text-xs);color:var(--text-tertiary)">Preparing…</div>
                <div style="font-size:var(--text-xs);color:var(--text-tertiary);opacity:.7;margin-top:var(--space-1)">A Puter sign-in popup may appear</div>
                <div class="ai-progress-step" id="ai-progress-pct" style="display:none">0%</div>
            </div>
            <div id="ai-step-preview" style="display:none;flex-direction:column;gap:var(--space-3)">
                <div style="display:flex;align-items:center;justify-content:space-between">
                    <div style="font-size:var(--text-sm);font-weight:var(--weight-semibold)" id="ai-preview-count"></div>
                    <button class="btn btn-ghost btn-sm" onclick="resetAiImport()">← Try again</button>
                </div>
                <div class="ai-warn-strip">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" style="flex-shrink:0;margin-top:1px"><path stroke="#d97706" stroke-width="2" stroke-linecap="round" d="M12 9v4m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/></svg>
                    <span>AI results may not be 100% accurate. <strong>Review each question and answer</strong> before saving.</span>
                </div>
                <div id="ai-questions-preview" style="display:flex;flex-direction:column;gap:var(--space-2);max-height:320px;overflow-y:auto"></div>
            </div>
            <div id="ai-step-error" style="display:none;flex-direction:column;gap:var(--space-3)">
                <div style="display:flex;align-items:flex-start;gap:10px;background:#fef2f2;border:1px solid #fca5a5;border-radius:var(--radius-md);padding:12px 14px;font-size:var(--text-sm);color:#991b1b">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" style="flex-shrink:0;margin-top:1px"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2"/><path stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M12 8v4m0 4h.01"/></svg>
                    <span id="ai-error-msg"></span>
                </div>
                <button class="btn btn-ghost btn-sm" style="align-self:flex-start" onclick="resetAiImport()">← Try again</button>
            </div>
        </div>
        <div class="modal-footer" id="ai-modal-footer" style="border-top:1px solid var(--border)">
            <button type="button" class="btn btn-ghost" onclick="closeAiImportModal()">Cancel</button>
            <button type="button" class="btn btn-primary" id="ai-process-btn" onclick="startAiProcessing()" disabled>
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" style="margin-right:5px"><path stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" d="M12 3c-1.2 5.4-4.5 7.5-9 9 4.5 1.5 7.8 3.6 9 9 1.2-5.4 4.5-7.5 9-9-4.5-1.5-7.8-3.6-9-9z"/></svg>
                Generate Questions
            </button>
        </div>
        <div class="modal-footer" id="ai-save-footer" style="display:none;border-top:1px solid var(--border)">
            <button type="button" class="btn btn-ghost" onclick="closeAiImportModal()">Cancel</button>
            <button type="button" class="btn btn-primary" id="ai-save-btn" onclick="saveAiQuestions()">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" style="margin-right:5px"><path stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v14a2 2 0 01-2 2z"/><polyline stroke="currentColor" stroke-width="2" stroke-linecap="round" points="17 21 17 13 7 13 7 21"/><polyline stroke="currentColor" stroke-width="2" stroke-linecap="round" points="7 3 7 8 15 8"/></svg>
                Add All to Exam
            </button>
        </div>
    </div>
</div>

<script>
let puterLoaded = false;
function loadPuter() {
    return new Promise(res => {
        if (puterLoaded || window.puter) { puterLoaded=true; res(); return; }
        const s = document.createElement('script'); s.src = 'https://js.puter.com/v2/';
        s.onload = () => { puterLoaded=true; res(); }; document.head.appendChild(s);
    });
}
function loadPdfJs() {
    return new Promise(res => {
        if (window.pdfjsLib) { res(); return; }
        const s = document.createElement('script');
        s.src = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js';
        s.onload = () => { pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js'; res(); };
        document.head.appendChild(s);
    });
}
function loadMammoth() {
    return new Promise(res => {
        if (window.mammoth) { res(); return; }
        const s = document.createElement('script'); s.src = 'https://cdnjs.cloudflare.com/ajax/libs/mammoth/1.6.0/mammoth.browser.min.js'; s.onload = res; document.head.appendChild(s);
    });
}
async function refreshPuterStatus() {
    const bar=document.getElementById('ai-puter-status'), label=document.getElementById('ai-puter-status-text');
    const linkEl=document.getElementById('ai-puter-link'), signinBtn=document.getElementById('ai-puter-signin-btn'), signoutBtn=document.getElementById('ai-puter-signout-btn');
    label.textContent='Checking Puter connection…'; [linkEl,signinBtn,signoutBtn].forEach(el=>el.style.display='none');
    try {
        await loadPuter();
        const ok = await puter.auth.isSignedIn();
        if (ok) {
            let name=''; try { const u=await puter.auth.getUser(); name=u?.username?' as @'+u.username:''; } catch(_){}
            bar.className='ai-status-bar connected'; label.textContent='Connected to Puter'+name; signoutBtn.style.display='';
        } else { bar.className='ai-status-bar disconnected'; label.textContent='Not signed in to Puter'; linkEl.style.display=''; signinBtn.style.display=''; }
    } catch(_) { bar.className='ai-status-bar disconnected'; label.textContent='Could not reach Puter'; linkEl.style.display=''; signinBtn.style.display=''; }
}
async function puterSignIn() { await loadPuter(); try { await puter.auth.signIn(); refreshPuterStatus(); } catch(_){} }
async function puterSignOut(){ await loadPuter(); try { await puter.auth.signOut(); refreshPuterStatus(); } catch(_){} }

// aiGeneratedSections = [{section_title, section_type, questions:[...]}, ...]
let aiSelectedFile=null, aiGeneratedSections=[];

function openAiImportModal()  { resetAiImport(true); openModal('ai-import-modal'); refreshPuterStatus(); }
function closeAiImportModal() { closeModal('ai-import-modal'); }
function handleAiFileDrop(e)  { e.preventDefault(); document.getElementById('ai-drop-zone').classList.remove('dragover'); if(e.dataTransfer.files[0]) setAiFile(e.dataTransfer.files[0]); }
function handleAiFileSelect(inp) { if(inp.files[0]) setAiFile(inp.files[0]); }
function setAiFile(file) {
    aiSelectedFile=file; document.getElementById('ai-drop-zone').classList.add('has-file');
    document.getElementById('ai-dropzone-label').style.display='none'; document.getElementById('ai-file-tag-wrap').style.display='';
    document.getElementById('ai-file-tag-name').textContent=file.name; document.getElementById('ai-process-btn').disabled=false;
}
function clearAiFile() {
    aiSelectedFile=null; document.getElementById('ai-file-input').value='';
    document.getElementById('ai-drop-zone').classList.remove('has-file','dragover');
    document.getElementById('ai-dropzone-label').style.display=''; document.getElementById('ai-file-tag-wrap').style.display='none';
    document.getElementById('ai-process-btn').disabled=true;
}
function resetAiImport(clearFile) {
    if(clearFile) { aiSelectedFile=null; document.getElementById('ai-file-input').value=''; document.getElementById('ai-drop-zone').classList.remove('has-file','dragover'); document.getElementById('ai-dropzone-label').style.display=''; document.getElementById('ai-file-tag-wrap').style.display='none'; }
    aiGeneratedSections=[];
    resetProgress();
    document.getElementById('ai-process-btn').disabled=!aiSelectedFile;
    ['upload','processing','preview','error'].forEach(s=>{ const el=document.getElementById('ai-step-'+s); if(el) el.style.display=s==='upload'?'':'none'; if(s==='preview'&&el) el.style.flexDirection='column'; });
    document.getElementById('ai-modal-footer').style.display=''; document.getElementById('ai-save-footer').style.display='none';
}
function showAiStep(step) {
    ['upload','processing','preview','error'].forEach(s=>{ const el=document.getElementById('ai-step-'+s); if(!el) return; el.style.display=s===step?(s==='processing'||s==='preview'?'flex':''):'none'; });
}
// ── Progress bar helpers ──────────────────────────────────────
// ── AI import progress ────────────────────────────────────────
// The pre-AI steps (file read → text extraction → upload) finish in
// milliseconds, so the bar used to ping straight to 88% the instant
// the request was sent and then sit there for the whole AI wait. To
// make the wait feel steady, we time-lock the 0 → 80% ramp to ~5s of
// real elapsed time, regardless of how fast the per-step targets
// arrive. Once the AI returns and we set target=100, we snap to the
// finish quickly.
const PROGRESS_RAMP_MS  = 10000;  // 0 → 80% takes this many ms of wall time
const PROGRESS_RAMP_PCT = 80;     // ceiling for the time-locked phase

let _progressTarget = 0, _progressCurrent = 0, _progressTimer = null;
let _progressStartedAt = 0;

function setProgress(pct, label, step) {
    _progressTarget = pct;
    if (label) document.getElementById('ai-processing-label').textContent = label;
    if (step)  document.getElementById('ai-progress-step').textContent = step;
    _tickProgress();
}

function _tickProgress() {
    if (_progressTimer) clearInterval(_progressTimer);
    if (!_progressStartedAt) _progressStartedAt = Date.now();

    _progressTimer = setInterval(() => {
        const gap = _progressTarget - _progressCurrent;
        if (gap <= 0) { clearInterval(_progressTimer); return; }

        let next;
        if (_progressTarget >= 95) {
            // Closing out — snap home (covers the AI-just-returned case).
            next = _progressCurrent + Math.max(2, gap * 0.3);
        } else {
            // Time-locked steady climb: position purely follows wall clock,
            // capped at PROGRESS_RAMP_PCT so we never blow past 80% before
            // the AI actually replies. Targets below the ceiling still cap
            // the climb (e.g. step says "30%" → bar parks at 30% even if
            // real time would put it higher).
            const elapsed = Date.now() - _progressStartedAt;
            const byTime  = Math.min(PROGRESS_RAMP_PCT, (elapsed / PROGRESS_RAMP_MS) * PROGRESS_RAMP_PCT);
            const minStep = _progressCurrent + 0.1;
            next = Math.min(_progressTarget, Math.max(minStep, byTime));
        }

        if (next >= _progressTarget) {
            next = _progressTarget;
            clearInterval(_progressTimer);
        }
        _progressCurrent = next;

        const fill = document.getElementById('ai-progress-fill');
        const pct  = document.getElementById('ai-progress-pct');
        if (fill) fill.style.width = _progressCurrent.toFixed(1) + '%';
        if (pct)  pct.textContent  = Math.round(_progressCurrent) + '%';
    }, 80);
}

function resetProgress() {
    if (_progressTimer) clearInterval(_progressTimer);
    _progressTarget = 0; _progressCurrent = 0;
    _progressStartedAt = 0;
    const fill = document.getElementById('ai-progress-fill');
    const pct  = document.getElementById('ai-progress-pct');
    if (fill) fill.style.width = '0%';
    if (pct)  pct.textContent  = '0%';
    document.getElementById('ai-progress-step').textContent = 'Preparing…';
    document.getElementById('ai-processing-label').textContent = 'Reading file…';
}

async function startAiProcessing() {
    if (!aiSelectedFile) return;
    resetProgress();
    showAiStep('processing');
    document.getElementById('ai-modal-footer').style.display = 'none';

    try {
        // Pre-flight: empty file would only confuse the AI and waste a call.
        if (!aiSelectedFile.size || aiSelectedFile.size === 0) {
            throw new Error('The selected file is empty (0 bytes). Pick a different file and try again.');
        }
        await loadPuter();
        const ext = aiSelectedFile.name.split('.').pop().toLowerCase();
        let content = null, isImage = false;

        // Step 1 — Read file (0 → 15%)
        setProgress(15, 'Reading file…', 'Loading file from disk');
        if (['jpg','jpeg','png'].includes(ext)) {
            content = aiSelectedFile; isImage = true;
        } else if (ext === 'pdf') {
            // Step 2 — Extract PDF (15 → 30%)
            setProgress(30, 'Extracting text…', 'Parsing PDF pages');
            await loadPdfJs();
            content = await extractPdfText(aiSelectedFile);
        } else if (['docx','doc'].includes(ext)) {
            // Step 2 — Extract DOCX (15 → 30%)
            setProgress(30, 'Extracting text…', 'Parsing document');
            await loadMammoth();
            content = await extractDocxText(aiSelectedFile);
        } else {
            content = await readFileAsText(aiSelectedFile);
        }

        // Stricter content guard. < 50 chars from a PDF/DOCX almost
        // always means the file is image-based (scanned, no text layer)
        // - sending that to the AI just produces a refusal response.
        if (!content) {
            throw new Error('Could not read this file. Try a different format.');
        }
        if (typeof content === 'string') {
            const len = content.trim().length;
            if (len < 5) {
                throw new Error('Could not extract any readable text from this file. If it is a scanned PDF, save it as JPG/PNG instead and re-upload — the AI can read images.');
            }
            if (len < 50 && (ext === 'pdf' || ext === 'doc' || ext === 'docx')) {
                throw new Error('Only ' + len + ' characters of text could be extracted from this ' + ext.toUpperCase() + '. It is most likely a scanned document with no selectable text. Save the pages as JPG/PNG images and upload one of those instead.');
            }
        }

        // Step 3 — Send to AI (→ 40%)
        setProgress(40, 'Sending to AI…', 'Uploading content to Puter AI');

        // Step 4 — AI processing (40 → 88%, slow crawl while we wait)
        const pts = parseInt(document.getElementById('ai-default-points').value) || 1;
        // Kick off slow crawl to 88% — will snap to 100 when done
        setProgress(88, 'AI is processing…', 'Generating questions from your file');

        const sections = isImage
            ? await callPuterWithImage(content, pts)
            : await callPuterWithText(content, pts);

        // Step 5 — Finalizing (88 → 100%)
        setProgress(100, 'Finalizing…', 'Parsing AI response');
        await new Promise(r => setTimeout(r, 350)); // let bar finish

        if (!sections || sections.length === 0) throw new Error('No sections or questions were detected.');
        const totalQ = sections.reduce((sum, s) => sum + s.questions.length, 0);
        if (totalQ === 0) throw new Error('No questions were detected.');

        // Refusal detection: parseAiResp can succeed on AI apology JSON
        // (e.g. one section titled "Error" with a single question whose
        // text says "No exam content was provided…"). Surface those as a
        // clear error instead of letting them render as a fake exam.
        const refusal = _detectRefusal(sections);
        if (refusal) {
            throw new Error(
                'The AI didn\u2019t see any actual exam in what was sent. It said:\n\n“' +
                refusal + '”\n\n' +
                'Most common cause: the file is a scanned/image-only PDF or a corrupt upload. Save the pages as JPG/PNG and upload one of those, or pick a different file.'
            );
        }

        aiGeneratedSections = sections;
        renderAiPreview(sections);
        showAiStep('preview');
        document.getElementById('ai-preview-count').textContent =
            `${sections.length} section${sections.length !== 1 ? 's' : ''}, ${totalQ} question${totalQ !== 1 ? 's' : ''} detected`;
        document.getElementById('ai-save-footer').style.display = '';
    } catch (err) {
        showAiStep('error');
        document.getElementById('ai-modal-footer').style.display = '';
        let msg = typeof err === 'string' ? err : err?.message || err?.error || 'Unknown error';
        document.getElementById('ai-error-msg').textContent = msg;
        document.getElementById('ai-process-btn').disabled = !aiSelectedFile;
    }
}

function readFileAsText(f) { return new Promise((r,j)=>{ const x=new FileReader(); x.onload=()=>r(x.result); x.onerror=j; x.readAsText(f); }); }
async function extractPdfText(f) {
    const b=await f.arrayBuffer(), pdf=await pdfjsLib.getDocument({data:b}).promise; let t='';
    for(let i=1;i<=Math.min(pdf.numPages,20);i++){const pg=await pdf.getPage(i),tc=await pg.getTextContent(); t+=tc.items.map(it=>it.str).join(' ')+'\n';} return t.trim();
}
async function extractDocxText(f) { const b=await f.arrayBuffer(); return(await mammoth.extractRawText({arrayBuffer:b})).value; }
async function ensurePuterAuth() { await loadPuter(); const ok=await puter.auth.isSignedIn(); if(!ok) await puter.auth.signIn(); }
function extractPuterText(response) {
    if(response&&response.success===false) throw new Error('Puter AI error: '+(response.error||'unknown'));
    const text=response?.message?.content?.[0]?.text||response?.message?.content||'';
    if(!text) throw new Error('AI returned an empty response.');
    return text;
}

// ── AI prompt — extracts sections + questions ─────────────────
const AI_PROMPT = `You are an expert exam question extractor for Philippine school exams. Extract ALL sections and ALL questions from the exam content.

Return ONLY a valid JSON array of sections — no markdown, no explanation, no extra text.

Structure:
[
  {
    "section_title": "I. Multiple Choice",
    "section_type": "multiple_choice",
    "section_description": "Choose the best answer.",
    "questions": [
      {
        "question_text": "What is the capital of France?",
        "choices": ["London", "Paris", "Berlin", "Rome"],
        "correct_index": 1,
        "correct_indices": [],
        "correct_answer": null,
        "points": 1
      }
    ]
  }
]

SECTION TYPE RULES:
- "multiple_choice" → Multiple Choice, True/False, Matching Type
- "checkboxes"      → Select all that apply, Multiple correct answers
- "short_answer"    → Identification, Fill in the blank, Enumeration, Completion
- "paragraph"       → Essay, Explain, Discuss, Long answer

IDENTIFYING CORRECT ANSWERS (critical — read carefully):
- In Philippine printed exams, the correct answer is shown in BOLD, UNDERLINED, or ALL-CAPS in the answer choices
- Look very carefully at each choice — whichever one appears visually emphasized (bold/underlined/caps) is the correct answer
- correct_index = 0-based position of the correct choice (0=a, 1=b, 2=c, 3=d)
- If no answer is marked, set correct_index to 0
- For short_answer/identification: set correct_answer to the expected answer if shown (e.g. "QL", "QUALITATIVE", etc.)
- Do NOT include any "description", hint, or "correct answer indicated as bold" text in the output — only the fields listed in the schema above

CHOICE FORMATTING:
- Strip letter/number prefixes from choices (e.g. "a. Paris" → "Paris", "A) Paris" → "Paris", "1. Paris" → "Paris")
- Keep the choice text only, no labels

TRUE/FALSE SECTIONS — IMPORTANT:
- Each numbered statement is a SEPARATE question
- question_text = the statement itself (e.g. "The sky is blue")
- choices = ["True", "False"] for every question
- Do NOT extract the instructions ("Write TRUE if...") as a question — that is a direction, not a question
- correct_index = 0 if True is correct, 1 if False is correct (or 0 if unknown)

IDENTIFICATION/SHORT ANSWER SECTIONS:
- Each numbered item is a separate question
- Extract any hint text after the question (like "QL" or "QT") as correct_answer
- Example: "11. Identify patterns, features, themes QL" → question_text="Identify patterns, features, themes", correct_answer="QUALITATIVE"
- Expand abbreviations: QL/Q.L. → "QUALITATIVE", QT/Q.T. → "QUANTITATIVE"

GENERAL RULES:
- Extract EVERY numbered question — do not skip any
- section_description = copy any directions/instructions shown at the top of that section
- Do NOT treat section headings or instructions as questions
- Output ONLY the JSON array, nothing else

OUTPUT FORMAT (strict — failures here break the import):
- The very first character of your response must be \`[\` and the very last must be \`]\`
- Do NOT wrap the array in an object (no \`{"sections": [...]}\` — just the bare array)
- Do NOT include a trailing comma after the last item
- Do NOT include comments
- Do NOT include any prose, summary, or markdown fences before/after the array`;

async function callPuterWithText(text, pts) {
    const trunc=text.length>10000?text.slice(0,10000)+'…[truncated]':text;
    await ensurePuterAuth();
    const response=await puter.ai.chat(AI_PROMPT+'\n\nCONTENT:\n\n'+trunc, {model:'claude-sonnet-4-6'});
    return parseAiResp(extractPuterText(response), pts);
}
async function callPuterWithImage(file, pts) {
    if (!file || !file.size) throw new Error('Image file is empty. Try a different image.');
    await ensurePuterAuth();
    const tmpName='exam_import_'+Date.now()+'.'+file.name.split('.').pop();
    let puterFile;
    try { puterFile=await puter.fs.write(tmpName,file); }
    catch(e) { throw new Error('Could not upload image to Puter: '+(e&&e.message||e)); }
    if (!puterFile || !puterFile.path) {
        throw new Error('Image upload to Puter returned no file path. Try again or use a different image.');
    }
    // If Puter reports the written file size, sanity-check it matches
    // the source. Zero-byte writes are the usual cause of the AI
    // responding with "no content was provided".
    if (typeof puterFile.size === 'number' && puterFile.size === 0) {
        try{await puter.fs.delete(puterFile.path);}catch(_){}
        throw new Error('Image upload to Puter wrote 0 bytes. Try again or use a different image.');
    }
    let response;
    try {
        response=await puter.ai.chat(
            [{role:'user',content:[{type:'file',puter_path:puterFile.path},{type:'text',text:AI_PROMPT}]}],
            {model:'claude-sonnet-4-6'}
        );
    } finally { try{await puter.fs.delete(puterFile.path);}catch(_){} }
    return parseAiResp(extractPuterText(response), pts);
}

// Expand common Philippine exam abbreviations in answers
function expandAnswer(ans) {
    if (!ans) return ans;
    const map = {
        'QL': 'QUALITATIVE', 'Q.L.': 'QUALITATIVE', 'Ql': 'QUALITATIVE', 'ql': 'QUALITATIVE',
        'QT': 'QUANTITATIVE', 'Q.T.': 'QUANTITATIVE', 'Qt': 'QUANTITATIVE', 'qt': 'QUANTITATIVE',
        'T': 'TRUE', 'F': 'FALSE',
    };
    const trimmed = ans.trim();
    return map[trimmed] || ans;
}

// ── JSON repair helpers ──────────────────────────────────────
// LLM responses sometimes break the contract in known ways. These
// helpers try, in order: (1) bare array slice, (2) object-wrapped
// array unwrap, (3) truncation recovery — walk back to the last
// complete `}` at array depth 0 and close with `]`.

function _stripFences(s) {
    return s.trim()
        .replace(/^```(?:json|javascript|js|json5)?\s*/i, '')
        .replace(/\s*```\s*$/i, '')
        .replace(/^~~~(?:json)?\s*/i, '')
        .replace(/\s*~~~\s*$/i, '')
        .trim();
}
function _stripTrailingCommas(s) {
    return s.replace(/,(\s*[}\]])/g, '$1');
}
function _tryParseArray(s) {
    const start = s.indexOf('[');
    const end   = s.lastIndexOf(']');
    if (start === -1 || end === -1 || end <= start) return null;
    const sliced = _stripTrailingCommas(s.slice(start, end + 1));
    try { const a = JSON.parse(sliced); return Array.isArray(a) ? a : null; } catch (_) { return null; }
}
function _tryParseObjectWrapper(s) {
    let obj;
    try { obj = JSON.parse(_stripTrailingCommas(s)); } catch (_) { return null; }
    if (!obj || typeof obj !== 'object') return null;
    for (const k of ['sections','data','result','output','exam','items','questions']) {
        if (Array.isArray(obj[k])) return obj[k];
    }
    // Single-section object → wrap it.
    if (Array.isArray(obj.questions)) return [obj];
    return null;
}
function _tryRepairTruncated(s) {
    const start = s.indexOf('[');
    if (start === -1) return null;
    const work = s.slice(start);
    // Walk forward, tracking string state and bracket depth, recording
    // each position where a section object closes at array-depth 1.
    let depth = 0, inStr = false, esc = false, lastSafe = -1;
    for (let i = 0; i < work.length; i++) {
        const c = work[i];
        if (esc) { esc = false; continue; }
        if (c === '\\') { esc = true; continue; }
        if (c === '"') { inStr = !inStr; continue; }
        if (inStr) continue;
        if (c === '{' || c === '[') depth++;
        else if (c === '}' || c === ']') {
            depth--;
            if (depth === 1 && c === '}') lastSafe = i; // a section closed
        }
    }
    if (lastSafe === -1) return null;
    let candidate = work.slice(0, lastSafe + 1) + ']';
    candidate = _stripTrailingCommas(candidate);
    try { const a = JSON.parse(candidate); return Array.isArray(a) ? a : null; } catch (_) { return null; }
}

// Detect AI "refusal" / apology responses that are technically valid
// JSON but carry no actual exam content - e.g. one section titled
// "Error" / "Apology" with a single short_answer question whose text
// is the AI explaining it couldn't read the file.
function _detectRefusal(sections) {
    if (!Array.isArray(sections) || sections.length === 0) return null;
    const PHRASES = [
        'no exam content', 'no content was provided', 'no content provided',
        'temporarily unavailable', 'file storage',
        'i apologize', 'i cannot read', 'i can\u2019t read', "i can't read",
        'i am unable', 'i\u2019m unable', "i'm unable",
        'could not extract', 'couldn\u2019t read', "couldn't read",
        'appears to be empty', 'no readable text',
        'no file', 'blank image', 'image is blank',
        'cannot access', 'unable to access',
        'no questions were found', 'no questions found'
    ];
    const matches = (txt) => {
        if (!txt) return false;
        const low = String(txt).toLowerCase();
        return PHRASES.some(p => low.indexOf(p) !== -1);
    };
    // Very strong signal: any section title hints at an error/apology.
    for (const sec of sections) {
        const title = String(sec.section_title || '').toLowerCase();
        if (title === 'error' || title === 'apology' ||
            title.startsWith('error') || title.startsWith('apology') ||
            title.indexOf('cannot') !== -1 || title.indexOf('unable') !== -1) {
            // Find a question text to surface in the error.
            const q = (sec.questions || []).find(q => q && q.question_text);
            return q ? String(q.question_text) : 'Error: ' + (sec.section_title || 'unknown');
        }
    }
    // If there is exactly 1 question and its text matches a refusal
    // phrase, that is also strong enough to surface.
    const allQuestions = sections.flatMap(s => s.questions || []);
    if (allQuestions.length === 1 && matches(allQuestions[0].question_text)) {
        return String(allQuestions[0].question_text);
    }
    // Catch the case where every question matches a refusal phrase.
    if (allQuestions.length > 0 && allQuestions.every(q => matches(q.question_text))) {
        return String(allQuestions[0].question_text);
    }
    return null;
}

function parseAiResp(raw, dp) {
    if (typeof raw !== 'string' || raw.trim().length === 0) {
        throw new Error('AI returned an empty response. Try uploading the file again.');
    }
    const s = _stripFences(raw);

    // Three repair strategies in order of fidelity.
    let arr = _tryParseArray(s);
    if (!arr) arr = _tryParseObjectWrapper(s);
    if (!arr) arr = _tryRepairTruncated(s);

    if (!arr) {
        // All strategies failed — surface the first bit of the raw
        // response so the user can see what the AI actually returned.
        const snippet = raw.length > 240 ? raw.slice(0, 240) + '… (+' + (raw.length - 240) + ' more chars)' : raw;
        throw new Error(
            'AI response could not be parsed as JSON. The model may have returned prose, ' +
            'or the response was cut off mid-answer. Try clicking Process again — if it ' +
            'keeps failing, use a shorter or clearer file.\n\nFirst part of the response:\n' + snippet
        );
    }
    if (!Array.isArray(arr)) throw new Error('AI did not return a list.');

    const VALID_TYPES=['multiple_choice','checkboxes','short_answer','paragraph'];
    return arr.map(sec=>({
        section_title: sec.section_title||sec.title||'Untitled Section',
        section_type: VALID_TYPES.includes(sec.section_type)?sec.section_type:'multiple_choice',
        section_description: sec.section_description||sec.description||'',
        questions: (Array.isArray(sec.questions)?sec.questions:[]).map(q=>({
            question_text:  q.question_text||'Untitled question',
            question_type:  VALID_TYPES.includes(q.question_type)?q.question_type:
                            (VALID_TYPES.includes(sec.section_type)?sec.section_type:'multiple_choice'),
            choices:        Array.isArray(q.choices)?q.choices:[],
            correct_index:  typeof q.correct_index==='number'?q.correct_index:0,
            correct_answer: expandAnswer(q.correct_answer)||null,
            correct_indices:Array.isArray(q.correct_indices)?q.correct_indices:[],
            points:         typeof q.points==='number'&&q.points>=0?q.points:dp,
            is_required:    1,
        }))
    })).filter(sec=>sec.questions.length>0);
}

// ── Preview ───────────────────────────────────────────────────
const AI_SECTION_COLORS = {
    multiple_choice: {bg:'#dbeafe',text:'#1d4ed8',border:'#93c5fd'},
    checkboxes:      {bg:'#d1fae5',text:'#065f46',border:'#6ee7b7'},
    short_answer:    {bg:'#fef3c7',text:'#92400e',border:'#fcd34d'},
    paragraph:       {bg:'#fce7f3',text:'#9d174d',border:'#f9a8d4'},
};

function renderAiPreview(sections) {
    const wrap=document.getElementById('ai-questions-preview'); wrap.innerHTML='';
    const CT=['multiple_choice','checkboxes','dropdown'];
    let globalQ=0;
    sections.forEach(sec=>{
        const sc=AI_SECTION_COLORS[sec.section_type]||AI_SECTION_COLORS['multiple_choice'];
        // Section header
        const secHead=document.createElement('div');
        secHead.style.cssText=`background:${sc.bg};border:1.5px solid ${sc.border};border-radius:8px;padding:8px 12px;margin-top:8px`;
        let secDesc = sec.section_description ? `<div style="font-size:11px;color:${sc.text};opacity:.75;margin-top:2px">${escHtml(sec.section_description)}</div>` : '';
        secHead.innerHTML=`<div style="display:flex;align-items:center;gap:8px"><span style="font-size:12px;font-weight:600;color:${sc.text}">${escHtml(sec.section_title)}</span><span style="font-size:11px;color:${sc.text};opacity:.7;margin-left:auto">${sec.section_type.replace(/_/g,' ')} · ${sec.questions.length} question${sec.questions.length!==1?'s':''}</span></div>${secDesc}`;
        wrap.appendChild(secHead);

        // Questions
        sec.questions.forEach(q=>{
            globalQ++;
            const card=document.createElement('div'); card.className='ai-q-card'; card.style.marginLeft='12px';
            let choices='';
            if(CT.includes(q.question_type)&&q.choices&&q.choices.length){
                choices='<div style="display:flex;flex-direction:column;gap:2px;margin-top:6px">'+q.choices.map((c,ci)=>{
                    const ok=q.question_type==='checkboxes'?(q.correct_indices||[]).includes(ci):ci===parseInt(q.correct_index);
                    const dot=ok
                        ? `<span style="width:14px;height:14px;border-radius:50%;background:#15803d;flex-shrink:0;display:inline-flex;align-items:center;justify-content:center"><svg width="8" height="8" viewBox="0 0 24 24" fill="none"><path stroke="#fff" stroke-width="3.5" stroke-linecap="round" d="M5 13l4 4L19 7"/></svg></span>`
                        : `<span style="width:14px;height:14px;border-radius:50%;border:1.5px solid #d1d5db;flex-shrink:0;display:inline-block"></span>`;
                    return`<div style="display:flex;align-items:center;gap:7px;padding:2px 0;font-size:13px;color:${ok?'#15803d':'#374151'};${ok?'font-weight:500':''}">${dot}${escHtml(c)}</div>`;
                }).join('')+'</div>';
            } else if(q.question_type==='short_answer'){
                choices=`<div style="margin-top:6px;font-size:12px;color:#6b7280;font-style:italic">${q.correct_answer?'Expected: <strong style=\'color:#111827\'">'+escHtml(q.correct_answer)+'</strong>':'Short answer — reviewed manually'}</div>`;
            } else {
                choices=`<div style="margin-top:6px;font-size:12px;color:#6b7280;font-style:italic">Paragraph — reviewed manually</div>`;
            }
            card.innerHTML=`<div class="ai-q-card-head"><span style="background:#2d6a4f;color:#fff;border-radius:4px;padding:1px 7px;font-weight:600;font-size:11px">Q${globalQ}</span><span style="font-size:11px;color:#6b7280">${q.question_type.replace(/_/g,' ')}</span><span style="margin-left:auto;font-size:11px;color:#6b7280">${q.points} pt${q.points!==1?'s':''}</span></div><div class="ai-q-card-body"><div class="ai-q-text">${escHtml(q.question_text)}</div>${choices}</div>`;
            wrap.appendChild(card);
        });
    });
}

function escHtml(s){return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}

// ── Save — creates sections first, then adds questions ────────
async function saveAiQuestions(){
    const btn=document.getElementById('ai-save-btn'); btn.disabled=true;
    const examId=<?= $selectedExam['id'] ?? 0 ?>;
    const csrfInput=document.querySelector('#ai-import-modal input[type="hidden"]');
    const csrfName=csrfInput?.name||'_csrf_token', csrf=csrfInput?.value||'';
    if(!examId){alert('Select an exam first.');btn.disabled=false;return;}
    if(!csrf){alert('Session expired. Refresh and try again.');btn.disabled=false;return;}

    const CT=['multiple_choice','checkboxes','dropdown'];
    const totalQ=aiGeneratedSections.reduce((sum,s)=>sum+s.questions.length,0);
    let saved=0;

    for(const sec of aiGeneratedSections){
        // 1. Create the section
        const secFd=new FormData();
        secFd.append('action','create_section');
        secFd.append('exam_id',examId);
        secFd.append('section_title',sec.section_title);
        secFd.append('section_desc', sec.section_description || '');
        secFd.append('section_type',sec.section_type);
        secFd.append(csrfName,csrf);
        let sectionId=null;
        try {
            const r=await fetch(location.href,{method:'POST',body:secFd,headers:{'X-Requested-With':'XMLHttpRequest'}});
            const d=await r.json();
            if(d.ok) sectionId=d.section_id;
        } catch(e){}
        if(!sectionId){ btn.textContent=`Error creating section "${sec.section_title}"`; continue; }

        // 2. Add questions to that section
        for(const q of sec.questions){
            const fd=new FormData();
            fd.append('action','add_question');fd.append('exam_id',examId);fd.append(csrfName,csrf);
            fd.append('question_text',q.question_text);
            fd.append('question_type',q.question_type);fd.append('points',q.points);fd.append('is_required','1');
            fd.append('section_id',sectionId);
            if(CT.includes(q.question_type)&&q.choices.length){
                q.choices.forEach(c=>fd.append('choices[]',c));
                if(q.question_type==='checkboxes')(q.correct_indices||[]).forEach(ci=>fd.append('correct_indices[]',ci));
                else fd.append('correct_index',q.correct_index);
            } else if(q.question_type==='short_answer') fd.append('expected_answer',q.correct_answer||'');
            try{
                const resp=await fetch(location.href,{method:'POST',body:fd,headers:{'X-Requested-With':'XMLHttpRequest'}});
                const d=await resp.json();
                if(d.ok) saved++;
                else console.warn('Failed to save question:', q.question_text);
            }catch(e){ console.error('Save error:', e); }
            btn.textContent=`Saving… (${saved}/${totalQ})`;
        }
    }
    window.location.reload();
}
</script>
<?php
$content   = ob_get_clean();
$pageTitle = 'Entrance Exam';
$activeNav = 'exam';
include VIEWS_PATH . '/layouts/app.php';
