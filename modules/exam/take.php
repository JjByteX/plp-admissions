<?php
// ============================================================
// modules/exam/take.php
// M4 — Student: take the entrance exam
// ============================================================

require_once CORE_PATH . '/bootstrap.php';
Auth::requireRole(ROLE_STUDENT);

$db     = db();
$userId = Auth::id();

$stmt = $db->prepare('SELECT * FROM applicants WHERE user_id=? ORDER BY id DESC LIMIT 1');
$stmt->execute([$userId]);
$applicant = $stmt->fetch();
if (!$applicant) { redirect('/student/dashboard'); }
$applicantId = $applicant['id'];

// Exam only available once docs approved
if ($applicant['overall_status'] !== 'exam') {
    Session::flash('error', 'You are not yet eligible to take the entrance exam.');
    redirect('/student/dashboard');
}

// Check if already submitted
$stmt = $db->prepare('SELECT * FROM exam_results WHERE applicant_id=? LIMIT 1');
$stmt->execute([$applicantId]);
$existing = $stmt->fetch();
if ($existing) {
    redirect('/student/dashboard');
}

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

$errors = [];

// ----------------------------------------------------------------
// POST — submit answers
// ----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $answers = $_POST['answers'] ?? [];
    $score   = 0;

    foreach ($questions as $q) {
        $chosen = isset($answers[$q['id']]) ? (int)$answers[$q['id']] : -1;
        if ($chosen === (int)$q['correct_index']) {
            $score++;
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
        json_encode(array_map('intval', $answers)),
    ]);

    // Advance applicant status
    $db->prepare('UPDATE applicants SET overall_status="interview" WHERE id=?')
       ->execute([$applicantId]);

    Session::flash('success', sprintf(
        'Exam submitted! You scored %d out of %d.',
        $score, count($questions)
    ));
    redirect('/student/dashboard');
}

// ----------------------------------------------------------------
// View
// ----------------------------------------------------------------
ob_start();
?>

<div class="page-header">
    <div>
        <h1 class="page-title"><?= e($exam['title']) ?></h1>
        <p class="page-description">
            <?= count($questions) ?> questions &middot; <?= $exam['duration_minutes'] ?> minutes &middot;
            Answer all questions before submitting.
        </p>
    </div>
</div>

<div class="alert alert-warning" style="margin-bottom:var(--space-6)">
    <svg width="16" height="16" fill="none" viewBox="0 0 24 24"><path stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M12 9v4m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/></svg>
    <span>You can only submit once. Do not close the page until you click <strong>Submit Exam</strong>.</span>
    <?php if ($exam['duration_minutes'] > 0): ?>
        &nbsp;&nbsp;<strong>Time remaining: <span id="timer"><?= str_pad($exam['duration_minutes'],2,'0',STR_PAD_LEFT) ?>:00</span></strong>
    <?php endif; ?>
</div>

<form method="POST" id="exam-form">
    <?= csrf_field() ?>

    <div style="display:flex;flex-direction:column;gap:var(--space-5)">
    <?php foreach ($questions as $i => $q):
        $choices = json_decode($q['choices'], true);
    ?>
        <div class="card" style="padding:var(--space-5)">
            <p style="font-weight:var(--weight-medium);margin-bottom:var(--space-4)">
                <span style="color:var(--text-tertiary);margin-right:var(--space-2)"><?= $i+1 ?>.</span>
                <?= e($q['question_text']) ?>
            </p>
            <div style="display:flex;flex-direction:column;gap:var(--space-3)">
            <?php foreach ($choices as $ci => $choice): ?>
                <label style="display:flex;align-items:center;gap:var(--space-3);cursor:pointer;
                              padding:var(--space-3) var(--space-4);border:1px solid var(--border);
                              border-radius:var(--radius-md);transition:background .15s"
                       class="exam-choice">
                    <input type="radio" name="answers[<?= $q['id'] ?>]" value="<?= $ci ?>"
                           style="accent-color:var(--accent)" required>
                    <span style="font-size:var(--text-sm)"><?= e($choice) ?></span>
                </label>
            <?php endforeach; ?>
            </div>
        </div>
    <?php endforeach; ?>
    </div>

    <div style="margin-top:var(--space-8);display:flex;justify-content:flex-end;gap:var(--space-3)">
        <div style="font-size:var(--text-sm);color:var(--text-tertiary);align-self:center" id="answered-count">
            0 of <?= count($questions) ?> answered
        </div>
        <button type="button" class="btn btn-primary" onclick="confirmSubmit()">Submit Exam</button>
    </div>
</form>

<!-- Confirm modal -->
<div id="confirm-modal" class="modal-backdrop" style="display:none">
    <div class="modal" style="max-width:380px">
        <div class="modal-header"><div class="modal-title">Submit Exam?</div></div>
        <div class="modal-body">
            <p style="color:var(--text-secondary);font-size:var(--text-sm)">
                Once submitted, your answers cannot be changed. Make sure you have answered all questions.
            </p>
        </div>
        <div class="modal-footer">
            <button class="btn btn-ghost" onclick="document.getElementById('confirm-modal').style.display='none'">Go Back</button>
            <button class="btn btn-primary" onclick="document.getElementById('exam-form').submit()">Yes, Submit</button>
        </div>
    </div>
</div>

<script>
function confirmSubmit() {
    document.getElementById('confirm-modal').style.display = 'flex';
}

// Answered count tracker
const form = document.getElementById('exam-form');
function updateCount() {
    const total = <?= count($questions) ?>;
    const answered = new Set(
        [...form.querySelectorAll('input[type=radio]:checked')]
            .map(r => r.name)
    ).size;
    document.getElementById('answered-count').textContent = answered + ' of ' + total + ' answered';
}
form.addEventListener('change', updateCount);

// Highlight chosen option
form.addEventListener('change', function(e) {
    if (e.target.type === 'radio') {
        const siblings = form.querySelectorAll(`input[name="${e.target.name}"]`);
        siblings.forEach(r => r.closest('label').style.background = '');
        e.target.closest('label').style.background = 'var(--accent-bg, rgba(45,106,79,.07))';
    }
});

<?php if ($exam['duration_minutes'] > 0): ?>
// Countdown timer
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
    if (seconds <= 300) timerEl.style.color = 'var(--error)';
}, 1000);
<?php endif; ?>
</script>

<?php
$content     = ob_get_clean();
$pageTitle   = 'Entrance Exam';
$activeNav   = 'exam';
$showStepper = true;
include VIEWS_PATH . '/layouts/app.php';
