<?php
// ============================================================
// modules/interview/staff_batch.php
// Batch create interview sessions from a template
// ============================================================

require_once CORE_PATH . '/bootstrap.php';
Auth::requireRole(ROLE_STAFF, ROLE_ADMIN);

$db      = db();
$staffId = Auth::id();
$isAdmin = Auth::role() === ROLE_ADMIN;

$errors  = [];
$success = [];
$created = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $startDate  = trim($_POST['start_date']  ?? '');
    $endDate    = trim($_POST['end_date']    ?? '');
    $startTime  = trim($_POST['start_time']  ?? '09:00');
    $endTime    = trim($_POST['end_time']    ?? '16:00');
    $capacity   = (int)($_POST['capacity']   ?? 30);
    $department = $isAdmin ? trim($_POST['department'] ?? '') : user_department($staffId);
    $days       = $_POST['days'] ?? [1, 2, 3, 4, 5];

    if (!$startDate)  $errors[] = 'Start date is required.';
    if (!$endDate)    $errors[] = 'End date is required.';
    if ($startDate && $endDate && $startDate > $endDate)
        $errors[] = 'End date must be after start date.';
    if ($capacity < 1) $errors[] = 'Capacity must be at least 1.';

    if (empty($errors)) {
        $created = batch_create_interview_sessions([
            'start_date' => $startDate,
            'end_date'   => $endDate,
            'start_time' => $startTime,
            'end_time'   => $endTime,
            'capacity'   => $capacity,
            'days'       => array_map('intval', $days),
        ], $department, $staffId);

        if ($created > 0) {
            Session::flash('success', "{$created} interview session(s) created successfully.");
        } else {
            Session::flash('info', 'No new sessions created (sessions may already exist for those dates).');
        }
        redirect('/staff/interviews');
    }
}

// Show the form
$departments = departments_list();

ob_start();
?>

<div style="max-width:600px;margin:0 auto">
    <div style="display:flex;align-items:center;gap:var(--space-3);margin-bottom:var(--space-6)">
        <a href="<?= url('/staff/interviews') ?>" class="btn btn-ghost btn-sm">
            <?= icon('ic_fluent_arrow_left_24_regular', 16) ?>
            Back
        </a>
        <h1 class="page-title" style="margin:0">Batch Create Sessions</h1>
    </div>

    <?php foreach ($errors as $e): ?>
        <div class="alert alert-error" style="margin-bottom:var(--space-3)"><?= e($e) ?></div>
    <?php endforeach; ?>

    <div class="card" style="padding:var(--space-6)">
        <p style="color:var(--text-secondary);margin-bottom:var(--space-5)">
            Create interview sessions for multiple days at once. The system will auto-assign pending applicants to new sessions.
        </p>

        <form method="POST">
            <?= csrf_field() ?>
            <div style="display:flex;flex-direction:column;gap:var(--space-4)">

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--space-4)">
                    <div>
                        <label class="form-label">Start Date *</label>
                        <input type="date" name="start_date" class="form-control" required
                               min="<?= date('Y-m-d') ?>"
                               value="<?= e($_POST['start_date'] ?? date('Y-m-d', strtotime('+1 day'))) ?>">
                    </div>
                    <div>
                        <label class="form-label">End Date *</label>
                        <input type="date" name="end_date" class="form-control" required
                               min="<?= date('Y-m-d') ?>"
                               value="<?= e($_POST['end_date'] ?? date('Y-m-d', strtotime('+14 days'))) ?>">
                    </div>
                </div>

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--space-4)">
                    <div>
                        <label class="form-label">Start Time</label>
                        <input type="time" name="start_time" class="form-control"
                               value="<?= e($_POST['start_time'] ?? '09:00') ?>">
                    </div>
                    <div>
                        <label class="form-label">End Time</label>
                        <input type="time" name="end_time" class="form-control"
                               value="<?= e($_POST['end_time'] ?? '16:00') ?>">
                    </div>
                </div>

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--space-4)">
                    <div>
                        <label class="form-label">Capacity per session</label>
                        <input type="number" name="capacity" class="form-control" min="1" max="200"
                               value="<?= e($_POST['capacity'] ?? '30') ?>">
                    </div>
                    <?php if ($isAdmin): ?>
                    <div>
                        <label class="form-label">Department</label>
                        <select name="department" class="form-control">
                            <option value="">All departments</option>
                            <?php foreach ($departments as $dept): ?>
                                <option value="<?= e($dept) ?>"><?= e($dept) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                </div>

                <div>
                    <label class="form-label">Days of the week</label>
                    <div style="display:flex;flex-wrap:wrap;gap:var(--space-3);margin-top:var(--space-2)">
                        <?php
                        $dayNames = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri'];
                        $selectedDays = $_POST['days'] ?? [1, 2, 3, 4, 5];
                        foreach ($dayNames as $num => $name):
                        ?>
                        <label style="display:flex;align-items:center;gap:var(--space-1);cursor:pointer">
                            <input type="checkbox" name="days[]" value="<?= $num ?>"
                                   <?= in_array($num, $selectedDays) ? 'checked' : '' ?>>
                            <?= $name ?>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary" style="margin-top:var(--space-3)">
                    <?= icon('ic_fluent_calendar_add_24_regular', 16) ?>
                    Create Sessions
                </button>
            </div>
        </form>
    </div>
</div>

<?php
$content   = ob_get_clean();
$activeNav = 'interviews';
$pageTitle = 'Batch Create Interview Sessions';
include VIEWS_PATH . '/layouts/app.php';
