<?php
// ============================================================
// modules/auth/admin/dashboard.php
// Admin overview — system-wide stats + quick links
// ============================================================

require_once CORE_PATH . '/bootstrap.php';
Auth::requireRole(ROLE_ADMIN);

$pdo        = db();
$schoolYear = school_setting('current_school_year');

// Totals
$stats = $pdo->prepare(
    "SELECT
        COUNT(*)                                          AS total,
        SUM(overall_status = 'released')                 AS released,
        SUM(a2.result = 'accepted')                      AS accepted,
        SUM(a2.result = 'waitlisted')                    AS waitlisted,
        SUM(a2.result = 'rejected')                      AS rejected_result
     FROM applicants a
     LEFT JOIN admission_results a2 ON a2.applicant_id = a.id
     WHERE a.school_year = ?"
);
$stats->execute([$schoolYear]);
$totals = $stats->fetch();

// Pipeline stage counts
$pipeline = $pdo->prepare(
    "SELECT overall_status, COUNT(*) as cnt
     FROM applicants WHERE school_year = ?
     GROUP BY overall_status"
);
$pipeline->execute([$schoolYear]);
$pipelineCounts = array_column($pipeline->fetchAll(), 'cnt', 'overall_status');

// User counts by role
$userCounts = $pdo->query(
    "SELECT role, COUNT(*) as cnt FROM users WHERE is_active = 1 GROUP BY role"
)->fetchAll();
$usersByRole = array_column($userCounts, 'cnt', 'role');

ob_start();
?>

<div class="page-header">
    <h1 class="page-title">Admin Panel</h1>
    <p class="page-description">
        System overview &middot; <?= e($schoolYear) ?> &middot;
        v<?= e(school_setting('system_version', '1.0.0')) ?>
    </p>
</div>

<!-- System metrics -->
<div class="metrics-row">
    <div class="metric-card">
        <div class="metric-label">Applicants</div>
        <div class="metric-value"><?= (int)$totals['total'] ?></div>
        <div class="metric-sub"><?= e($schoolYear) ?></div>
    </div>
    <div class="metric-card metric-card--success">
        <div class="metric-label">Accepted</div>
        <div class="metric-value"><?= (int)$totals['accepted'] ?></div>
        <div class="metric-sub">of <?= (int)$totals['released'] ?> released</div>
    </div>
    <div class="metric-card metric-card--warning">
        <div class="metric-label">Waitlisted</div>
        <div class="metric-value"><?= (int)$totals['waitlisted'] ?></div>
        <div class="metric-sub">&nbsp;</div>
    </div>
    <div class="metric-card">
        <div class="metric-label">Staff accounts</div>
        <div class="metric-value"><?= (int)($usersByRole['staff'] ?? 0) ?></div>
        <div class="metric-sub"><?= (int)($usersByRole['admin'] ?? 0) ?> admin(s)</div>
    </div>
</div>

<!-- Pipeline overview -->
<?php
$stages = [
    'pending'   => 'Pending',
    'documents' => 'Documents',
    'exam'      => 'Exam',
    'interview' => 'Interview',
    'released'  => 'Released',
];
?>
<div class="pipeline-row">
    <?php foreach ($stages as $key => $label): ?>
    <div class="pipeline-stage">
        <div class="pipeline-stage-count"><?= (int)($pipelineCounts[$key] ?? 0) ?></div>
        <div class="pipeline-stage-label"><?= $label ?></div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Quick actions -->
<div class="quick-actions">
    <div class="card quick-action-card quick-action-card--users">
        <div class="card-title" style="margin-bottom:var(--space-2)">User Management</div>
        <p class="card-description" style="margin-bottom:var(--space-4)">Create, deactivate, or reset staff and admin accounts.</p>
        <a href="<?= url('/admin/users') ?>" class="btn btn-primary btn-sm">Manage users</a>
    </div>
    <div class="card quick-action-card quick-action-card--year">
        <div class="card-title" style="margin-bottom:var(--space-2)">School Year</div>
        <p class="card-description" style="margin-bottom:var(--space-4)">Archive applicant data and open a new admission cycle.</p>
        <a href="<?= url('/admin/school-year') ?>" class="btn btn-secondary btn-sm">Manage school year</a>
    </div>
    <div class="card quick-action-card quick-action-card--export">
        <div class="card-title" style="margin-bottom:var(--space-2)">Export Results</div>
        <p class="card-description" style="margin-bottom:var(--space-4)">Download the full admission results list as CSV.</p>
        <a href="<?= url('/admin/results') ?>" class="btn btn-secondary btn-sm">Export CSV</a>
    </div>
    <div class="card quick-action-card quick-action-card--settings">
        <div class="card-title" style="margin-bottom:var(--space-2)">System Settings</div>
        <p class="card-description" style="margin-bottom:var(--space-4)">Update school name, logo, and accent color.</p>
        <a href="<?= url('/admin/settings') ?>" class="btn btn-secondary btn-sm">Open settings</a>
    </div>
</div>

<?php
$content   = ob_get_clean();
$pageTitle = 'Admin Panel';
$activeNav = 'dashboard';
include VIEWS_PATH . '/layouts/app.php';