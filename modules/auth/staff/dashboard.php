<?php
// ============================================================
// modules/auth/staff/dashboard.php
// Staff management overview
// ============================================================

require_once CORE_PATH . '/bootstrap.php';
Auth::requireRole(ROLE_STAFF, ROLE_ADMIN);

$pdo         = db();
$schoolYear  = school_setting('current_school_year');

// Aggregate counts
$counts = $pdo->prepare(
    "SELECT
        COUNT(*)                                          AS total,
        SUM(overall_status = 'pending')                  AS pending,
        SUM(overall_status = 'documents')                AS documents,
        SUM(overall_status = 'exam')                     AS exam,
        SUM(overall_status = 'interview')                AS interview,
        SUM(overall_status = 'released')                 AS released
     FROM applicants WHERE school_year = ?"
);
$counts->execute([$schoolYear]);
$stats = $counts->fetch();

// Documents awaiting review
$pendingDocs = $pdo->prepare(
    "SELECT COUNT(*) FROM documents d
     JOIN applicants a ON a.id = d.applicant_id
     WHERE d.status = 'uploaded' AND a.school_year = ?"
);
$pendingDocs->execute([$schoolYear]);
$docsToReview = (int) $pendingDocs->fetchColumn();

// Upcoming interviews today
$todaySlots = (int) $pdo->query(
    "SELECT COUNT(*) FROM interview_slots WHERE slot_date = CURDATE() AND status = 'scheduled'"
)->fetchColumn();

// Recent applicants
$recent = $pdo->prepare(
    "SELECT u.name, a.course_applied, a.applicant_type, a.overall_status, a.created_at
     FROM applicants a JOIN users u ON u.id = a.user_id
     WHERE a.school_year = ?
     ORDER BY a.created_at DESC LIMIT 8"
);
$recent->execute([$schoolYear]);
$recentApplicants = $recent->fetchAll();

ob_start();
?>

<!-- Metrics -->
<div class="metrics-row">
    <div class="metric-card">
        <div class="metric-label">Total applicants</div>
        <div class="metric-value"><?= (int)$stats['total'] ?></div>
        <div class="metric-sub"><?= e($schoolYear) ?></div>
    </div>
    <div class="metric-card">
        <div class="metric-label">Docs to review</div>
        <div class="metric-value" style="color:var(--warning)"><?= $docsToReview ?></div>
        <div class="metric-sub">Uploaded, awaiting review</div>
    </div>
    <div class="metric-card">
        <div class="metric-label">Interviews today</div>
        <div class="metric-value"><?= $todaySlots ?></div>
        <div class="metric-sub"><?= date('M j, Y') ?></div>
    </div>
    <div class="metric-card">
        <div class="metric-label">Results released</div>
        <div class="metric-value"><?= (int)$stats['released'] ?></div>
        <div class="metric-sub">of <?= (int)$stats['total'] ?> applicants</div>
    </div>
</div>

<!-- Pipeline -->
<div class="card" style="margin-bottom:var(--space-6)">
    <div class="card-header">
        <div class="card-title">Applicant pipeline</div>
        <a href="<?= url('/staff/applicants') ?>" class="btn btn-secondary btn-sm">View all</a>
    </div>
    <div style="display:grid;grid-template-columns:repeat(5,1fr);gap:var(--space-4)">
        <?php
        $stages = [
            ['label' => 'Pending',     'key' => 'pending',    'badge' => 'badge-pending'],
            ['label' => 'Documents',   'key' => 'documents',  'badge' => 'badge-uploaded'],
            ['label' => 'Exam',        'key' => 'exam',       'badge' => 'badge-review'],
            ['label' => 'Interview',   'key' => 'interview',  'badge' => 'badge-review'],
            ['label' => 'Released',    'key' => 'released',   'badge' => 'badge-approved'],
        ];
        foreach ($stages as $s): ?>
            <div style="text-align:center;padding:var(--space-4);background:var(--bg-subtle);border-radius:var(--radius-md)">
                <div style="font-size:var(--text-2xl);font-weight:var(--weight-semibold);letter-spacing:-0.03em">
                    <?= (int)$stats[$s['key']] ?>
                </div>
                <div style="font-size:var(--text-xs);color:var(--text-tertiary);margin-top:var(--space-1)"><?= $s['label'] ?></div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Recent applicants table -->
<div class="card">
    <div class="card-header">
        <div class="card-title">Recent applications</div>
        <?php if ($docsToReview > 0): ?>
            <a href="<?= url('/staff/applicants') ?>" class="btn btn-primary btn-sm">
                Review <?= $docsToReview ?> document<?= $docsToReview !== 1 ? 's' : '' ?>
            </a>
        <?php endif; ?>
    </div>
    <?php if (empty($recentApplicants)): ?>
        <p style="font-size:var(--text-sm);color:var(--text-tertiary);padding:var(--space-4) 0">
            No applicants yet for this school year.
        </p>
    <?php else: ?>
        <div class="table-wrap" style="border:none;border-radius:0;margin:-1px">
            <table>
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Course</th>
                        <th>Type</th>
                        <th>Status</th>
                        <th>Applied</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentApplicants as $row): ?>
                        <tr>
                            <td style="font-weight:var(--weight-medium)"><?= e($row['name']) ?></td>
                            <td style="color:var(--text-secondary);font-size:var(--text-xs)"><?= e($row['course_applied']) ?></td>
                            <td style="text-transform:capitalize"><?= e($row['applicant_type']) ?></td>
                            <td>
                                <span class="badge badge-<?= $row['overall_status'] === 'released' ? 'approved' : ($row['overall_status'] === 'pending' ? 'pending' : 'uploaded') ?>">
                                    <?= ucfirst($row['overall_status']) ?>
                                </span>
                            </td>
                            <td style="color:var(--text-tertiary);font-size:var(--text-xs)"><?= format_date($row['created_at'], 'M j') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php
$content   = ob_get_clean();
$pageTitle = 'Dashboard';
$activeNav = 'dashboard';
include VIEWS_PATH . '/layouts/app.php';
