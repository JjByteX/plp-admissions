<?php
// views/partials/nav_staff.php
$nav = $activeNav ?? '';

$items = [
    ['href' => '/staff/dashboard',   'key' => 'dashboard',   'label' => 'Dashboard',      'icon' => 'home'],
    ['href' => '/staff/applicants',  'key' => 'applicants',  'label' => 'Applicants',     'icon' => 'users'],
    ['href' => '/staff/exam',        'key' => 'exam',        'label' => 'Exam Manager',   'icon' => 'edit'],
    ['href' => '/staff/interviews',  'key' => 'interviews',  'label' => 'Interviews',     'icon' => 'calendar'],
    ['href' => '/staff/results',     'key' => 'results',     'label' => 'Release Results','icon' => 'award'],
    ['href' => '/staff/settings',    'key' => 'settings',    'label' => 'Settings',       'icon' => 'settings'],
];
?>
<div class="nav-section-label">Management</div>
<?php foreach ($items as $item): ?>
    <a href="<?= url($item['href']) ?>"
       class="nav-item <?= $nav === $item['key'] ? 'active' : '' ?>"
       aria-current="<?= $nav === $item['key'] ? 'page' : 'false' ?>">
        <?php include __DIR__ . '/icons/' . $item['icon'] . '.svg.php'; ?>
        <?= e($item['label']) ?>
    </a>
<?php endforeach; ?>