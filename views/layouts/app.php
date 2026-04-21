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
$pageWide     = $pageWide ?? false;
$isStudent    = ($userRole === 'student');
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
            document.addEventListener('DOMContentLoaded', function() {
                const pill = document.querySelector('.theme-pill');
                if (pill) pill.dataset.theme = t;
            });
        })();
    </script>
</head>
<body>

<div class="layout <?= $isStudent ? 'layout-student' : '' ?>">

<?php if ($isStudent): ?>

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
                    <?php include __DIR__ . '/../partials/icons/school.svg.php'; ?>
                </div>
            <?php endif; ?>
            <span class="sidebar-school-name"><?= e($schoolName) ?></span>
        </div>

        <!-- Progress Stepper — centered in header (students only) -->
        <?php if ($showStepper && $isStudent): ?>
            <div class="student-header-stepper">
                <?php include __DIR__ . '/../partials/stepper.php'; ?>
            </div>
        <?php endif; ?>

        <!-- Profile menu — avatar only, opens on click -->
        <div class="dropdown student-header-profile">
            <button class="student-header-avatar" data-dropdown type="button" aria-label="User menu" aria-haspopup="true">
                <div class="user-avatar"><?= e($userInitials) ?></div>
            </button>
            <div class="dropdown-menu student-header-dropdown">
                <div class="student-header-dropdown-info">
                    <div class="user-name"><?= e($authUser['name'] ?? '') ?></div>
                    <div class="user-role"><?= ucfirst(e($userRole)) ?></div>
                </div>
                <div class="dropdown-separator"></div>
                <a href="<?= url('/student/settings') ?>" class="dropdown-item">
                    <?php include __DIR__ . '/../partials/icons/settings.svg.php'; ?>
                    Settings
                </a>
                <a href="#" class="dropdown-item">
                    <?php include __DIR__ . '/../partials/icons/help.svg.php'; ?>
                    Help &amp; About
                </a>
                <div class="dropdown-separator"></div>
                <a href="<?= url('/logout') ?>" class="dropdown-item danger">
                    <?php include __DIR__ . '/../partials/icons/logout.svg.php'; ?>
                    Log out
                </a>
            </div>
        </div>

    </header>

<?php else: ?>

    <!-- ====================================================
         SIDEBAR (staff / admin)
    ==================================================== -->
    <aside class="sidebar" id="sidebar">

        <!-- Brand -->
        <div class="sidebar-brand">
            <?php if ($schoolLogo): ?>
                <img src="<?= str_starts_with($schoolLogo, 'http') ? e($schoolLogo) : e(url('/' . $schoolLogo)) ?>" alt="Logo" class="sidebar-logo">
            <?php else: ?>
                <div class="sidebar-logo-placeholder">
                    <?php include __DIR__ . '/../partials/icons/school.svg.php'; ?>
                </div>
            <?php endif; ?>
            <span class="sidebar-school-name"><?= e($schoolName) ?></span>
        </div>

        <!-- Navigation — rendered per role -->
        <nav class="sidebar-nav" aria-label="Main navigation">
            <?php if ($userRole === 'staff'): ?>
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
                    <a href="<?= url($userRole === 'staff' ? '/staff/settings' : '/admin/settings') ?>" class="dropdown-item">
                        <?php include __DIR__ . '/../partials/icons/settings.svg.php'; ?>
                        Settings
                    </a>
                    <div class="dropdown-item theme-toggle-row" onclick="
                        const t = document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark';
                        document.documentElement.dataset.theme = t;
                        localStorage.setItem('plp_theme', t);
                        document.querySelector('.theme-pill').dataset.theme = t;" style="cursor:pointer;justify-content:space-between;">
                        <span style="display:flex;align-items:center;gap:var(--space-2)">
                            <svg width="15" height="15" fill="none" viewBox="0 0 24 24"><path stroke="currentColor" stroke-width="1.8" stroke-linecap="round" d="M21 12.79A9 9 0 1111.21 3 7 7 0 0021 12.79z"/></svg>
                            Dark Mode
                        </span>
                        <div class="theme-pill" data-theme="light" style="
                            position:relative;width:32px;height:18px;border-radius:999px;
                            background:var(--border);transition:background .2s;flex-shrink:0;pointer-events:none;">
                            <div style="
                                position:absolute;top:3px;left:3px;width:12px;height:12px;
                                border-radius:50%;background:#fff;
                                transition:transform .2s;
                                transform:translateX(0);">
                            </div>
                        </div>
                    </div>
                    <style>
                        .theme-pill[data-theme='dark'] { background: var(--accent) !important; }
                        .theme-pill[data-theme='dark'] div { transform: translateX(14px) !important; }
                    </style>
                    <div class="dropdown-separator"></div>
                    <a href="<?= url('/logout') ?>" class="dropdown-item danger">
                        <?php include __DIR__ . '/../partials/icons/logout.svg.php'; ?>
                        Log out
                    </a>
                </div>
            </div>
        </div>

    </aside>

<?php endif; ?>

    <!-- ====================================================
         MAIN
    ==================================================== -->
    <div class="main">

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
        <main class="page <?= $pageWide ? 'page-wide' : '' ?>" id="main-content">
            <?= $content ?? '' ?>
        </main>

    </div><!-- /.main -->

</div><!-- /.layout -->

<script src="<?= asset('js/app.js') ?>"></script>
<script>
    // Inject accent from DB
    setAccentColor('<?= e($accentColor) ?>');

    // Show hamburger on mobile (staff/admin only)
    <?php if (!$isStudent): ?>
    if (window.innerWidth <= 768) {
        const toggle = document.getElementById('sidebar-toggle');
        if (toggle) toggle.style.display = 'flex';
    }
    <?php endif; ?>
</script>

</body>
</html>