<?php
// A6: Shared admin settings tab navigation
$adminTabs = [
    '/admin/settings'     => 'Branding & Password',
    '/admin/school-year'  => 'School Year',
    '/admin/courses'      => 'Courses & Thresholds',
    '/admin/users'        => 'User Management',
];
$currentPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$basePath    = parse_url(BASE_URL, PHP_URL_PATH);
$relPath     = $basePath ? substr($currentPath, strlen($basePath)) : $currentPath;
?>
<div style="display:flex;gap:var(--space-1);margin-bottom:var(--space-6);border-bottom:1px solid var(--border);flex-wrap:wrap">
    <?php foreach ($adminTabs as $tabPath => $tabLabel):
        $isActive = ($relPath === $tabPath);
    ?>
        <a href="<?= url($tabPath) ?>" style="
            padding:var(--space-2) var(--space-4);
            border-bottom:2px solid <?= $isActive ? 'var(--accent)' : 'transparent' ?>;
            color:<?= $isActive ? 'var(--accent)' : 'var(--text-secondary)' ?>;
            font-size:var(--text-sm);
            font-weight:<?= $isActive ? 'var(--weight-semibold)' : 'var(--weight-regular)' ?>;
            white-space:nowrap;text-decoration:none;margin-bottom:-1px;
        "><?= e($tabLabel) ?></a>
    <?php endforeach; ?>
</div>
