<?php
// views/partials/sidebar_nav_items.php
// Renders a role's nav list inside the sidebar.
//
// Counterpart of lakbay-pasig's SidebarGroup > SidebarMenu > SidebarMenuItem
// > SidebarMenuButton stack (components/ui/sidebar.tsx). nav_admin.php,
// nav_staff.php and nav_proctor.php each build their own, already
// role-filtered, $sidebarItems and include this file, so the markup that
// the collapse logic depends on exists once, not three times.
//
// Item keys: href, key, label, icon, and optionally
//   'alert'   — red dot, "needs setup"
//   'pending' — amber dot, "has pending work"
//
// What the markup gives the collapse logic:
//   .nav-label  is the label. Collapsed, the link shrinks to a 32px square
//               and the label is clipped (not display:none), so the link
//               keeps its accessible name, same as Lakbay.
//   data-tooltip  read by the Sidebar tooltip in app.js, which only shows
//               while the rail is collapsed on desktop (Lakbay: Radix
//               Tooltip with hidden={state !== "collapsed" || isMobile}).
//   .nav-dot    expanded: sits at the right of the row. Collapsed: moves to
//               the corner of the icon, since the row has no room for it.
//
// Sets $sidebarHasDot so layouts/app.php can put a dot on the phone menu
// button, because the row dots themselves are inside the closed drawer
// (Lakbay does the same with pendingTotal).

$nav           = $activeNav ?? '';
$sidebarHasDot = false;
?>
<div class="sidebar-group">
    <ul class="sidebar-menu">
        <?php foreach ($sidebarItems as $item):
            $isActive = ($nav === $item['key']);
            $dot      = !empty($item['alert']) ? 'alert' : (!empty($item['pending']) ? 'pending' : '');
            if ($dot !== '') $sidebarHasDot = true;
        ?>
        <li class="sidebar-menu-item">
            <a href="<?= url($item['href']) ?>"
               class="nav-item <?= $isActive ? 'active' : '' ?>"
               data-tooltip="<?= e($item['label']) ?>"
               <?= $isActive ? 'aria-current="page"' : '' ?>>
                <?= icon($item['icon'] . ($isActive ? ':fill' : ''), 16) ?>
                <span class="nav-label"><?= e($item['label']) ?></span>
                <?php if ($dot === 'alert'): ?>
                    <span class="nav-dot nav-dot--alert" title="Needs setup"></span>
                <?php elseif ($dot === 'pending'): ?>
                    <span class="nav-dot nav-dot--pending" title="Has pending work"></span>
                <?php endif; ?>
            </a>
        </li>
        <?php endforeach; ?>
    </ul>
</div>
