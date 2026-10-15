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
<div style="margin-bottom:var(--space-6)">
    <?php
    $adminTabItems = [];
    foreach ($adminTabs as $tabPath => $tabLabel) {
        $adminTabItems[] = ['label' => $tabLabel, 'href' => url($tabPath), 'active' => ($relPath === $tabPath)];
    }
    echo seg_control($adminTabItems, 'Admin settings');
    ?>
</div>
