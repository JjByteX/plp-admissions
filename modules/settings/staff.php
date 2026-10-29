<?php
// ============================================================
// modules/settings/staff.php
// M8 — Staff: school branding + password change
// ============================================================

require_once CORE_PATH . '/bootstrap.php';
Auth::requireRole(ROLE_STAFF, ROLE_PROCTOR, ROLE_SSO, ROLE_DEAN, ROLE_ADMIN);
settings_modal_gate();

$db      = db();
$userId  = Auth::id();
$user    = Auth::user();
$errors  = [];
$success = [];

if (($action = require_post_action()) !== null) {

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
            Auth::rememberRevokeAll((int) $userId);
            $success[] = 'Password changed.';
        }
    }
}

ob_start();
?>

<?php foreach ($errors as $e): ?>
    <div class="alert alert-error mb-3"><?= e($e) ?></div>
<?php endforeach; ?>
<?php foreach ($success as $s): ?>
    <div class="alert alert-success mb-3"><?= e($s) ?></div>
<?php endforeach; ?>

<div class="flex flex-column gap-6 mwpx-560 my-0 mx-auto">

    <!-- Appearance: text size (per-device) + theme toggle -->
    <div class="card p-6" data-settings-section="appearance">
        <div class="fw-semibold mb-4">Appearance</div>
        <?php include VIEWS_PATH . '/partials/font_size_setting.php'; ?>
        <?php include VIEWS_PATH . '/partials/dark_mode_setting.php'; ?>
    </div>

    <!-- Password -->
    <div class="card p-6" data-settings-section="password">
        <div class="fw-semibold mb-5">Change Password</div>
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="change_password">
            <div class="flex flex-column gap-4">
                <div>
                    <label class="form-label" for="staff_current_password">Current Password</label>
                    <input type="password" id="staff_current_password" name="current_password" class="form-control" required>
                </div>
                <div>
                    <label class="form-label" for="staff_new_password">New Password</label>
                    <input type="password" id="staff_new_password" name="new_password" class="form-control" minlength="8" required>
                </div>
                <div>
                    <label class="form-label" for="staff_confirm_password">Confirm New Password</label>
                    <input type="password" id="staff_confirm_password" name="confirm_password" class="form-control" required>
                </div>
            </div>
            <label class="flex align-items-center gap-2 text-sm mt-4" for="staff_show_password">
                <input type="checkbox" id="staff_show_password" class="js-show-password"> Show passwords
            </label>
            <div class="mt-5">
                <button type="submit" class="btn btn-primary">Update Password</button>
            </div>
        </form>
    </div>

    <!-- Help & About -->
    <div class="card p-6" data-settings-section="about">
        <div class="fw-semibold mb-4">Help & About</div>
        <div class="flex flex-column gap-3">
            <div class="flex justify-content-between">
                <span class="text-sm text-secondary">System Version</span>
                <span class="text-sm fw-medium"><?= e(school_setting('system_version','1.0.0')) ?></span>
            </div>
            <div class="flex justify-content-between">
                <span class="text-sm text-secondary">System</span>
                <span class="text-sm fw-medium">PLP Admission System</span>
            </div>
            <div class="flex justify-content-between">
                <span class="text-sm text-secondary">Client</span>
                <span class="text-sm fw-medium">Pamantasan ng Lungsod ng Pasig</span>
            </div>
        </div>
    </div>

</div>

<?php
$content   = ob_get_clean();
$pageTitle = 'Settings';
$pageScripts = ['js/pages/show-password.js'];
require_once VIEWS_PATH . '/partials/page_header.php';
$pageBreadcrumb = [page_home_crumb()];
$activeNav = 'settings';
include VIEWS_PATH . '/layouts/app.php';
