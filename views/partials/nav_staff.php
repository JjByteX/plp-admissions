<?php
// views/partials/nav_staff.php
$nav = $activeNav ?? '';

// Readiness checks for red-dot indicators
$_navDb = db();
$_navSY = school_setting('current_school_year', date('Y').'-'.(date('Y')+1));
$_navExamReady = (int)$_navDb->query('SELECT COUNT(*) FROM exams WHERE is_active=1')->fetchColumn() > 0;
$_navSlotStmt  = $_navDb->prepare('SELECT COUNT(*) FROM exam_slot_schedule WHERE school_year=?');
$_navSlotStmt->execute([$_navSY]);
$_navExamReady = $_navExamReady && (int)$_navSlotStmt->fetchColumn() > 0;
$_navIntReady  = (int)$_navDb->query("SELECT COUNT(*) FROM interview_slots WHERE slot_date >= CURDATE()")->fetchColumn() > 0;

$items = [
    ['href' => '/staff/dashboard',   'key' => 'dashboard',   'label' => 'Dashboard',      'icon' => 'ic_fluent_home_24_regular'],
    ['href' => '/staff/applicants',  'key' => 'applicants',  'label' => 'Applicants',     'icon' => 'ic_fluent_people_24_regular'],
    ['href' => '/staff/exam',        'key' => 'exam',        'label' => 'Exams',          'icon' => 'ic_fluent_edit_24_regular',      'alert' => !$_navExamReady],
    ['href' => '/staff/interviews',  'key' => 'interviews',  'label' => 'Interviews',     'icon' => 'ic_fluent_calendar_ltr_24_regular', 'alert' => !$_navIntReady],
    ['href' => '/staff/results',     'key' => 'results',     'label' => 'Results',        'icon' => 'ic_fluent_ribbon_star_24_regular'],
    ['href' => '/staff/audit-log',   'key' => 'audit-log',   'label' => 'Audit Log',      'icon' => 'ic_fluent_eye_show_24_regular'],
];
?>
<div class="nav-section-label">Management</div>
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