<?php
// ============================================================
// modules/settings/admin_school_year.php
// Admin: manage admissions window (open/close dates)
// School year is derived automatically from the open date.
// ============================================================

require_once CORE_PATH . '/bootstrap.php';
Auth::requireRole(ROLE_SSO, ROLE_ADMIN);

$db      = db();
$errors  = [];
$success = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'set_admissions_window') {
        $open        = trim($_POST['admissions_open']    ?? '');
        $close       = trim($_POST['admissions_close']   ?? '');
        $docDeadline = trim($_POST['document_deadline']  ?? '');

        if (!$open || !$close || !$docDeadline) {
            $errors[] = 'Admissions opens, closes, and document deadline are all required.';
        } elseif ($close <= $open) {
            $errors[] = 'Admissions close date must be after the open date.';
        } elseif ($docDeadline <= $open) {
            $errors[] = 'Document deadline must be after the admissions open date.';
        } elseif ($docDeadline > $close) {
            $errors[] = 'Document deadline must be on or before the admissions close date.';
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
        if (strcasecmp($confirm, 'Start new cycle') !== 0) {
            $errors[] = 'Type "Start new cycle" to confirm.';
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



        <form method="POST" id="apw-form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="set_admissions_window">
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:var(--space-4);margin-bottom:var(--space-3)">
                <div>
                    <label class="form-label" for="apw-open">Admissions Opens</label>
                    <input type="date" id="apw-open" name="admissions_open" class="form-input"
                           value="<?= e($admissionsOpen) ?>" required>
                </div>
                <div>
                    <label class="form-label" for="apw-close">Admissions Closes</label>
                    <input type="date" id="apw-close" name="admissions_close" class="form-input"
                           value="<?= e($admissionsClose) ?>" required>
                </div>
                <div>
                    <label class="form-label" for="apw-deadline">Document Deadline</label>
                    <input type="date" id="apw-deadline" name="document_deadline" class="form-input"
                           value="<?= e($docDeadline) ?>" required>
                </div>
            </div>
            <div id="apw-hint" style="display:none;font-size:var(--text-sm);color:var(--error);margin-bottom:var(--space-3)"></div>
            <button type="submit" id="apw-save" class="btn btn-primary" disabled>Save Window</button>
        </form>
        <script>
        (function(){
            var form     = document.getElementById('apw-form');
            if (!form) return;
            var openEl   = document.getElementById('apw-open');
            var closeEl  = document.getElementById('apw-close');
            var deadEl   = document.getElementById('apw-deadline');
            var saveBtn  = document.getElementById('apw-save');
            var hintEl   = document.getElementById('apw-hint');

            function evaluate(){
                var o = openEl.value, c = closeEl.value, d = deadEl.value;

                // Bind deadline range to the chosen open/close so the
                // native date picker greys out invalid days.
                deadEl.min = o || '';
                deadEl.max = c || '';

                var msg = '';
                if (!o || !c || !d) {
                    msg = 'Fill in all three dates to save.';
                } else if (c <= o) {
                    msg = 'Admissions close date must be after the open date.';
                } else if (d <= o) {
                    msg = 'Document deadline must be after the admissions open date.';
                } else if (d > c) {
                    msg = 'Document deadline must be on or before the admissions close date.';
                }

                if (msg) {
                    saveBtn.disabled = true;
                    hintEl.textContent = msg;
                    hintEl.style.display = '';
                } else {
                    saveBtn.disabled = false;
                    hintEl.textContent = '';
                    hintEl.style.display = 'none';
                }
            }

            ['input','change','blur'].forEach(function(evt){
                openEl .addEventListener(evt, evaluate);
                closeEl.addEventListener(evt, evaluate);
                deadEl .addEventListener(evt, evaluate);
            });
            evaluate();
        })();
        </script>
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
                <label class="form-label">Type <strong>Start new cycle</strong> to confirm</label>
                <input type="text" name="confirm_text" class="form-input"
                       placeholder="Start new cycle" autocomplete="off" style="max-width:280px">
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
