<?php
// views/partials/nav_staff.php
// Professor sidebar — only the pages a Professor can actually open.
// (Admin / SSO / Dean use nav_admin.php with their own per-role filter.)
//
// Per the role redesign:
//   Documents       → SSO / Admin (Professor cannot review)
//   Exam Builder    → SSO / Admin (Professor has no exam content access)
//   Exam Slots      → Professor in read-only (room sheet visibility)
//   Interview Queue → Professor (their own desk's queue)
//   Results / Audit → out of scope for Professor

$nav = $activeNav ?? '';

// Light readiness check — interview slot existence drives the red-dot
// indicator next to "Interview Queue" so the Professor knows when
// nothing is scheduled yet.
$_navDb       = db();
$_navIntReady = (int)$_navDb->query(
    "SELECT COUNT(*) FROM interview_slots WHERE slot_date >= CURDATE()"
)->fetchColumn() > 0;

// Flat list — only 3 items, no section label needed.
$items = [
    ['href' => '/staff/dashboard',         'key' => 'dashboard',  'label' => 'Dashboard',       'icon' => 'ic_fluent_home_24_regular'],
    ['href' => '/staff/interviews/queue',  'key' => 'interviews', 'label' => 'Interview Queue', 'icon' => 'ic_fluent_people_24_regular',     'alert' => !$_navIntReady],
    ['href' => '/staff/exam/slots',        'key' => 'exam',       'label' => 'Exam Slots',      'icon' => 'ic_fluent_calendar_ltr_24_regular'],
];
?>
<?php foreach ($items as $item): ?>
    <a href="<?= url($item['href']) ?>"
       class="nav-item <?= $nav === $item['key'] ? 'active' : '' ?>"
       aria-current="<?= $nav === $item['key'] ? 'page' : 'false' ?>">
        <?php include __DIR__ . '/icons/' . $item['icon'] . '.svg'; ?>
        <?= e($item['label']) ?>
        <?php if (!empty($item['alert'])): ?>
            <span style="display:inline-block;width:6px;height:6px;border-radius:50%;background:var(--error);margin-left:auto;flex-shrink:0" title="Needs setup"></span>
        <?php endif; ?>
    </a>
<?php endforeach; ?>
