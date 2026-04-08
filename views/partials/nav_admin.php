<?php
// views/partials/nav_admin.php
$nav = $activeNav ?? '';

$staff = [
    ['href' => '/admin/dashboard',   'key' => 'dashboard',   'label' => 'Dashboard',     'icon' => 'home'],
    ['href' => '/staff/applicants',  'key' => 'applicants',  'label' => 'Applicants',    'icon' => 'users'],
    ['href' => '/staff/exam',        'key' => 'exam',        'label' => 'Exam Manager',  'icon' => 'edit'],
    ['href' => '/staff/interviews',  'key' => 'interviews',  'label' => 'Interviews',    'icon' => 'calendar'],
    ['href' => '/staff/results',     'key' => 'results',     'label' => 'Release Results','icon' => 'award'],
];

$admin = [
    ['href' => '/admin/users',       'key' => 'users',       'label' => 'User Accounts', 'icon' => 'shield'],
    ['href' => '/admin/school-year', 'key' => 'school-year', 'label' => 'School Year',   'icon' => 'refresh'],
    ['href' => '/admin/settings',    'key' => 'settings',    'label' => 'Settings',      'icon' => 'settings'],
];
?>
<div class="nav-section-label">Admissions</div>
<?php foreach ($staff as $item): ?>
    <a href="<?= url($item['href']) ?>"
       class="nav-item <?= $nav === $item['key'] ? 'active' : '' ?>">
        <?php include __DIR__ . '/icons/' . $item['icon'] . '.svg.php'; ?>
        <?= e($item['label']) ?>
    </a>
<?php endforeach; ?>

<div class="nav-section-label" style="margin-top:var(--space-2)">Admin</div>
<?php foreach ($admin as $item): ?>
    <a href="<?= url($item['href']) ?>"
       class="nav-item <?= $nav === $item['key'] ? 'active' : '' ?>">
        <?php include __DIR__ . '/icons/' . $item['icon'] . '.svg.php'; ?>
        <?= e($item['label']) ?>
    </a>
<?php endforeach; ?>