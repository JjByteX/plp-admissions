<?php
// ============================================================
// modules/exam/staff_manage.php
// M4 — Staff: create/manage exam, add/edit questions
// ============================================================

require_once CORE_PATH . '/bootstrap.php';
Auth::requireRole(ROLE_STAFF);

$db = db();

$errors  = [];
$success = [];

// ----------------------------------------------------------------
// POST handlers
// ----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    switch ($action) {

        case 'create_exam':
            $title    = trim($_POST['title'] ?? '');
            $duration = (int)($_POST['duration_minutes'] ?? 60);
            if (!$title) { $errors[] = 'Exam title is required.'; break; }
            $db->prepare('UPDATE exams SET is_active=0')->execute(); // deactivate others
            $stmt = $db->prepare('INSERT INTO exams (title, duration_minutes, is_active) VALUES (?,?,1)');
            $stmt->execute([$title, $duration]);
            $success[] = 'Exam created and set as active.';
            break;

        case 'activate_exam':
            $examId = (int)($_POST['exam_id'] ?? 0);
            $db->prepare('UPDATE exams SET is_active=0')->execute();
            $db->prepare('UPDATE exams SET is_active=1 WHERE id=?')->execute([$examId]);
            $success[] = 'Exam activated.';
            break;

        case 'add_question':
            $examId  = (int)($_POST['exam_id'] ?? 0);
            $qText   = trim($_POST['question_text'] ?? '');
            $correct = (int)($_POST['correct_index'] ?? 0);
            $choices = array_filter(array_map('trim', $_POST['choices'] ?? []));
            if (!$qText || count($choices) < 2) {
                $errors[] = 'Question text and at least 2 choices are required.'; break;
            }
            $choices = array_values($choices);
            if ($correct >= count($choices)) { $correct = 0; }
            $maxOrder = $db->prepare('SELECT COALESCE(MAX(sort_order),0) FROM questions WHERE exam_id=?');
            $maxOrder->execute([$examId]);
            $sortOrder = (int)$maxOrder->fetchColumn() + 1;
            $stmt = $db->prepare(
                'INSERT INTO questions (exam_id, question_text, choices, correct_index, sort_order) VALUES (?,?,?,?,?)'
            );
            $stmt->execute([$examId, $qText, json_encode($choices), $correct, $sortOrder]);
            $success[] = 'Question added.';
            break;

        case 'delete_question':
            $qId = (int)($_POST['question_id'] ?? 0);
            $db->prepare('DELETE FROM questions WHERE id=?')->execute([$qId]);
            $success[] = 'Question deleted.';
            break;
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

// Exam result stats
$resultStats = [];
if ($selectedExam) {
    $stmt = $db->prepare(
        'SELECT COUNT(*) as total, AVG(score) as avg_score, MAX(score) as max_score, MIN(score) as min_score
         FROM exam_results WHERE exam_id=?'
    );
    $stmt->execute([$selectedExamId]);
    $resultStats = $stmt->fetch();
}

ob_start();
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Entrance Exam</h1>
        <p class="page-description">Manage exam content and question bank.</p>
    </div>
    <button class="btn btn-primary" onclick="document.getElementById('create-exam-modal').style.display='flex'">
        + New Exam
    </button>
</div>

<?php foreach ($errors as $e): ?>
    <div class="alert alert-error" style="margin-bottom:var(--space-3)"><?= e($e) ?></div>
<?php endforeach; ?>
<?php foreach ($success as $s): ?>
    <div class="alert alert-success" style="margin-bottom:var(--space-3)"><?= e($s) ?></div>
<?php endforeach; ?>

<div style="display:grid;grid-template-columns:240px 1fr;gap:var(--space-6)">

    <!-- Sidebar: exam list -->
    <div>
        <div style="font-size:var(--text-xs);font-weight:var(--weight-semibold);text-transform:uppercase;letter-spacing:.06em;color:var(--text-tertiary);margin-bottom:var(--space-3)">Exams</div>
        <?php if (empty($exams)): ?>
            <p style="font-size:var(--text-sm);color:var(--text-tertiary)">No exams yet.</p>
        <?php else: ?>
            <?php foreach ($exams as $ex): ?>
                <a href="?exam=<?= $ex['id'] ?>"
                   style="display:block;padding:var(--space-3) var(--space-4);border-radius:var(--radius-md);
                          text-decoration:none;margin-bottom:var(--space-1);
                          background:<?= $selectedExamId===$ex['id'] ? 'var(--neutral-100)' : 'transparent' ?>;
                          color:var(--text-primary)">
                    <div style="font-size:var(--text-sm);font-weight:var(--weight-medium)"><?= e($ex['title']) ?></div>
                    <div style="font-size:var(--text-xs);color:var(--text-tertiary)"><?= $ex['duration_minutes'] ?> min</div>
                    <?php if ($ex['is_active']): ?>
                        <span class="badge badge-success" style="margin-top:4px">Active</span>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Main panel -->
    <div>
    <?php if ($selectedExam): ?>

        <!-- Exam header -->
        <div class="card" style="margin-bottom:var(--space-5);padding:var(--space-4) var(--space-5)">
            <div style="display:flex;align-items:center;gap:var(--space-4)">
                <div style="flex:1">
                    <div style="font-weight:var(--weight-semibold);font-size:var(--text-base)"><?= e($selectedExam['title']) ?></div>
                    <div style="font-size:var(--text-sm);color:var(--text-tertiary)"><?= $selectedExam['duration_minutes'] ?> minutes &middot; <?= count($questions) ?> questions</div>
                </div>
                <?php if (!$selectedExam['is_active']): ?>
                    <form method="POST">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="activate_exam">
                        <input type="hidden" name="exam_id" value="<?= $selectedExam['id'] ?>">
                        <button class="btn btn-secondary btn-sm">Set Active</button>
                    </form>
                <?php else: ?>
                    <span class="badge badge-success">Active Exam</span>
                <?php endif; ?>
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

        <!-- Add question button -->
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:var(--space-4)">
            <div style="font-weight:var(--weight-semibold)">Questions</div>
            <button class="btn btn-secondary btn-sm"
                    onclick="document.getElementById('add-q-modal').style.display='flex'">+ Add Question</button>
        </div>

        <!-- Question list -->
        <?php if (empty($questions)): ?>
            <div style="text-align:center;padding:var(--space-10);color:var(--text-tertiary);font-size:var(--text-sm)">
                No questions yet. Add your first question.
            </div>
        <?php else: ?>
            <div style="display:flex;flex-direction:column;gap:var(--space-3)">
            <?php foreach ($questions as $qi => $q):
                $choices = json_decode($q['choices'], true);
            ?>
                <div class="card" style="padding:var(--space-4) var(--space-5)">
                    <div style="display:flex;gap:var(--space-3);align-items:flex-start">
                        <span style="font-size:var(--text-sm);color:var(--text-tertiary);flex-shrink:0;padding-top:2px"><?= $qi+1 ?>.</span>
                        <div style="flex:1">
                            <p style="font-weight:var(--weight-medium);margin-bottom:var(--space-3)"><?= e($q['question_text']) ?></p>
                            <div style="display:flex;flex-direction:column;gap:var(--space-2)">
                            <?php foreach ($choices as $ci => $choice): ?>
                                <div style="display:flex;align-items:center;gap:var(--space-2);font-size:var(--text-sm)">
                                    <?php if ($ci === (int)$q['correct_index']): ?>
                                        <svg width="14" height="14" fill="none" viewBox="0 0 24 24" style="color:var(--success);flex-shrink:0"><path stroke="currentColor" stroke-width="2.5" stroke-linecap="round" d="M5 13l4 4L19 7"/></svg>
                                    <?php else: ?>
                                        <span style="width:14px;height:14px;border:1.5px solid var(--border);border-radius:50%;display:inline-block;flex-shrink:0"></span>
                                    <?php endif; ?>
                                    <span style="color:<?= $ci===(int)$q['correct_index'] ? 'var(--success)' : 'var(--text-secondary)' ?>"><?= e($choice) ?></span>
                                </div>
                            <?php endforeach; ?>
                            </div>
                        </div>
                        <form method="POST" onsubmit="return confirm('Delete this question?')">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete_question">
                            <input type="hidden" name="question_id" value="<?= $q['id'] ?>">
                            <button class="btn-icon" style="color:var(--error)" title="Delete question">
                                <svg width="16" height="16" fill="none" viewBox="0 0 24 24"><path stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M3 6h18m-2 0V20a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a1 1 0 011-1h4a1 1 0 011 1v2"/></svg>
                            </button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- Add question modal -->
        <div id="add-q-modal" class="modal-backdrop" style="display:none">
            <div class="modal" style="max-width:560px">
                <div class="modal-header">
                    <div class="modal-title">Add Question</div>
                    <button class="btn-icon" onclick="document.getElementById('add-q-modal').style.display='none'">
                        <svg width="18" height="18" fill="none" viewBox="0 0 24 24"><path stroke="currentColor" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>
                <form method="POST">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="add_question">
                    <input type="hidden" name="exam_id" value="<?= $selectedExam['id'] ?>">
                    <div class="modal-body" style="display:flex;flex-direction:column;gap:var(--space-4)">
                        <div>
                            <label class="form-label">Question <span style="color:var(--error)">*</span></label>
                            <textarea name="question_text" class="form-control" rows="2" required></textarea>
                        </div>
                        <div>
                            <label class="form-label">Choices <span style="color:var(--error)">*</span></label>
                            <div style="display:flex;flex-direction:column;gap:var(--space-2)" id="choices-list">
                                <?php for ($i=0;$i<4;$i++): ?>
                                    <div style="display:flex;align-items:center;gap:var(--space-2)">
                                        <input type="radio" name="correct_index" value="<?= $i ?>"
                                               <?= $i===0?'checked':'' ?> title="Mark as correct" style="accent-color:var(--accent)">
                                        <input type="text" name="choices[]" class="form-control"
                                               placeholder="Choice <?= chr(65+$i) ?>" <?= $i<2?'required':'' ?>>
                                    </div>
                                <?php endfor; ?>
                            </div>
                            <p style="font-size:var(--text-xs);color:var(--text-tertiary);margin-top:var(--space-2)">Select the radio button next to the correct answer.</p>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-ghost" onclick="document.getElementById('add-q-modal').style.display='none'">Cancel</button>
                        <button type="submit" class="btn btn-primary">Add Question</button>
                    </div>
                </form>
            </div>
        </div>

    <?php else: ?>
        <div style="text-align:center;padding:var(--space-16);color:var(--text-tertiary)">
            <p style="font-size:var(--text-sm)">Select an exam from the list or create a new one.</p>
        </div>
    <?php endif; ?>
    </div>
</div>

<!-- Create exam modal -->
<div id="create-exam-modal" class="modal-backdrop" style="display:none">
    <div class="modal" style="max-width:400px">
        <div class="modal-header">
            <div class="modal-title">New Exam</div>
            <button class="btn-icon" onclick="document.getElementById('create-exam-modal').style.display='none'">
                <svg width="18" height="18" fill="none" viewBox="0 0 24 24"><path stroke="currentColor" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create_exam">
            <div class="modal-body" style="display:flex;flex-direction:column;gap:var(--space-4)">
                <div>
                    <label class="form-label">Exam Title <span style="color:var(--error)">*</span></label>
                    <input type="text" name="title" class="form-control" placeholder="e.g. PLP Admission Test 2025" required>
                </div>
                <div>
                    <label class="form-label">Duration (minutes)</label>
                    <input type="number" name="duration_minutes" class="form-control" value="60" min="1" max="300">
                </div>
                <div class="alert alert-warning" style="font-size:var(--text-sm)">
                    Creating a new exam will deactivate the currently active exam.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-ghost" onclick="document.getElementById('create-exam-modal').style.display='none'">Cancel</button>
                <button type="submit" class="btn btn-primary">Create Exam</button>
            </div>
        </form>
    </div>
</div>

<?php
$content   = ob_get_clean();
$pageTitle = 'Entrance Exam';
$activeNav = 'exam';
include VIEWS_PATH . '/layouts/app.php';
