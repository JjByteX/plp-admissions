<?php
// ============================================================
// modules/exam/staff_manage.php
// M4 — Staff: create/manage exam, add/edit questions
// Google Forms-style question builder
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
    'multiple_choice' => ['label' => 'Multiple Choice',  'icon' => 'radio'],
    'checkboxes'      => ['label' => 'Checkboxes',        'icon' => 'check'],
    'dropdown'        => ['label' => 'Dropdown',          'icon' => 'chevron'],
    'short_answer'    => ['label' => 'Short Answer',      'icon' => 'text'],
    'paragraph'       => ['label' => 'Paragraph',         'icon' => 'paragraph'],
    'linear_scale'    => ['label' => 'Linear Scale',      'icon' => 'scale'],
];

$CHOICE_TYPES = ['multiple_choice', 'checkboxes', 'dropdown'];

// ----------------------------------------------------------------
// POST handlers
// ----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    switch ($action) {

        case 'create_exam':
            $title    = trim($_POST['title'] ?? '');
            $desc     = trim($_POST['description'] ?? '');
            $duration = (int)($_POST['duration_minutes'] ?? 60);
            $passing  = $_POST['passing_score'] !== '' ? (int)$_POST['passing_score'] : null;
            $shuffleQ = isset($_POST['shuffle_questions']) ? 1 : 0;
            $shuffleC = isset($_POST['shuffle_choices']) ? 1 : 0;
            if (!$title) { $errors[] = 'Exam title is required.'; break; }
            $db->prepare('UPDATE exams SET is_active=0')->execute();
            $stmt = $db->prepare(
                'INSERT INTO exams (title, description, duration_minutes, passing_score, shuffle_questions, shuffle_choices, is_active)
                 VALUES (?,?,?,?,?,?,1)'
            );
            $stmt->execute([$title, $desc ?: null, $duration, $passing, $shuffleQ, $shuffleC]);
            $success[] = 'Exam created and set as active.';
            break;

        case 'edit_exam':
            $examId   = (int)($_POST['exam_id'] ?? 0);
            $title    = trim($_POST['title'] ?? '');
            $desc     = trim($_POST['description'] ?? '');
            $duration = (int)($_POST['duration_minutes'] ?? 60);
            $passing  = $_POST['passing_score'] !== '' ? (int)$_POST['passing_score'] : null;
            $shuffleQ = isset($_POST['shuffle_questions']) ? 1 : 0;
            $shuffleC = isset($_POST['shuffle_choices']) ? 1 : 0;
            if (!$title) { $errors[] = 'Exam title is required.'; break; }
            $db->prepare(
                'UPDATE exams SET title=?, description=?, duration_minutes=?, passing_score=?,
                 shuffle_questions=?, shuffle_choices=? WHERE id=?'
            )->execute([$title, $desc ?: null, $duration, $passing, $shuffleQ, $shuffleC, $examId]);
            $success[] = 'Exam updated.';
            break;

        case 'activate_exam':
            $examId = (int)($_POST['exam_id'] ?? 0);
            $db->prepare('UPDATE exams SET is_active=0')->execute();
            $db->prepare('UPDATE exams SET is_active=1 WHERE id=?')->execute([$examId]);
            $success[] = 'Exam activated.';
            break;

        case 'add_question':
        case 'edit_question':
            $isEdit  = ($action === 'edit_question');
            $examId  = (int)($_POST['exam_id'] ?? 0);
            $qId     = (int)($_POST['question_id'] ?? 0);
            $qText   = trim($_POST['question_text'] ?? '');
            $qDesc   = trim($_POST['question_desc'] ?? '');
            $qType   = $_POST['question_type'] ?? 'multiple_choice';
            $points  = max(0, (int)($_POST['points'] ?? 1));
            $required = isset($_POST['is_required']) ? 1 : 0;

            if (!$qText) { $errors[] = 'Question text is required.'; break; }
            if (!array_key_exists($qType, $QUESTION_TYPES)) { $qType = 'multiple_choice'; }

            // Parse choices / correct answer
            $choices       = null;
            $correctIndex  = null;
            $correctAnswer = null;
            $scaleMin = 1; $scaleMax = 5;
            $scaleMinLabel = null; $scaleMaxLabel = null;

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
                $choices = null;
                // No auto-grading for scale by default
            } elseif ($qType === 'short_answer') {
                $correctAnswer = trim($_POST['expected_answer'] ?? '') ?: null;
            }
            // paragraph: no grading

            if ($isEdit && $qId) {
                $db->prepare(
                    'UPDATE questions SET question_text=?, question_type=?, description=?, points=?, is_required=?,
                     choices=?, correct_index=?, correct_answer=?, scale_min=?, scale_max=?,
                     scale_min_label=?, scale_max_label=? WHERE id=? AND exam_id=?'
                )->execute([
                    $qText, $qType, $qDesc ?: null, $points, $required,
                    $choices, $correctIndex, $correctAnswer,
                    $scaleMin, $scaleMax, $scaleMinLabel, $scaleMaxLabel,
                    $qId, $examId
                ]);
                $success[] = 'Question updated.';
            } else {
                $maxOrder = $db->prepare('SELECT COALESCE(MAX(sort_order),0) FROM questions WHERE exam_id=?');
                $maxOrder->execute([$examId]);
                $sortOrder = (int)$maxOrder->fetchColumn() + 1;
                $db->prepare(
                    'INSERT INTO questions (exam_id, question_text, question_type, description, points, is_required,
                     choices, correct_index, correct_answer, scale_min, scale_max, scale_min_label, scale_max_label, sort_order)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
                )->execute([
                    $examId, $qText, $qType, $qDesc ?: null, $points, $required,
                    $choices, $correctIndex, $correctAnswer,
                    $scaleMin, $scaleMax, $scaleMinLabel, $scaleMaxLabel, $sortOrder
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
            // AJAX — return JSON
            header('Content-Type: application/json');
            echo json_encode(['ok' => true]);
            exit;
    }
}

// ----------------------------------------------------------------
// Load data
// ----------------------------------------------------------------
$exams = $db->query('SELECT * FROM exams ORDER BY id DESC')->fetchAll();
$activeExam = $db->query('SELECT * FROM exams WHERE is_active=1 LIMIT 1')->fetch() ?: null;
$questions  = [];
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
    }
}

// Stats
$resultStats = [];
$totalPoints = 0;
if ($selectedExam) {
    $stmt = $db->prepare(
        'SELECT COUNT(*) as total, AVG(score) as avg_score, MAX(score) as max_score, MIN(score) as min_score
         FROM exam_results WHERE exam_id=?'
    );
    $stmt->execute([$selectedExamId]);
    $resultStats = $stmt->fetch();
    $totalPoints = array_sum(array_column($questions, 'points'));
}

// Type icons as inline SVG snippets
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

ob_start();
?>

<style>
/* ── Question card hover/drag ────────────────────────── */
.q-card { transition: box-shadow .15s, border-color .15s; }
.q-card:hover { border-color: var(--accent); }
.q-card.dragging { opacity:.5; }
.drag-handle { cursor: grab; color: var(--text-tertiary); padding: 4px; }
.drag-handle:active { cursor: grabbing; }

/* ── Type pills ──────────────────────────────────────── */
.type-pill {
    display: inline-flex; align-items: center; gap: 4px;
    font-size: var(--text-xs); padding: 2px 8px;
    background: var(--neutral-100); border-radius: 99px;
    color: var(--text-secondary);
}

/* ── Question type selector grid ─────────────────────── */
.type-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:var(--space-2); }
.type-option {
    display:flex; flex-direction:column; align-items:center; gap:4px;
    padding: var(--space-3); border: 2px solid var(--border);
    border-radius: var(--radius-md); cursor: pointer;
    font-size: var(--text-xs); color: var(--text-secondary);
    text-align: center; transition: border-color .15s, background .15s;
    user-select: none;
}
.type-option:hover { border-color: var(--accent); background: var(--accent-bg, rgba(45,106,79,.05)); }
.type-option.selected { border-color: var(--accent); background: var(--accent-bg, rgba(45,106,79,.08)); color: var(--accent); }

/* ── Points badge ────────────────────────────────────── */
.pts-badge {
    font-size: var(--text-xs); font-weight: var(--weight-semibold);
    padding: 2px 8px; background: var(--accent); color: #fff;
    border-radius: 99px;
}

/* ── Exam sidebar link ───────────────────────────────── */
.exam-link {
    display:block; padding:var(--space-3) var(--space-4);
    border-radius:var(--radius-md); text-decoration:none;
    margin-bottom:var(--space-1); color:var(--text-primary);
    transition: background .12s;
}
.exam-link:hover { background: var(--neutral-100); }
.exam-link.active { background: var(--neutral-100); }

/* ── Toggle switch ───────────────────────────────────── */
.toggle-wrap { display:flex; align-items:center; gap:var(--space-3); }
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

/* ── Scale preview ───────────────────────────────────── */
.scale-preview { display:flex; gap:var(--space-2); flex-wrap:wrap; }
.scale-btn {
    width:36px; height:36px; border:2px solid var(--border);
    border-radius:var(--radius-md); display:flex; align-items:center; justify-content:center;
    font-size:var(--text-sm); font-weight:var(--weight-medium);
    background:var(--surface); color:var(--text-primary);
}
</style>

<div class="page-header">
    <div>
        <h1 class="page-title">Entrance Exam</h1>
        <p class="page-description">Build and manage exam questions.</p>
    </div>
    <button class="btn btn-primary" onclick="openModal('create-exam-modal')">
        + New Exam
    </button>
</div>

<?php foreach ($errors as $e): ?>
    <div class="alert alert-error" style="margin-bottom:var(--space-3)"><?= e($e) ?></div>
<?php endforeach; ?>
<?php foreach ($success as $s): ?>
    <div class="alert alert-success" style="margin-bottom:var(--space-3)"><?= e($s) ?></div>
<?php endforeach; ?>

<div style="display:grid;grid-template-columns:220px 1fr;gap:var(--space-6);align-items:start">

    <!-- ── Sidebar ── -->
    <div>
        <div style="font-size:var(--text-xs);font-weight:var(--weight-semibold);text-transform:uppercase;letter-spacing:.06em;color:var(--text-tertiary);margin-bottom:var(--space-3)">Exams</div>
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
                </a>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- ── Main panel ── -->
    <div>
    <?php if ($selectedExam): ?>

        <!-- Exam header card -->
        <div class="card" style="margin-bottom:var(--space-5);padding:var(--space-5)">
            <div style="display:flex;align-items:flex-start;gap:var(--space-4)">
                <div style="flex:1">
                    <div style="font-weight:var(--weight-semibold);font-size:var(--text-lg)"><?= e($selectedExam['title']) ?></div>
                    <?php if ($selectedExam['description']): ?>
                        <div style="font-size:var(--text-sm);color:var(--text-secondary);margin-top:2px"><?= e($selectedExam['description']) ?></div>
                    <?php endif; ?>
                    <div style="font-size:var(--text-xs);color:var(--text-tertiary);margin-top:var(--space-2);display:flex;gap:var(--space-4)">
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
                </div>
                <div style="display:flex;gap:var(--space-2)">
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
            <button class="btn btn-primary btn-sm" onclick="openAddQuestionModal()">+ Add Question</button>
        </div>

        <!-- Questions -->
        <?php if (empty($questions)): ?>
            <div class="card" style="padding:var(--space-12);text-align:center;color:var(--text-tertiary)">
                <div style="font-size:var(--text-2xl);margin-bottom:var(--space-3)">📝</div>
                <div style="font-size:var(--text-sm)">No questions yet. Click <strong>+ Add Question</strong> to get started.</div>
            </div>
        <?php else: ?>
            <div id="questions-list" style="display:flex;flex-direction:column;gap:var(--space-3)">
            <?php foreach ($questions as $qi => $q):
                $choices = $q['choices'] ? json_decode($q['choices'], true) : [];
                $typeMeta = $QUESTION_TYPES[$q['question_type']] ?? $QUESTION_TYPES['multiple_choice'];
            ?>
                <div class="card q-card" style="padding:var(--space-4) var(--space-5)" data-qid="<?= $q['id'] ?>">
                    <div style="display:flex;gap:var(--space-3);align-items:flex-start">

                        <!-- Drag handle -->
                        <div class="drag-handle" title="Drag to reorder">
                            <svg width="14" height="14" fill="currentColor" viewBox="0 0 24 24">
                                <path d="M8 6a1.5 1.5 0 100-3 1.5 1.5 0 000 3zM8 13.5a1.5 1.5 0 100-3 1.5 1.5 0 000 3zM8 21a1.5 1.5 0 100-3 1.5 1.5 0 000 3zM16 6a1.5 1.5 0 100-3 1.5 1.5 0 000 3zM16 13.5a1.5 1.5 0 100-3 1.5 1.5 0 000 3zM16 21a1.5 1.5 0 100-3 1.5 1.5 0 000 3z"/>
                            </svg>
                        </div>

                        <div style="flex:1;min-width:0">
                            <!-- Question meta -->
                            <div style="display:flex;align-items:center;gap:var(--space-2);margin-bottom:var(--space-2)">
                                <span style="font-size:var(--text-xs);color:var(--text-tertiary)"><?= $qi+1 ?>.</span>
                                <span class="type-pill">
                                    <?= typeIcon($q['question_type']) ?>
                                    <?= $typeMeta['label'] ?>
                                </span>
                                <span class="pts-badge"><?= $q['points'] ?> pt<?= $q['points']!=1?'s':'' ?></span>
                                <?php if (!$q['is_required']): ?>
                                    <span class="type-pill">Optional</span>
                                <?php endif; ?>
                            </div>

                            <!-- Question text -->
                            <p style="font-weight:var(--weight-medium);margin-bottom:var(--space-2)"><?= e($q['question_text']) ?></p>
                            <?php if ($q['description']): ?>
                                <p style="font-size:var(--text-sm);color:var(--text-tertiary);margin-bottom:var(--space-3)"><?= e($q['description']) ?></p>
                            <?php endif; ?>

                            <!-- Answer preview -->
                            <?php if (in_array($q['question_type'], $CHOICE_TYPES) && !empty($choices)): ?>
                                <div style="display:flex;flex-direction:column;gap:var(--space-1)">
                                <?php
                                $correctIndices = [];
                                if ($q['question_type'] === 'checkboxes' && $q['correct_answer']) {
                                    $correctIndices = json_decode($q['correct_answer'], true) ?? [];
                                }
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
                                    <?php if ($q['scale_min_label']): ?>
                                        <span style="font-size:var(--text-xs);color:var(--text-tertiary)"><?= e($q['scale_min_label']) ?></span>
                                    <?php endif; ?>
                                    <?php for ($s=$q['scale_min'];$s<=$q['scale_max'];$s++): ?>
                                        <div class="scale-btn"><?= $s ?></div>
                                    <?php endfor; ?>
                                    <?php if ($q['scale_max_label']): ?>
                                        <span style="font-size:var(--text-xs);color:var(--text-tertiary)"><?= e($q['scale_max_label']) ?></span>
                                    <?php endif; ?>
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
                            <button class="btn-icon" title="Edit question"
                                    onclick="openEditQuestionModal(<?= htmlspecialchars(json_encode($q)) ?>)">
                                <svg width="15" height="15" fill="none" viewBox="0 0 24 24"><path stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                            </button>
                            <form method="POST" onsubmit="return confirm('Delete this question?')" style="display:inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete_question">
                                <input type="hidden" name="question_id" value="<?= $q['id'] ?>">
                                <button class="btn-icon" style="color:var(--error)" title="Delete question">
                                    <svg width="15" height="15" fill="none" viewBox="0 0 24 24"><path stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M3 6h18m-2 0V20a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a1 1 0 011-1h4a1 1 0 011 1v2"/></svg>
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>

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
    <div class="modal" style="max-width:480px">
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
    <div class="modal" style="max-width:480px">
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
     ADD / EDIT QUESTION MODAL
════════════════════════════════════════════════════════════ -->
<div id="question-modal" class="modal-backdrop" style="display:none">
    <div class="modal" style="max-width:600px;max-height:90vh;display:flex;flex-direction:column">
        <div class="modal-header">
            <div class="modal-title" id="q-modal-title">Add Question</div>
            <button class="btn-icon" onclick="closeModal('question-modal')">
                <svg width="18" height="18" fill="none" viewBox="0 0 24 24"><path stroke="currentColor" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>

        <form method="POST" id="question-form" style="display:flex;flex-direction:column;flex:1;overflow:hidden">
            <?= csrf_field() ?>
            <input type="hidden" name="action" id="q-action" value="add_question">
            <input type="hidden" name="exam_id" value="<?= $selectedExam['id'] ?? 0 ?>">
            <input type="hidden" name="question_id" id="q-id" value="">

            <div class="modal-body" style="overflow-y:auto;display:flex;flex-direction:column;gap:var(--space-4)">

                <!-- Question type selector -->
                <div>
                    <label class="form-label">Question Type</label>
                    <div class="type-grid" id="type-grid">
                        <?php foreach ($QUESTION_TYPES as $typeKey => $typeMeta): ?>
                            <div class="type-option <?= $typeKey==='multiple_choice'?'selected':'' ?>"
                                 data-type="<?= $typeKey ?>"
                                 onclick="selectQuestionType('<?= $typeKey ?>')">
                                <?php
                                $svgPaths = [
                                    'multiple_choice' => '<circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="2"/><circle cx="12" cy="12" r="4" fill="currentColor"/>',
                                    'checkboxes'      => '<rect x="3" y="3" width="18" height="18" rx="3" stroke="currentColor" stroke-width="2"/><path stroke="currentColor" stroke-width="2.5" stroke-linecap="round" d="M7 12l4 4 6-6"/>',
                                    'dropdown'        => '<rect x="3" y="6" width="18" height="12" rx="2" stroke="currentColor" stroke-width="2"/><path stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M8 11l4 4 4-4"/>',
                                    'short_answer'    => '<path stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M3 8h18M3 12h12"/>',
                                    'paragraph'       => '<path stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M3 6h18M3 10h18M3 14h12M3 18h8"/>',
                                    'linear_scale'    => '<path stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M3 12h18M7 8l-4 4 4 4M17 8l4 4-4 4"/>',
                                ];
                                ?>
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none"><?= $svgPaths[$typeKey] ?></svg>
                                <?= $typeMeta['label'] ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <input type="hidden" name="question_type" id="q-type" value="multiple_choice">
                </div>

                <!-- Question text + description -->
                <div>
                    <label class="form-label">Question <span style="color:var(--error)">*</span></label>
                    <textarea name="question_text" id="q-text" class="form-control" rows="2" required placeholder="Enter question…"></textarea>
                </div>
                <div>
                    <label class="form-label">Description <span style="font-size:var(--text-xs);font-weight:400;color:var(--text-tertiary)">(optional hint shown below the question)</span></label>
                    <input type="text" name="question_desc" id="q-desc" class="form-control" placeholder="e.g. Choose the best answer">
                </div>

                <!-- Points + Required row -->
                <div style="display:flex;gap:var(--space-4);align-items:flex-end">
                    <div style="width:100px">
                        <label class="form-label">Points</label>
                        <input type="number" name="points" id="q-points" class="form-control" value="1" min="0" max="100">
                    </div>
                    <label style="display:flex;align-items:center;gap:var(--space-3);cursor:pointer;padding-bottom:6px">
                        <input type="checkbox" name="is_required" id="q-required" checked style="accent-color:var(--accent)">
                        <span style="font-size:var(--text-sm)">Required</span>
                    </label>
                </div>

                <!-- CHOICES section (MC / Checkboxes / Dropdown) -->
                <div id="choices-section">
                    <label class="form-label">Answer Choices <span style="color:var(--error)">*</span></label>
                    <div id="choices-list" style="display:flex;flex-direction:column;gap:var(--space-2)"></div>
                    <button type="button" class="btn btn-ghost btn-sm" style="margin-top:var(--space-2)" onclick="addChoice()">+ Add Choice</button>
                    <p style="font-size:var(--text-xs);color:var(--text-tertiary);margin-top:var(--space-2)" id="correct-hint"></p>
                </div>

                <!-- SHORT ANSWER expected value -->
                <div id="shortanswer-section" style="display:none">
                    <label class="form-label">Expected Answer <span style="font-size:var(--text-xs);font-weight:400;color:var(--text-tertiary)">(optional – for auto-grading exact match)</span></label>
                    <input type="text" name="expected_answer" id="q-expected" class="form-control" placeholder="Leave blank for manual grading">
                </div>

                <!-- LINEAR SCALE settings -->
                <div id="scale-section" style="display:none;flex-direction:column;gap:var(--space-3)">
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--space-3)">
                        <div>
                            <label class="form-label">Min</label>
                            <select name="scale_min" id="q-scale-min" class="form-control" onchange="updateScalePreview()">
                                <option value="0">0</option>
                                <option value="1" selected>1</option>
                            </select>
                        </div>
                        <div>
                            <label class="form-label">Max</label>
                            <select name="scale_max" id="q-scale-max" class="form-control" onchange="updateScalePreview()">
                                <?php for($n=2;$n<=10;$n++): ?>
                                    <option value="<?= $n ?>" <?= $n===5?'selected':'' ?>><?= $n ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                    </div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--space-3)">
                        <div>
                            <label class="form-label">Min Label</label>
                            <input type="text" name="scale_min_label" id="q-scale-min-label" class="form-control" placeholder="e.g. Strongly disagree">
                        </div>
                        <div>
                            <label class="form-label">Max Label</label>
                            <input type="text" name="scale_max_label" id="q-scale-max-label" class="form-control" placeholder="e.g. Strongly agree">
                        </div>
                    </div>
                    <div>
                        <label class="form-label">Preview</label>
                        <div class="scale-preview" id="scale-preview"></div>
                    </div>
                </div>

            </div><!-- /modal-body -->

            <div class="modal-footer">
                <button type="button" class="btn btn-ghost" onclick="closeModal('question-modal')">Cancel</button>
                <button type="submit" class="btn btn-primary" id="q-submit-btn">Add Question</button>
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
    document.getElementById('edit-exam-id').value        = exam.id;
    document.getElementById('edit-exam-title').value     = exam.title;
    document.getElementById('edit-exam-desc').value      = exam.description || '';
    document.getElementById('edit-exam-duration').value  = exam.duration_minutes;
    document.getElementById('edit-exam-passing').value   = exam.passing_score || '';
    document.getElementById('edit-exam-shuffleQ').checked = exam.shuffle_questions == 1;
    document.getElementById('edit-exam-shuffleC').checked = exam.shuffle_choices == 1;
    openModal('edit-exam-modal');
}

// ── Question modal ────────────────────────────────────────────
let currentChoices = [];   // {text, correct}

function openAddQuestionModal() {
    document.getElementById('q-modal-title').textContent  = 'Add Question';
    document.getElementById('q-action').value             = 'add_question';
    document.getElementById('q-id').value                 = '';
    document.getElementById('q-submit-btn').textContent   = 'Add Question';
    document.getElementById('q-text').value               = '';
    document.getElementById('q-desc').value               = '';
    document.getElementById('q-points').value             = '1';
    document.getElementById('q-required').checked         = true;
    document.getElementById('q-expected').value           = '';

    // Reset scale
    document.getElementById('q-scale-min').value         = '1';
    document.getElementById('q-scale-max').value         = '5';
    document.getElementById('q-scale-min-label').value   = '';
    document.getElementById('q-scale-max-label').value   = '';

    currentChoices = [
        {text:'', correct: true},
        {text:'', correct: false},
        {text:'', correct: false},
        {text:'', correct: false},
    ];
    selectQuestionType('multiple_choice');
    openModal('question-modal');
}

function openEditQuestionModal(q) {
    document.getElementById('q-modal-title').textContent = 'Edit Question';
    document.getElementById('q-action').value            = 'edit_question';
    document.getElementById('q-id').value               = q.id;
    document.getElementById('q-submit-btn').textContent  = 'Save Changes';
    document.getElementById('q-text').value              = q.question_text;
    document.getElementById('q-desc').value              = q.description || '';
    document.getElementById('q-points').value            = q.points;
    document.getElementById('q-required').checked        = q.is_required == 1;
    document.getElementById('q-expected').value          = q.correct_answer || '';

    document.getElementById('q-scale-min').value         = q.scale_min || 1;
    document.getElementById('q-scale-max').value         = q.scale_max || 5;
    document.getElementById('q-scale-min-label').value   = q.scale_min_label || '';
    document.getElementById('q-scale-max-label').value   = q.scale_max_label || '';

    const choices = q.choices ? JSON.parse(q.choices) : [];
    const correctIndices = (q.question_type === 'checkboxes' && q.correct_answer)
        ? JSON.parse(q.correct_answer) : [];
    const singleCorrect = parseInt(q.correct_index ?? 0);

    currentChoices = choices.map((text, i) => ({
        text,
        correct: q.question_type === 'checkboxes'
            ? correctIndices.includes(i)
            : i === singleCorrect
    }));

    selectQuestionType(q.question_type);
    openModal('question-modal');
}

// ── Type selection ────────────────────────────────────────────
function selectQuestionType(type) {
    document.getElementById('q-type').value = type;

    // Update grid
    document.querySelectorAll('.type-option').forEach(el => {
        el.classList.toggle('selected', el.dataset.type === type);
    });

    const choiceTypes = ['multiple_choice','checkboxes','dropdown'];
    const isChoice    = choiceTypes.includes(type);

    document.getElementById('choices-section').style.display      = isChoice ? '' : 'none';
    document.getElementById('shortanswer-section').style.display  = type === 'short_answer' ? '' : 'none';
    document.getElementById('scale-section').style.display        = type === 'linear_scale' ? 'flex' : 'none';

    if (isChoice) {
        if (currentChoices.length === 0) {
            currentChoices = [
                {text:'', correct: true},
                {text:'', correct: false},
                {text:'', correct: false},
                {text:'', correct: false},
            ];
        }
        renderChoices(type);
        document.getElementById('correct-hint').textContent =
            type === 'checkboxes'
                ? 'Check all correct answers.'
                : 'Select the correct answer.';
    }

    if (type === 'linear_scale') updateScalePreview();
}

// ── Choices ───────────────────────────────────────────────────
function renderChoices(type) {
    const list = document.getElementById('choices-list');
    list.innerHTML = '';
    const isCheckbox = type === 'checkboxes';

    currentChoices.forEach((c, i) => {
        const row = document.createElement('div');
        row.style.cssText = 'display:flex;align-items:center;gap:8px';

        if (isCheckbox) {
            const cb = document.createElement('input');
            cb.type = 'checkbox'; cb.name = 'correct_indices[]'; cb.value = i;
            cb.checked = c.correct; cb.style.accentColor = 'var(--accent)';
            cb.title = 'Mark as correct';
            row.appendChild(cb);
        } else {
            const rb = document.createElement('input');
            rb.type = 'radio'; rb.name = 'correct_index'; rb.value = i;
            rb.checked = c.correct; rb.style.accentColor = 'var(--accent)';
            rb.title = 'Mark as correct';
            row.appendChild(rb);
        }

        const inp = document.createElement('input');
        inp.type = 'text'; inp.name = 'choices[]'; inp.className = 'form-control';
        inp.placeholder = 'Choice ' + String.fromCharCode(65 + i);
        inp.value = c.text;
        inp.required = i < 2;
        inp.oninput = () => { currentChoices[i].text = inp.value; };
        row.appendChild(inp);

        if (i >= 2) {
            const del = document.createElement('button');
            del.type = 'button'; del.className = 'btn-icon'; del.title = 'Remove choice';
            del.style.color = 'var(--error)';
            del.innerHTML = '<svg width="14" height="14" fill="none" viewBox="0 0 24 24"><path stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M18 6L6 18M6 6l12 12"/></svg>';
            del.onclick = () => { currentChoices.splice(i,1); renderChoices(type); };
            row.appendChild(del);
        }

        list.appendChild(row);
    });
}

function addChoice() {
    currentChoices.push({text:'', correct: false});
    const type = document.getElementById('q-type').value;
    renderChoices(type);
}

// ── Scale preview ─────────────────────────────────────────────
function updateScalePreview() {
    const min = parseInt(document.getElementById('q-scale-min').value);
    const max = parseInt(document.getElementById('q-scale-max').value);
    const preview = document.getElementById('scale-preview');
    preview.innerHTML = '';
    for (let i = min; i <= max; i++) {
        const btn = document.createElement('div');
        btn.className = 'scale-btn';
        btn.textContent = i;
        preview.appendChild(btn);
    }
}
updateScalePreview();

// ── Drag to reorder ───────────────────────────────────────────
(function() {
    const list = document.getElementById('questions-list');
    if (!list) return;

    let dragged = null;

    list.querySelectorAll('.q-card').forEach(card => {
        card.draggable = true;
        card.addEventListener('dragstart', () => { dragged = card; card.classList.add('dragging'); });
        card.addEventListener('dragend', () => {
            card.classList.remove('dragging');
            saveOrder();
        });
        card.addEventListener('dragover', e => {
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
        const fd = new FormData();
        fd.append('action', 'reorder_questions');
        fd.append('<?= csrf_token_field() ?? '_csrf' ?>', document.querySelector('input[name="<?= csrf_token_field() ?? '_csrf' ?>"]')?.value ?? '');
        order.forEach(id => fd.append('order[]', id));
        fetch(location.href, {method:'POST', body: fd}).catch(()=>{});
    }
})();
</script>

<?php
// Helper for CSRF field name (adjust if your csrf_field() uses a different name)
function csrf_token_field() { return '_csrf_token'; }

$content   = ob_get_clean();
$pageTitle = 'Entrance Exam';
$activeNav = 'exam';
include VIEWS_PATH . '/layouts/app.php';