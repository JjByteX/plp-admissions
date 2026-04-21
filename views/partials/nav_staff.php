<?php
// views/partials/nav_staff.php
$nav = $activeNav ?? '';

$items = [
    ['href' => '/staff/dashboard',   'key' => 'dashboard',   'label' => 'Dashboard',      'icon' => 'ic_fluent_home_24_regular'],
    ['href' => '/staff/applicants',  'key' => 'applicants',  'label' => 'Applicants',     'icon' => 'ic_fluent_people_24_regular'],
    ['href' => '/staff/exam',        'key' => 'exam',        'label' => 'Exam Manager',   'icon' => 'ic_fluent_edit_24_regular'],
    ['href' => '/staff/interviews',  'key' => 'interviews',  'label' => 'Interviews',     'icon' => 'ic_fluent_calendar_ltr_24_regular'],
    ['href' => '/staff/results',     'key' => 'results',     'label' => 'Release Results','icon' => 'ic_fluent_ribbon_star_24_regular'],
    ['href' => '/staff/audit-log',   'key' => 'audit-log',   'label' => 'Audit Log',      'icon' => 'ic_fluent_eye_show_24_regular'],
    ['href' => '/staff/settings',    'key' => 'settings',    'label' => 'Settings',       'icon' => 'ic_fluent_settings_24_regular'],
];
?>
<div class="nav-section-label">Management</div>
<?php foreach ($items as $item): ?>
    <a href="<?= url($item['href']) ?>"
       class="nav-item <?= $nav === $item['key'] ? 'active' : '' ?>"
       aria-current="<?= $nav === $item['key'] ? 'page' : 'false' ?>">
        <?php include __DIR__ . '/icons/' . $item['icon'] . '.svg'; ?>
        <?= e($item['label']) ?>
    </a>
<?php endforeach; ?>