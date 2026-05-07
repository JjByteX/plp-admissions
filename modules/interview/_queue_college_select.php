<?php
// ============================================================
// _queue_college_select.php
// Admin landing for the live queue: pick a college first.
// Expects: $db, $today (set by staff_queue.php).
// ============================================================

$departments = function_exists('departments_list') ? departments_list() : [];

// Per-college: today's session + queue counts
$qCounts = [];
foreach ($departments as $d) {
    $qCounts[$d] = ['queue' => 0, 'sessions' => 0, 'in_progress' => 0, 'waiting' => 0];
}
try {
    $stmt = $db->prepare(
        'SELECT s.department,
                COUNT(DISTINCT s.id) AS sessions,
                COUNT(q.id)          AS queue_total,
                SUM(CASE WHEN q.status = "in_progress" THEN 1 ELSE 0 END) AS in_progress,
                SUM(CASE WHEN q.status = "checked_in"  THEN 1 ELSE 0 END) AS waiting
           FROM interview_slots s
           LEFT JOIN interview_queue q ON q.slot_id = s.id
          WHERE s.slot_date = ?
          GROUP BY s.department'
    );
    $stmt->execute([$today]);
    foreach ($stmt->fetchAll() as $row) {
        $qCounts[$row['department']] = [
            'queue'       => (int) $row['queue_total'],
            'sessions'    => (int) $row['sessions'],
            'in_progress' => (int) $row['in_progress'],
            'waiting'     => (int) $row['waiting'],
        ];
    }
} catch (\Throwable $e) { /* non-fatal */ }

ob_start();
?>

<div style="margin-bottom:var(--space-5)">
    <a href="<?= url('/staff/interviews') ?>" class="btn btn-ghost btn-sm">&larr; Back</a>
</div>

<style>
@keyframes q-pulse-dot {
    0%,100% { opacity: 1; transform: scale(1); }
    50%     { opacity: .5; transform: scale(1.3); }
}
.q-college-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
    gap: var(--space-4);
    max-width: 900px;
    margin: 0 auto;
}
.q-college-card {
    display: flex; flex-direction: column; align-items: center; text-align: center;
    gap: var(--space-3); padding: var(--space-8) var(--space-5);
    background: var(--bg-elevated); border: 1.5px solid var(--border);
    border-radius: var(--radius-lg);
    text-decoration: none; color: var(--text-primary);
    transition: border-color .18s, box-shadow .18s, transform .15s;
    cursor: pointer;
}
.q-college-card:hover {
    border-color: var(--accent);
    box-shadow: 0 6px 20px rgba(0,0,0,.07);
    transform: translateY(-3px);
}
.q-college-card.is-live { border-color: var(--accent); }
.q-college-card-icon {
    width: 50px; height: 50px; border-radius: var(--radius-lg);
    background: var(--accent-muted); color: var(--accent);
    display: flex; align-items: center; justify-content: center;
}
.q-college-card-name {
    font-size: var(--text-sm); font-weight: var(--weight-semibold);
    color: var(--text-primary); line-height: 1.3;
}
.q-college-card-meta {
    font-size: var(--text-xs); color: var(--text-tertiary);
    display: flex; align-items: center; gap: var(--space-2); flex-wrap: wrap; justify-content: center;
}
.q-college-card-live {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 2px 8px; border-radius: 999px;
    background: var(--accent-muted); color: var(--accent);
    font-size: 10px; font-weight: var(--weight-semibold);
    text-transform: uppercase; letter-spacing: .05em;
}
.q-college-card-live .dot {
    width: 6px; height: 6px; border-radius: 50%;
    background: var(--accent);
    animation: q-pulse-dot 1.4s infinite;
}
</style>

<?php if (empty($departments)): ?>
    <div style="text-align:center;padding:var(--space-16);color:var(--text-tertiary);font-size:var(--text-sm)">
        No colleges / departments configured in the system.
    </div>
<?php else: ?>
    <div class="q-college-grid">
        <?php foreach ($departments as $dept):
            $info  = $qCounts[$dept] ?? ['queue' => 0, 'sessions' => 0, 'in_progress' => 0, 'waiting' => 0];
            $live  = $info['in_progress'] > 0 || $info['waiting'] > 0;
        ?>
            <a href="<?= url('/staff/interviews/queue') ?>?college=<?= urlencode($dept) ?>"
               class="q-college-card <?= $live ? 'is-live' : '' ?>">
                <div class="q-college-card-icon">
                    <?= icon('ic_fluent_building_bank_24_regular', 24) ?>
                </div>
                <div class="q-college-card-name"><?= e($dept) ?></div>
                <div class="q-college-card-meta">
                    <?php if ($info['in_progress'] > 0): ?>
                        <span class="q-college-card-live"><span class="dot"></span> Live</span>
                    <?php endif; ?>
                    <?= $info['sessions'] ?> session<?= $info['sessions'] !== 1 ? 's' : '' ?> today
                    <?php if ($info['queue'] > 0): ?>
                        &nbsp;&middot;&nbsp; <?= $info['queue'] ?> applicant<?= $info['queue'] !== 1 ? 's' : '' ?>
                    <?php endif; ?>
                </div>
            </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php
$content   = ob_get_clean();
$pageTitle = 'Live Queue';
$activeNav = 'interviews';
include VIEWS_PATH . '/layouts/app.php';
