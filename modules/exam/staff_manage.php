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

// buildExamTitle(), exam_in_progress() and exam_has_results() live in core/exam.php.

if (($action = require_post_action()) !== null) {

    // ── Exam-in-use guard ────────────────────────────────────
    // Locked (has submitted results): no adding or deleting questions, sections
    // or the exam; edit_question only changes wording. Scores are stored when a
    // student submits, so this keeps the exam matching those scores.
    // Live (a student is taking it): allowed, but staff must confirm first.
    $examLocked = false;
    $guardMsg   = null;
    $needsConfirm = false;
    if (in_array($action, ['create_exam', 'activate_exam', 'delete_exam', 'delete_section',
                           'add_question', 'edit_question', 'delete_question'], true)) {
        $targetExamId = post_int('exam_id');
        if ($action === 'delete_question') {
            $st = $db->prepare('SELECT exam_id FROM questions WHERE id=?');
            $st->execute([post_int('question_id')]);
            $targetExamId = (int)$st->fetchColumn();
        } elseif ($action === 'delete_section') {
            $st = $db->prepare('SELECT exam_id FROM exam_sections WHERE id=?');
            $st->execute([post_int('section_id')]);
            $targetExamId = (int)$st->fetchColumn();
        }
        // Creating or activating an exam switches students off the current active exam.
        $liveExamId = $targetExamId;
        if ($action === 'create_exam' || $action === 'activate_exam') {
            $liveExamId = (int)$db->query('SELECT id FROM exams WHERE is_active=1 LIMIT 1')->fetchColumn();
            if ($action === 'activate_exam' && $liveExamId === $targetExamId) $liveExamId = 0;
        }

        $examLocked = in_array($action, ['delete_exam', 'delete_section', 'add_question', 'delete_question', 'edit_question'], true)
                      && exam_has_results($db, $targetExamId);
        $live = exam_in_progress($db, $liveExamId);

        if ($examLocked && $action !== 'edit_question') {
            $guardMsg = 'This exam already has submitted results, so questions, sections and the exam itself can no longer be added or deleted. Scores already recorded stay as they are. To change the content, create a new exam.';
        } elseif ($live && $action === 'delete_exam') {
            $guardMsg = 'Students are taking this exam right now, so it cannot be deleted.';
        } elseif ($live && empty($_POST['confirm_live'])) {
            $guardMsg = 'Students are taking the exam right now. This change applies to them immediately and can change their score. Confirm to continue.';
            $needsConfirm = true;
        }
    }
    if ($guardMsg !== null) {
        $isFetch = is_ajax_request() || (($_SERVER['HTTP_SEC_FETCH_DEST'] ?? '') === 'empty');
        if ($isFetch) {
            http_response_code(409);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'blocked' => true, 'needs_confirm' => $needsConfirm, 'error' => $guardMsg]);
            exit;
        }
        $errors[] = $guardMsg;
        $action = '';                       // skip the switch; the page re-renders with the message
    }

    switch ($action) {

        // ── Exam CRUD ────────────────────────────────────────────
        // Schedule and access code live on each room slot
        // (exam_slot_schedule); the exam itself is content-only
        // (description + shuffle flags). The title is auto-derived
        // server-side from the active school year.
        case 'create_exam':
            $description    = post_str('description');
            $shuffleQ       = isset($_POST['shuffle_questions']) ? 1 : 0;
            $shuffleC       = isset($_POST['shuffle_choices'])   ? 1 : 0;
            $title          = buildExamTitle();
            $db->prepare('UPDATE exams SET is_active=0')->execute();
            $insExam = $db->prepare(
                'INSERT INTO exams (title, description, shuffle_questions, shuffle_choices, is_active)
                 VALUES (?,?,?,?,1)
                 RETURNING id'
            );
            $insExam->execute([$title, $description ?: null, $shuffleQ, $shuffleC]);
            $newExamId = (int)$insExam->fetchColumn();
            $success[] = 'Exam created and set as active.';
            audit_log('exam_created', "Created exam: {$title}", 'exam', $newExamId);
            // Redirect to this exam's explicit URL so the page always has ?exam=ID
            header('Location: ' . url('/staff/exam') . '?exam=' . $newExamId . '&created=1');
            exit;

        case 'edit_exam':
            $examId      = post_int('exam_id');
            $description = post_str('description');
            $shuffleQ    = isset($_POST['shuffle_questions']) ? 1 : 0;
            $shuffleC    = isset($_POST['shuffle_choices'])   ? 1 : 0;
            $title       = buildExamTitle();
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
            $examId = post_int('exam_id');
            $db->prepare('UPDATE exams SET is_active=0')->execute();
            $db->prepare('UPDATE exams SET is_active=1 WHERE id=?')->execute([$examId]);
            $success[] = 'Exam activated.';
            audit_log('exam_activated', "Activated exam ID {$examId}", 'exam', $examId);
            break;

        case 'delete_exam':
            $examId = post_int('exam_id');
            if (!$examId) { $errors[] = 'Invalid exam.'; break; }
            // CASCADE constraints on exam_sections, questions, and exam_results
            // handle child rows automatically — just delete the parent.
            $db->prepare('DELETE FROM exams WHERE id=?')->execute([$examId]);
            audit_log('exam_deleted', "Deleted exam ID {$examId}", 'exam', $examId);
            header('Location: ' . url('/staff/exam'));
            exit;

        // ── Section CRUD ─────────────────────────────────────────
        case 'create_section':
            $examId    = post_int('exam_id');
            $secTitle  = post_str('section_title');
            $secType   = $_POST['section_type'] ?? 'multiple_choice';
            $secDesc   = post_str('section_desc');
            if (!$secTitle) { $errors[] = 'Section title is required.'; break; }
            if (!array_key_exists($secType, $QUESTION_TYPES)) $secType = 'multiple_choice';
            $maxOrd = $db->prepare('SELECT COALESCE(MAX(sort_order),0) FROM exam_sections WHERE exam_id=?');
            $maxOrd->execute([$examId]);
            $insSec = $db->prepare(
                'INSERT INTO exam_sections (exam_id, title, description, question_type, sort_order) VALUES (?,?,?,?,?) RETURNING id'
            );
            $insSec->execute([$examId, $secTitle, $secDesc ?: null, $secType, (int)$maxOrd->fetchColumn() + 1]);
            $newSecId = (int)$insSec->fetchColumn();
            if (is_ajax_request()) {
                header('Content-Type: application/json');
                echo json_encode(['ok' => true, 'section_id' => $newSecId]);
                exit;
            }
            $success[] = 'Section added.';
            break;

        case 'edit_section':
            $secId    = post_int('section_id');
            $secTitle = post_str('section_title');
            $secType  = $_POST['section_type'] ?? 'multiple_choice';
            $secDesc  = post_str('section_desc');
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
            $secId = post_int('section_id');
            // Delete all questions in section first, then the section itself
            $db->prepare('DELETE FROM questions WHERE section_id=?')->execute([$secId]);
            $db->prepare('DELETE FROM exam_sections WHERE id=?')->execute([$secId]);
            $success[] = 'Section deleted.';
            break;

        // ── Question CRUD ─────────────────────────────────────────
        case 'add_question':
        case 'edit_question':
            $isEdit   = ($action === 'edit_question');
            $examId   = post_int('exam_id');
            $qId      = post_int('question_id');
            $sectionId= $_POST['section_id'] !== '' ? (int)$_POST['section_id'] : null;
            $qText    = post_str('question_text');
            $qDesc    = post_str('question_desc');
            $qType    = $_POST['question_type'] ?? 'multiple_choice';
            $points   = max(0, post_int('points', 1));
            $required = isset($_POST['is_required']) ? 1 : 0;

            if (!$qText) { $errors[] = 'Question text is required.'; break; }
            if (!$sectionId) { $errors[] = 'Every question must belong to a section.'; break; }
            if (!array_key_exists($qType, $QUESTION_TYPES)) $qType = 'multiple_choice';

            if ($isEdit && $qId && $examLocked) {
                // Results exist: only the wording may change. Type, points, choices and the answer key stay as they are.
                $db->prepare('UPDATE questions SET question_text=?, description=? WHERE id=? AND exam_id=?')
                   ->execute([$qText, $qDesc ?: null, $qId, $examId]);
                $success[] = 'Wording updated. Points, choices and the answer key are locked because this exam already has submitted results.';
                break;
            }

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
                    $ci = post_int('correct_index');
                    $correctIndex = ($ci >= 0 && $ci < count($rawChoices)) ? $ci : 0;
                }
            } elseif ($qType === 'linear_scale') {
                $scaleMin = max(0, min(1, post_int('scale_min', 1)));
                $scaleMax = max(2, min(10, post_int('scale_max', 5)));
                $scaleMinLabel = post_str('scale_min_label') ?: null;
                $scaleMaxLabel = post_str('scale_max_label') ?: null;
            } elseif ($qType === 'short_answer') {
                $correctAnswer = post_str('expected_answer') ?: null;
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
                $insQ = $db->prepare(
                    'INSERT INTO questions (exam_id, question_text, question_type, description, points,
                     is_required, choices, correct_index, correct_answer, scale_min, scale_max,
                     scale_min_label, scale_max_label, section_id, sort_order)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                     RETURNING id'
                );
                $insQ->execute([
                    $examId, $qText, $qType, $qDesc ?: null, $points, $required,
                    $choices, $correctIndex, $correctAnswer,
                    $scaleMin, $scaleMax, $scaleMinLabel, $scaleMaxLabel,
                    $sectionId, (int)$maxOrder->fetchColumn() + 1
                ]);
                if (is_ajax_request()) {
                    header('Content-Type: application/json');
                    echo json_encode(['ok' => true, 'id' => (int)$insQ->fetchColumn()]);
                    exit;
                }
                $success[] = 'Question added.';
            }
            break;

        case 'delete_question':
            $qId = post_int('question_id');
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
            $qId   = post_int('question_id');
            $qText = post_str('question_text');
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
// Question count per exam, for the cards in the exam directory.
$examMeta = [];
foreach ($db->query('SELECT exam_id, COUNT(*) AS n FROM questions GROUP BY exam_id')->fetchAll() as $r) {
    $examMeta[(int)$r['exam_id']] = ['question_count' => (int)$r['n']];
}
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

// Flags for the page: confirm prompt while students are taking the exam,
// notice once results exist.
$examLive   = exam_in_progress($db, (int)($selectedExam['id'] ?? 0))
           || exam_in_progress($db, (int)($activeExam['id'] ?? 0));
$examFrozen = $selectedExam && exam_has_results($db, (int)$selectedExam['id']);

// Group questions by section (every question must belong to one)
$questionsBySection = [];
foreach ($sections as $sec) $questionsBySection[$sec['id']] = [];
foreach ($questions as $q) {
    $sid = (int)$q['section_id'];
    if (isset($questionsBySection[$sid])) {
        $questionsBySection[$sid][] = $q;
    }
}

ob_start();
?>


<?php foreach ($errors as $e): ?>
    <div class="alert alert-error" style="margin-bottom:var(--space-3)"><?= e($e) ?></div>
<?php endforeach; ?>
<?php if (!empty($examFrozen)): ?>
    <div class="alert alert-warning" style="margin-bottom:var(--space-3)">This exam already has submitted results. You can still fix wording, but questions, choices, points and answer keys are locked so recorded scores stay accurate. To change the content, create a new exam.</div>
<?php endif; ?>
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
   LANDING - two big entry-point cards
════════════════════════════════════════════════════ */ ?>
<?php if ($view === 'landing'): ?>

<div class="select-grid">

    <!-- Exam Builder — always enabled -->
    <a href="<?= url('/staff/exam') ?>?view=exams" class="select-card">
        <div class="select-card-title">Exam Builder</div>
        <div class="select-card-desc">Create exams, set questions, and manage schedules.</div>
        <div class="select-card-meta">
            <div class="select-card-meta-row">
                <?= count($exams) ?> exam<?= count($exams) !== 1 ? 's' : '' ?> created
                <?php if ($activeExam): ?>
                    &nbsp;&middot;&nbsp;
                    <span style="color:var(--success);font-weight:var(--weight-medium)"><?= e($activeExam['title']) ?> is active</span>
                <?php endif; ?>
            </div>
        </div>
    </a>

    <!-- Room Slots — disabled if no exams set up yet -->
    <?php if (!empty($exams)): ?>
    <a href="<?= url('/staff/exam/slots') ?>" class="select-card">
    <?php else: ?>
    <div class="select-card is-disabled" title="Create an exam first before setting up room slots">
    <?php endif; ?>
        <div class="select-card-title">Room Slots</div>
        <div class="select-card-desc">Assign rooms, manage seating, and organize exam-day logistics.</div>
        <div class="select-card-meta">
            <div class="select-card-meta-row">
                <?php if (empty($exams)): ?>
                    Create an exam first
                <?php else: ?>
                    <?= $slotCount ?> slot<?= $slotCount !== 1 ? 's' : '' ?> configured
                <?php endif; ?>
            </div>
        </div>
    <?php if (!empty($exams)): ?>
    </a>
    <?php else: ?>
    </div>
    <?php endif; ?>

</div>

<?php /* ════════════════════════════════════════════════════
   EXAM DIRECTORY - card grid of all exams
════════════════════════════════════════════════════ */ ?>
<?php elseif ($view === 'exams'): ?>

<div class="select-grid exam-dir-grid">
    <?php foreach ($exams as $ex):
        $qCount = $examMeta[$ex['id']]['question_count'] ?? 0;
        $isActive = (bool)$ex['is_active'];
    ?>
        <a href="<?= url('/staff/exam') ?>?exam=<?= $ex['id'] ?>" class="select-card exam-dir-card <?= $isActive ? 'is-active' : '' ?>">
            <?php if ($isActive): ?>
                <div class="select-card-badge"><span class="badge badge-success">Active</span></div>
            <?php endif; ?>
            <div class="select-card-title"><?= e($ex['title']) ?></div>
            <div class="select-card-meta">
                <div class="select-card-meta-row">
                    <?= $qCount ?> question<?= $qCount !== 1 ? 's' : '' ?>
                </div>
            </div>
            <div class="select-card-footer">
                <span>Created <?= e(format_date($ex['created_at'] ?? null, 'M j, Y')) ?></span>
                <span class="select-card-action">
                    Edit
                </span>
            </div>
        </a>
    <?php endforeach; ?>

    <!-- New Exam card (always last in grid) -->
    <div class="select-card select-card--add exam-dir-card" onclick="openModal('create-exam-modal')"
         role="button" tabindex="0"
         onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();this.click();}">
        <div class="select-card-title"><?= empty($exams) ? 'Create Your First Exam' : 'New Exam' ?></div>
        <div class="select-card-desc"><?= empty($exams) ? 'Get started by creating an entrance exam.' : 'Build a new entrance exam' ?></div>
    </div>
</div>

<?php /* ════════════════════════════════════════════════════
   EXAM EDITOR - full question builder for a selected exam
════════════════════════════════════════════════════ */ ?>
<?php else: ?>

<?php if ($selectedExam): ?>

        <!-- Exam header card: read-only auto-derived title + editable description and shuffle flags. -->

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

                        <textarea class="exam-inline exam-inline--desc" name="description" rows="1" aria-label="Description"
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
                                data-confirm="Delete this exam? All questions will be removed." data-confirm-danger data-confirm-ok="Delete"
                                onclick="document.getElementById('delete-exam-form').submit();">Delete</button>
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
                                            <span style="width:13px;height:13px;border:1.5px solid var(--border);border-radius:var(--radius);display:inline-block;flex-shrink:0"></span>
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
                                <?= icon('dots-six-vertical', 14) ?>
                            </button>
                            <button class="btn-icon" title="Edit question" onclick="startInlineQFullEdit(<?= $q['id'] ?>)">
                                <?= icon('ic_fluent_edit_24_regular', 14) ?>
                            </button>
                            <form method="POST" data-confirm="Remove this question?" data-confirm-danger data-confirm-ok="Remove" style="display:inline">
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
                    <div class="section-header" style="background:<?= $sc['bg'] ?>;border-color:<?= $sc['border'] ?>;border-radius:var(--radius) var(--radius) 0 0;margin-bottom:0;align-items:flex-start">
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
                                      role="button" tabindex="0"
                                      onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();this.click();}"
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
                            <div id="sec-empty-<?= $sec['id'] ?>" style="padding:var(--space-4) var(--space-5);border:1.5px dashed var(--border);border-radius:var(--radius);text-align:center;color:var(--text-tertiary);font-size:var(--text-sm)">
                                No questions yet - click <strong>Add question</strong> below to get started.
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
                <?= icon('list-plus', 14) ?>
                Add Section
            </button>
            <button class="btn btn-secondary btn-sm" onclick="openAiImportModal()" style="display:flex;align-items:center;gap:5px">
                <?= icon('map-pin', 14) ?>
                Import with AI
            </button>
        </div>

<?php else: ?>
    <div class="card" style="padding:var(--space-16);text-align:left;color:var(--text-tertiary)">
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
                            padding:var(--space-3) var(--space-4);border-radius:var(--radius)">
                    Title will be set automatically:
                    <strong>PLP Admissions Test (<?= e($schoolYear) ?>)</strong>
                    <div style="font-size:var(--text-xs);color:var(--text-tertiary);margin-top:2px">
                        Pulled from the active school year. Change it on the
                        <a href="<?= url('/admin/school-year') ?>">School Year</a> page.
                    </div>
                </div>
                <div>
                    <label class="form-label" for="create-exam-desc">Description</label>
                    <textarea id="create-exam-desc" name="description" class="form-control" rows="2" placeholder="Brief instructions shown to students…"></textarea>
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
                            padding:var(--space-3) var(--space-4);border-radius:var(--radius)">
                    Title is set automatically:
                    <strong>PLP Admissions Test (<?= e($schoolYear) ?>)</strong>
                    <div style="font-size:var(--text-xs);color:var(--text-tertiary);margin-top:2px">
                        Pulled from the active school year. Change it on the
                        <a href="<?= url('/admin/school-year') ?>">School Year</a> page.
                    </div>
                </div>
                <div>
                    <label class="form-label" for="edit-exam-desc">Description</label>
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
                    <label class="form-label" for="sec-title-field">Section Title <span style="color:var(--error)">*</span></label>
                    <input type="text" name="section_title" id="sec-title-field" class="form-control"
                           placeholder="e.g. Part 1: Multiple Choice" required>
                </div>
                <div>
                    <label class="form-label" for="sec-desc-field">Description / Instructions</label>
                    <textarea name="section_desc" id="sec-desc-field" class="form-control" rows="2"
                              placeholder="e.g. Choose the best answer for each question."></textarea>
                </div>
                <div>
                    <span class="form-label">Answer Mode</span>
                    <p style="font-size:var(--text-xs);color:var(--text-tertiary);margin-bottom:var(--space-2)">
                        All questions added to this section will automatically use this type.
                    </p>
                    <div class="type-grid" id="sec-type-grid">
                        <?php foreach ($QUESTION_TYPES as $typeKey => $typeMeta): ?>
                            <div class="type-option <?= $typeKey==='multiple_choice'?'selected':'' ?>"
                                 data-type="<?= $typeKey ?>"
                                 role="button" tabindex="0"
                                 onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();this.click();}"
                                 onclick="selectSectionType('<?= $typeKey ?>')">
                                <?= typeIcon($typeKey, 20) ?>
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
window.PAGE = <?= json_encode([
    'examId'        => (int)($selectedExam['id'] ?? 0),
    'live'          => !empty($examLive),
    'typeLabels'    => array_map(fn($v) => $v['label'], $QUESTION_TYPES),
    'sectionColors' => $SECTION_COLORS,
    'icons'         => [
        'add'     => icon('ic_fluent_add_24_regular', 11),
        'dismiss' => icon('ic_fluent_dismiss_24_regular', 13),
    ],
], JSON_HEX_TAG | JSON_HEX_AMP) ?>;
</script>

<!-- ════════════════════════════════════════════════════════════
     AI IMPORT MODAL
════════════════════════════════════════════════════════════ -->

<div id="ai-import-modal" class="modal-backdrop" style="display:none">
    <div class="modal" style="max-width:500px;max-height:90vh;display:flex;flex-direction:column">
        <?= csrf_field() ?>
        <div class="modal-header" style="padding:var(--space-4) var(--space-5);border-bottom:1px solid var(--border)">
            <div style="display:flex;align-items:center;gap:var(--space-3)">
                <div style="width:34px;height:34px;border-radius:var(--radius);background:var(--accent);display:flex;align-items:center;justify-content:center;flex-shrink:0">
                    <?= icon('sparkle', 17, 'color:#fff') ?>
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
                <a id="ai-puter-link" href="https://puter.com" target="_blank" style="display:none;font-size:var(--text-xs);font-weight:var(--weight-medium);color:var(--accent);text-decoration:none;white-space:nowrap">Create account <?= icon('ic_fluent_arrow_right_24_regular', 14) ?></a>
                <button id="ai-puter-signin-btn" onclick="puterSignIn()" class="btn btn-ghost btn-sm" style="display:none;color:var(--accent)">Sign in <?= icon('ic_fluent_arrow_right_24_regular', 14) ?></button>
                <button id="ai-puter-signout-btn" onclick="puterSignOut()" class="btn btn-ghost btn-sm" style="display:none">Sign out</button>
            </div>
            <div id="ai-step-upload">
                <div class="ai-dropzone" id="ai-drop-zone" role="button" tabindex="0" onkeydown="if(event.target===this&&(event.key==='Enter'||event.key===' ')){event.preventDefault();this.click();}" onclick="document.getElementById('ai-file-input').click()" ondragover="event.preventDefault();this.classList.add('dragover')" ondragleave="this.classList.remove('dragover')" ondrop="handleAiFileDrop(event)">
                    <div class="ai-dropzone-icon"><?= icon('file', 20, 'color:var(--text-tertiary)') ?></div>
                    <div id="ai-dropzone-label" style="font-size:var(--text-sm);font-weight:var(--weight-medium);color:var(--text-secondary)">Drop file here or <span style="color:var(--accent)">click to browse</span></div>
                    <div style="font-size:var(--text-xs);color:var(--text-tertiary);margin-top:4px">JPG · PNG · PDF · DOCX · TXT</div>
                    <div id="ai-file-tag-wrap" style="display:none;margin-top:var(--space-3)">
                        <span class="ai-file-tag">
                            <?= icon('file', 12, 'color:#fff') ?>
                            <span id="ai-file-tag-name"></span>
                            <button class="rm" onclick="event.stopPropagation();clearAiFile()" title="Remove"><?= icon('ic_fluent_dismiss_24_regular', 12) ?></button>
                        </span>
                    </div>
                    <input type="file" id="ai-file-input" aria-label="Choose file" accept=".jpg,.jpeg,.png,.pdf,.docx,.txt,.doc" style="display:none" onchange="handleAiFileSelect(this)">
                </div>
                <div style="display:flex;align-items:center;gap:var(--space-2);margin-top:var(--space-3)">
                    <label for="ai-default-points" style="font-size:var(--text-sm);color:var(--text-secondary);white-space:nowrap">Points per question</label>
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
                    <button class="btn btn-ghost btn-sm" onclick="resetAiImport()"><?= icon('ic_fluent_arrow_left_24_regular', 16) ?> Try again</button>
                </div>
                <div class="ai-warn-strip">
                    <?= icon('warning:fill', 14, 'margin-top:1px;color:#d97706') ?>
                    <span>AI results may not be 100% accurate. <strong>Review each question and answer</strong> before saving.</span>
                </div>
                <div id="ai-questions-preview" style="display:flex;flex-direction:column;gap:var(--space-2);max-height:320px;overflow-y:auto"></div>
            </div>
            <div id="ai-step-error" style="display:none;flex-direction:column;gap:var(--space-3)">
                <div style="display:flex;align-items:flex-start;gap:10px;background:var(--error-bg);border:1px solid var(--error);border-radius:var(--radius);padding:12px 14px;font-size:var(--text-sm);color:var(--error)">
                    <?= icon('warning-circle:fill', 16, 'margin-top:1px') ?>
                    <span id="ai-error-msg"></span>
                </div>
                <button class="btn btn-ghost btn-sm" style="align-self:flex-start" onclick="resetAiImport()"><?= icon('ic_fluent_arrow_left_24_regular', 16) ?> Try again</button>
            </div>
        </div>
        <div class="modal-footer" id="ai-modal-footer" style="border-top:1px solid var(--border)">
            <button type="button" class="btn btn-ghost" onclick="closeAiImportModal()">Cancel</button>
            <button type="button" class="btn btn-primary" id="ai-process-btn" onclick="startAiProcessing()" disabled>
                <?= icon('sparkle', 14, 'margin-right:5px') ?>
                Generate Questions
            </button>
        </div>
        <div class="modal-footer" id="ai-save-footer" style="display:none;border-top:1px solid var(--border)">
            <button type="button" class="btn btn-ghost" onclick="closeAiImportModal()">Cancel</button>
            <button type="button" class="btn btn-primary" id="ai-save-btn" onclick="saveAiQuestions()">
                <?= icon('floppy-disk', 14, 'margin-right:5px') ?>
                Add All to Exam
            </button>
        </div>
    </div>
</div>

<?php
$content   = ob_get_clean();
$pageTitle = 'Entrance Exam';
$pageStyles  = ['css/pages/exam-manage.css'];
$pageScripts = ['js/pages/exam-manage.js', 'js/pages/exam-ai-import.js'];
// Entrance Exam > Exams > Exam Editor (replaces the old back-arrow buttons).
$pageBreadcrumb = [];
if ($view === 'exams') {
    $pageHeading    = 'Exams';
    $pageBreadcrumb = [['label' => 'Entrance Exam', 'to' => '/staff/exam']];
} elseif ($view === 'editor') {
    $pageHeading    = 'Exam Editor';
    $pageBreadcrumb = [
        ['label' => 'Entrance Exam', 'to' => '/staff/exam'],
        ['label' => 'Exams',         'to' => '/staff/exam?view=exams'],
    ];
}
$activeNav = 'exam';
$pageWide  = ($view !== 'editor'); // picker views share the wide container
include VIEWS_PATH . '/layouts/app.php';
