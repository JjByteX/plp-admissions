<?php
// views/partials/nav_proctor.php
// Proctor sidebar — only the pages a Proctor can actually open.
// (Admin / SSO / Dean use nav_admin.php with their own per-role filter.)
//
// Per the role redesign:
//   Documents       → SSO / Admin (Proctor cannot review)
//   Exam Builder    → SSO / Admin (Proctor has no exam content access)
//   Exam Slots      → Proctor (generate access codes for their department)
//   Interviews      → out of scope for Proctor (Professor handles interviews)
//   Results / Audit → out of scope for Proctor

$nav = $activeNav ?? '';

// Light readiness check — exam slot existence drives the red-dot
// indicator next to "Exam Slots" so the Proctor knows when
// nothing is scheduled yet.
$_navDb       = db();
$_navSY       = school_setting('current_school_year', date('Y').'-'.(date('Y')+1));
$_navSlotStmt = $_navDb->prepare('SELECT COUNT(*) FROM exam_slot_schedule WHERE school_year=?');
$_navSlotStmt->execute([$_navSY]);
$_navExamReady = (int)$_navSlotStmt->fetchColumn() > 0;

// Single item — Proctor goes straight to Exam Slots (no dashboard).
$items = [
    ['href' => '/staff/exam/slots',  'key' => 'exam',      'label' => 'Exam Slots', 'icon' => 'calendar-blank', 'alert' => !$_navExamReady],
];

// Rendered by the shared sidebar partial (collapse / expand markup lives there).
$sidebarItems = $items;
include __DIR__ . '/sidebar_nav_items.php';
