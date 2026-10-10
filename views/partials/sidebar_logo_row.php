<?php
// views/partials/sidebar_logo_row.php
// Header row of the staff / admin sidebar.
//
// Ported from lakbay-pasig's components/sidebar-logo-row.tsx. One partial,
// used for every role, so every rail collapses and expands the same way.
//
// Expected from the including file (layouts/app.php):
//   $sidebarHome  — where the logo + school name link to
//   $schoolName, $schoolLogo
//
// Lakbay renders ONE of two trees depending on React state. Here both are
// in the DOM and CSS picks one from the wrapper's data-state (see the
// "SIDEBAR" section of app.css), so the first paint is already correct.
//
//   Expanded: logo + school name as a link, plus a collapse button on the
//             right (sidebar-simple icon, mirrored so it points inward).
//   Collapsed: ONE button fills the logo's slot and is the expand control,
//             since a 48px rail has no room for a second target. Hovering
//             (or keyboard-focusing) it swaps the logo for the expand icon,
//             in CSS only — only the click calls Sidebar.toggle().
//
// Both rows are 32px tall and the expanded link has 4px of left padding,
// so the logo sits at the same x and y in both states. Keep them equal or
// the logo jumps when the rail toggles.
//
// On phones the rail is a drawer and always shows the expanded row; the
// collapse button there closes the drawer (Sidebar.toggle() does that when
// isMobile), same as Lakbay's sheet.

$_logoSrc = $schoolLogo !== ''
    ? (str_starts_with($schoolLogo, 'http') ? $schoolLogo : url('/' . $schoolLogo))
    : '';

$_logoHtml = $_logoSrc !== ''
    ? '<img src="' . e($_logoSrc) . '" alt="' . e($schoolName) . '" class="sidebar-logo">'
    : '<span class="sidebar-logo-placeholder">' . icon('bank:fill', 14) . '</span>';
?>
<div class="sidebar-logo-row sidebar-logo-row--expanded">
    <a href="<?= e(url($sidebarHome)) ?>" class="sidebar-brand-link" title="<?= e($schoolName) ?>">
        <?= $_logoHtml ?>
        <span class="sidebar-brand-name"><?= e($schoolName) ?></span>
    </a>
    <button type="button" class="sidebar-icon-btn" data-sidebar-toggle aria-label="Collapse sidebar">
        <?= icon('sidebar-simple', 16, '', 'class="sidebar-icon-flip"') ?>
    </button>
</div>

<button type="button" class="sidebar-logo-toggle" data-sidebar-toggle aria-label="Expand sidebar">
    <span class="sidebar-logo-face"><?= $_logoHtml ?></span>
    <span class="sidebar-toggle-face"><?= icon('sidebar-simple', 16) ?></span>
</button>
