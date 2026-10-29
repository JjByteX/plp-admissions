<?php
// ============================================================
// _queue_college_select.php
// Admin landing for the live queue: pick a college first.
// Expects: $db, $today (set by staff_queue.php).
// ============================================================

$departments = function_exists('departments_list') ? departments_list() : [];

// Per-college: sessions today, upcoming sessions, applicants booked from today
// onward, and today's live counts (in progress / waiting).
$qEmpty  = ['queue' => 0, 'sessions' => 0, 'upcoming' => 0, 'in_progress' => 0, 'waiting' => 0];
$qCounts = [];
foreach ($departments as $d) {
    $qCounts[$d] = $qEmpty;
}
try {
    $stmt = $db->prepare(
        "SELECT s.department,
                COUNT(DISTINCT CASE WHEN s.slot_date =  CAST(:d1 AS date) THEN s.id END) AS sessions,
                COUNT(DISTINCT CASE WHEN s.slot_date >  CAST(:d2 AS date) THEN s.id END) AS upcoming,
                COUNT(q.id) AS queue_total,
                SUM(CASE WHEN s.slot_date = CAST(:d3 AS date) AND q.status = 'in_progress' THEN 1 ELSE 0 END) AS in_progress,
                SUM(CASE WHEN s.slot_date = CAST(:d4 AS date) AND q.status = 'checked_in'  THEN 1 ELSE 0 END) AS waiting
           FROM interview_slots s
           LEFT JOIN interview_queue q ON q.slot_id = s.id
          WHERE s.slot_date >= CAST(:d5 AS date)
          GROUP BY s.department"
    );
    $stmt->execute([':d1' => $today, ':d2' => $today, ':d3' => $today, ':d4' => $today, ':d5' => $today]);
    foreach ($stmt->fetchAll() as $row) {
        $qCounts[$row['department']] = [
            'queue'       => (int) $row['queue_total'],
            'sessions'    => (int) $row['sessions'],
            'upcoming'    => (int) $row['upcoming'],
            'in_progress' => (int) $row['in_progress'],
            'waiting'     => (int) $row['waiting'],
        ];
    }
} catch (\Throwable $e) { /* non-fatal */ }

ob_start();
?>

<?php if (empty($departments)): ?>
    <div class="select-empty">No colleges / departments configured in the system.</div>
<?php else: ?>
    <div class="select-grid college-grid">
        <?php foreach ($departments as $dept):
            $info  = $qCounts[$dept] ?? $qEmpty;
            $live  = $info['in_progress'] > 0 || $info['waiting'] > 0;
        ?>
            <a href="<?= url('/staff/interviews/queue') ?>?college=<?= urlencode($dept) ?>"
               class="select-card college-card <?= $live ? 'is-live' : '' ?>">
                <?php if ($info['in_progress'] > 0): ?>
                    <span class="select-card-badge"><span class="select-card-live"><span class="dot"></span> Live</span></span>
                <?php endif; ?>
                <div class="select-card-title"><?= e($dept) ?></div>
                <div class="select-card-meta">
                    <div class="select-card-meta-row">
                        <?= $info['sessions'] ?> session<?= $info['sessions'] !== 1 ? 's' : '' ?> today
                        &middot; <?= $info['upcoming'] ?> upcoming
                    </div>
                    <div class="select-card-meta-row">
                        <?= $info['queue'] ?> applicant<?= $info['queue'] !== 1 ? 's' : '' ?>
                        <?php if ($info['waiting'] > 0 || $info['in_progress'] > 0): ?>
                            &middot; <?= $info['waiting'] ?> waiting, <?= $info['in_progress'] ?> in progress
                        <?php endif; ?>
                    </div>
                </div>
            </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php
$content   = ob_get_clean();
$pageTitle = 'Live Queue';
$pageBreadcrumb = [['label' => 'Interview Sessions', 'to' => '/staff/interviews']];
$activeNav = 'interviews';
$pageWide  = true;
include VIEWS_PATH . '/layouts/app.php';
