<?php
// ============================================================
// modules/settings/admin_school_year.php
// Admin: manage admissions window (open/close dates)
// School year is derived automatically from the open date.
// ============================================================

require_once CORE_PATH . '/bootstrap.php';
Auth::requireRole(ROLE_ADMIN);

$db      = db();
$errors  = [];
$success = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'set_admissions_window') {
        $open     = trim($_POST['admissions_open']  ?? '');
        $close    = trim($_POST['admissions_close'] ?? '');
        $docDeadline = trim($_POST['document_deadline'] ?? '');

        if (!$open || !$close) {
            $errors[] = 'Set both open and close dates.';
        } elseif ($close <= $open) {
            $errors[] = 'Close date must be after open date.';
        } elseif ($docDeadline && $docDeadline <= $open) {
            $errors[] = 'Document deadline must be after the admissions open date.';
        } else {
            $upsert = 'INSERT INTO school_settings (setting_key, setting_value) VALUES (?,?)
                       ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)';
            $db->prepare($upsert)->execute(['admissions_open',  $open]);
            $db->prepare($upsert)->execute(['admissions_close', $close]);
            $db->prepare($upsert)->execute(['document_deadline', $docDeadline]);

            // Derive and persist school year for reference
            $openYear  = (int) date('Y', strtotime($open));
            $schoolYear = $openYear . '-' . ($openYear + 1);
            $db->prepare($upsert)->execute(['current_school_year', $schoolYear]);

            audit_log('admissions_window_set',
                "Admissions window set: {$open} to {$close} (AY {$schoolYear})");
            $success[] = "Admissions window saved (AY {$schoolYear}).";
        }
    }

    if ($action === 'new_cycle') {
        $confirm = trim($_POST['confirm_text'] ?? '');
        if ($confirm !== 'START NEW CYCLE') {
            $errors[] = 'Type START NEW CYCLE to confirm.';
        } else {
            $db->exec('UPDATE exams SET is_active=0');
            audit_log('new_cycle_started', 'Started new admission cycle — exam deactivated.');
            $success[] = 'New cycle started. Previous data preserved.';
        }
    }
}

// Reload settings after possible save
$admissionsOpen    = school_setting('admissions_open',  '');
$admissionsClose   = school_setting('admissions_close', '');
$docDeadline       = school_setting('document_deadline', '');
$currentYear       = school_setting('current_school_year', '—');
$isOpen          = admissions_is_open();

// Check if exam & interview are set up for readiness indicators
$syCheck           = $currentYear !== '—' ? $currentYear : date('Y').'-'.(date('Y')+1);
$hasActiveExam     = (int)$db->query('SELECT COUNT(*) FROM exams WHERE is_active=1')->fetchColumn() > 0;
$_q2 = $db->prepare('SELECT COUNT(*) FROM exam_slot_schedule WHERE school_year=?');
$_q2->execute([$syCheck]);
$hasExamSlots      = (int)$_q2->fetchColumn() > 0;
$hasInterviewSlots = (int)$db->query("SELECT COUNT(*) FROM interview_slots WHERE slot_date >= CURDATE()")->fetchColumn() > 0;
$examReady         = $hasActiveExam && $hasExamSlots;
$interviewReady    = $hasInterviewSlots;
// Stats by school year
$stmt = $db->query(
    'SELECT school_year, overall_status, COUNT(*) as cnt
     FROM applicants GROUP BY school_year, overall_status ORDER BY school_year DESC'
);
$rawStats = $stmt->fetchAll();

$statsByYear = [];
foreach ($rawStats as $row) {
    $statsByYear[$row['school_year']][$row['overall_status']] = (int)$row['cnt'];
}

ob_start();
?>

<?php foreach ($errors as $e): ?>
    <div class="alert alert-error" style="margin-bottom:var(--space-3)"><?= e($e) ?></div>
<?php endforeach; ?>
<?php foreach ($success as $s): ?>
    <div class="alert alert-success" style="margin-bottom:var(--space-3)"><?= e($s) ?></div>
<?php endforeach; ?>

<div class="admin-form-stack">

    <!-- Admissions window -->
    <div class="card">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:var(--space-5)">
            <div class="card-title" style="margin:0">Admissions Window</div>
            <?php if ($isOpen): ?>
                <span class="badge badge-approved">Open</span>
            <?php elseif ($admissionsOpen || $admissionsClose): ?>
                <span class="badge badge-pending">Closed</span>
            <?php else: ?>
                <span class="badge" style="background:var(--bg-subtle);color:var(--text-tertiary)">Not set</span>
            <?php endif; ?>
        </div>



        <!-- Readiness indicators -->
        <div style="display:flex;gap:var(--space-4);margin-bottom:var(--space-5);flex-wrap:wrap">
            <div style="display:flex;align-items:center;gap:var(--space-2);font-size:var(--text-sm)">
                <span style="display:inline-block;width:8px;height:8px;border-radius:50%;
                             background:<?= $examReady ? 'var(--success)' : 'var(--error)' ?>"></span>
                <span style="color:var(--text-secondary)">Exams</span>
                <span style="font-size:var(--text-xs);color:var(--text-tertiary)">
                    <?php if (!$hasActiveExam && !$hasExamSlots): ?>
                        — No active exam or room slots
                    <?php elseif (!$hasActiveExam): ?>
                        — No active exam
                    <?php elseif (!$hasExamSlots): ?>
                        — No room slots configured
                    <?php else: ?>
                        — Ready
                    <?php endif; ?>
                </span>
            </div>
            <div style="display:flex;align-items:center;gap:var(--space-2);font-size:var(--text-sm)">
                <span style="display:inline-block;width:8px;height:8px;border-radius:50%;
                             background:<?= $interviewReady ? 'var(--success)' : 'var(--error)' ?>"></span>
                <span style="color:var(--text-secondary)">Interviews</span>
                <span style="font-size:var(--text-xs);color:var(--text-tertiary)">
                    <?= $interviewReady ? '— Ready' : '— No upcoming interview sessions' ?>
                </span>
            </div>
        </div>

        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="set_admissions_window">
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:var(--space-4);margin-bottom:var(--space-5)">
                <div>
                    <label class="form-label">Admissions Opens</label>
                    <input type="date" name="admissions_open" class="form-input"
                           value="<?= e($admissionsOpen) ?>">
                </div>
                <div>
                    <label class="form-label">Admissions Closes</label>
                    <input type="date" name="admissions_close" class="form-input"
                           value="<?= e($admissionsClose) ?>">
                </div>
                <div>
                    <label class="form-label">Document Deadline</label>
                    <input type="date" name="document_deadline" class="form-input"
                           value="<?= e($docDeadline) ?>">
                </div>
            </div>
            <button type="submit" class="btn btn-primary">Save Window</button>
        </form>
    </div>

    <!-- Historical summary -->
    <?php if (!empty($statsByYear)): ?>
    <div class="card">
        <div class="card-title" style="margin-bottom:var(--space-5)">Applicant History</div>
        <div style="display:flex;flex-direction:column;gap:var(--space-4)">
        <?php foreach ($statsByYear as $year => $statuses):
            $total = array_sum($statuses);
        ?>
            <div style="padding:var(--space-4);background:var(--bg-subtle);border-radius:var(--radius-md)">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:var(--space-3)">
                    <div>
                        <span style="font-weight:var(--weight-semibold)"><?= e($year) ?></span>
                        <?php if ($year === $currentYear): ?>
                            <span class="badge badge-approved" style="margin-left:var(--space-2)">Current</span>
                        <?php endif; ?>
                    </div>
                    <span style="font-size:var(--text-sm);color:var(--text-tertiary)"><?= $total ?> total</span>
                </div>
                <div style="display:flex;gap:var(--space-4);flex-wrap:wrap">
                    <?php
                    $stages = ['pending','documents','submitted','exam','interview','released'];
                    foreach ($stages as $stage):
                        $cnt = $statuses[$stage] ?? 0;
                        if (!$cnt) continue;
                    ?>
                        <div style="font-size:var(--text-sm)">
                            <span style="color:var(--text-tertiary)"><?= ucfirst($stage) ?>:</span>
                            <span style="font-weight:var(--weight-semibold);margin-left:4px"><?= $cnt ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- New cycle — danger zone -->
    <div class="card card-danger">
        <div class="card-title" style="color:var(--error);margin-bottom:var(--space-1)">Start New Admission Cycle</div>
        <p class="card-description" style="margin-bottom:var(--space-5)">
            Starts a new admissions cycle. The current exam is deactivated and all previous applicant data is preserved.
        </p>
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="new_cycle">
            <div>
                <label class="form-label">Type <strong>START NEW CYCLE</strong> to confirm</label>
                <input type="text" name="confirm_text" class="form-input"
                       placeholder="START NEW CYCLE" autocomplete="off" style="max-width:280px">
            </div>
            <div style="margin-top:var(--space-5)">
                <button type="submit" class="btn btn-danger">Start New Cycle</button>
            </div>
        </form>
    </div>

</div>

<?php
$content   = ob_get_clean();
$pageTitle = 'Admissions';
$activeNav = 'school-year';
include VIEWS_PATH . '/layouts/app.php';