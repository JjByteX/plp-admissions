<?php
// ============================================================
// views/layouts/app.php
// M1 — Layout Shell
// Usage: set $pageTitle, $activeNav, $showStepper before including
// ============================================================

$schoolName   = school_setting('school_name', 'Pamantasan ng Lungsod ng Pasig');
$schoolLogo   = school_setting('school_logo', '');
$accentColor  = school_setting('accent_color', '#2d6a4f');
$authUser     = Auth::user();
$userInitials = strtoupper(substr($authUser['name'] ?? 'U', 0, 1));
$userRole     = $authUser['role'] ?? 'student';
$pageTitle    = $pageTitle ?? 'Dashboard';
$activeNav    = $activeNav ?? '';
$showStepper  = $showStepper ?? false;
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?> — <?= e($schoolName) ?></title>
    <meta name="robots" content="noindex, nofollow">

    <!-- Preconnect for Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

    <link rel="stylesheet" href="<?= asset('css/app.css') ?>">

    <!-- Inject accent color from DB before paint -->
    <script>
        // Apply saved theme before render to prevent flash
        (function(){
            const t = localStorage.getItem('plp_theme') || 'light';
            document.documentElement.dataset.theme = t;
        })();
    </script>
</head>
<body>

<div class="layout">

    <!-- ====================================================
         SIDEBAR
    ==================================================== -->
    <aside class="sidebar" id="sidebar">

        <!-- Brand -->
        <div class="sidebar-brand">
            <?php if ($schoolLogo): ?>
                <img src="<?= e(url('/' . $schoolLogo)) ?>" alt="Logo" class="sidebar-logo">
            <?php else: ?>
                <div class="sidebar-logo-placeholder">
                    <?php include __DIR__ . '/../partials/icons/school.svg.php'; ?>
                </div>
            <?php endif; ?>
            <span class="sidebar-school-name"><?= e($schoolName) ?></span>
        </div>

        <!-- Navigation — rendered per role -->
        <nav class="sidebar-nav" aria-label="Main navigation">
            <?php if ($userRole === 'student'): ?>
                <?php include __DIR__ . '/../partials/nav_student.php'; ?>
            <?php elseif ($userRole === 'staff'): ?>
                <?php include __DIR__ . '/../partials/nav_staff.php'; ?>
            <?php elseif ($userRole === 'admin'): ?>
                <?php include __DIR__ . '/../partials/nav_admin.php'; ?>
            <?php endif; ?>
        </nav>

        <!-- User footer -->
        <div class="sidebar-footer">
            <div class="dropdown">
                <div class="sidebar-user" data-dropdown tabindex="0" role="button" aria-label="User menu">
                    <div class="user-avatar"><?= e($userInitials) ?></div>
                    <div class="user-info">
                        <div class="user-name truncate"><?= e($authUser['name'] ?? '') ?></div>
                        <div class="user-role"><?= e($userRole) ?></div>
                    </div>
                    <svg width="14" height="14" fill="none" viewBox="0 0 24 24" style="flex-shrink:0;color:var(--text-tertiary)">
                        <path stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M6 9l6 6 6-6"/>
                    </svg>
                </div>
                <div class="dropdown-menu">
                    <a href="<?= url('/student/settings') ?>" class="dropdown-item">
                        <?php include __DIR__ . '/../partials/icons/settings.svg.php'; ?>
                        Settings
                    </a>
                    <a href="#" class="dropdown-item">
                        <?php include __DIR__ . '/../partials/icons/help.svg.php'; ?>
                        Help & About
                    </a>
                    <div class="dropdown-separator"></div>
                    <a href="<?= url('/logout') ?>" class="dropdown-item danger">
                        <?php include __DIR__ . '/../partials/icons/logout.svg.php'; ?>
                        Log out
                    </a>
                </div>
            </div>
        </div>

    </aside>

    <!-- ====================================================
         MAIN
    ==================================================== -->
    <div class="main">

        <!-- Top bar -->
        <header class="topbar">
            <!-- Mobile hamburger -->
            <button id="sidebar-toggle" class="btn-ghost btn btn-sm" aria-label="Open menu" style="display:none">
                <svg width="18" height="18" fill="none" viewBox="0 0 24 24">
                    <path stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M3 6h18M3 12h18M3 18h18"/>
                </svg>
            </button>

            <span class="topbar-title"><?= e($pageTitle) ?></span>

            <div class="topbar-actions">
                <!-- Theme toggle -->
                <button class="theme-toggle" onclick="Theme.toggle()" aria-label="Toggle theme">
                    <!-- Sun icon (shown in dark mode) -->
                    <svg data-theme-icon="dark" class="hidden" width="16" height="16" fill="none" viewBox="0 0 24 24">
                        <circle cx="12" cy="12" r="4" stroke="currentColor" stroke-width="2"/>
                        <path stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M12 2v2M12 20v2M4.22 4.22l1.42 1.42M18.36 18.36l1.42 1.42M2 12h2M20 12h2M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42"/>
                    </svg>
                    <!-- Moon icon (shown in light mode) -->
                    <svg data-theme-icon="light" width="16" height="16" fill="none" viewBox="0 0 24 24">
                        <path stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>
                    </svg>
                </button>
            </div>
        </header>

        <!-- Progress Stepper — students only -->
        <?php if ($showStepper && $userRole === 'student'): ?>
            <?php include __DIR__ . '/../partials/stepper.php'; ?>
        <?php endif; ?>

        <!-- Flash messages -->
        <?php if (Session::hasFlash('success') || Session::hasFlash('error') || Session::hasFlash('info')): ?>
            <div style="padding: var(--space-4) var(--space-8) 0;">
                <?php if ($msg = Session::getFlash('success')): ?>
                    <div class="alert alert-success animate-fade-in" data-auto-dismiss="5000">
                        <svg width="16" height="16" fill="none" viewBox="0 0 24 24"><path stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        <?= e($msg) ?>
                    </div>
                <?php endif; ?>
                <?php if ($msg = Session::getFlash('error')): ?>
                    <div class="alert alert-error animate-fade-in" data-auto-dismiss="6000">
                        <svg width="16" height="16" fill="none" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="2"/><path stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M12 8v4M12 16h.01"/></svg>
                        <?= e($msg) ?>
                    </div>
                <?php endif; ?>
                <?php if ($msg = Session::getFlash('info')): ?>
                    <div class="alert alert-info animate-fade-in" data-auto-dismiss="5000">
                        <svg width="16" height="16" fill="none" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="2"/><path stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M12 16v-4M12 8h.01"/></svg>
                        <?= e($msg) ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- Page content injected here -->
        <main class="page" id="main-content">
            <?= $content ?? '' ?>
        </main>

    </div><!-- /.main -->

</div><!-- /.layout -->

<script src="<?= asset('js/app.js') ?>"></script>
<script>
    // Inject accent from DB
    setAccentColor('<?= e($accentColor) ?>');

    // Show hamburger on mobile
    if (window.innerWidth <= 768) {
        document.getElementById('sidebar-toggle').style.display = 'flex';
    }
</script>

</body>
</html>
