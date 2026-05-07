<?php
// ============================================================
// modules/interview/staff_manage.php
// Landing page — two entry-point cards:
//   1. Interview Setup (colleges → desks → schedules)
//   2. Interview Queue (live calling + inline evaluation)
// ============================================================

require_once CORE_PATH . '/bootstrap.php';
Auth::requireRole(ROLE_STAFF, ROLE_ADMIN);

$db      = db();
$staffId = Auth::id();
$isAdmin = Auth::role() === ROLE_ADMIN;
$today   = date('Y-m-d');

// Graceful schema upgrade: ensure new columns exist on interview_slots.
// (Desks have been merged into sessions — assigned_to/location_label/location_notes
// live directly on interview_slots now.)
foreach ([
    ['assigned_to',    'INT(10) UNSIGNED DEFAULT NULL AFTER created_by'],
    ['location_label', 'VARCHAR(120) NOT NULL DEFAULT "" AFTER assigned_to'],
    ['location_notes', 'TEXT DEFAULT NULL AFTER location_label'],
] as $col) {
    try { $db->query("SELECT {$col[0]} FROM interview_slots LIMIT 0"); }
    catch (\Throwable $e) {
        try { $db->exec("ALTER TABLE interview_slots ADD COLUMN {$col[0]} {$col[1]}"); }
        catch (\Throwable $e2) {}
    }
}

// Stats for landing cards
$upcomingStmt = $db->prepare(
    'SELECT COUNT(*) FROM interview_slots WHERE slot_date >= ?'
);
$upcomingStmt->execute([$today]);
$totalUpcoming = (int)$upcomingStmt->fetchColumn();

$totalSessionsStmt = $db->prepare('SELECT COUNT(*) FROM interview_slots');
$totalSessionsStmt->execute();
$totalSessions = (int)$totalSessionsStmt->fetchColumn();

// Queue stats for today (across sessions this staff is the interviewer for,
// falling back to created_by for legacy rows that have no assigned_to).
$todayWaiting = 0;
$todayInProgress = 0;
$todayStmt = $db->prepare(
    'SELECT SUM(q.status = "checked_in") AS waiting,
            SUM(q.status = "in_progress") AS in_progress
     FROM   interview_queue q
     JOIN   interview_slots s ON s.id = q.slot_id
     WHERE  s.slot_date = ?
       AND  COALESCE(s.assigned_to, s.created_by) = ?'
);
$todayStmt->execute([$today, $staffId]);
$todayRow = $todayStmt->fetch();
if ($todayRow) {
    $todayWaiting    = (int)($todayRow['waiting'] ?? 0);
    $todayInProgress = (int)($todayRow['in_progress'] ?? 0);
}
$todayActive = $todayWaiting + $todayInProgress;

ob_start();
?>

<style>
    @keyframes pulse-dot {
        0%,100%{opacity:1;transform:scale(1)}
        50%{opacity:.5;transform:scale(1.3)}
    }
    .intv-landing-wrap {
        display:flex;align-items:center;justify-content:center;
        min-height:calc(100vh - 200px);padding-bottom:var(--space-16);
    }
    .intv-landing-grid {
        display:grid;grid-template-columns:1fr 1fr;gap:var(--space-6);
        max-width:640px;width:100%;
    }
    .intv-landing-card {
        display:flex;flex-direction:column;align-items:center;text-align:left;
        gap:var(--space-4);padding:var(--space-10) var(--space-6);
        background:var(--bg-elevated);border:1.5px solid var(--border);
        border-radius:var(--radius-lg);text-decoration:none;color:var(--text-primary);
        transition:border-color .18s,box-shadow .18s,transform .15s;cursor:pointer;
    }
    .intv-landing-card:hover {
        border-color:var(--accent);box-shadow:0 8px 24px rgba(0,0,0,.08);transform:translateY(-4px);
    }
    .intv-landing-icon {
        width:60px;height:60px;border-radius:var(--radius-lg);
        background:var(--accent-muted);color:var(--accent);
        display:flex;align-items:center;justify-content:center;flex-shrink:0;
    }
    .intv-landing-title {
        font-size:var(--text-lg);font-weight:var(--weight-semibold);
        color:var(--text-primary);letter-spacing:-0.2px;margin-top:var(--space-1);
    }
    .intv-landing-desc {
        font-size:var(--text-sm);color:var(--text-secondary);line-height:1.55;
        margin-top:var(--space-1);
    }
    .intv-landing-meta {
        font-size:var(--text-xs);color:var(--text-tertiary);
        display:flex;align-items:center;gap:var(--space-2);
        justify-content:center;flex-wrap:wrap;margin-top:var(--space-2);
    }
    @media (max-width:640px) { .intv-landing-grid { grid-template-columns:1fr;max-width:320px; } }
</style>

<div class="intv-landing-wrap">
<div class="intv-landing-grid">

    <!-- Interview Setup (left) -->
    <a href="<?= url('/staff/interviews/setup') ?>" class="intv-landing-card">
        <div class="intv-landing-icon">
            <?= icon('ic_fluent_settings_24_regular', 28) ?>
        </div>
        <div class="intv-landing-title">Interview Setup</div>
        <div class="intv-landing-desc">
            Schedule sessions and assign interviewers per college.
        </div>
        <div class="intv-landing-meta">
            <?= icon('ic_fluent_calendar_ltr_24_regular', 13) ?>
            <?= $totalUpcoming ?> upcoming session<?= $totalUpcoming !== 1 ? 's' : '' ?>
            <?php if ($totalSessions !== $totalUpcoming): ?>
                &nbsp;·&nbsp;
                <?= $totalSessions ?> total
            <?php endif; ?>
        </div>
    </a>

    <!-- Interview Queue (right) -->
    <a href="<?= url('/staff/interviews/queue') ?>" class="intv-landing-card">
        <div class="intv-landing-icon">
            <?php if ($todayActive > 0): ?>
                <span style="display:inline-block;width:10px;height:10px;border-radius:50%;
                              background:var(--accent);animation:pulse-dot 1.8s ease-in-out infinite;
                              position:absolute;top:12px;right:12px"></span>
            <?php endif; ?>
            <?= icon('ic_fluent_people_24_regular', 28) ?>
        </div>
        <div class="intv-landing-title">Interview Queue</div>
        <div class="intv-landing-desc">
            Call students and record evaluations.
        </div>
        <div class="intv-landing-meta">
            <?php if ($todayActive > 0): ?>
                <span style="display:inline-block;width:6px;height:6px;border-radius:50%;
                              background:var(--accent);animation:pulse-dot 1.8s ease-in-out infinite"></span>
                <?= $todayWaiting ?> waiting · <?= $todayInProgress ?> in progress
            <?php else: ?>
                <?= icon('ic_fluent_people_24_regular', 13) ?>
                No active interviews
            <?php endif; ?>
        </div>
    </a>

</div>
</div>

<?php
$content   = ob_get_clean();
$pageTitle = 'Interview Sessions';
$activeNav = 'interviews';
include VIEWS_PATH . '/layouts/app.php';
