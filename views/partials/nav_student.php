<?php
// views/partials/nav_student.php
$nav = $activeNav ?? '';

$items = [
    ['href' => '/student/documents',  'key' => 'documents',  'label' => 'Documents',    'icon' => 'file-text'],
    ['href' => '/student/exam',       'key' => 'exam',       'label' => 'Entrance Exam','icon' => 'pencil-simple'],
    ['href' => '/student/interview',  'key' => 'interview',  'label' => 'Interview',    'icon' => 'calendar-blank'],
    ['href' => '/student/result',     'key' => 'result',     'label' => 'My Result',    'icon' => 'medal'],
];
?>
<?php foreach ($items as $item): ?>
    <a href="<?= url($item['href']) ?>"
       class="nav-item <?= $nav === $item['key'] ? 'active' : '' ?>"
       aria-current="<?= $nav === $item['key'] ? 'page' : 'false' ?>">
        <?= icon($item['icon'] . ($nav === $item['key'] ? ':fill' : ''), 18) ?>
        <?= e($item['label']) ?>
    </a>
<?php endforeach; ?>