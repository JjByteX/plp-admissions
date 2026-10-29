<?php
// ============================================================
// views/layouts/app.php
// M1 — Layout Shell
// Usage: set $pageTitle, $activeNav, $showStepper before including
// ============================================================

$schoolName   = school_setting('school_name', 'PLP Admissions');
$schoolLogo   = school_setting('school_logo', '');
$accentColor  = school_setting('accent_color', '#2d6a4f');
$authUser     = Auth::user();
$userInitials = strtoupper(substr($authUser['name'] ?? 'U', 0, 1));
$userRole     = $authUser['role'] ?? 'student';
$pageTitle    = $pageTitle ?? 'Dashboard';
$activeNav    = $activeNav ?? '';
$showStepper  = $showStepper ?? false;
$pageWide     = $pageWide ?? false;
$isStudent    = ($userRole === 'student');
// Embedded in the settings modal's iframe: draw the page content only.
$isEmbed      = is_embed();
$embedSection = preg_replace('/[^a-z_-]/', '', strtolower((string)($_GET['section'] ?? '')));
// True when the page already draws its own heading (an <h1> or .page-title).
$hasPageHeading = !empty($content) && preg_match('/<h1\b|class=["\'][^"\']*\bpage-title\b/i', $content);

// Page heading. Title only, no subtitle.
//   $pageHeading = 'Text'  -> override the heading text (defaults to $pageTitle)
//   $pageHeading = false   -> no heading for this page
//   $pageActions = '<html>' -> buttons shown at the top right of the heading row
//   data-page-action / data-page-actions in the page body are also moved up there.
// Pages that already draw their own heading / .page-header are left alone.
// Staff / admin pages get the .page-head row; students get the simpler
// .page-header-generated row (same $pageActions slot).
$pageHeading    = $pageHeading ?? $pageTitle;
$pageActions    = $pageActions ?? '';
// Breadcrumb ancestors ("Previous Page > Current Page"), rendered by the
// shared views/partials/page_header.php. Pages only set $pageBreadcrumb.
$pageBreadcrumb = $pageBreadcrumb ?? [];
require_once __DIR__ . '/../partials/page_header.php';
$showPageHead   = !$isStudent
    && $pageHeading !== false
    && !$hasPageHeading
    && !str_contains((string)($content ?? ''), 'class="page-header"');
$showStudentHead = $isStudent && $pageHeading !== false && !$hasPageHeading;
if ($isEmbed) { $showPageHead = false; $showStudentHead = false; }

// Pull data-page-action / data-page-actions elements out of the page body on the
// server and hand them to the heading row, so they are in the heading from the
// first paint. (Doing this with JS after load made them show in the table first
// and then jump up.) Only the .page-head layout hosts them, like the old script.
if (!function_exists('extract_page_actions')) {
    function extract_page_actions(string $html): array
    {
        $actions = '';

        // data-page-actions: a <div> wrapper; its children go to the heading, the wrapper is dropped.
        while (preg_match('/<div\b[^>]*\bdata-page-actions(?=[\s=>\/])[^>]*>/i', $html, $m, PREG_OFFSET_CAPTURE)) {
            $start  = $m[0][1];
            $inner  = $start + strlen($m[0][0]);
            $depth  = 1;
            $pos    = $inner;
            $end    = null;
            while ($depth > 0 && preg_match('/<(\/?)div\b[^>]*>/i', $html, $t, PREG_OFFSET_CAPTURE, $pos)) {
                $depth += ($t[1][0] === '/') ? -1 : 1;
                $pos = $t[0][1] + strlen($t[0][0]);
                if ($depth === 0) $end = $t[0][1];
            }
            if ($end === null) break; // unbalanced markup: leave it for the JS fallback
            $actions .= substr($html, $inner, $end - $inner);
            $html = substr($html, 0, $start) . substr($html, $pos);
        }

        // data-page-action: the button / link itself.
        $html = preg_replace_callback(
            '/<(button|a)\b[^>]*\bdata-page-action(?=[\s=>\/])[^>]*>.*?<\/\1>/is',
            function ($mm) use (&$actions) {
                $actions .= preg_replace('/\sdata-page-action(?:=(?:"[^"]*"|\'[^\']*\'))?(?=[\s>\/])/i', '', $mm[0], 1);
                return '';
            },
            $html
        );

        return [$html, $actions];
    }
}
if ($showPageHead && !empty($content)) {
    [$content, $movedActions] = extract_page_actions((string)$content);
    $pageActions = (string)$pageActions . $movedActions;
}

// Sidebar (staff / admin / SSO / dean / proctor), ported from lakbay-pasig's
// SidebarProvider. Lakbay keeps the desktop open/closed state in a cookie so
// the server can render the right state on first paint; same here. The cookie
// is written by Sidebar.setOpen() in app.js. Only the literal 'false' collapses
// the rail, so a missing or odd value means expanded (Lakbay: defaultOpen).
$sidebarOpen = ($_COOKIE['sidebar_state'] ?? 'true') !== 'false';

// Where the logo + school name in the sidebar header link to: each role's own
// landing page, the same one its first nav item points at.
$sidebarHome = match ($userRole) {
    ROLE_STAFF   => '/staff/interviews/queue',
    ROLE_PROCTOR => '/staff/exam/slots',
    default      => '/admin/dashboard',
};
?>
<?php
// CSP header
// CSP — `connect-src` is intentionally permissive (any HTTPS host) because
// the exam builder's Puter AI integration uploads via signed PUT URLs that
// point at object-storage hosts (S3 / R2 / *.puter.site / *.amazonaws.com)
// returned at runtime. A strict allow-list breaks every time Puter changes
// infra. (The old documents AI Validate feature has been removed; Puter is
// only used by the exam builder now.)
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com https://js.puter.com; worker-src 'self' blob: https://cdnjs.cloudflare.com; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdn.jsdelivr.net; font-src 'self' https://fonts.gstatic.com https://cdn.jsdelivr.net; img-src 'self' data: blob: https:; frame-src 'self' https://*.puter.com; connect-src 'self' https: blob: data:;");
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: SAMEORIGIN");
header("Referrer-Policy: strict-origin-when-cross-origin");
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?> - <?= e($schoolName) ?></title>
    <meta name="robots" content="noindex, nofollow">

    <!-- Preconnect for Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;1,9..40,400&family=DM+Mono:wght@400;500&display=swap">

    <?= phosphor_head() ?>

    <!-- Critical canvas colour: applies before app.css arrives so page switches never flash -->
    <link rel="stylesheet" href="<?= asset('css/app-critical.css') ?>">
    <!-- Theme and text size are applied before the stylesheet, so the first paint is already in the right theme -->
    <script src="<?= asset('js/app-head.js') ?>"></script>
    <!-- Bootstrap 5.3.3 + Bootstrap Icons, served locally (works offline). Order: Bootstrap -> bootstrap-compat.css -> app.css -->
    <link rel="stylesheet" href="<?= asset('vendor/bootstrap/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= asset('vendor/bootstrap-icons/bootstrap-icons.min.css') ?>">
    <link rel="stylesheet" href="<?= asset('css/bootstrap-compat.css') ?>">
    <link rel="stylesheet" href="<?= asset('css/app.css') ?>">
    <link rel="stylesheet" href="<?= asset('css/app-layout.css') ?>">
    <?php foreach (($pageStyles ?? []) as $pageStyle): ?>
    <link rel="stylesheet" href="<?= asset($pageStyle) ?>">
    <?php endforeach; ?>

    <?php if ($schoolLogo): ?>
    <link rel="preload" as="image" href="<?= str_starts_with($schoolLogo, 'http') ? e($schoolLogo) : e(url('/' . $schoolLogo)) ?>">
    <?php endif; ?>

</head>
<body<?= $isEmbed ? ' class="is-embed"' : '' ?><?= ($isEmbed && $embedSection !== '') ? ' data-embed-section="' . $embedSection . '"' : '' ?>>

<div class="layout <?= ($isStudent && !$isEmbed) ? 'layout-student' : '' ?>">

<?php if ($isEmbed): ?>
<?php elseif ($isStudent): ?>

    <!-- ====================================================
         STUDENT HEADER (no sidebar)
    ==================================================== -->
    <header class="student-header">

        <!-- Brand -->
        <div class="student-header-brand">
            <?php if ($schoolLogo): ?>
                <img src="<?= str_starts_with($schoolLogo, 'http') ? e($schoolLogo) : e(url('/' . $schoolLogo)) ?>" alt="Logo" class="sidebar-logo">
            <?php else: ?>
                <div class="sidebar-logo-placeholder">
                    <?= icon('bank:fill', 18) ?>
                </div>
            <?php endif; ?>
            <span class="sidebar-school-name"><?= e($schoolName) ?></span>
        </div>

        <!-- Progress Stepper — centered in header (students only) -->
        <div class="student-header-stepper">
            <?php if ($showStepper): ?>
                <?php include __DIR__ . '/../partials/stepper.php'; ?>
            <?php endif; ?>
        </div>

        <!-- Notification bell + Profile menu -->
        <div style="display:flex;align-items:center;gap:var(--space-3);justify-content:flex-end">

        <!-- Notification Bell -->
        <?php $notifCount = notification_count(Auth::id()); ?>
        <div class="dropdown" id="notif-dropdown">
            <button class="btn-icon" data-dropdown type="button" aria-label="Notifications" style="position:relative">
                <?= icon('bell', 20) ?>
                <?php if ($notifCount > 0): ?>
                <span class="notif-badge" id="notif-badge"><?= $notifCount > 9 ? '9+' : $notifCount ?></span>
                <?php endif; ?>
            </button>
            <div class="dropdown-menu" style="width:320px;max-height:400px;overflow-y:auto;right:0;left:auto" id="notif-menu">
                <div style="padding:var(--space-3) var(--space-4);display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid var(--border)">
                    <strong style="font-size:var(--text-sm)">Notifications</strong>
                    <?php if ($notifCount > 0): ?>
                    <button class="btn btn-ghost btn-sm" onclick="markAllRead()" style="font-size:var(--text-xs)">Mark all read</button>
                    <?php endif; ?>
                </div>
                <div id="notif-list">
                    <?php
                    $notifications = get_notifications(Auth::id(), 10);
                    if (empty($notifications)): ?>
                        <div style="padding:var(--space-6);text-align:center;color:var(--text-tertiary);font-size:var(--text-sm)">No notifications</div>
                    <?php else:
                        foreach ($notifications as $n): ?>
                        <a href="<?= $n['link'] ? url($n['link']) : '#' ?>" class="dropdown-item" style="flex-direction:column;align-items:flex-start;gap:2px;padding:var(--space-3) var(--space-4);<?= !$n['is_read'] ? 'background:var(--bg-secondary)' : '' ?>">
                            <div style="font-weight:<?= !$n['is_read'] ? 'var(--weight-semibold)' : 'normal' ?>;font-size:var(--text-sm)"><?= e($n['title']) ?></div>
                            <?php if ($n['message']): ?>
                            <div style="font-size:var(--text-xs);color:var(--text-tertiary);line-height:1.4"><?= e(mb_strimwidth($n['message'], 0, 80, '...')) ?></div>
                            <?php endif; ?>
                            <div style="font-size:var(--text-2xs);color:var(--text-tertiary);margin-top:2px"><?= date('M j, g:i A', strtotime($n['created_at'])) ?></div>
                        </a>
                    <?php endforeach;
                    endif; ?>
                </div>
            </div>
        </div>

        <div class="dropdown student-header-profile">
            <button class="student-header-avatar" data-dropdown type="button" aria-label="User menu" aria-haspopup="true">
                <div class="user-avatar"><?= e($userInitials) ?></div>
            </button>
            <div class="dropdown-menu student-header-dropdown">
                <div class="student-header-dropdown-info">
                    <div class="user-name"><?= e($authUser['name'] ?? '') ?></div>
                    <div class="user-role"><?= e(Auth::roleLabel($userRole)) ?></div>
                </div>
                <div class="dropdown-separator"></div>
                <a href="<?= url('/student/settings') ?>" class="dropdown-item" data-settings-open>
                    <?= icon('gear', 15) ?>
                    Settings
                </a>
                <div class="dropdown-item theme-toggle-row" role="button" tabindex="0" onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();this.click();}" onclick="
                    const t = document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark';
                    document.documentElement.dataset.theme = t;
                    localStorage.setItem('plp_theme', t);
                    document.querySelectorAll('.theme-pill').forEach(p => p.dataset.theme = t);" style="cursor:pointer;justify-content:space-between;">
                    <span style="display:flex;align-items:center;gap:var(--space-2)">
                        <?= icon('ic_fluent_weather_moon_24_regular', 15) ?>
                        Dark Mode
                    </span>
                    <div class="theme-pill" data-theme="light" style="
                        position:relative;width:32px;height:18px;border-radius:var(--radius);
                        background:var(--border);transition:background .2s;flex-shrink:0;pointer-events:none;">
                        <div style="
                            position:absolute;top:3px;left:3px;width:12px;height:12px;
                            border-radius:50%;background:var(--white);
                            transition:transform .2s;
                            transform:translateX(0);">
                        </div>
                    </div>
                </div>
                <?php
                // Check if student has an active application that can be withdrawn
                $_wdAppl = applicant_for_user((int)($authUser['id'] ?? 0), 'id, overall_status');
                $_canWithdraw = $_wdAppl && !in_array($_wdAppl['overall_status'] ?? '', ['withdrawn',''], true);
                ?>
                <?php if ($_canWithdraw): ?>
                <div class="dropdown-separator"></div>
                <a href="#" class="dropdown-item danger" role="button" tabindex="0" onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();this.click();}" onclick="event.preventDefault();document.getElementById('withdraw-modal').style.display='flex'">
                    <?= icon('x-circle', 15) ?>
                    Withdraw Application
                </a>
                <?php endif; ?>
                <a href="<?= url('/logout') ?>" class="dropdown-item" data-logout>
                    <?= icon('sign-out', 15) ?>
                    Log out
                </a>
            </div>
        </div>

        </div><!-- /notification+profile wrapper -->

    </header>

<?php else: ?>

    <!-- ====================================================
         SIDEBAR (staff / admin)
    ==================================================== -->
    <div class="sidebar-wrapper" id="sidebar-wrapper"
         data-state="<?= $sidebarOpen ? 'expanded' : 'collapsed' ?>"
         data-collapsible="<?= $sidebarOpen ? '' : 'icon' ?>"
         data-mobile-open="false">

        <!-- Spacer: takes the rail's width in the flex row so .main sits beside
             it, and animates the same way the rail does. The rail itself is
             position:fixed, so without this .main would slide under it. -->
        <div class="sidebar-gap" aria-hidden="true"></div>

        <!-- Phone only: dims the page behind the open drawer, click to close. -->
        <div class="sidebar-overlay" data-sidebar-overlay></div>

        <aside class="sidebar" id="sidebar" data-sidebar="sidebar">
          <div class="sidebar-inner">

            <!-- Header: shared logo row (partials/sidebar_logo_row.php) -->
            <div class="sidebar-header">
                <?php include __DIR__ . '/../partials/sidebar_logo_row.php'; ?>
            </div>

            <!-- Navigation — rendered per role.
                 Professor (staff) uses the trimmed nav_staff.php.
                 Proctor uses nav_proctor.php (exam-only sidebar).
                 Admin / SSO / Dean share the management nav, which itself
                 filters individual items per role. All three build their list
                 and hand it to partials/sidebar_nav_items.php. -->
            <nav class="sidebar-nav" aria-label="Main navigation">
                <?php if ($userRole === ROLE_STAFF): ?>
                    <?php include __DIR__ . '/../partials/nav_staff.php'; ?>
                <?php elseif ($userRole === ROLE_PROCTOR): ?>
                    <?php include __DIR__ . '/../partials/nav_proctor.php'; ?>
                <?php elseif (in_array($userRole, [ROLE_ADMIN, ROLE_SSO, ROLE_DEAN], true)): ?>
                    <?php include __DIR__ . '/../partials/nav_admin.php'; ?>
                <?php endif; ?>
            </nav>

            <!-- User footer: the account row is the menu trigger. Collapsed, only
                 the avatar shows. The menu opens upward and hugs the row's left
                 edge (Lakbay: side="top" align="start"). -->
            <div class="sidebar-footer">
                <div class="dropdown">
                    <div class="sidebar-user" data-dropdown tabindex="0" role="button"
                         aria-label="User menu" aria-haspopup="true"
                         onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();this.click();}"
                         data-tooltip="<?= e($authUser['name'] ?? 'Account') ?>">
                        <div class="user-avatar"><?= e($userInitials) ?></div>
                        <div class="user-info">
                            <div class="user-name truncate"><?= e($authUser['name'] ?? '') ?></div>
                            <div class="user-role"><?= e(Auth::roleLabel($userRole)) ?></div>
                        </div>
                        <?= icon('caret-up-down', 16, 'flex-shrink:0;color:var(--text-secondary)', 'class="sidebar-user-caret"') ?>
                    </div>
                    <div class="dropdown-menu">
                        <?php
                            // Only ROLE_ADMIN may visit /admin/settings (school
                            // branding + admin password). Everyone else (Staff /
                            // SSO / Dean) lands on /staff/settings, which is now
                            // a personal-only page (theme, password, help).
                            $settingsHref = ($userRole === ROLE_ADMIN)
                                ? '/admin/settings'
                                : '/staff/settings';
                        ?>
                        <a href="<?= url($settingsHref) ?>" class="dropdown-item" data-settings-open>
                            <?= icon('gear', 15) ?>
                            Settings
                        </a>
                        <div class="dropdown-item theme-toggle-row" role="button" tabindex="0" onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();this.click();}" onclick="
                            const t = document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark';
                            document.documentElement.dataset.theme = t;
                            localStorage.setItem('plp_theme', t);
                            document.querySelector('.theme-pill').dataset.theme = t;" style="cursor:pointer;justify-content:space-between;">
                            <span style="display:flex;align-items:center;gap:var(--space-2)">
                                <?= icon('ic_fluent_weather_moon_24_regular', 15) ?>
                                Dark Mode
                            </span>
                            <div class="theme-pill" data-theme="light" style="
                                position:relative;width:32px;height:18px;border-radius:var(--radius);
                                background:var(--border);transition:background .2s;flex-shrink:0;pointer-events:none;">
                                <div style="
                                    position:absolute;top:3px;left:3px;width:12px;height:12px;
                                    border-radius:50%;background:var(--white);
                                    transition:transform .2s;
                                    transform:translateX(0);">
                                </div>
                            </div>
                        </div>
                        <a href="<?= url('/logout') ?>" class="dropdown-item" data-logout>
                            <?= icon('sign-out', 15) ?>
                            Log out
                        </a>
                    </div>
                </div>
            </div>

          </div><!-- /.sidebar-inner -->
        </aside>
    </div><!-- /.sidebar-wrapper -->

<?php endif; ?>

    <!-- ====================================================
         MAIN
    ==================================================== -->
    <div class="main">

        <?php if (!$isStudent && !$isEmbed): ?>
        <!-- Phone-only top bar (hidden >768px by CSS). Below that the sidebar is a
             drawer, so this button is the only way to open it. It carries a dot
             when any nav row has one, because the row dots are inside the closed
             drawer (Lakbay: pendingTotal on SidebarTrigger). -->
        <header class="mobile-bar">
            <div class="mobile-bar-trigger">
                <button type="button" class="sidebar-icon-btn" data-sidebar-toggle aria-label="Toggle sidebar">
                    <?= icon('sidebar-simple', 16) ?>
                </button>
                <?php if (!empty($sidebarHasDot)): ?>
                    <output class="mobile-bar-dot" aria-label="Items need attention"></output>
                <?php endif; ?>
            </div>
        </header>
        <?php endif; ?>

        <!-- Flash messages -->
        <?php if (Session::hasFlash('success') || Session::hasFlash('error') || Session::hasFlash('info')): ?>
            <div style="padding: var(--space-4) var(--space-8) 0;">
                <?php if ($msg = Session::getFlash('success')): ?>
                    <div class="alert alert-success animate-fade-in" data-auto-dismiss="5000">
                        <?= icon('ic_fluent_checkmark_circle_24_regular', 16) ?>
                        <?= e($msg) ?>
                    </div>
                <?php endif; ?>
                <?php if ($msg = Session::getFlash('error')): ?>
                    <div class="alert alert-error animate-fade-in" data-auto-dismiss="6000">
                        <?= icon('ic_fluent_info_24_regular', 16) ?>
                        <?= e($msg) ?>
                    </div>
                <?php endif; ?>
                <?php if ($msg = Session::getFlash('info')): ?>
                    <div class="alert alert-info animate-fade-in" data-auto-dismiss="5000">
                        <?= icon('ic_fluent_info_24_regular', 16) ?>
                        <?= e($msg) ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- Page content injected here -->
        <main class="page <?= $pageWide ? 'page-wide' : '' ?>" id="main-content">
            <?php if ($showPageHead): ?>
            <?= page_header((string)$pageHeading, $pageBreadcrumb, (string)$pageActions, 'staff') ?>
            <?php elseif ($showStudentHead): ?>
            <?= page_header((string)$pageHeading, $pageBreadcrumb, (string)$pageActions, 'student') ?>
            <?php endif; ?>
            <?= $content ?? '' ?>
        </main>

    </div><!-- /.main -->

</div><!-- /.layout -->

<script type="application/json" id="layout-data"><?= json_encode([
    'baseUrl' => rtrim(BASE_URL, '/'),
    'accent'  => $accentColor,
    'loginUrl' => url('/login'),
    'logoutUrl' => url('/logout'),
    'session' => (Auth::check() && !$isEmbed) ? [
        'warnBefore'    => (int) SESSION_WARN_BEFORE,
        'secsRemaining' => (int) Session::secondsRemaining(),
        'keepaliveUrl'  => url('/auth/keepalive'),
        'csrf'          => csrf_token(),
    ] : null,
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script src="<?= asset('js/app-boot.js') ?>"></script>
<?php if ($showPageHead): ?>
<script src="<?= asset('js/app-page-head.js') ?>"></script>
<?php endif; ?>
<?php /* Page scripts load before jquery/app.js, the same point the old inline blocks ran at. */ ?>
<?php foreach (($pageScripts ?? []) as $pageScript): ?>
<script src="<?= asset($pageScript) ?>"></script>
<?php endforeach; ?>
<script src="<?= asset('js/jquery.min.js') ?>"></script>
<script src="<?= asset('vendor/bootstrap/bootstrap.bundle.min.js') ?>"></script>
<script src="<?= asset('js/app.js') ?>"></script>
<script src="<?= asset('js/app-layout.js') ?>"></script>

<?php if (Auth::check() && !$isEmbed): ?>
<!-- ── Session timeout warning ──────────────────────────────── -->
<div id="session-timeout-modal" style="
    display:none;position:fixed;inset:0;z-index:9999;
    background:rgba(0,0,0,.55);backdrop-filter:blur(4px);
    align-items:center;justify-content:center;">
    <div class="session-modal">
        <div class="session-modal-icon">
            <?= icon('ic_fluent_warning_24_regular', 24) ?>
        </div>
        <div style="font-weight:var(--weight-semibold);font-size:var(--text-lg);margin-bottom:var(--space-2)">
            Session Expiring Soon
        </div>
        <div style="font-size:var(--text-sm);color:var(--text-secondary);margin-bottom:var(--space-6)">
            You'll be logged out in <strong id="session-countdown"></strong> due to inactivity.
        </div>
        <div style="display:flex;flex-direction:column;gap:var(--space-3)">
            <button onclick="keepAlive()" class="btn btn-primary" style="width:100%">
                Stay Logged In
            </button>
            <button onclick="logOutNow()" class="btn btn-ghost" style="width:100%;color:var(--text-tertiary)">
                Log Out Now
            </button>
        </div>
    </div>
</div>

<?php endif; ?>

<?php if ($isStudent && !empty($_canWithdraw)): ?>
<!-- Global Withdraw Application Modal -->
<div id="withdraw-modal" class="modal-backdrop" style="display:none" aria-modal="true" role="dialog">
    <div class="modal" style="max-width:440px">
        <div class="modal-header">
            <div class="modal-title">Withdraw Application</div>
            <button class="btn-icon" onclick="document.getElementById('withdraw-modal').style.display='none'" type="button">
                <?= icon('ic_fluent_dismiss_24_regular', 18) ?>
            </button>
        </div>
        <form method="POST" action="<?= url('/student/result') ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="withdraw">
            <div class="modal-body" style="display:flex;flex-direction:column;gap:var(--space-4)">
                <div style="background:var(--warning-bg);border:1px solid var(--warning);border-radius:var(--radius);padding:var(--space-4)">
                    <div style="display:flex;gap:var(--space-3);align-items:flex-start">
                        <?= icon('warning-circle:fill', 18, 'margin-top:1px;color:#f97316') ?>
                        <div>
                            <div style="font-weight:var(--weight-semibold);font-size:var(--text-sm);color:var(--warning);margin-bottom:2px">This cannot be undone</div>
                            <p style="font-size:var(--text-sm);color:var(--text-secondary)">
                                Withdrawing permanently removes you from the admissions process.
                            </p>
                        </div>
                    </div>
                </div>
                <div>
                    <label class="form-label" for="withdraw-confirm-input">Type <strong>Withdraw</strong> to confirm</label>
                    <input type="text" id="withdraw-confirm-input" class="form-control"
                           placeholder="Withdraw" autocomplete="off" style="max-width:220px;text-transform:none"
                           oninput="document.getElementById('withdraw-submit-btn').disabled = this.value.trim().toLowerCase() !== 'withdraw'">
                </div>
                <div>
                    <label class="form-label" for="withdraw-reason">Reason <span style="color:var(--text-tertiary);font-weight:var(--weight-regular)">(optional)</span></label>
                    <textarea name="withdraw_reason" id="withdraw-reason" class="form-control" rows="2"
                              placeholder="e.g. Enrolling at a different school"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-ghost"
                        onclick="document.getElementById('withdraw-modal').style.display='none'">Cancel</button>
                <button type="submit" id="withdraw-submit-btn" class="btn btn-danger" disabled>Withdraw</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if (Auth::check()): ?>
<!-- Logout confirmation (opened by any [data-logout] link) -->
<div id="logout-modal" class="modal-backdrop" style="display:none" aria-modal="true" role="dialog" aria-labelledby="logout-modal-title">
    <div class="modal" style="max-width:380px">
        <div class="modal-header">
            <div class="modal-title" id="logout-modal-title">Log out</div>
        </div>
        <div class="modal-body">
            <p style="font-size:var(--text-sm);color:var(--text-secondary)">Are you sure you want to log out?</p>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-ghost" id="logout-cancel-btn">Cancel</button>
            <button type="button" class="btn btn-danger" id="logout-confirm-btn">Log out</button>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if (!$isEmbed && Auth::check()): ?>
<?php include __DIR__ . '/../partials/settings_modal.php'; ?>
<?php endif; ?>


</body>
</html>
