<?php
// views/partials/nav_staff.php
// Professor sidebar — only the pages a Professor can actually open.
// (Admin / SSO / Dean use nav_admin.php with their own per-role filter.)
//
// Per the role redesign:
//   Documents       → SSO / Admin (Professor cannot review)
//   Exam Builder    → SSO / Admin (Professor has no exam content access)
//   Exam Slots      → Proctor role only (Professor no longer has exam access)
//   Interview Queue → Professor (their own desk's queue)
//   Results / Audit → out of scope for Professor

$nav = $activeNav ?? '';

// Light readiness check — interview slot existence drives the red-dot
// indicator next to "Interview Queue" so the Professor knows when
// nothing is scheduled yet.
$_navDb       = db();
$_navIntReady = (int)$_navDb->query(
    "SELECT COUNT(*) FROM interview_slots WHERE slot_date >= CURRENT_DATE"
)->fetchColumn() > 0;

// Flat list — only Interview Queue now. Dashboard removed;
// Staff (Professor) role lands directly on the Interview Queue.
$items = [
    ['href' => '/staff/interviews/queue',  'key' => 'interviews', 'label' => 'Interview Queue', 'icon' => 'users',     'alert' => !$_navIntReady],
];

// Rendered by the shared sidebar partial (collapse / expand markup lives there).
$sidebarItems = $items;
include __DIR__ . '/sidebar_nav_items.php';
