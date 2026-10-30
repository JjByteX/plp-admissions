<?php
// ============================================================
// modules/interview/staff_manage.php
// Landing page — two entry-point cards:
//   1. Interview Setup (colleges → desks → schedules)
//   2. Interview Queue (live calling + inline evaluation)
// ============================================================

require_once CORE_PATH . '/bootstrap.php';
Auth::requireRole(ROLE_STAFF, ROLE_SSO, ROLE_DEAN, ROLE_ADMIN);

// SSO is setup-only — they have no need for the two-card Setup vs Queue
// landing, and they have no access to the queue at all. Send them
// straight into Interview Setup so the sidebar item lands one click
// closer to where they actually work.
if (Auth::role() === ROLE_SSO) {
    redirect('/staff/interviews/setup');
}

$db      = db();
$staffId = Auth::id();
$isAdmin = Auth::role() === ROLE_ADMIN;
$today   = now_date();

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
// Same visibility as the Queue page: admin sees every session, a dean sees
// their college, everyone else only the sessions they own.
$todayScheduled = 0;
$todayWaiting = 0;
$todayInProgress = 0;
$scopeSql    = ' AND COALESCE(s.assigned_to, s.created_by) = ?';
$scopeParams = [$staffId];
if ($isAdmin) {
    $scopeSql = ''; $scopeParams = [];
} elseif (StaffScope::current()->restricted() && StaffScope::current()->department() !== '') {
    $scopeSql = ' AND s.department = ?'; $scopeParams = [StaffScope::current()->department()];
}
$todayStmt = $db->prepare(
    "SELECT SUM(CASE WHEN q.status = 'scheduled'   THEN 1 ELSE 0 END) AS scheduled,
            SUM(CASE WHEN q.status = 'checked_in'  THEN 1 ELSE 0 END) AS waiting,
            SUM(CASE WHEN q.status = 'in_progress' THEN 1 ELSE 0 END) AS in_progress
     FROM   interview_queue q
     JOIN   interview_slots s ON s.id = q.slot_id
     WHERE  s.slot_date = ?" . $scopeSql
);
$todayStmt->execute(array_merge([$today], $scopeParams));
$todayRow = $todayStmt->fetch();
if ($todayRow) {
    $todayScheduled  = (int)($todayRow['scheduled'] ?? 0);
    $todayWaiting    = (int)($todayRow['waiting'] ?? 0);
    $todayInProgress = (int)($todayRow['in_progress'] ?? 0);
}
$todayActive = $todayWaiting + $todayInProgress;

ob_start();
?>

<?php
// No interview sessions at all means setup hasn't happened yet, and the Queue
// page has nothing to drive: dim the card and route the click into Setup so
// the user is led through the only useful next step.
$queueDisabled = ($totalSessions === 0);
$queueHref     = $queueDisabled
    ? url('/staff/interviews/setup')
    : url('/staff/interviews/queue');
$queueClasses  = 'select-card' . ($queueDisabled ? ' is-disabled' : '');
$queueTitle    = $queueDisabled
    ? 'Set up interview sessions before opening the queue.'
    : '';
?>

<div class="select-grid">

    <!-- Interview Setup -->
    <a href="<?= url('/staff/interviews/setup') ?>" class="select-card">
        <div class="select-card-title">Interview Setup</div>
        <div class="select-card-desc">Schedule sessions and assign interviewers per college.</div>
        <div class="select-card-meta">
            <div class="select-card-meta-row">
                <?= $totalUpcoming ?> upcoming session<?= $totalUpcoming !== 1 ? 's' : '' ?>
                <?php if ($totalSessions !== $totalUpcoming): ?>
                    &nbsp;&middot;&nbsp; <?= $totalSessions ?> total
                <?php endif; ?>
            </div>
        </div>
    </a>

    <!-- Interview Queue -->
    <a href="<?= e($queueHref) ?>" class="<?= e($queueClasses) ?><?= ($todayActive > 0 && !$queueDisabled) ? ' is-live' : '' ?>"
       <?php if ($queueDisabled): ?>aria-disabled="true" title="<?= e($queueTitle) ?>"<?php endif; ?>>
        <?php if ($todayActive > 0 && !$queueDisabled): ?>
            <span class="select-card-badge"><span class="select-card-live"><span class="dot"></span> Live</span></span>
        <?php endif; ?>
        <div class="select-card-title">Interview Queue</div>
        <div class="select-card-desc">
            <?= $queueDisabled
                ? 'Set up sessions first to start calling students.'
                : 'Call students and record evaluations.' ?>
        </div>
        <div class="select-card-meta">
            <div class="select-card-meta-row">
                <?php if ($queueDisabled): ?>
                    Setup required
                <?php elseif ($todayActive > 0): ?>
                    <?= $todayWaiting ?> waiting · <?= $todayInProgress ?> in progress
                <?php elseif ($todayScheduled > 0): ?>
                    <?= $todayScheduled ?> scheduled today · none checked in yet
                <?php else: ?>
                    No interviews today
                <?php endif; ?>
            </div>
        </div>
    </a>

</div>

<?php
$content   = ob_get_clean();
$pageTitle = 'Interview Sessions';
$pageWide  = true; // every picker page uses the same wide container
$activeNav = 'interviews';
include VIEWS_PATH . '/layouts/app.php';
