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
    ['href' => '/staff/exam/slots',  'key' => 'exam',      'label' => 'Exam Slots', 'icon' => 'ic_fluent_calendar_ltr_24_regular', 'alert' => !$_navExamReady],
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
