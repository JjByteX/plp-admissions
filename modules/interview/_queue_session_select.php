<?php
// ============================================================
// _queue_session_select.php
// Admin → after picking a college, list today's sessions for
// that college as cards. Each card opens that session's queue.
// Expects: $db, $today, $selectedCollege.
// ============================================================

$stmt = $db->prepare(
    'SELECT s.id, s.slot_date, s.slot_time, s.end_time,
            s.location_label, s.location_notes, s.capacity, s.status,
            u.name AS interviewer_name,
            COUNT(q.id) AS queue_total,
            SUM(CASE WHEN q.status = "in_progress" THEN 1 ELSE 0 END) AS in_progress,
            SUM(CASE WHEN q.status = "checked_in"  THEN 1 ELSE 0 END) AS waiting,
            SUM(CASE WHEN q.status = "scheduled"   THEN 1 ELSE 0 END) AS scheduled,
            SUM(CASE WHEN q.status = "completed"   THEN 1 ELSE 0 END) AS completed,
            SUM(CASE WHEN q.status = "no_show"     THEN 1 ELSE 0 END) AS no_show
       FROM interview_slots s
       LEFT JOIN users u           ON u.id = s.assigned_to
       LEFT JOIN interview_queue q ON q.slot_id = s.id
      WHERE s.department = ?
        AND s.slot_date  = ?
      GROUP BY s.id
      ORDER BY s.slot_time ASC'
);
$stmt->execute([$selectedCollege, $today]);
$sessions = $stmt->fetchAll();

ob_start();
?>

<div style="margin-bottom:var(--space-5)">
    <a href="<?= url('/staff/interviews/queue') ?>" class="btn btn-ghost btn-sm">&larr; Back</a>
</div>

<style>
@keyframes q-pulse-dot {
    0%,100% { opacity: 1; transform: scale(1); }
    50%     { opacity: .5; transform: scale(1.3); }
}
.q-sess-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
    gap: var(--space-4);
}
.q-sess-card {
    display: flex; flex-direction: column;
    gap: var(--space-3); padding: var(--space-5);
    background: var(--bg-elevated); border: 1.5px solid var(--border);
    border-radius: var(--radius-lg);
    text-decoration: none; color: var(--text-primary);
    transition: border-color .15s, box-shadow .15s;
    position: relative; cursor: pointer; min-height: 220px;
}
.q-sess-card:hover { border-color: var(--accent); box-shadow: var(--shadow-sm); }
.q-sess-card.is-live { border-color: var(--accent); background: var(--accent-muted); }
.q-sess-card-title {
    font-size: var(--text-base); font-weight: var(--weight-semibold);
    color: var(--text-primary); line-height: 1.35;
    padding-right: 70px;
}
.q-sess-meta {
    display: flex; flex-direction: column; gap: 4px;
    font-size: var(--text-xs); color: var(--text-tertiary);
}
.q-sess-meta-row { display: flex; align-items: center; gap: 5px; }
.q-sess-stats {
    display: grid; grid-template-columns: repeat(4, 1fr);
    gap: var(--space-2); margin-top: auto; padding-top: var(--space-3);
    border-top: 1px solid var(--border);
}
.q-sess-stat { text-align: center; }
.q-sess-stat-num {
    font-size: var(--text-base); font-weight: var(--weight-semibold);
    line-height: 1;
}
.q-sess-stat-label {
    font-size: 10px; color: var(--text-tertiary);
    text-transform: uppercase; letter-spacing: .05em;
    margin-top: 2px;
}
.q-sess-live-badge {
    position: absolute; top: var(--space-4); right: var(--space-4);
    display: inline-flex; align-items: center; gap: 4px;
    padding: 2px 8px; border-radius: 999px;
    background: var(--accent); color: #fff;
    font-size: 10px; font-weight: var(--weight-semibold);
    text-transform: uppercase; letter-spacing: .05em;
}
.q-sess-live-badge .dot {
    width: 6px; height: 6px; border-radius: 50%;
    background: #fff;
    animation: q-pulse-dot 1.4s infinite;
}
</style>

<?php if (empty($sessions)): ?>
    <div style="padding:var(--space-8) var(--space-4);background:var(--bg-subtle);
                 border-radius:var(--radius-md);text-align:center;color:var(--text-tertiary)">
        <?= icon('ic_fluent_calendar_24_regular', 32, 'color:var(--text-tertiary)') ?>
        <div style="margin-top:var(--space-3);font-size:var(--text-sm)">
            No sessions scheduled for <?= e($selectedCollege) ?> today.
        </div>
        <div style="margin-top:var(--space-2);font-size:var(--text-xs)">
            <a href="<?= url('/staff/interviews/setup') ?>?college=<?= urlencode($selectedCollege) ?>"
               style="color:var(--accent)">
                Add a session in setup &rarr;
            </a>
        </div>
    </div>
<?php else: ?>
    <div class="q-sess-grid">
        <?php foreach ($sessions as $s):
            $isLive    = ((int) $s['in_progress']) > 0;
            $href      = url('/staff/interviews/queue') . '?slot=' . (int) $s['id'];
            $timeLabel = $s['slot_time'] ? date('g:i A', strtotime($s['slot_time'])) : '—';
            if ($s['end_time']) {
                $timeLabel .= ' &ndash; ' . date('g:i A', strtotime($s['end_time']));
            }
        ?>
            <a href="<?= e($href) ?>" class="q-sess-card <?= $isLive ? 'is-live' : '' ?>">
                <?php if ($isLive): ?>
                    <div class="q-sess-live-badge"><span class="dot"></span> Live</div>
                <?php endif; ?>
                <div class="q-sess-card-title">
                    <?= e($s['interviewer_name'] ?? 'Unassigned') ?>
                </div>
                <div class="q-sess-meta">
                    <div class="q-sess-meta-row">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none">
                            <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2"/>
                            <path stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M12 7v5l3 3"/>
                        </svg>
                        <?= $timeLabel ?>
                    </div>
                    <?php if (!empty($s['location_label'])): ?>
                        <div class="q-sess-meta-row">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none">
                                <path stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                                      d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/>
                                <circle cx="12" cy="10" r="3" stroke="currentColor" stroke-width="2"/>
                            </svg>
                            <?= e($s['location_label']) ?>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="q-sess-stats">
                    <div class="q-sess-stat">
                        <div class="q-sess-stat-num" style="color:var(--info)"><?= (int) $s['waiting'] ?></div>
                        <div class="q-sess-stat-label">Waiting</div>
                    </div>
                    <div class="q-sess-stat">
                        <div class="q-sess-stat-num" style="color:var(--accent)"><?= (int) $s['in_progress'] ?></div>
                        <div class="q-sess-stat-label">Live</div>
                    </div>
                    <div class="q-sess-stat">
                        <div class="q-sess-stat-num" style="color:var(--success)"><?= (int) $s['completed'] ?></div>
                        <div class="q-sess-stat-label">Done</div>
                    </div>
                    <div class="q-sess-stat">
                        <div class="q-sess-stat-num" style="color:var(--error)"><?= (int) $s['no_show'] ?></div>
                        <div class="q-sess-stat-label">No-show</div>
                    </div>
                </div>
            </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php
$content   = ob_get_clean();
$pageTitle = 'Live Queue · ' . $selectedCollege;
$activeNav = 'interviews';
include VIEWS_PATH . '/layouts/app.php';
