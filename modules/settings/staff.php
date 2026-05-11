<?php
// ============================================================
// modules/settings/staff.php
// M8 — Staff: school branding + password change
// ============================================================

require_once CORE_PATH . '/bootstrap.php';
Auth::requireRole(ROLE_STAFF, ROLE_PROCTOR, ROLE_SSO, ROLE_DEAN, ROLE_ADMIN);

$db      = db();
$userId  = Auth::id();
$user    = Auth::user();
$errors  = [];
$success = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    // -- Password --------------------------------------------------
    if ($action === 'change_password') {
        $current = $_POST['current_password'] ?? '';
        $new     = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        $stmt = $db->prepare('SELECT password_hash FROM users WHERE id=?');
        $stmt->execute([$userId]);
        $hash = $stmt->fetchColumn();

        if (!password_verify($current, $hash)) {
            $errors[] = 'Current password is incorrect.';
        } elseif (strlen($new) < 8) {
            $errors[] = 'New password must be at least 8 characters.';
        } elseif ($new !== $confirm) {
            $errors[] = 'Passwords do not match.';
        } else {
            $newHash = password_hash($new, PASSWORD_BCRYPT, ['cost' => 12]);
            $db->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([$newHash, $userId]);
            $success[] = 'Password changed.';
        }
    }
}

ob_start();
?>

<?php foreach ($errors as $e): ?>
    <div class="alert alert-error" style="margin-bottom:var(--space-3)"><?= e($e) ?></div>
<?php endforeach; ?>
<?php foreach ($success as $s): ?>
    <div class="alert alert-success" style="margin-bottom:var(--space-3)"><?= e($s) ?></div>
<?php endforeach; ?>

<div style="display:flex;justify-content:flex-start;margin-bottom:var(--space-4);max-width:560px;margin-left:auto;margin-right:auto">
    <a href="javascript:history.back()" class="btn btn-ghost btn-sm" style="display:flex;align-items:center;gap:5px">
        <?= icon('ic_fluent_arrow_left_24_regular', 16) ?>
        Back
    </a>
</div>

<div style="display:flex;flex-direction:column;gap:var(--space-6);max-width:560px;margin:0 auto">

    <!-- Appearance: theme toggle -->
    <div class="card" style="padding:var(--space-6)">
        <div style="font-weight:var(--weight-semibold);margin-bottom:var(--space-4)">Appearance</div>
        <div style="display:flex;align-items:center;justify-content:space-between">
            <div>
                <div style="font-size:var(--text-sm);font-weight:var(--weight-medium)">Dark Mode</div>
                <div style="font-size:var(--text-xs);color:var(--text-tertiary)">Saved to this device</div>
            </div>
            <button id="theme-toggle-btn" class="btn btn-secondary btn-sm" onclick="toggleThemeFromSettings()" aria-pressed="false">
                <span id="theme-toggle-label">Switch to Dark</span>
            </button>
        </div>
    </div>

    <!-- Password -->
    <div class="card" style="padding:var(--space-6)">
        <div style="font-weight:var(--weight-semibold);margin-bottom:var(--space-5)">Change Password</div>
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="change_password">
            <div style="display:flex;flex-direction:column;gap:var(--space-4)">
                <div>
                    <label class="form-label">Current Password</label>
                    <input type="password" name="current_password" class="form-control" required>
                </div>
                <div>
                    <label class="form-label">New Password</label>
                    <input type="password" name="new_password" class="form-control" minlength="8" required>
                </div>
                <div>
                    <label class="form-label">Confirm New Password</label>
                    <input type="password" name="confirm_password" class="form-control" required>
                </div>
            </div>
            <div style="margin-top:var(--space-5)">
                <button type="submit" class="btn btn-primary">Update Password</button>
            </div>
        </form>
    </div>

    <!-- Help & About -->
    <div class="card" style="padding:var(--space-6)">
        <div style="font-weight:var(--weight-semibold);margin-bottom:var(--space-4)">Help & About</div>
        <div style="display:flex;flex-direction:column;gap:var(--space-3)">
            <div style="display:flex;justify-content:space-between">
                <span style="font-size:var(--text-sm);color:var(--text-secondary)">System Version</span>
                <span style="font-size:var(--text-sm);font-weight:var(--weight-medium)"><?= e(school_setting('system_version','1.0.0')) ?></span>
            </div>
            <div style="display:flex;justify-content:space-between">
                <span style="font-size:var(--text-sm);color:var(--text-secondary)">System</span>
                <span style="font-size:var(--text-sm);font-weight:var(--weight-medium)">PLP Admission System</span>
            </div>
            <div style="display:flex;justify-content:space-between">
                <span style="font-size:var(--text-sm);color:var(--text-secondary)">Client</span>
                <span style="font-size:var(--text-sm);font-weight:var(--weight-medium)">Pamantasan ng Lungsod ng Pasig</span>
            </div>
        </div>
    </div>

</div>

<script>
function toggleThemeFromSettings() {
    const html  = document.documentElement;
    const theme = html.dataset.theme === 'dark' ? 'light' : 'dark';
    html.dataset.theme = theme;
    localStorage.setItem('plp_theme', theme);
    const btn = document.getElementById('theme-toggle-btn');
    document.getElementById('theme-toggle-label').textContent = theme === 'dark' ? 'Switch to Light' : 'Switch to Dark';
    btn.setAttribute('aria-pressed', theme === 'dark' ? 'true' : 'false');
    // sync the sidebar pill too
    const pill = document.querySelector('.theme-pill');
    if (pill) pill.dataset.theme = theme;
}
document.addEventListener('DOMContentLoaded', function() {
    const t = localStorage.getItem('plp_theme') || 'light';
    document.getElementById('theme-toggle-label').textContent = t === 'dark' ? 'Switch to Light' : 'Switch to Dark';
    document.getElementById('theme-toggle-btn').setAttribute('aria-pressed', t === 'dark' ? 'true' : 'false');
});
</script>

<?php
$content   = ob_get_clean();
$pageTitle = 'Settings';
$activeNav = 'settings';
include VIEWS_PATH . '/layouts/app.php';
