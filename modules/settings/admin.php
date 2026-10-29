<?php
// ============================================================
// modules/settings/admin.php
// M8 — Admin: system settings, branding, password, appearance
// ============================================================

require_once CORE_PATH . '/bootstrap.php';
Auth::requireRole(ROLE_ADMIN);
settings_modal_gate();

$db      = db();
$userId  = Auth::id();
$user    = Auth::user();
$errors  = [];
$success = [];

if (($action = require_post_action()) !== null) {

    if ($action === 'update_branding') {
        $schoolName  = post_str('school_name');
        $accentColor = post_str('accent_color', '#2d6a4f');
        if (!$schoolName) { $errors[] = 'School name is required.'; }

        $logoPath = null;
        if (!empty($_FILES['school_logo']['name'])) {
            $file    = $_FILES['school_logo'];
            $finfo   = new finfo(FILEINFO_MIME_TYPE);
            $mime    = $finfo->file($file['tmp_name']);
            $allowed = ['image/jpeg','image/png','image/webp','image/svg+xml'];
            if (!in_array($mime, $allowed, true)) { $errors[] = 'Invalid logo format.'; }
            elseif ($file['size'] > 2*1024*1024) { $errors[] = 'Logo max 2 MB.'; }
            else {
                $ext     = pathinfo($file['name'], PATHINFO_EXTENSION);
                $fname   = 'school_logo_'.time().'.'.strtolower($ext);
                $fileUrl = uploadcare_upload($file['tmp_name'], $fname, $mime);
                if ($fileUrl) {
                    $logoPath = $fileUrl;
                } else { $errors[] = 'Logo upload failed.'; }
            }
        }

        if (empty($errors)) {
            set_school_setting('school_name', $schoolName);
            set_school_setting('accent_color', $accentColor);
            if ($logoPath) set_school_setting('school_logo', $logoPath);
            audit_log(AuditAction::SETTINGS_BRANDING_UPDATED, "Updated branding: school_name={$schoolName}");
            $success[] = 'Branding updated.';
        }
    }

    if ($action === 'change_password') {
        $cur  = $_POST['current_password'] ?? '';
        $new  = $_POST['new_password'] ?? '';
        $conf = $_POST['confirm_password'] ?? '';
        $stmt = $db->prepare('SELECT password_hash FROM users WHERE id=?');
        $stmt->execute([$userId]);
        $hash = $stmt->fetchColumn();
        if (!password_verify($cur, $hash)) { $errors[] = 'Current password incorrect.'; }
        elseif (strlen($new) < 8)          { $errors[] = 'Min 8 characters.'; }
        elseif ($new !== $conf)            { $errors[] = 'Passwords do not match.'; }
        else {
            $db->prepare('UPDATE users SET password_hash=? WHERE id=?')
               ->execute([password_hash($new, PASSWORD_BCRYPT, ['cost'=>12]), $userId]);
            audit_log(AuditAction::ADMIN_PASSWORD_CHANGED, 'Admin changed their own password', 'user', $userId);
            Auth::rememberRevokeAll((int) $userId);
            $success[] = 'Password changed.';
        }
    }
}

$schoolName  = school_setting('school_name', 'Pamantasan ng Lungsod ng Pasig');
$accentColor = school_setting('accent_color', '#2d6a4f');
$schoolLogo  = school_setting('school_logo', '');

ob_start();
?>

<?php foreach ($errors as $e): ?>
    <div class="alert alert-error mb-3"><?= e($e) ?></div>
<?php endforeach; ?>
<?php foreach ($success as $s): ?>
    <div class="alert alert-success mb-3"><?= e($s) ?></div>
<?php endforeach; ?>

<div class="flex flex-column gap-6 mwpx-560 my-0 mx-auto">

       <!-- Appearance (per-device text size + dark mode) -->
    <div class="card p-6" data-settings-section="appearance">
        <div class="fw-semibold mb-4">Appearance</div>
        <?php include VIEWS_PATH . '/partials/font_size_setting.php'; ?>
        <?php include VIEWS_PATH . '/partials/dark_mode_setting.php'; ?>
    </div>

    <!-- Branding -->
    <div class="card p-6" data-settings-section="branding">
        <div class="fw-semibold mb-5">School Branding</div>
        <form method="POST" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update_branding">
            <div class="flex flex-column gap-4">
                <?php if ($schoolLogo): ?>
                    <div>
                        <span class="form-label">Current Logo</span>
                        <img src="<?= str_starts_with($schoolLogo, 'http') ? e($schoolLogo) : e(url('/'. $schoolLogo)) ?>" alt="Logo"
                             class="rounded" style="height:48px;display:block">
                    </div>
                <?php endif; ?>
                <div>
                    <label class="form-label" for="school_logo">School Logo</label>
                    <input type="file" id="school_logo" name="school_logo" class="form-control" accept=".jpg,.jpeg,.png,.webp,.svg">
                    <p class="text-xs text-tertiary mt-1">JPG, PNG, WEBP, or SVG · max 2 MB</p>
                </div>
                <div>
                    <label class="form-label" for="school_name">School Name</label>
                    <input type="text" id="school_name" name="school_name" class="form-control" value="<?= e($schoolName) ?>" required>
                </div>
                <div>
                    <label class="form-label" for="color-picker">Accent Color</label>
                    <div class="flex align-items-center gap-3">
                        <input type="color" name="accent_color" value="<?= e($accentColor) ?>"
                               class="bd rounded pointer" style="width:44px;height:36px;padding:2px;background:none"
                               id="color-picker">
                        <input type="text" class="form-control" style="max-width:120px" aria-label="Accent color hex"
                               value="<?= e($accentColor) ?>" id="hex-input"
                               oninput="document.getElementById('color-picker').value=this.value">
                    </div>
                </div>
            </div>
            <div class="mt-5">
                <button type="submit" class="btn btn-primary">Save Branding</button>
            </div>
        </form>
    </div>

    <!-- Password -->
    <div class="card p-6" data-settings-section="password">
        <div class="fw-semibold mb-5">Change Password</div>
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="change_password">
            <div class="flex flex-column gap-4">
                <div>
                    <label class="form-label" for="admin_current_password">Current Password</label>
                    <input type="password" id="admin_current_password" name="current_password" class="form-control" required>
                </div>
                <div>
                    <label class="form-label" for="admin_new_password">New Password</label>
                    <input type="password" id="admin_new_password" name="new_password" class="form-control" minlength="8" required>
                </div>
                <div>
                    <label class="form-label" for="admin_confirm_password">Confirm New Password</label>
                    <input type="password" id="admin_confirm_password" name="confirm_password" class="form-control" required>
                </div>
            </div>
            <label class="flex align-items-center gap-2 text-sm mt-4" for="admin_show_password">
                <input type="checkbox" id="admin_show_password" class="js-show-password"> Show passwords
            </label>
            <div class="mt-5">
                <button type="submit" class="btn btn-primary">Update Password</button>
            </div>
        </form>
    </div>

</div>

<?php
$content   = ob_get_clean();
$pageTitle = 'Settings';
$pageScripts = ['js/pages/admin-settings.js', 'js/pages/show-password.js'];
require_once VIEWS_PATH . '/partials/page_header.php';
$pageBreadcrumb = [page_home_crumb()];
$activeNav = 'settings';
include VIEWS_PATH . '/layouts/app.php';