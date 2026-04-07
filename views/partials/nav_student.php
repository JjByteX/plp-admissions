<?php
// views/partials/nav_student.php
$nav = $activeNav ?? '';

$items = [
    ['href' => '/student/dashboard',  'key' => 'dashboard',  'label' => 'Dashboard',  'icon' => 'home'],
    ['href' => '/student/documents',  'key' => 'documents',  'label' => 'Documents',  'icon' => 'file'],
    ['href' => '/student/exam',       'key' => 'exam',       'label' => 'Entrance Exam','icon' => 'edit'],
    ['href' => '/student/interview',  'key' => 'interview',  'label' => 'Interview',  'icon' => 'calendar'],
    ['href' => '/student/result',     'key' => 'result',     'label' => 'My Result',  'icon' => 'award'],
    ['href' => '/student/settings',   'key' => 'settings',   'label' => 'Settings',   'icon' => 'settings'],
];
?>
<?php foreach ($items as $item): ?>
    <a href="<?= url($item['href']) ?>"
       class="nav-item <?= $nav === $item['key'] ? 'active' : '' ?>"
       aria-current="<?= $nav === $item['key'] ? 'page' : 'false' ?>">
        <?php include __DIR__ . '/icons/' . $item['icon'] . '.svg.php'; ?>
        <?= e($item['label']) ?>
    </a>
<?php endforeach; ?>
