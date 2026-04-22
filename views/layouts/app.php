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
                    <?php include __DIR__ . '/../partials/icons/ic_fluent_building_bank_24_regular.svg'; ?>
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
                    <?php include __DIR__ . '/../partials/icons/ic_fluent_settings_24_regular.svg'; ?>
                    Settings
                </a>
                <div class="dropdown-separator"></div>
                <a href="<?= url('/logout') ?>" class="dropdown-item danger">
                    <?php include __DIR__ . '/../partials/icons/ic_fluent_sign_out_24_regular.svg'; ?>
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
                    <?php include __DIR__ . '/../partials/icons/ic_fluent_building_bank_24_regular.svg'; ?>
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
                    <?= icon('ic_fluent_arrow_down_24_regular', 14, 'flex-shrink:0;color:var(--text-tertiary)') ?>
                </div>
                <div class="dropdown-menu">
                    <a href="<?= url($userRole === 'staff' ? '/staff/settings' : '/admin/settings') ?>" class="dropdown-item">
                        <?php include __DIR__ . '/../partials/icons/ic_fluent_settings_24_regular.svg'; ?>
                        Settings
                    </a>
                    <div class="dropdown-item theme-toggle-row" onclick="
                        const t = document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark';
                        document.documentElement.dataset.theme = t;
                        localStorage.setItem('plp_theme', t);
                        document.querySelector('.theme-pill').dataset.theme = t;" style="cursor:pointer;justify-content:space-between;">
                        <span style="display:flex;align-items:center;gap:var(--space-2)">
                            <?= icon('ic_fluent_weather_moon_24_regular', 15) ?>
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
                        <?php include __DIR__ . '/../partials/icons/ic_fluent_sign_out_24_regular.svg'; ?>
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

<?php if (Auth::check()): ?>
<!-- ── Session timeout warning ──────────────────────────────── -->
<div id="session-timeout-modal" style="
    display:none;position:fixed;inset:0;z-index:9999;
    background:rgba(0,0,0,.55);backdrop-filter:blur(4px);
    align-items:center;justify-content:center;">
    <div style="
        background:var(--bg-elevated);border:1px solid var(--border);
        border-radius:var(--radius-xl);padding:var(--space-8);
        max-width:360px;width:90%;text-align:center;
        box-shadow:0 24px 48px rgba(0,0,0,.3)">
        <div style="width:48px;height:48px;border-radius:50%;
                    background:var(--warning-bg,#fef3c7);
                    display:flex;align-items:center;justify-content:center;
                    margin:0 auto var(--space-4)">
            <svg width="24" height="24" fill="none" viewBox="0 0 24 24">
                <path stroke="#d97706" stroke-width="2" stroke-linecap="round"
                      d="M12 9v4m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
            </svg>
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

<script>
(function () {
    const WARN_BEFORE   = <?= SESSION_WARN_BEFORE ?>;         // seconds before expiry to warn
    let secsRemaining   = <?= Session::secondsRemaining() ?>; // seconds left right now
    let countdownTimer  = null;
    let warnTimer       = null;
    const modal         = document.getElementById('session-timeout-modal');
    const countdownEl   = document.getElementById('session-countdown');

    function fmtTime(s) {
        const m = Math.floor(s / 60), r = s % 60;
        return m > 0 ? `${m}m ${r}s` : `${r}s`;
    }

    function showWarning() {
        let secs = WARN_BEFORE;
        countdownEl.textContent = fmtTime(secs);
        modal.style.display = 'flex';
        countdownTimer = setInterval(() => {
            secs--;
            if (secs <= 0) {
                clearInterval(countdownTimer);
                window.location.href = '<?= url('/login') ?>';
            } else {
                countdownEl.textContent = fmtTime(secs);
            }
        }, 1000);
    }

    function scheduleWarning() {
        if (warnTimer) clearTimeout(warnTimer);
        const fireIn = Math.max(0, (secsRemaining - WARN_BEFORE) * 1000);
        warnTimer = setTimeout(showWarning, fireIn);
    }

    async function keepAlive() {
        modal.style.display = 'none';
        clearInterval(countdownTimer);
        try {
            const res  = await fetch('<?= url('/auth/keepalive') ?>', {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest',
                           'Content-Type': 'application/x-www-form-urlencoded' },
                body: '_csrf=<?= csrf_token() ?>'
            });
            const data = await res.json();
            secsRemaining = data.seconds_remaining ?? secsRemaining;
        } catch (e) { /* ignore */ }
        scheduleWarning();
    }

    function logOutNow() {
        window.location.href = '<?= url('/logout') ?>';
    }

    // Expose so login page can trigger the "session expired" modal
    window.showTimeoutModal = function () {
        countdownEl.textContent = '0s';
        modal.style.display = 'flex';
        // Replace buttons with just a "Log In Again" button
        modal.querySelector('div[style*="flex-direction:column"]').innerHTML =
            `<a href="<?= url('/login') ?>" class="btn btn-primary" style="width:100%">Log In Again</a>`;
        modal.querySelector('#session-countdown').closest('div').textContent =
            'Your session expired due to inactivity. Please log in again.';
        modal.querySelector('div[style*="font-weight"]').textContent = 'Session Expired';
    };

    scheduleWarning();
})();
</script>
<?php endif; ?>

</body>
</html>