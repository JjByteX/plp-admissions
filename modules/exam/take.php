<?php
// ============================================================
// modules/exam/take.php
// M4 — Student: take the entrance exam
// Google Forms-style rendering, selection prevention
// ============================================================

require_once CORE_PATH . '/bootstrap.php';
Auth::requireRole(ROLE_STUDENT);

$db     = db();
$userId = Auth::id();

$stmt = $db->prepare('SELECT * FROM applicants WHERE user_id=? ORDER BY id DESC LIMIT 1');
$stmt->execute([$userId]);
$applicant = $stmt->fetch();
if (!$applicant) { redirect('/student/documents'); }
$applicantId = $applicant['id'];

$stepperCurrent = 'exam';
if ($applicant['overall_status'] !== 'exam') {
    Session::flash('error', 'You are not yet eligible to take the entrance exam.');
    redirect('/student/documents');
}

// Already submitted?
$stmt = $db->prepare('SELECT * FROM exam_results WHERE applicant_id=? LIMIT 1');
$stmt->execute([$applicantId]);
$existing = $stmt->fetch();
if ($existing) { redirect('/student/interview'); }

// Fetch active exam
$exam = $db->query('SELECT * FROM exams WHERE is_active=1 LIMIT 1')->fetch();
if (!$exam) {
    ob_start();
    echo '<div class="alert alert-warning">No active entrance exam has been set up yet. Please check back later.</div>';
    $content = ob_get_clean(); $pageTitle='Entrance Exam'; $activeNav='exam'; $showStepper=true;
    include VIEWS_PATH . '/layouts/app.php';
    return;
}
$examId = $exam['id'];

// Fetch questions
$stmt = $db->prepare('SELECT * FROM questions WHERE exam_id=? ORDER BY sort_order, id');
$stmt->execute([$examId]);
$questions = $stmt->fetchAll();

// Fetch sections
$stmt = $db->prepare('SELECT * FROM exam_sections WHERE exam_id=? ORDER BY sort_order, id');
$stmt->execute([$examId]);
$sections = $stmt->fetchAll();

// Group questions by section
$questionsBySection = [];
foreach ($sections as $sec) $questionsBySection[$sec['id']] = [];
$unsectioned = [];
foreach ($questions as $q) {
    $sid = (int)$q['section_id'];
    if (isset($questionsBySection[$sid])) $questionsBySection[$sid][] = $q;
    else $unsectioned[] = $q;
}

// Shuffle if configured
if ($exam['shuffle_questions']) {
    foreach ($questionsBySection as &$secQs) shuffle($secQs);
    shuffle($unsectioned);
}

$errors = [];
$totalPoints = array_sum(array_column($questions, 'points'));

// ----------------------------------------------------------------
// POST — submit answers
// ----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $answers   = $_POST['answers'] ?? [];
    $score     = 0;
    $savedAnswers = [];

    foreach ($questions as $q) {
        $qId   = $q['id'];
        $qType = $q['question_type'] ?? 'multiple_choice';

        switch ($qType) {
            case 'multiple_choice':
            case 'dropdown':
                $chosen = isset($answers[$qId]) ? (int)$answers[$qId] : -1;
                $savedAnswers[$qId] = $chosen;
                if ($chosen === (int)$q['correct_index']) {
                    $score += (int)$q['points'];
                }
                break;

            case 'checkboxes':
                $chosen = isset($answers[$qId]) && is_array($answers[$qId])
                    ? array_map('intval', $answers[$qId]) : [];
                sort($chosen);
                $savedAnswers[$qId] = $chosen;
                $correctIndices = $q['correct_answer'] ? json_decode($q['correct_answer'], true) : [];
                sort($correctIndices);
                if (!empty($correctIndices) && $chosen === $correctIndices) {
                    $score += (int)$q['points'];
                } elseif (empty($correctIndices) && !empty($chosen)) {
                    // No correct answer set — give credit for any answer
                    $score += (int)$q['points'];
                }
                break;

            case 'short_answer':
                $text = trim($answers[$qId] ?? '');
                $savedAnswers[$qId] = $text;
                if ($q['correct_answer'] !== null && $q['correct_answer'] !== '') {
                    if (mb_strtolower($text) === mb_strtolower(trim($q['correct_answer']))) {
                        $score += (int)$q['points'];
                    }
                }
                // If no expected answer, score is 0 (manual grading required)
                break;

            case 'paragraph':
                $savedAnswers[$qId] = trim($answers[$qId] ?? '');
                // Always 0 — manual grading
                break;

            case 'linear_scale':
                $chosen = isset($answers[$qId]) ? (int)$answers[$qId] : -1;
                $savedAnswers[$qId] = $chosen;
                // Give credit for any selection
                if ($chosen >= $q['scale_min'] && $chosen <= $q['scale_max']) {
                    $score += (int)$q['points'];
                }
                break;
        }
    }

    $stmt = $db->prepare(
        'INSERT INTO exam_results (applicant_id, exam_id, score, total_items, answers)
         VALUES (?,?,?,?,?)'
    );
    $stmt->execute([
        $applicantId,
        $examId,
        $score,
        count($questions),
        json_encode($savedAnswers),
    ]);

    $db->prepare('UPDATE applicants SET overall_status="interview" WHERE id=?')
       ->execute([$applicantId]);

    Session::flash('success', sprintf(
        'Exam submitted! You answered all questions.',
        $score, count($questions)
    ));
    redirect('/student/interview');
}

// ----------------------------------------------------------------
// View
// ----------------------------------------------------------------
ob_start();
?>

<style>
/* ── Prevent text selection on exam content ──────────────── */
#exam-content,
#exam-content * {
    -webkit-user-select: none;
    -moz-user-select: none;
    -ms-user-select: none;
    user-select: none;
    -webkit-touch-callout: none;
}

/* Allow selection only inside text inputs/textareas for typing */
#exam-content input[type="text"],
#exam-content input[type="number"],
#exam-content textarea {
    -webkit-user-select: text;
    -moz-user-select: text;
    -ms-user-select: text;
    user-select: text;
}

/* ── Question cards ──────────────────────────────────────── */
.q-card {
    padding: var(--space-6);
    transition: border-color .15s;
}

/* Section divider in student exam view */
.exam-section-header {
    display: flex; flex-direction: column; gap: 2px;
    padding: var(--space-3) var(--space-5);
    border-radius: var(--radius-md);
    border: 1.5px solid transparent;
    margin-bottom: var(--space-1);
}
.exam-section-title {
    font-weight: var(--weight-semibold);
    font-size: var(--text-sm);
}
.exam-section-desc {
    font-size: var(--text-xs);
    opacity: .8;
    margin-top: 2px;
}
.q-number {
    font-size: var(--text-xs);
    font-weight: var(--weight-semibold);
    color: var(--text-tertiary);
    text-transform: uppercase;
    letter-spacing: .04em;
    margin-bottom: var(--space-2);
}
.q-text {
    font-weight: var(--weight-medium);
    font-size: var(--text-base);
    margin-bottom: var(--space-2);
    line-height: 1.5;
}
.q-desc {
    font-size: var(--text-sm);
    color: var(--text-secondary);
    margin-bottom: var(--space-4);
}
.q-required {
    color: var(--error);
    margin-left: 2px;
}

/* ── Choice labels ───────────────────────────────────────── */
.choice-label {
    display: flex;
    align-items: center;
    gap: var(--space-3);
    padding: var(--space-3) var(--space-4);
    border: 1.5px solid var(--border);
    border-radius: var(--radius-md);
    cursor: pointer;
    transition: background .12s, border-color .12s, box-shadow .12s;
    margin-bottom: var(--space-2);
    background: var(--bg-elevated);
    user-select: none;
}
.choice-label:hover {
    border-color: var(--accent);
    background: var(--accent-muted);
}
.choice-label.chosen {
    background: var(--accent-muted);
    border-color: var(--accent);
    box-shadow: 0 0 0 3px rgba(45,106,79,.08);
}
.choice-label input { accent-color: var(--accent); flex-shrink: 0; width: 16px; height: 16px; }
.choice-label span { font-size: var(--text-sm); line-height: var(--leading-normal); }

/* ── Dropdown ────────────────────────────────────────────── */
.exam-select {
    max-width: 320px;
}

/* ── Short answer ────────────────────────────────────────── */
.short-answer-input {
    max-width: 440px;
    width: 100%;
    padding: 10px var(--space-4);
    border: 1.5px solid var(--border);
    border-radius: var(--radius-md);
    background: var(--bg-elevated);
    font-family: var(--font-sans);
    font-size: var(--text-sm);
    color: var(--text-primary);
    outline: none;
    transition: border-color .15s, box-shadow .15s;
    min-height: 42px;
}
.short-answer-input::placeholder { color: var(--text-placeholder); }
.short-answer-input:hover:not(:focus) { border-color: var(--border-strong); }
.short-answer-input:focus {
    border-color: var(--accent);
    box-shadow: 0 0 0 3px rgba(45,106,79,.10);
}

/* ── Paragraph ───────────────────────────────────────────── */
.paragraph-input {
    width: 100%;
    padding: 10px var(--space-4);
    border: 1.5px solid var(--border);
    border-radius: var(--radius-md);
    background: var(--bg-elevated);
    font-family: var(--font-sans);
    font-size: var(--text-sm);
    color: var(--text-primary);
    outline: none;
    resize: vertical;
    min-height: 100px;
    line-height: var(--leading-relaxed);
    transition: border-color .15s, box-shadow .15s;
}
.paragraph-input::placeholder { color: var(--text-placeholder); }
.paragraph-input:hover:not(:focus) { border-color: var(--border-strong); }
.paragraph-input:focus {
    border-color: var(--accent);
    box-shadow: 0 0 0 3px rgba(45,106,79,.10);
}

/* ── Linear scale ────────────────────────────────────────── */
.scale-row {
    display: flex;
    align-items: center;
    gap: var(--space-2);
    flex-wrap: wrap;
}
.scale-label {
    font-size: var(--text-xs);
    color: var(--text-tertiary);
    white-space: nowrap;
}
.scale-option {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 4px;
    cursor: pointer;
}
.scale-option input { display: none; }
.scale-bubble {
    width: 40px; height: 40px;
    border: 2px solid var(--border);
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: var(--text-sm);
    font-weight: var(--weight-medium);
    transition: background .12s, border-color .12s, color .12s;
    cursor: pointer;
}
.scale-option:hover .scale-bubble {
    border-color: var(--accent);
    background: var(--accent-bg, rgba(45,106,79,.07));
}
.scale-option input:checked ~ .scale-bubble {
    background: var(--accent);
    border-color: var(--accent);
    color: #fff;
}

/* ── Progress bar ────────────────────────────────────────── */
.exam-progress {
    position: sticky;
    top: 0;
    z-index: 10;
    background: var(--surface);
    border-bottom: 1px solid var(--border);
    padding: var(--space-3) var(--space-5);
    display: flex;
    align-items: center;
    gap: var(--space-4);
    margin: 0 calc(-1 * var(--space-6)) var(--space-6);
}
.progress-bar-wrap {
    flex: 1;
    height: 6px;
    background: var(--neutral-200, #e5e7eb);
    border-radius: 99px;
    overflow: hidden;
}
.progress-bar-fill {
    height: 100%;
    background: var(--accent);
    border-radius: 99px;
    transition: width .3s;
    width: 0%;
}

/* ── Timer ───────────────────────────────────────────────── */
.timer-chip {
    display: inline-flex; align-items: center; gap: 6px;
    font-size: var(--text-sm); font-weight: var(--weight-semibold);
    padding: 4px 12px;
    background: var(--neutral-100);
    border-radius: 99px;
    font-variant-numeric: tabular-nums;
}
.timer-chip.warning { background: rgba(220,38,38,.08); color: var(--error); }
</style>

<div class="page-header">
    <div>
        <h1 class="page-title"><?= e($exam['title']) ?></h1>
        <?php if ($exam['description']): ?>
            <p class="page-description"><?= e($exam['description']) ?></p>
        <?php else: ?>
            <p class="page-description">
                <?= count($questions) ?> questions &middot; <?= $exam['duration_minutes'] ?> minutes &middot;
                Answer all required questions before submitting.
            </p>
        <?php endif; ?>
    </div>
</div>

<div class="alert alert-warning" style="margin-bottom:var(--space-6)">
    <?= icon('ic_fluent_warning_24_regular', 16) ?>
    <span>You can only submit <strong>once</strong>. Do not close this page until you click <strong>Submit Exam</strong>.</span>
    <?php if ($exam['duration_minutes'] > 0): ?>
        &nbsp;&nbsp;Time remaining: <span id="timer" class="timer-chip"><?= str_pad($exam['duration_minutes'],2,'0',STR_PAD_LEFT) ?>:00</span>
    <?php endif; ?>
</div>

<form method="POST" id="exam-form">
    <?= csrf_field() ?>

    <!-- Sticky progress bar -->
    <div class="exam-progress" id="exam-progress">
        <span style="font-size:var(--text-xs);color:var(--text-tertiary);white-space:nowrap" id="answered-label">0 / <?= count($questions) ?> answered</span>
        <div class="progress-bar-wrap">
            <div class="progress-bar-fill" id="progress-fill"></div>
        </div>
    </div>

    <div id="exam-content" style="display:flex;flex-direction:column;gap:var(--space-4)">
    <?php
    // Build shuffle index for choices if needed
    $shuffleChoices = (bool)$exam['shuffle_choices'];

    $SECTION_COLORS = [
        'multiple_choice' => ['bg' => '#dbeafe', 'text' => '#1d4ed8', 'border' => '#93c5fd'],
        'checkboxes'      => ['bg' => '#d1fae5', 'text' => '#065f46', 'border' => '#6ee7b7'],
        'dropdown'        => ['bg' => '#ede9fe', 'text' => '#5b21b6', 'border' => '#c4b5fd'],
        'short_answer'    => ['bg' => '#fef3c7', 'text' => '#92400e', 'border' => '#fcd34d'],
        'paragraph'       => ['bg' => '#fce7f3', 'text' => '#9d174d', 'border' => '#f9a8d4'],
        'linear_scale'    => ['bg' => '#f3f4f6', 'text' => '#374151', 'border' => '#d1d5db'],
    ];

    $globalI = 0;

    // Helper: render a single question card
    function renderStudentQuestion($q, &$globalI, $shuffleChoices) {
        $qType   = $q['question_type'] ?? 'multiple_choice';
        $choices = $q['choices'] ? json_decode($q['choices'], true) : [];
        $required = (bool)$q['is_required'];

        // Shuffle choices while preserving correct index mapping
        $choiceMap = range(0, count($choices)-1);
        if ($shuffleChoices && in_array($qType, ['multiple_choice','checkboxes','dropdown'])) {
            shuffle($choiceMap);
        }
        $globalI++;
        $totalQs = $GLOBALS['_exam_total_q'];
    ?>
        <div class="card q-card" id="qcard-<?= $q['id'] ?>" data-qid="<?= $q['id'] ?>" data-type="<?= $qType ?>">
            <div class="q-number">
                Question <?= $globalI ?> of <?= $totalQs ?>
                <?php if ($required): ?><span class="q-required">*</span><?php endif; ?>
                <?php if ($q['points'] > 0): ?>
                    &middot; <?= $q['points'] ?> pt<?= $q['points']!=1?'s':'' ?>
                <?php endif; ?>
            </div>
            <div class="q-text"><?= e($q['question_text']) ?></div>
            <?php if ($q['description']): ?>
                <div class="q-desc"><?= e($q['description']) ?></div>
            <?php endif; ?>

            <!-- ── MULTIPLE CHOICE ── -->
            <?php if ($qType === 'multiple_choice'): ?>
                <div>
                <?php foreach ($choiceMap as $ci):
                    $choice = $choices[$ci]; ?>
                    <label class="choice-label" id="cl-<?= $q['id'] ?>-<?= $ci ?>">
                        <input type="radio" name="answers[<?= $q['id'] ?>]" value="<?= $ci ?>"
                               <?= $required ? 'required' : '' ?>
                               onchange="markChosenRadio(this)">
                        <span><?= e($choice) ?></span>
                    </label>
                <?php endforeach; ?>
                </div>

            <!-- ── CHECKBOXES ── -->
            <?php elseif ($qType === 'checkboxes'): ?>
                <div>
                <?php foreach ($choiceMap as $ci):
                    $choice = $choices[$ci]; ?>
                    <label class="choice-label" id="cl-<?= $q['id'] ?>-<?= $ci ?>">
                        <input type="checkbox" name="answers[<?= $q['id'] ?>][]" value="<?= $ci ?>"
                               onchange="markChosenCheckbox(this)">
                        <span><?= e($choice) ?></span>
                    </label>
                <?php endforeach; ?>
                </div>

            <!-- ── DROPDOWN ── -->
            <?php elseif ($qType === 'dropdown'): ?>
                <select name="answers[<?= $q['id'] ?>]" class="form-select exam-select"
                        <?= $required ? 'required' : '' ?>
                        onchange="markAnswered(<?= $q['id'] ?>)">
                    <option value="">— Select an answer —</option>
                    <?php foreach ($choiceMap as $ci):
                        $choice = $choices[$ci]; ?>
                        <option value="<?= $ci ?>"><?= e($choice) ?></option>
                    <?php endforeach; ?>
                </select>

            <!-- ── SHORT ANSWER ── -->
            <?php elseif ($qType === 'short_answer'): ?>
                <input type="text"
                       name="answers[<?= $q['id'] ?>]"
                       class="short-answer-input"
                       placeholder="Your answer"
                       <?= $required ? 'required' : '' ?>
                       oninput="markAnswered(<?= $q['id'] ?>)">

            <!-- ── PARAGRAPH ── -->
            <?php elseif ($qType === 'paragraph'): ?>
                <textarea name="answers[<?= $q['id'] ?>]"
                          class="paragraph-input"
                          placeholder="Your answer"
                          rows="3"
                          <?= $required ? 'required' : '' ?>
                          oninput="markAnswered(<?= $q['id'] ?>)"></textarea>

            <!-- ── LINEAR SCALE ── -->
            <?php elseif ($qType === 'linear_scale'):
                $sMin = (int)($q['scale_min'] ?? 1);
                $sMax = (int)($q['scale_max'] ?? 5);
            ?>
                <div class="scale-row">
                    <?php if ($q['scale_min_label']): ?>
                        <span class="scale-label"><?= e($q['scale_min_label']) ?></span>
                    <?php endif; ?>
                    <?php for ($s = $sMin; $s <= $sMax; $s++): ?>
                        <label class="scale-option">
                            <input type="radio" name="answers[<?= $q['id'] ?>]" value="<?= $s ?>"
                                   <?= $required ? 'required' : '' ?>
                                   onchange="markScaleChosen(this, <?= $q['id'] ?>)">
                            <div class="scale-bubble"><?= $s ?></div>
                        </label>
                    <?php endfor; ?>
                    <?php if ($q['scale_max_label']): ?>
                        <span class="scale-label"><?= e($q['scale_max_label']) ?></span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    <?php } // end renderStudentQuestion

    $GLOBALS['_exam_total_q'] = count($questions);

    foreach ($sections as $sec):
        $secQs = $questionsBySection[$sec['id']] ?? [];
        if (empty($secQs)) continue;
        $sc = $SECTION_COLORS[$sec['question_type']] ?? $SECTION_COLORS['multiple_choice'];
    ?>
        <div class="exam-section-header" style="background:<?= $sc['bg'] ?>;border-color:<?= $sc['border'] ?>">
            <div class="exam-section-title" style="color:<?= $sc['text'] ?>"><?= e($sec['title']) ?></div>
            <?php if (!empty($sec['description'])): ?>
                <div class="exam-section-desc" style="color:<?= $sc['text'] ?>"><?= e($sec['description']) ?></div>
            <?php endif; ?>
        </div>
        <?php foreach ($secQs as $q): renderStudentQuestion($q, $globalI, $shuffleChoices); endforeach; ?>
    <?php endforeach;

    // Unsectioned questions (fallback)
    foreach ($unsectioned as $q): renderStudentQuestion($q, $globalI, $shuffleChoices); endforeach;
    ?>
    </div>

    <div style="margin-top:var(--space-8);display:flex;justify-content:space-between;align-items:center">
        <div style="font-size:var(--text-sm);color:var(--text-tertiary)" id="answered-count">
            0 of <?= count($questions) ?> answered
        </div>
        <button type="button" class="btn btn-primary" onclick="confirmSubmit()">Submit Exam</button>
    </div>
</form>

<!-- Confirm modal -->
<div id="confirm-modal" class="modal-backdrop" style="display:none">
    <div class="modal" style="max-width:400px">
        <div class="modal-header"><div class="modal-title">Submit Exam?</div></div>
        <div class="modal-body">
            <p style="color:var(--text-secondary);font-size:var(--text-sm)" id="confirm-message">
                Once submitted, your answers cannot be changed.
            </p>
        </div>
        <div class="modal-footer">
            <button class="btn btn-ghost" onclick="document.getElementById('confirm-modal').style.display='none'">Go Back</button>
            <button class="btn btn-primary" onclick="document.getElementById('exam-form').submit()">Yes, Submit</button>
        </div>
    </div>
</div>

<script>
// ── Context menu & drag prevention on exam content ──────────
const examContent = document.getElementById('exam-content');
if (examContent) {
    examContent.addEventListener('contextmenu', e => e.preventDefault());
    examContent.addEventListener('dragstart',   e => e.preventDefault());
    // Block Ctrl+A / Cmd+A on the exam area
    examContent.addEventListener('keydown', e => {
        if ((e.ctrlKey || e.metaKey) && e.key === 'a') e.preventDefault();
    });
}

// ── Answer tracking ──────────────────────────────────────────
const TOTAL = <?= count($questions) ?>;
const answeredSet = new Set();

function updateProgress() {
    const n = answeredSet.size;
    document.getElementById('answered-count').textContent = n + ' of ' + TOTAL + ' answered';
    document.getElementById('answered-label').textContent  = n + ' / ' + TOTAL + ' answered';
    document.getElementById('progress-fill').style.width   = (TOTAL ? (n/TOTAL*100) : 0) + '%';
}

function markAnswered(qid) {
    // Check if actually has a value
    const form = document.getElementById('exam-form');
    const inputs = form.querySelectorAll(`[name="answers[${qid}]"], [name="answers[${qid}][]"]`);
    let hasAnswer = false;
    inputs.forEach(inp => {
        if (inp.type === 'radio' || inp.type === 'checkbox') {
            if (inp.checked) hasAnswer = true;
        } else if (inp.value.trim()) hasAnswer = true;
    });
    if (hasAnswer) answeredSet.add(qid);
    else answeredSet.delete(qid);

    updateProgress();
}

// Radio — single choice or dropdown
function markChosenRadio(input) {
    const name = input.name;
    document.querySelectorAll(`input[name="${CSS.escape(name)}"]`).forEach(r => {
        const label = r.closest('label');
        if (label) label.classList.toggle('chosen', r.checked);
    });
    // Extract qid from name like answers[123]
    const qid = parseInt(name.match(/\[(\d+)\]/)[1]);
    markAnswered(qid);
}

// Checkbox — multi select
function markChosenCheckbox(input) {
    const label = input.closest('label');
    if (label) label.classList.toggle('chosen', input.checked);
    const qid = parseInt(input.name.match(/\[(\d+)\]/)[1]);
    markAnswered(qid);
}

// Linear scale — toggle bubble
function markScaleChosen(input, qid) {
    // Uncheck visual on siblings first
    document.querySelectorAll(`input[name="answers[${qid}]"]`).forEach(r => {
        const bubble = r.parentElement?.querySelector('.scale-bubble');
        if (bubble) bubble.classList.toggle('chosen-scale', r.checked);
    });
    markAnswered(qid);
}

// Dropdown/text change handler — already calls markAnswered directly

// ── Submit confirm ───────────────────────────────────────────
function confirmSubmit() {
    const unanswered = TOTAL - answeredSet.size;
    const msg = unanswered > 0
        ? `You have ${unanswered} unanswered question${unanswered>1?'s':''}.  Once submitted, your answers cannot be changed.`
        : 'Once submitted, your answers cannot be changed. Make sure all answers are correct.';
    document.getElementById('confirm-message').textContent = msg;
    document.getElementById('confirm-modal').style.display = 'flex';
}

// ── Countdown timer ──────────────────────────────────────────
<?php if ($exam['duration_minutes'] > 0): ?>
let seconds = <?= $exam['duration_minutes'] ?> * 60;
const timerEl = document.getElementById('timer');
const interval = setInterval(() => {
    seconds--;
    if (seconds <= 0) {
        clearInterval(interval);
        document.getElementById('exam-form').submit();
        return;
    }
    const m = String(Math.floor(seconds/60)).padStart(2,'0');
    const s = String(seconds%60).padStart(2,'0');
    timerEl.textContent = m + ':' + s;
    if (seconds <= 300) timerEl.classList.add('warning');
}, 1000);
<?php endif; ?>

// ── Warn before page leave ───────────────────────────────────
let submitted = false;
document.getElementById('exam-form').addEventListener('submit', () => { submitted = true; });
window.addEventListener('beforeunload', e => {
    if (!submitted) {
        e.preventDefault();
        e.returnValue = '';
    }
});
</script>

<!-- Step navigation -->
<div class="step-nav" style="margin-top:var(--space-4)">
    <a href="<?= url('/student/documents') ?>" class="btn btn-ghost">← Documents</a>
    <span></span>
</div>

<?php
$content     = ob_get_clean();
$pageTitle   = 'Entrance Exam';
$activeNav   = 'exam';
$showStepper = true;
include VIEWS_PATH . '/layouts/app.php';