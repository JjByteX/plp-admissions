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

        <!-- Notification bell + Profile menu -->
        <div style="display:flex;align-items:center;gap:var(--space-3)">

        <!-- Notification Bell -->
        <?php $notifCount = notification_count(Auth::id()); ?>
        <div class="dropdown" id="notif-dropdown">
            <button class="btn-icon" data-dropdown type="button" aria-label="Notifications" style="position:relative">
                <svg width="20" height="20" fill="none" viewBox="0 0 24 24"><path stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 005.454-1.31A8.967 8.967 0 0118 9.75V9A6 6 0 006 9v.75a8.967 8.967 0 01-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 01-5.714 0m5.714 0a3 3 0 11-5.714 0"/></svg>
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
                            <div style="font-size:10px;color:var(--text-tertiary);margin-top:2px"><?= date('M j, g:i A', strtotime($n['created_at'])) ?></div>
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
                    <div class="user-role"><?= ucfirst(e($userRole)) ?></div>
                </div>
                <div class="dropdown-separator"></div>
                <a href="<?= url('/student/settings') ?>" class="dropdown-item">
                    <?php include __DIR__ . '/../partials/icons/ic_fluent_settings_24_regular.svg'; ?>
                    Settings
                </a>
                <?php
                // Check if student has an active application that can be withdrawn
                $_wdStmt = db()->prepare('SELECT id, overall_status FROM applicants WHERE user_id = ? ORDER BY id DESC LIMIT 1');
                $_wdStmt->execute([$authUser['id'] ?? 0]);
                $_wdAppl = $_wdStmt->fetch();
                $_canWithdraw = $_wdAppl && !in_array($_wdAppl['overall_status'] ?? '', ['withdrawn',''], true);
                ?>
                <?php if ($_canWithdraw): ?>
                <div class="dropdown-separator"></div>
                <a href="#" class="dropdown-item danger" onclick="event.preventDefault();document.getElementById('withdraw-modal').style.display='flex'">
                    <svg width="15" height="15" fill="none" viewBox="0 0 24 24"><path stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M9 9l6 6m0-6l-6 6M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    Withdraw Application
                </a>
                <?php endif; ?>
                <div class="dropdown-separator"></div>
                <a href="<?= url('/logout') ?>" class="dropdown-item danger">
                    <?php include __DIR__ . '/../partials/icons/ic_fluent_sign_out_24_regular.svg'; ?>
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
                    <?= icon('ic_fluent_more_horizontal_24_filled', 20, 'flex-shrink:0;color:var(--text-tertiary)') ?>
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

<script>window.__baseUrl = '<?= rtrim(BASE_URL, '/') ?>';</script>
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
                <div style="background:#fff7ed;border:1px solid #f97316;border-radius:var(--radius-md);padding:var(--space-4)">
                    <div style="display:flex;gap:var(--space-3);align-items:flex-start">
                        <svg width="18" height="18" fill="none" viewBox="0 0 24 24" style="flex-shrink:0;margin-top:1px">
                            <path stroke="#f97316" stroke-width="2" stroke-linecap="round" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        <div>
                            <div style="font-weight:var(--weight-semibold);font-size:var(--text-sm);color:#c2410c;margin-bottom:2px">This cannot be undone</div>
                            <p style="font-size:var(--text-sm);color:var(--text-secondary)">
                                Withdrawing permanently removes you from the admissions process.
                            </p>
                        </div>
                    </div>
                </div>
                <div>
                    <label class="form-label">Type <strong>WITHDRAW</strong> to confirm</label>
                    <input type="text" id="withdraw-confirm-input" class="form-control"
                           placeholder="WITHDRAW" autocomplete="off" style="max-width:220px"
                           oninput="document.getElementById('withdraw-submit-btn').disabled = this.value !== 'WITHDRAW'">
                </div>
                <div>
                    <label class="form-label">Reason <span style="color:var(--text-tertiary);font-weight:var(--weight-regular)">(optional)</span></label>
                    <textarea name="withdraw_reason" class="form-control" rows="2"
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
<script>
document.getElementById('withdraw-modal').addEventListener('click', function(e) {
    if (e.target === this) this.style.display = 'none';
});
</script>
<?php endif; ?>

</body>
</html>