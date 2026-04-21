<?php
// views/partials/nav_admin.php
$nav = $activeNav ?? '';

$staff = [
    ['href' => '/admin/dashboard',   'key' => 'dashboard',   'label' => 'Dashboard',     'icon' => 'ic_fluent_home_24_regular'],
    ['href' => '/staff/applicants',  'key' => 'applicants',  'label' => 'Applicants',    'icon' => 'ic_fluent_people_24_regular'],
    ['href' => '/staff/exam',        'key' => 'exam',        'label' => 'Exam Manager',  'icon' => 'ic_fluent_edit_24_regular'],
    ['href' => '/staff/interviews',  'key' => 'interviews',  'label' => 'Interviews',    'icon' => 'ic_fluent_calendar_ltr_24_regular'],
    ['href' => '/staff/results',     'key' => 'results',     'label' => 'Release Results','icon' => 'ic_fluent_ribbon_star_24_regular'],
];

$admin = [
    ['href' => '/admin/users',       'key' => 'users',       'label' => 'User Accounts', 'icon' => 'ic_fluent_shield_24_regular'],
    ['href' => '/admin/school-year', 'key' => 'school-year', 'label' => 'School Year',   'icon' => 'ic_fluent_arrow_sync_24_regular'],
    ['href' => '/admin/audit-log',   'key' => 'audit-log',   'label' => 'Audit Log',     'icon' => 'ic_fluent_eye_show_24_regular'],
    ['href' => '/admin/settings',    'key' => 'settings',    'label' => 'Settings',      'icon' => 'ic_fluent_settings_24_regular'],
];
?>
<div class="nav-section-label">Admissions</div>
<?php foreach ($staff as $item): ?>
    <a href="<?= url($item['href']) ?>"
       class="nav-item <?= $nav === $item['key'] ? 'active' : '' ?>">
        <?php include __DIR__ . '/icons/' . $item['icon'] . '.svg'; ?>
        <?= e($item['label']) ?>
    </a>
<?php endforeach; ?>

<div class="nav-section-label" style="margin-top:var(--space-2)">Admin</div>
<?php foreach ($admin as $item): ?>
    <a href="<?= url($item['href']) ?>"
       class="nav-item <?= $nav === $item['key'] ? 'active' : '' ?>">
        <?php include __DIR__ . '/icons/' . $item['icon'] . '.svg'; ?>
        <?= e($item['label']) ?>
    </a>
<?php endforeach; ?>