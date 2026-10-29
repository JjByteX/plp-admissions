<?php
// ============================================================
// _queue_session_select.php
// Admin → after picking a college, list today's sessions for
// that college as cards. Each card opens that session's queue.
// Expects: $db, $today, $selectedCollege.
// ============================================================

$stmt = $db->prepare(
    "SELECT s.id, s.slot_date, s.slot_time, s.end_time,
            s.location_label, s.location_notes, s.capacity, s.status,
            u.name AS interviewer_name,
            COUNT(q.id) AS queue_total,
            SUM(CASE WHEN q.status = 'in_progress' THEN 1 ELSE 0 END) AS in_progress,
            SUM(CASE WHEN q.status = 'checked_in'  THEN 1 ELSE 0 END) AS waiting,
            SUM(CASE WHEN q.status = 'scheduled'   THEN 1 ELSE 0 END) AS scheduled,
            SUM(CASE WHEN q.status = 'completed'   THEN 1 ELSE 0 END) AS completed,
            SUM(CASE WHEN q.status = 'no_show'     THEN 1 ELSE 0 END) AS no_show
       FROM interview_slots s
       LEFT JOIN users u           ON u.id = s.assigned_to
       LEFT JOIN interview_queue q ON q.slot_id = s.id
      WHERE s.department = ?
        AND s.slot_date  = ?
      GROUP BY s.id, u.name
      ORDER BY s.slot_time ASC NULLS FIRST"
);
$stmt->execute([$selectedCollege, $today]);
$sessions = $stmt->fetchAll();

ob_start();
?>


<?php if (empty($sessions)): ?>
    <div class="select-empty">
        <div style="margin-top:var(--space-3)">
            No sessions scheduled for <?= e($selectedCollege) ?> today.
        </div>
        <div style="margin-top:var(--space-2);font-size:var(--text-xs)">
            <a href="<?= url('/staff/interviews/setup') ?>?college=<?= urlencode($selectedCollege) ?>"
               style="color:var(--accent)">
                Add a session in setup
            </a>
        </div>
    </div>
<?php else: ?>
    <div class="select-grid q-sess-grid">
        <?php foreach ($sessions as $s):
            $isLive    = ((int) $s['in_progress']) > 0;
            $href      = url('/staff/interviews/queue') . '?slot=' . (int) $s['id'];
            $timeLabel = $s['slot_time'] ? date('g:i A', strtotime($s['slot_time'])) : '-';
            if ($s['end_time']) {
                $timeLabel .= ' &ndash; ' . date('g:i A', strtotime($s['end_time']));
            }
        ?>
            <a href="<?= e($href) ?>" class="select-card q-sess-card <?= $isLive ? 'is-live' : '' ?>">
                <?php if ($isLive): ?>
                    <span class="select-card-badge"><span class="select-card-live"><span class="dot"></span> Live</span></span>
                <?php endif; ?>
                <div class="select-card-title">
                    <?= e($s['interviewer_name'] ?? 'Unassigned') ?>
                </div>
                <div class="select-card-meta">
                    <div class="select-card-meta-row">
                        <?= $timeLabel ?>
                    </div>
                    <?php if (!empty($s['location_label'])): ?>
                        <div class="select-card-meta-row">
                            <?= e($s['location_label']) ?>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="q-sess-stats">
                    <div class="q-sess-stat">
                        <div class="q-sess-stat-num"><?= (int) $s['waiting'] ?></div>
                        <div class="q-sess-stat-label">Waiting</div>
                    </div>
                    <div class="q-sess-stat">
                        <div class="q-sess-stat-num"><?= (int) $s['in_progress'] ?></div>
                        <div class="q-sess-stat-label">Live</div>
                    </div>
                    <div class="q-sess-stat">
                        <div class="q-sess-stat-num"><?= (int) $s['completed'] ?></div>
                        <div class="q-sess-stat-label">Done</div>
                    </div>
                    <div class="q-sess-stat">
                        <div class="q-sess-stat-num"<?= ((int) $s['no_show']) > 0 ? ' style="color:var(--error)"' : '' ?>><?= (int) $s['no_show'] ?></div>
                        <div class="q-sess-stat-label">No-show</div>
                    </div>
                </div>
            </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php
$content   = ob_get_clean();
$pageTitle = 'Live Queue';
$pageHeading = $selectedCollege; // heading = the college, like every other picker's second level
$pageBreadcrumb = [
    ['label' => 'Interview Sessions', 'to' => '/staff/interviews'],
    ['label' => 'Live Queue',         'to' => '/staff/interviews/queue'],
];
$activeNav = 'interviews';
$pageWide  = true;
$pageStyles = ['css/pages/queue-session-select.css'];
include VIEWS_PATH . '/layouts/app.php';
