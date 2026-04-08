<?php
// ============================================================
// modules/settings/admin_school_year.php
// M8 — Admin: school year reset and archive management
// ============================================================

require_once CORE_PATH . '/bootstrap.php';
Auth::requireRole(ROLE_ADMIN);

$db      = db();
$errors  = [];
$success = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'set_school_year') {
        $year = trim($_POST['school_year'] ?? '');
        // Validate format YYYY-YYYY
        if (!preg_match('/^\d{4}-\d{4}$/', $year)) {
            $errors[] = 'Use the format YYYY-YYYY (e.g. 2025-2026).';
        } else {
            [$y1, $y2] = explode('-', $year);
            if ((int)$y2 !== (int)$y1 + 1) {
                $errors[] = 'The second year must be one more than the first (e.g. 2025-2026).';
            } else {
                $db->prepare(
                    'INSERT INTO school_settings (setting_key, setting_value) VALUES ("current_school_year",?)
                     ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)'
                )->execute([$year]);
                $success[] = "Current school year set to $year.";
            }
        }
    }

    if ($action === 'new_cycle') {
        $newYear = trim($_POST['new_year'] ?? '');
        $confirm = trim($_POST['confirm_text'] ?? '');

        if ($confirm !== 'START NEW CYCLE') {
            $errors[] = 'Type START NEW CYCLE exactly to confirm.';
        } elseif (!preg_match('/^\d{4}-\d{4}$/', $newYear)) {
            $errors[] = 'Invalid school year format.';
        } else {
            // Archive: deactivate exam, do NOT delete applicant data (keep history)
            $db->prepare(
                'INSERT INTO school_settings (setting_key, setting_value) VALUES ("current_school_year",?)
                 ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)'
            )->execute([$newYear]);

            // Deactivate current exam
            $db->exec('UPDATE exams SET is_active=0');

            $success[] = "New cycle $newYear started. All previous applicant data is preserved. Exam deactivated — create a new one for the new cycle.";
        }
    }
}

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

$currentYear = school_setting('current_school_year', '');

ob_start();
?>

<div class="page-header">
    <h1 class="page-title">School Year</h1>
    <p class="page-description">Manage admission cycles and school year settings.</p>
</div>

<?php foreach ($errors as $e): ?>
    <div class="alert alert-error" style="margin-bottom:var(--space-3)"><?= e($e) ?></div>
<?php endforeach; ?>
<?php foreach ($success as $s): ?>
    <div class="alert alert-success" style="margin-bottom:var(--space-3)"><?= e($s) ?></div>
<?php endforeach; ?>

<div class="admin-form-stack">

    <!-- Current school year -->
    <div class="card">
        <div class="card-title" style="margin-bottom:var(--space-5)">Current School Year</div>
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="set_school_year">
            <div style="display:flex;gap:var(--space-3);align-items:flex-end">
                <div style="flex:1">
                    <label class="form-label">School Year</label>
                    <input type="text" name="school_year" class="form-input"
                           value="<?= e($currentYear) ?>" placeholder="e.g. 2025-2026">
                </div>
                <button type="submit" class="btn btn-primary">Update</button>
            </div>
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
                    $stages = ['pending','documents','exam','interview','released'];
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
            This will switch the active school year and deactivate the current entrance exam.
            All existing applicant data is preserved. A new exam must be created for the new cycle.
        </p>
        <form method="POST" onsubmit="return document.getElementById('confirm-cycle-input').value === 'START NEW CYCLE'
                || (alert('Type START NEW CYCLE exactly to confirm.'), false)">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="new_cycle">
            <div style="display:flex;flex-direction:column;gap:var(--space-4)">
                <div>
                    <label class="form-label">New School Year</label>
                    <input type="text" name="new_year" class="form-input"
                           placeholder="e.g. 2026-2027" style="max-width:200px">
                </div>
                <div>
                    <label class="form-label">
                        Type <strong>START NEW CYCLE</strong> to confirm
                    </label>
                    <input type="text" name="confirm_text" id="confirm-cycle-input" class="form-input"
                           placeholder="START NEW CYCLE" autocomplete="off">
                </div>
            </div>
            <div style="margin-top:var(--space-5)">
                <button type="submit" class="btn btn-danger">Start New Cycle</button>
            </div>
        </form>
    </div>

</div>

<?php
$content   = ob_get_clean();
$pageTitle = 'School Year';
$activeNav = 'school-year';
include VIEWS_PATH . '/layouts/app.php';