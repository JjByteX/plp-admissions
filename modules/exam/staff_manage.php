<?php
// ============================================================
// modules/exam/staff_manage.php
// M4 — Staff: create/manage exam, add/edit questions
// Enhanced: Exam Sets (dates + password), Inline Editing,
//           Section/Part grouping by answer mode
// ============================================================

require_once CORE_PATH . '/bootstrap.php';
Auth::requireRole(ROLE_STAFF, ROLE_ADMIN);

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
    'linear_scale'    => ['bg' => '#f3f4f6', 'text' => '#374151', 'border' => '#d1d5db'],
];

// ----------------------------------------------------------------
// POST handlers
// ----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    switch ($action) {

        // ── Exam CRUD ────────────────────────────────────────────
        case 'create_exam':
            $title          = trim($_POST['title'] ?? '');
            $desc           = trim($_POST['description'] ?? '');
            $duration       = (int)($_POST['duration_minutes'] ?? 60);
            $passing        = $_POST['passing_score'] !== '' ? (int)$_POST['passing_score'] : null;
            $shuffleQ       = isset($_POST['shuffle_questions']) ? 1 : 0;
            $shuffleC       = isset($_POST['shuffle_choices'])   ? 1 : 0;
            $schedStart     = trim($_POST['scheduled_start'] ?? '') ?: null;
            $schedEnd       = trim($_POST['scheduled_end']   ?? '') ?: null;
            $accessPassword = trim($_POST['access_password'] ?? '') ?: null;
            if (!$title) { $errors[] = 'Exam title is required.'; break; }
            $db->prepare('UPDATE exams SET is_active=0')->execute();
            $db->prepare(
                'INSERT INTO exams (title, description, duration_minutes, passing_score,
                 shuffle_questions, shuffle_choices, scheduled_start, scheduled_end,
                 access_password, is_active)
                 VALUES (?,?,?,?,?,?,?,?,?,1)'
            )->execute([$title, $desc ?: null, $duration, $passing, $shuffleQ, $shuffleC,
                        $schedStart, $schedEnd, $accessPassword]);
            $success[] = 'Exam created and set as active.';
            break;

        case 'edit_exam':
            $examId         = (int)($_POST['exam_id'] ?? 0);
            $title          = trim($_POST['title'] ?? '');
            $desc           = trim($_POST['description'] ?? '');
            $duration       = (int)($_POST['duration_minutes'] ?? 60);
            $passing        = $_POST['passing_score'] !== '' ? (int)$_POST['passing_score'] : null;
            $shuffleQ       = isset($_POST['shuffle_questions']) ? 1 : 0;
            $shuffleC       = isset($_POST['shuffle_choices'])   ? 1 : 0;
            $schedStart     = trim($_POST['scheduled_start'] ?? '') ?: null;
            $schedEnd       = trim($_POST['scheduled_end']   ?? '') ?: null;
            $accessPassword = trim($_POST['access_password'] ?? '') ?: null;
            if (!$title) { $errors[] = 'Exam title is required.'; break; }
            $db->prepare(
                'UPDATE exams SET title=?, description=?, duration_minutes=?, passing_score=?,
                 shuffle_questions=?, shuffle_choices=?, scheduled_start=?, scheduled_end=?,
                 access_password=? WHERE id=?'
            )->execute([$title, $desc ?: null, $duration, $passing, $shuffleQ, $shuffleC,
                        $schedStart, $schedEnd, $accessPassword, $examId]);
            // Handle inline-edit AJAX
            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
                header('Content-Type: application/json');
                echo json_encode(['ok' => true, 'title' => $title]);
                exit;
            }
            $success[] = 'Exam updated.';
            break;

        case 'activate_exam':
            $examId = (int)($_POST['exam_id'] ?? 0);
            $db->prepare('UPDATE exams SET is_active=0')->execute();
            $db->prepare('UPDATE exams SET is_active=1 WHERE id=?')->execute([$examId]);
            $success[] = 'Exam activated.';
            break;

        // ── Section CRUD ─────────────────────────────────────────
        case 'create_section':
            $examId    = (int)($_POST['exam_id'] ?? 0);
            $secTitle  = trim($_POST['section_title'] ?? '');
            $secDesc   = trim($_POST['section_desc'] ?? '');
            $secType   = $_POST['section_type'] ?? 'multiple_choice';
            if (!$secTitle) { $errors[] = 'Section title is required.'; break; }
            if (!array_key_exists($secType, $QUESTION_TYPES)) $secType = 'multiple_choice';
            $maxOrd = $db->prepare('SELECT COALESCE(MAX(sort_order),0) FROM exam_sections WHERE exam_id=?');
            $maxOrd->execute([$examId]);
            $db->prepare(
                'INSERT INTO exam_sections (exam_id, title, description, question_type, sort_order) VALUES (?,?,?,?,?)'
            )->execute([$examId, $secTitle, $secDesc ?: null, $secType, (int)$maxOrd->fetchColumn() + 1]);
            $newSecId = (int)$db->lastInsertId();
            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
                header('Content-Type: application/json');
                echo json_encode(['ok' => true, 'section_id' => $newSecId]);
                exit;
            }
            $success[] = 'Section added.';
            break;

        case 'edit_section':
            $secId    = (int)($_POST['section_id'] ?? 0);
            $secTitle = trim($_POST['section_title'] ?? '');
            $secDesc  = trim($_POST['section_desc'] ?? '');
            $secType  = $_POST['section_type'] ?? 'multiple_choice';
            if (!$secTitle) { $errors[] = 'Section title is required.'; break; }
            if (!array_key_exists($secType, $QUESTION_TYPES)) $secType = 'multiple_choice';
            $db->prepare('UPDATE exam_sections SET title=?, description=?, question_type=? WHERE id=?')
               ->execute([$secTitle, $secDesc ?: null, $secType, $secId]);
            // Handle inline AJAX
            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
                header('Content-Type: application/json');
                echo json_encode(['ok' => true, 'title' => $secTitle, 'type' => $secType]);
                exit;
            }
            $success[] = 'Section updated.';
            break;

        case 'delete_section':
            $secId = (int)($_POST['section_id'] ?? 0);
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
$questions    = [];
$sections     = [];
$selectedExamId = (int)($_GET['exam'] ?? ($activeExam['id'] ?? 0));
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
/* ── Question card ───────────────────────────────── */
.q-card { transition: box-shadow .15s, border-color .15s; }
.q-card:hover { border-color: var(--accent); }
.q-card.dragging { opacity:.5; }
.drag-handle { cursor: grab; color: var(--text-tertiary); padding: 4px; }
.drag-handle:active { cursor: grabbing; }

/* ── Section header bar ──────────────────────────── */
.section-header {
    display: flex; align-items: center; gap: var(--space-3);
    padding: var(--space-3) var(--space-4);
    border-radius: var(--radius-md); margin-bottom: var(--space-2);
    border: 1.5px solid transparent;
    position: relative;
}
.section-header .section-actions {
    display: none; gap: var(--space-1); margin-left: auto;
}
.section-header:hover .section-actions { display: flex; }

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
    background: var(--neutral-100); border-radius: 99px;
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

/* ── Exam sidebar ────────────────────────────────── */
.exam-link {
    display:block; padding:var(--space-3) var(--space-4);
    border-radius:var(--radius-md); text-decoration:none;
    margin-bottom:var(--space-1); color:var(--text-primary);
    transition: background .12s;
}
.exam-link:hover { background: var(--neutral-100); }
.exam-link.active { background: var(--neutral-100); }

/* ── Toggle ──────────────────────────────────────── */
.toggle { position:relative; width:36px; height:20px; }
.toggle input { opacity:0; width:0; height:0; }
.toggle-slider {
    position:absolute; inset:0; border-radius:99px;
    background:var(--neutral-300); cursor:pointer; transition:.2s;
}
.toggle-slider:before {
    content:''; position:absolute; width:14px; height:14px;
    left:3px; top:3px; border-radius:50%; background:#fff; transition:.2s;
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
.iq-footer { padding: var(--space-3) var(--space-4); border-top: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; background: var(--neutral-50); }
.iq-type-badge { display: inline-flex; align-items: center; gap: 5px; font-size: var(--text-xs); padding: 3px 10px; border-radius: 99px; font-weight: var(--weight-medium); }
.iq-choice-row { display: flex; align-items: center; gap: 8px; }
.iq-choice-row input[type=text] { flex: 1; }
.iq-add-choice { font-size: var(--text-xs); color: var(--accent); background: none; border: none; cursor: pointer; padding: 4px 0; display: flex; align-items: center; gap: 4px; }
</style>

<?php foreach ($errors as $e): ?>
    <div class="alert alert-error" style="margin-bottom:var(--space-3)"><?= e($e) ?></div>
<?php endforeach; ?>
<?php foreach ($success as $s): ?>
    <div class="alert alert-success" style="margin-bottom:var(--space-3)"><?= e($s) ?></div>
<?php endforeach; ?>

<div style="display:grid;grid-template-columns:220px 1fr;gap:var(--space-6);align-items:start">

    <!-- ── Sidebar ── -->
    <div>
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:var(--space-3)">
            <div style="font-size:var(--text-xs);font-weight:var(--weight-semibold);text-transform:uppercase;letter-spacing:.06em;color:var(--text-tertiary)">Exams</div>
            <button onclick="openModal('create-exam-modal')" title="New Exam"
                    style="display:flex;align-items:center;justify-content:center;width:18px;height:18px;border-radius:var(--radius-sm);border:none;background:transparent;color:var(--text-tertiary);font-size:16px;line-height:1;cursor:pointer;padding:0;transition:background var(--transition-fast),color var(--transition-fast)"
                    onmouseover="this.style.background='var(--bg-overlay)';this.style.color='var(--text-primary)'"
                    onmouseout="this.style.background='transparent';this.style.color='var(--text-tertiary)'">+</button>
        </div>
        <?php if (empty($exams)): ?>
            <p style="font-size:var(--text-sm);color:var(--text-tertiary)">No exams yet.</p>
        <?php else: ?>
            <?php foreach ($exams as $ex): ?>
                <a href="?exam=<?= $ex['id'] ?>" class="exam-link <?= $selectedExamId===$ex['id']?'active':'' ?>">
                    <div style="font-size:var(--text-sm);font-weight:var(--weight-medium)"><?= e($ex['title']) ?></div>
                    <div style="font-size:var(--text-xs);color:var(--text-tertiary)"><?= $ex['duration_minutes'] ?> min</div>
                    <?php if ($ex['is_active']): ?>
                        <span class="badge badge-success" style="margin-top:4px">Active</span>
                    <?php endif; ?>
                    <?php if ($ex['scheduled_start']): ?>
                        <div style="font-size:var(--text-xs);color:var(--text-tertiary);margin-top:2px;display:flex;align-items:center;gap:3px">
                            <svg width="10" height="10" viewBox="0 0 24 24" fill="none"><rect x="3" y="4" width="18" height="18" rx="2" stroke="currentColor" stroke-width="2"/><path stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M16 2v4M8 2v4M3 10h18"/></svg>
                            <?= date('M j', strtotime($ex['scheduled_start'])) ?>
                        </div>
                    <?php endif; ?>
                    <?php if ($ex['access_password']): ?>
                        <div style="font-size:var(--text-xs);color:var(--warning);margin-top:2px;display:flex;align-items:center;gap:3px">
                            <svg width="10" height="10" viewBox="0 0 24 24" fill="none"><rect x="3" y="11" width="18" height="11" rx="2" stroke="currentColor" stroke-width="2"/><path stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M7 11V7a5 5 0 0110 0v4"/></svg>
                            Password protected
                        </div>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- ── Main panel ── -->
    <div>
    <?php if ($selectedExam): ?>

        <!-- Exam header card with INLINE EDITING -->
        <div class="card" style="margin-bottom:var(--space-5);padding:var(--space-5)" id="exam-header-card">
            <div style="display:flex;align-items:flex-start;gap:var(--space-4)">
                <div style="flex:1;min-width:0">

                    <!-- Inline-editable title -->
                    <div id="exam-title-view">
                        <div style="display:flex;align-items:center;gap:var(--space-2)">
                            <span id="exam-title-text" style="font-weight:var(--weight-semibold);font-size:var(--text-lg)"><?= e($selectedExam['title']) ?></span>
                            <button onclick="startInlineExamEdit()" title="Edit title"
                                    style="opacity:.4;background:none;border:none;cursor:pointer;padding:2px;display:flex;align-items:center;transition:opacity .15s"
                                    onmouseover="this.style.opacity='.9'" onmouseout="this.style.opacity='.4'">
                                <svg width="13" height="13" fill="none" viewBox="0 0 24 24"><path stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                            </button>
                        </div>
                    </div>
                    <div id="exam-title-edit" style="display:none">
                        <input type="text" id="exam-title-input" class="inline-edit-input"
                               value="<?= e($selectedExam['title']) ?>"
                               style="font-weight:var(--weight-semibold);font-size:var(--text-lg)">
                        <div class="inline-edit-actions">
                            <button class="inline-save-btn" onclick="saveInlineExamTitle()">Save</button>
                            <button class="inline-cancel-btn" onclick="cancelInlineExamEdit()">Cancel</button>
                        </div>
                    </div>

                    <?php if ($selectedExam['description']): ?>
                        <div style="font-size:var(--text-sm);color:var(--text-secondary);margin-top:2px"><?= e($selectedExam['description']) ?></div>
                    <?php endif; ?>
                    <div style="font-size:var(--text-xs);color:var(--text-tertiary);margin-top:var(--space-2);display:flex;gap:var(--space-4);flex-wrap:wrap">
                        <span><?= $selectedExam['duration_minutes'] ?> minutes</span>
                        <span><?= count($questions) ?> questions</span>
                        <span><?= $totalPoints ?> total points</span>
                        <?php if ($selectedExam['passing_score']): ?>
                            <span>Passing: <?= $selectedExam['passing_score'] ?></span>
                        <?php endif; ?>
                        <?php if ($selectedExam['shuffle_questions']): ?>
                            <span>🔀 Shuffled</span>
                        <?php endif; ?>
                    </div>

                    <!-- Schedule + Password info strip -->
                    <?php if ($selectedExam['scheduled_start'] || $selectedExam['access_password']): ?>
                        <div class="exam-meta-strip">
                            <?php if ($selectedExam['scheduled_start']): ?>
                                <div class="exam-meta-item">
                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none"><rect x="3" y="4" width="18" height="18" rx="2" stroke="currentColor" stroke-width="2"/><path stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M16 2v4M8 2v4M3 10h18"/></svg>
                                    Opens: <strong><?= e(fmtDateTime($selectedExam['scheduled_start'])) ?></strong>
                                </div>
                            <?php endif; ?>
                            <?php if ($selectedExam['scheduled_end']): ?>
                                <div class="exam-meta-item">
                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2"/><path stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M12 6v6l4 2"/></svg>
                                    Closes: <strong><?= e(fmtDateTime($selectedExam['scheduled_end'])) ?></strong>
                                </div>
                            <?php endif; ?>
                            <?php if ($selectedExam['access_password']): ?>
                                <div class="exam-meta-item" style="color:var(--warning)">
                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none"><rect x="3" y="11" width="18" height="11" rx="2" stroke="currentColor" stroke-width="2"/><path stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M7 11V7a5 5 0 0110 0v4"/></svg>
                                    Password protected
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
                <div style="display:flex;gap:var(--space-2);flex-shrink:0">
                    <button class="btn btn-ghost btn-sm"
                            onclick="openEditExamModal(<?= htmlspecialchars(json_encode($selectedExam)) ?>)">Edit</button>
                    <?php if (!$selectedExam['is_active']): ?>
                        <form method="POST" style="display:inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="activate_exam">
                            <input type="hidden" name="exam_id" value="<?= $selectedExam['id'] ?>">
                            <button class="btn btn-secondary btn-sm">Set Active</button>
                        </form>
                    <?php else: ?>
                        <span class="badge badge-success">Active</span>
                    <?php endif; ?>
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

        <!-- Question list header -->
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:var(--space-4)">
            <div style="font-weight:var(--weight-semibold)">Questions</div>
            <div style="display:flex;gap:var(--space-2)">
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
        </div>

        <!-- ── Sectioned question list ── -->
        <div id="questions-list">

        <?php
        $globalQNum = 0;
        // Helper to render a single question card
        function renderQuestionCard($q, &$globalQNum, $QUESTION_TYPES, $CHOICE_TYPES): void {
            global $selectedExam;
            $globalQNum++;
            $choices  = $q['choices'] ? json_decode($q['choices'], true) : [];
            $typeMeta = $QUESTION_TYPES[$q['question_type']] ?? $QUESTION_TYPES['multiple_choice'];
            $qJson    = htmlspecialchars(json_encode($q));
        ?>
            <div class="card q-card" style="padding:0;overflow:hidden" data-qid="<?= $q['id'] ?>" data-q="<?= $qJson ?>">
                <div class="q-view-mode" style="padding:var(--space-4) var(--space-5)">
                    <div style="display:flex;gap:var(--space-3);align-items:flex-start">
                        <div class="drag-handle" title="Drag to reorder">
                            <svg width="14" height="14" fill="currentColor" viewBox="0 0 24 24">
                                <path d="M8 6a1.5 1.5 0 100-3 1.5 1.5 0 000 3zM8 13.5a1.5 1.5 0 100-3 1.5 1.5 0 000 3zM8 21a1.5 1.5 0 100-3 1.5 1.5 0 000 3zM16 6a1.5 1.5 0 100-3 1.5 1.5 0 000 3zM16 13.5a1.5 1.5 0 100-3 1.5 1.5 0 000 3zM16 21a1.5 1.5 0 100-3 1.5 1.5 0 000 3z"/>
                            </svg>
                        </div>
                        <div style="flex:1;min-width:0">
                            <div style="display:flex;align-items:center;gap:var(--space-2);margin-bottom:var(--space-2)">
                                <span style="font-size:var(--text-xs);color:var(--text-tertiary)"><?= $globalQNum ?>.</span>
                                <span class="pts-badge"><?= $q['points'] ?> pt<?= $q['points']!=1?'s':'' ?></span>
                                <?php if (!$q['is_required']): ?>
                                    <span class="type-pill">Optional</span>
                                <?php endif; ?>
                            </div>
                            <p style="font-weight:var(--weight-medium);margin-bottom:var(--space-2)"><?= e($q['question_text']) ?></p>
                            <?php if ($q['description']): ?>
                                <p style="font-size:var(--text-sm);color:var(--text-tertiary);margin-bottom:var(--space-3)"><?= e($q['description']) ?></p>
                            <?php endif; ?>
                            <!-- Answer preview -->
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
                                            <svg width="13" height="13" fill="none" viewBox="0 0 24 24" style="color:var(--success);flex-shrink:0"><path stroke="currentColor" stroke-width="2.5" stroke-linecap="round" d="M5 13l4 4L19 7"/></svg>
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
                        <!-- Actions -->
                        <div style="display:flex;gap:var(--space-1)">
                            <button class="btn-icon" title="Edit question" onclick="startInlineQFullEdit(<?= $q['id'] ?>)">
                                <svg width="15" height="15" fill="none" viewBox="0 0 24 24"><path stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                            </button>
                            <form method="POST" onsubmit="return confirm('Delete this question?')" style="display:inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete_question">
                                <input type="hidden" name="question_id" value="<?= $q['id'] ?>">
                                <button class="btn-icon" style="color:var(--error)" title="Delete">
                                    <svg width="15" height="15" fill="none" viewBox="0 0 24 24"><path stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M3 6h18m-2 0V20a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a1 1 0 011-1h4a1 1 0 011 1v2"/></svg>
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        <?php } ?>

        <?php if (empty($sections) && empty($questions)): ?>
            <div class="card" style="padding:var(--space-12);text-align:center;color:var(--text-tertiary)">
                <div style="font-size:var(--text-2xl);margin-bottom:var(--space-3)">📝</div>
                <div style="font-size:var(--text-sm)">No questions yet. Click <strong>Add Section</strong> to create a section and start adding questions.</div>
            </div>
        <?php else: ?>

            <!-- Sections -->
            <?php foreach ($sections as $sec):
                $sc = $SECTION_COLORS[$sec['question_type']] ?? $SECTION_COLORS['multiple_choice'];
                $secQs = $questionsBySection[$sec['id']] ?? [];
                $secJson = htmlspecialchars(json_encode($sec));
            ?>
                <div class="card section-block" style="margin-bottom:var(--space-5);padding:0;overflow:visible" data-section-id="<?= $sec['id'] ?>">

                    <!-- Section header -->
                    <div class="section-header" style="background:<?= $sc['bg'] ?>;border-color:<?= $sc['border'] ?>;border-radius:var(--radius-md) var(--radius-md) 0 0;margin-bottom:0">
                        <div style="flex:1;min-width:0">
                            <div id="sec-title-view-<?= $sec['id'] ?>" style="display:flex;align-items:center;gap:var(--space-2)">
                                <?= typeIcon($sec['question_type']) ?>
                                <span id="sec-title-text-<?= $sec['id'] ?>"
                                      style="font-size:var(--text-sm);font-weight:var(--weight-semibold);color:<?= $sc['text'] ?>;cursor:pointer"
                                      class="inline-editable"
                                      onclick="startInlineSecEdit(<?= $sec['id'] ?>)"
                                      title="Click to rename"><?= e($sec['title']) ?></span>
                                <span style="font-size:var(--text-xs);color:<?= $sc['text'] ?>;opacity:.65" id="sec-count-<?= $sec['id'] ?>"><?= count($secQs) ?> question<?= count($secQs)!==1?'s':'' ?></span>
                            </div>
                            <?php if (!empty($sec['description'])): ?>
                                <div style="font-size:var(--text-xs);color:<?= $sc['text'] ?>;opacity:.8;margin-top:3px;padding-left:18px"><?= e($sec['description']) ?></div>
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
                            <?php if (empty($secQs)): ?>
                            <button class="btn-icon" title="Edit section" onclick="openEditSectionModal(<?= $secJson ?>)">
                                <svg width="13" height="13" fill="none" viewBox="0 0 24 24"><path stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                            </button>
                            <?php endif; ?>
                            <form method="POST" id="del-sec-form-<?= $sec['id'] ?>" style="display:inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete_section">
                                <input type="hidden" name="section_id" value="<?= $sec['id'] ?>">
                                <button type="button" class="btn-icon" style="color:var(--error)" title="Delete section"
                                        onclick="confirmDeleteSection(<?= $sec['id'] ?>, <?= count($secQs) ?>)">
                                    <svg width="13" height="13" fill="none" viewBox="0 0 24 24"><path stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M3 6h18m-2 0V20a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a1 1 0 011-1h4a1 1 0 011 1v2"/></svg>
                                </button>
                            </form>
                        </div>
                    </div>

                    <!-- Questions in this section -->
                    <div class="section-questions" id="sec-questions-<?= $sec['id'] ?>" style="display:flex;flex-direction:column;gap:var(--space-3);padding:var(--space-4)">
                        <?php if (empty($secQs)): ?>
                            <div id="sec-empty-<?= $sec['id'] ?>" style="padding:var(--space-4) var(--space-5);border:1.5px dashed var(--border);border-radius:var(--radius-md);text-align:center;color:var(--text-tertiary);font-size:var(--text-sm)">
                                No questions yet — click <strong>Add question</strong> below to get started.
                            </div>
                        <?php else: ?>
                            <?php foreach ($secQs as $q): renderQuestionCard($q, $globalQNum, $QUESTION_TYPES, $CHOICE_TYPES); endforeach; ?>
                        <?php endif; ?>

                        <!-- Inline question creator (hidden by default) -->
                        <div id="inline-creator-<?= $sec['id'] ?>" class="inline-question-creator" style="display:none" data-section-id="<?= $sec['id'] ?>" data-section-type="<?= $sec['question_type'] ?>"></div>
                    </div>

                    <!-- Add question footer -->
                    <div style="border-top:1px solid var(--border);padding:var(--space-3) var(--space-4)">
                        <button class="btn btn-ghost btn-sm" id="sec-add-btn-<?= $sec['id'] ?>"
                                onclick="showInlineCreator(<?= $sec['id'] ?>, '<?= $sec['question_type'] ?>')"
                                style="display:flex;align-items:center;gap:5px;color:var(--accent)">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none"><path stroke="currentColor" stroke-width="2.5" stroke-linecap="round" d="M12 5v14M5 12h14"/></svg>
                            Add question
                        </button>
                    </div>
                </div>
            <?php endforeach; ?>

        <?php endif; ?>
        </div><!-- /questions-list -->

    <?php else: ?>
        <div class="card" style="padding:var(--space-16);text-align:center;color:var(--text-tertiary)">
            <div style="font-size:var(--text-2xl);margin-bottom:var(--space-3)">📋</div>
            <p style="font-size:var(--text-sm)">Select an exam from the list or create a new one.</p>
        </div>
    <?php endif; ?>
    </div>
</div>

<!-- ════════════════════════════════════════════════════════════
     CREATE EXAM MODAL
════════════════════════════════════════════════════════════ -->
<div id="create-exam-modal" class="modal-backdrop" style="display:none">
    <div class="modal" style="max-width:520px;max-height:92vh;overflow-y:auto">
        <div class="modal-header">
            <div class="modal-title">New Exam</div>
            <button class="btn-icon" onclick="closeModal('create-exam-modal')">
                <svg width="18" height="18" fill="none" viewBox="0 0 24 24"><path stroke="currentColor" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create_exam">
            <div class="modal-body" style="display:flex;flex-direction:column;gap:var(--space-4)">
                <div>
                    <label class="form-label">Title <span style="color:var(--error)">*</span></label>
                    <input type="text" name="title" class="form-control" placeholder="e.g. PLP Admission Test 2025" required>
                </div>
                <div>
                    <label class="form-label">Description</label>
                    <textarea name="description" class="form-control" rows="2" placeholder="Brief instructions shown to students…"></textarea>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--space-3)">
                    <div>
                        <label class="form-label">Duration (minutes)</label>
                        <input type="number" name="duration_minutes" class="form-control" value="60" min="1" max="300">
                    </div>
                    <div>
                        <label class="form-label">Passing Score</label>
                        <input type="number" name="passing_score" class="form-control" placeholder="Optional" min="0">
                    </div>
                </div>

                <!-- Schedule -->
                <div style="border-top:1px solid var(--border);padding-top:var(--space-4)">
                    <div style="font-size:var(--text-sm);font-weight:var(--weight-semibold);margin-bottom:var(--space-3);display:flex;align-items:center;gap:6px">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none"><rect x="3" y="4" width="18" height="18" rx="2" stroke="currentColor" stroke-width="2"/><path stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M16 2v4M8 2v4M3 10h18"/></svg>
                        Schedule
                    </div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--space-3)">
                        <div>
                            <label class="form-label">Opens</label>
                            <input type="datetime-local" name="scheduled_start" class="form-control">
                        </div>
                        <div>
                            <label class="form-label">Closes</label>
                            <input type="datetime-local" name="scheduled_end" class="form-control">
                        </div>
                    </div>
                    <p style="font-size:var(--text-xs);color:var(--text-tertiary);margin-top:6px">Leave blank to allow access at any time.</p>
                </div>

                <!-- Password -->
                <div style="border-top:1px solid var(--border);padding-top:var(--space-4)">
                    <div style="font-size:var(--text-sm);font-weight:var(--weight-semibold);margin-bottom:var(--space-3);display:flex;align-items:center;gap:6px">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none"><rect x="3" y="11" width="18" height="11" rx="2" stroke="currentColor" stroke-width="2"/><path stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M7 11V7a5 5 0 0110 0v4"/></svg>
                        Access Password
                    </div>
                    <input type="text" name="access_password" class="form-control" placeholder="Leave blank for no password requirement">
                    <p style="font-size:var(--text-xs);color:var(--text-tertiary);margin-top:6px">Students must enter this password before starting the exam.</p>
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
                <svg width="18" height="18" fill="none" viewBox="0 0 24 24"><path stroke="currentColor" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="edit_exam">
            <input type="hidden" name="exam_id" id="edit-exam-id">
            <div class="modal-body" style="display:flex;flex-direction:column;gap:var(--space-4)">
                <div>
                    <label class="form-label">Title <span style="color:var(--error)">*</span></label>
                    <input type="text" name="title" id="edit-exam-title" class="form-control" required>
                </div>
                <div>
                    <label class="form-label">Description</label>
                    <textarea name="description" id="edit-exam-desc" class="form-control" rows="2"></textarea>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--space-3)">
                    <div>
                        <label class="form-label">Duration (minutes)</label>
                        <input type="number" name="duration_minutes" id="edit-exam-duration" class="form-control" min="1" max="300">
                    </div>
                    <div>
                        <label class="form-label">Passing Score</label>
                        <input type="number" name="passing_score" id="edit-exam-passing" class="form-control" placeholder="Optional" min="0">
                    </div>
                </div>

                <!-- Schedule -->
                <div style="border-top:1px solid var(--border);padding-top:var(--space-4)">
                    <div style="font-size:var(--text-sm);font-weight:var(--weight-semibold);margin-bottom:var(--space-3);display:flex;align-items:center;gap:6px">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none"><rect x="3" y="4" width="18" height="18" rx="2" stroke="currentColor" stroke-width="2"/><path stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M16 2v4M8 2v4M3 10h18"/></svg>
                        Schedule
                    </div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--space-3)">
                        <div>
                            <label class="form-label">Opens</label>
                            <input type="datetime-local" name="scheduled_start" id="edit-exam-start" class="form-control">
                        </div>
                        <div>
                            <label class="form-label">Closes</label>
                            <input type="datetime-local" name="scheduled_end" id="edit-exam-end" class="form-control">
                        </div>
                    </div>
                    <p style="font-size:var(--text-xs);color:var(--text-tertiary);margin-top:6px">Leave blank to allow access at any time.</p>
                </div>

                <!-- Password -->
                <div style="border-top:1px solid var(--border);padding-top:var(--space-4)">
                    <div style="font-size:var(--text-sm);font-weight:var(--weight-semibold);margin-bottom:var(--space-3);display:flex;align-items:center;gap:6px">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none"><rect x="3" y="11" width="18" height="11" rx="2" stroke="currentColor" stroke-width="2"/><path stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M7 11V7a5 5 0 0110 0v4"/></svg>
                        Access Password
                    </div>
                    <input type="text" name="access_password" id="edit-exam-password" class="form-control" placeholder="Leave blank to remove password">
                    <p style="font-size:var(--text-xs);color:var(--text-tertiary);margin-top:6px">Students must enter this password before starting the exam.</p>
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
                <svg width="18" height="18" fill="none" viewBox="0 0 24 24"><path stroke="currentColor" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
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
                    <label class="form-label">Instructions / Description <span style="font-size:var(--text-xs);font-weight:400;color:var(--text-tertiary)">(optional)</span></label>
                    <textarea name="section_desc" id="sec-desc-field" class="form-control" rows="2"
                              placeholder="e.g. Read each item carefully and choose the best answer."></textarea>
                    <p style="font-size:var(--text-xs);color:var(--text-tertiary);margin-top:4px">Shown to students above the questions in this section.</p>
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
// ── Modal helpers ─────────────────────────────────────────────
function openModal(id)  { document.getElementById(id).style.display = 'flex'; }
function closeModal(id) { document.getElementById(id).style.display = 'none'; }

// ── Edit exam modal ───────────────────────────────────────────
function openEditExamModal(exam) {
    document.getElementById('edit-exam-id').value       = exam.id;
    document.getElementById('edit-exam-title').value    = exam.title;
    document.getElementById('edit-exam-desc').value     = exam.description || '';
    document.getElementById('edit-exam-duration').value = exam.duration_minutes;
    document.getElementById('edit-exam-passing').value  = exam.passing_score || '';
    document.getElementById('edit-exam-shuffleQ').checked = exam.shuffle_questions == 1;
    document.getElementById('edit-exam-shuffleC').checked = exam.shuffle_choices == 1;
    const toLocal = v => v ? v.replace(' ', 'T').substring(0,16) : '';
    document.getElementById('edit-exam-start').value    = toLocal(exam.scheduled_start);
    document.getElementById('edit-exam-end').value      = toLocal(exam.scheduled_end);
    document.getElementById('edit-exam-password').value = exam.access_password || '';
    openModal('edit-exam-modal');
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

// ── Section delete confirm ────────────────────────────────────
function confirmDeleteSection(secId, qCount) {
    const msg = qCount > 0
        ? `This section has ${qCount} question${qCount > 1 ? 's' : ''} that will also be deleted. Delete anyway?`
        : 'Delete this section?';
    if (confirm(msg)) {
        document.getElementById('del-sec-form-' + secId).submit();
    }
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
                    <svg width="11" height="11" viewBox="0 0 24 24" fill="none"><path stroke="currentColor" stroke-width="2.5" stroke-linecap="round" d="M12 5v14M5 12h14"/></svg>
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
        <div>
            <label class="form-label">Description <span style="font-size:var(--text-xs);font-weight:400;color:var(--text-tertiary)">(optional hint)</span></label>
            <input type="text" id="qe-desc-${qid}" class="form-control" value="${escQA(q.description||'')}" placeholder="e.g. Choose the best answer">
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
               <svg width="13" height="13" fill="none" viewBox="0 0 24 24"><path stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M18 6L6 18M6 6l12 12"/></svg>
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
    card.querySelector('.q-view-mode').style.display = '';
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
    fd.append('question_desc',document.getElementById(`qe-desc-${qid}`)?.value.trim()||'');
    fd.append('question_type',q.question_type);
    fd.append('points',document.getElementById(`qe-points-${qid}`)?.value||1);
    if (document.getElementById(`qe-required-${qid}`)?.checked) fd.append('is_required','1');

    if (CHOICE_TYPES.includes(q.question_type)) {
        const texts = choices.map(c=>c.text).filter(t=>t.trim());
        if (texts.length < 2) { alert('Please enter at least 2 choices.'); if(saveBtn){saveBtn.disabled=false;saveBtn.textContent='Save changes';} return; }
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
        else { if(saveBtn){saveBtn.disabled=false;saveBtn.textContent='Save changes';} alert('Failed to save.'); }
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
                <svg width="11" height="11" viewBox="0 0 24 24" fill="none"><path stroke="currentColor" stroke-width="2.5" stroke-linecap="round" d="M12 5v14M5 12h14"/></svg>
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
            <div>
                <label class="form-label">Description <span style="font-size:var(--text-xs);font-weight:400;color:var(--text-tertiary)">(optional hint)</span></label>
                <input type="text" id="iq-desc-${secId}" class="form-control" placeholder="e.g. Choose the best answer">
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
               <svg width="13" height="13" fill="none" viewBox="0 0 24 24"><path stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M18 6L6 18M6 6l12 12"/></svg>
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
    const desc    = document.getElementById(`iq-desc-${secId}`)?.value.trim() || '';
    const examId  = <?= $selectedExam['id'] ?? 0 ?>;
    const csrfInput = document.querySelector('input[name^="_csrf"]');

    const fd = new FormData();
    fd.append('action', 'add_question');
    fd.append('exam_id', examId);
    fd.append('section_id', secId);
    fd.append('question_text', qText);
    fd.append('question_desc', desc);
    fd.append('question_type', secType);
    fd.append('points', points);
    if (required) fd.append('is_required', '1');
    fd.append(csrfInput.name, csrfInput.value);

    const isChoice = CHOICE_TYPE_KEYS.includes(secType);
    if (isChoice) {
        const texts = choices.map(c => c.text).filter(t => t.trim());
        if (texts.length < 2) {
            alert('Please enter at least 2 choices.');
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
            alert('Failed to save. Please try again.');
        }
    } catch(e) {
        window.location.reload();
    }
}

(function() {
    const list = document.getElementById('questions-list');
    if (!list) return;
    let dragged = null;
    list.querySelectorAll('.q-card').forEach(card => {
        card.draggable = true;
        card.addEventListener('dragstart', () => { dragged = card; card.classList.add('dragging'); });
        card.addEventListener('dragend',   () => { card.classList.remove('dragging'); saveOrder(); });
        card.addEventListener('dragover',  e => {
            e.preventDefault();
            const after = getDragAfterElement(list, e.clientY);
            if (after === null) list.appendChild(dragged);
            else list.insertBefore(dragged, after);
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
        const order = [...list.querySelectorAll('.q-card')].map(el => el.dataset.qid);
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
.ai-processing-ring { width:44px;height:44px;margin:0 auto;border:3px solid var(--border);border-top-color:var(--accent);border-radius:50%;animation:ai-spin .75s linear infinite; }
.ai-q-card { border:1px solid var(--border);border-radius:var(--radius-md);overflow:hidden; }
.ai-q-card-head { display:flex;align-items:center;gap:8px;padding:9px 14px;background:var(--neutral-50);border-bottom:1px solid var(--border);font-size:var(--text-xs);color:var(--text-tertiary); }
.ai-q-card-body { padding:12px 14px; }
.ai-q-text { font-size:var(--text-sm);font-weight:var(--weight-medium);color:var(--text-primary);margin-bottom:8px;line-height:1.45; }
.ai-choice-row { display:flex;align-items:center;gap:7px;padding:3px 0;font-size:var(--text-sm);color:var(--text-secondary); }
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
                <svg width="18" height="18" fill="none" viewBox="0 0 24 24"><path stroke="currentColor" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
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
                            <button class="rm" onclick="event.stopPropagation();clearAiFile()" title="Remove">✕</button>
                        </span>
                    </div>
                    <input type="file" id="ai-file-input" accept=".jpg,.jpeg,.png,.pdf,.docx,.txt,.doc" style="display:none" onchange="handleAiFileSelect(this)">
                </div>
                <div style="display:flex;align-items:center;gap:var(--space-2);margin-top:var(--space-3)">
                    <label style="font-size:var(--text-sm);color:var(--text-secondary);white-space:nowrap">Points per question</label>
                    <input type="number" id="ai-default-points" class="form-control" value="1" min="0" max="100" style="width:64px;padding:var(--space-1) var(--space-2);text-align:center">
                </div>
            </div>
            <div id="ai-step-processing" style="display:none;padding:var(--space-8) 0;text-align:center">
                <div class="ai-processing-ring"></div>
                <div style="margin-top:var(--space-4);font-weight:var(--weight-medium);font-size:var(--text-sm)" id="ai-processing-label">Reading file…</div>
                <div style="font-size:var(--text-xs);color:var(--text-tertiary);margin-top:4px">A Puter sign-in popup may appear</div>
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
    document.getElementById('ai-process-btn').disabled=!aiSelectedFile;
    ['upload','processing','preview','error'].forEach(s=>{ const el=document.getElementById('ai-step-'+s); if(el) el.style.display=s==='upload'?'':'none'; if(s==='preview'&&el) el.style.flexDirection='column'; });
    document.getElementById('ai-modal-footer').style.display=''; document.getElementById('ai-save-footer').style.display='none';
}
function showAiStep(step) {
    ['upload','processing','preview','error'].forEach(s=>{ const el=document.getElementById('ai-step-'+s); if(!el) return; el.style.display=s===step?(s==='processing'||s==='preview'?'flex':''):'none'; if(s==='preview') el.style.flexDirection='column'; });
}
function setProcessingLabel(t) { document.getElementById('ai-processing-label').textContent=t; }

async function startAiProcessing() {
    if(!aiSelectedFile) return;
    showAiStep('processing'); document.getElementById('ai-modal-footer').style.display='none';
    try {
        await loadPuter();
        const ext=aiSelectedFile.name.split('.').pop().toLowerCase();
        let content=null, isImage=false;
        setProcessingLabel('Reading file…');
        if(['jpg','jpeg','png'].includes(ext)) { content=aiSelectedFile; isImage=true; }
        else if(ext==='pdf') { setProcessingLabel('Extracting text from PDF…'); await loadPdfJs(); content=await extractPdfText(aiSelectedFile); }
        else if(['docx','doc'].includes(ext)) { setProcessingLabel('Extracting text from document…'); await loadMammoth(); content=await extractDocxText(aiSelectedFile); }
        else { content=await readFileAsText(aiSelectedFile); }
        if(!content||(typeof content==='string'&&content.trim().length<5)) throw new Error('Could not extract readable content from this file.');
        setProcessingLabel('Sending to AI…');
        const pts=parseInt(document.getElementById('ai-default-points').value)||1;
        const sections=isImage?await callPuterWithImage(content,pts):await callPuterWithText(content,pts);
        if(!sections||sections.length===0) throw new Error('No sections or questions were detected.');
        const totalQ=sections.reduce((sum,s)=>sum+s.questions.length,0);
        if(totalQ===0) throw new Error('No questions were detected.');
        aiGeneratedSections=sections;
        renderAiPreview(sections);
        showAiStep('preview');
        document.getElementById('ai-preview-count').textContent=
            `${sections.length} section${sections.length!==1?'s':''}, ${totalQ} question${totalQ!==1?'s':''} detected`;
        document.getElementById('ai-save-footer').style.display='';
    } catch(err) {
        showAiStep('error'); document.getElementById('ai-modal-footer').style.display='';
        let msg=typeof err==='string'?err:err?.message||err?.error||'Unknown error';
        document.getElementById('ai-error-msg').textContent=msg;
        document.getElementById('ai-process-btn').disabled=!aiSelectedFile;
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
const AI_PROMPT = `You are an exam question extractor. Extract ALL sections and their questions from the exam content.

Return ONLY a valid JSON array of sections — no markdown, no explanation, no extra text.

Structure:
[
  {
    "section_title": "I. Multiple Choice",
    "section_type": "multiple_choice",
    "section_description": "Read each item carefully and choose the best answer.",
    "questions": [
      {
        "question_text": "What is the capital of France?",
        "choices": ["London", "Paris", "Berlin", "Rome"],
        "correct_index": 1,
        "correct_indices": [],
        "correct_answer": null,
        "points": 1,
        "description": ""
      }
    ]
  }
]

Section type rules (pick the best match):
- "multiple_choice" → Multiple Choice, True/False, Matching Type
- "checkboxes"      → Select all that apply, Multiple correct answers
- "short_answer"    → Identification, Fill in the blank, Enumeration, Completion
- "paragraph"       → Essay, Explain, Discuss, Long answer

Additional rules:
- section_description: Copy any instructions or directions shown for that section (e.g. "Write the letter of the correct answer", "Identify the following", "Choose the best answer"). Leave as empty string if none found.
- For True/False sections: use section_type "multiple_choice" and choices ["True","False"]
- Strip letter/number prefixes from choices (e.g. "a. Paris" → "Paris", "A) Paris" → "Paris")
- correct_index is the 0-based index of the correct choice (for multiple_choice/dropdown)
- If the correct answer is not shown, set correct_index to 0
- Output ONLY the JSON array, nothing else`;

async function callPuterWithText(text, pts) {
    const trunc=text.length>10000?text.slice(0,10000)+'…[truncated]':text;
    await ensurePuterAuth();
    const response=await puter.ai.chat(AI_PROMPT+'\n\nCONTENT:\n\n'+trunc, {model:'claude-haiku-4-5'});
    return parseAiResp(extractPuterText(response), pts);
}
async function callPuterWithImage(file, pts) {
    await ensurePuterAuth();
    const tmpName='exam_import_'+Date.now()+'.'+file.name.split('.').pop();
    let puterFile; try { puterFile=await puter.fs.write(tmpName,file); } catch(e) { throw new Error('Could not upload image to Puter: '+e.message); }
    let response; try { response=await puter.ai.chat([{role:'user',content:[{type:'file',puter_path:puterFile.path},{type:'text',text:AI_PROMPT}]}],{model:'claude-haiku-4-5'}); } finally { try{await puter.fs.delete(puterFile.path);}catch(_){} }
    return parseAiResp(extractPuterText(response), pts);
}

function parseAiResp(raw, dp) {
    let s=raw.trim().replace(/^```(?:json)?\s*/i,'').replace(/\s*```\s*$/i,'').trim();
    const start=s.indexOf('['), end=s.lastIndexOf(']');
    if(start!==-1&&end!==-1&&end>start) s=s.slice(start,end+1);
    let arr; try{arr=JSON.parse(s);}catch(e){throw new Error('AI response could not be parsed as JSON.');}
    if(!Array.isArray(arr)) throw new Error('AI did not return a valid list.');

    const VALID_TYPES=['multiple_choice','checkboxes','short_answer','paragraph'];
    return arr.map(sec=>({
        section_title: sec.section_title||sec.title||'Untitled Section',
        section_type: VALID_TYPES.includes(sec.section_type)?sec.section_type:'multiple_choice',
        section_description: sec.section_description||sec.description||'',
        questions: (Array.isArray(sec.questions)?sec.questions:[]).map(q=>({
            question_text:  q.question_text||'Untitled question',
            question_type:  VALID_TYPES.includes(q.question_type)?q.question_type:
                            (VALID_TYPES.includes(sec.section_type)?sec.section_type:'multiple_choice'),
            description:    q.description||'',
            choices:        Array.isArray(q.choices)?q.choices:[],
            correct_index:  typeof q.correct_index==='number'?q.correct_index:0,
            correct_answer: q.correct_answer||null,
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
        secHead.style.cssText=`background:${sc.bg};border:1.5px solid ${sc.border};border-radius:var(--radius-md);padding:8px 12px;display:flex;align-items:center;gap:8px;margin-top:var(--space-2)`;
        secHead.innerHTML=`<span style="font-size:var(--text-xs);font-weight:var(--weight-semibold);color:${sc.text}">${escHtml(sec.section_title)}</span><span style="font-size:var(--text-xs);color:${sc.text};opacity:.7;margin-left:auto">${sec.section_type.replace(/_/g,' ')} · ${sec.questions.length} question${sec.questions.length!==1?'s':''}</span>`;
        wrap.appendChild(secHead);

        // Questions
        sec.questions.forEach(q=>{
            globalQ++;
            const card=document.createElement('div'); card.className='ai-q-card'; card.style.marginLeft='var(--space-3)';
            let choices='';
            if(CT.includes(q.question_type)&&q.choices.length){
                choices=q.choices.map((c,ci)=>{
                    const ok=q.question_type==='checkboxes'?(q.correct_indices||[]).includes(ci):ci===q.correct_index;
                    return`<div class="ai-choice-row${ok?' correct':''}"><div class="ai-choice-ind">${ok?'<svg width="9" height="9" viewBox="0 0 24 24" fill="none"><path stroke="#fff" stroke-width="3.5" stroke-linecap="round" d="M5 13l4 4L19 7"/></svg>':''}</div>${escHtml(c)}</div>`;
                }).join('');
            } else if(q.question_type==='short_answer'){
                choices=`<div style="font-size:var(--text-xs);color:var(--text-tertiary);font-style:italic">${q.correct_answer?'Expected: '+escHtml(q.correct_answer):'Short answer — manually graded'}</div>`;
            } else {
                choices=`<div style="font-size:var(--text-xs);color:var(--text-tertiary);font-style:italic">Paragraph — manually graded</div>`;
            }
            card.innerHTML=`<div class="ai-q-card-head"><span style="background:var(--accent);color:#fff;border-radius:4px;padding:1px 7px;font-weight:var(--weight-semibold)">Q${globalQ}</span><span>${q.question_type.replace(/_/g,' ')}</span><span style="margin-left:auto">${q.points} pt${q.points!==1?'s':''}</span></div><div class="ai-q-card-body"><div class="ai-q-text">${escHtml(q.question_text)}</div>${choices}</div>`;
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
    if(!examId){alert('No exam selected.');btn.disabled=false;return;}
    if(!csrf){alert('Security token missing. Please refresh.');btn.disabled=false;return;}

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
            fd.append('question_text',q.question_text);fd.append('question_desc',q.description||'');
            fd.append('question_type',q.question_type);fd.append('points',q.points);fd.append('is_required','1');
            fd.append('section_id',sectionId);
            if(CT.includes(q.question_type)&&q.choices.length){
                q.choices.forEach(c=>fd.append('choices[]',c));
                if(q.question_type==='checkboxes')(q.correct_indices||[]).forEach(ci=>fd.append('correct_indices[]',ci));
                else fd.append('correct_index',q.correct_index);
            } else if(q.question_type==='short_answer') fd.append('expected_answer',q.correct_answer||'');
            try{const resp=await fetch(location.href,{method:'POST',body:fd});if(resp.ok)saved++;}catch(e){}
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