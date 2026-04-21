<?php
// views/partials/nav_student.php
$nav = $activeNav ?? '';

$items = [
    ['href' => '/student/documents',  'key' => 'documents',  'label' => 'Documents',    'icon' => 'ic_fluent_document_24_regular'],
    ['href' => '/student/exam',       'key' => 'exam',       'label' => 'Entrance Exam','icon' => 'ic_fluent_edit_24_regular'],
    ['href' => '/student/interview',  'key' => 'interview',  'label' => 'Interview',    'icon' => 'ic_fluent_calendar_ltr_24_regular'],
    ['href' => '/student/result',     'key' => 'result',     'label' => 'My Result',    'icon' => 'ic_fluent_ribbon_star_24_regular'],
];
?>
<?php foreach ($items as $item): ?>
    <a href="<?= url($item['href']) ?>"
       class="nav-item <?= $nav === $item['key'] ? 'active' : '' ?>"
       aria-current="<?= $nav === $item['key'] ? 'page' : 'false' ?>">
        <?php include __DIR__ . '/icons/' . $item['icon'] . '.svg'; ?>
        <?= e($item['label']) ?>
    </a>
<?php endforeach; ?>