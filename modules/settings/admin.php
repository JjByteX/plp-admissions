<?php
// ============================================================
// modules/settings/admin.php
// M8 — Admin: system settings, branding, password, appearance
// ============================================================

require_once CORE_PATH . '/bootstrap.php';
Auth::requireRole(ROLE_ADMIN);

$db      = db();
$userId  = Auth::id();
$user    = Auth::user();
$errors  = [];
$success = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'update_branding') {
        $schoolName  = trim($_POST['school_name'] ?? '');
        $accentColor = trim($_POST['accent_color'] ?? '#2d6a4f');
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
            $ups = fn($k,$v) => $db->prepare(
                'INSERT INTO school_settings (setting_key,setting_value) VALUES (?,?)
                 ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)'
            )->execute([$k,$v]);
            $ups('school_name', $schoolName);
            $ups('accent_color', $accentColor);
            if ($logoPath) $ups('school_logo', $logoPath);
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
    <div class="alert alert-error" style="margin-bottom:var(--space-3)"><?= e($e) ?></div>
<?php endforeach; ?>
<?php foreach ($success as $s): ?>
    <div class="alert alert-success" style="margin-bottom:var(--space-3)"><?= e($s) ?></div>
<?php endforeach; ?>

<div style="display:flex;flex-direction:column;gap:var(--space-6);max-width:560px;margin:0 auto">

    <!-- Branding -->
    <div class="card" style="padding:var(--space-6)">
        <div style="font-weight:var(--weight-semibold);margin-bottom:var(--space-5)">School Branding</div>
        <form method="POST" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update_branding">
            <div style="display:flex;flex-direction:column;gap:var(--space-4)">
                <?php if ($schoolLogo): ?>
                    <div>
                        <label class="form-label">Current Logo</label>
                        <img src="<?= str_starts_with($schoolLogo, 'http') ? e($schoolLogo) : e(url('/'. $schoolLogo)) ?>" alt="Logo"
                             style="height:48px;border-radius:var(--radius-sm);display:block">
                    </div>
                <?php endif; ?>
                <div>
                    <label class="form-label">School Logo</label>
                    <input type="file" name="school_logo" class="form-control" accept=".jpg,.jpeg,.png,.webp,.svg">
                    <p style="font-size:var(--text-xs);color:var(--text-tertiary);margin-top:4px">JPG, PNG, WEBP, or SVG · max 2 MB</p>
                </div>
                <div>
                    <label class="form-label">School Name</label>
                    <input type="text" name="school_name" class="form-control" value="<?= e($schoolName) ?>" required>
                </div>
                <div>
                    <label class="form-label">Accent Color</label>
                    <div style="display:flex;align-items:center;gap:var(--space-3)">
                        <input type="color" name="accent_color" value="<?= e($accentColor) ?>"
                               style="width:44px;height:36px;padding:2px;border:1px solid var(--border);border-radius:var(--radius-md);cursor:pointer;background:none"
                               id="color-picker">
                        <input type="text" class="form-control" style="max-width:120px"
                               value="<?= e($accentColor) ?>" id="hex-input"
                               oninput="document.getElementById('color-picker').value=this.value">
                    </div>
                </div>
            </div>
            <div style="margin-top:var(--space-5)">
                <button type="submit" class="btn btn-primary">Save Branding</button>
            </div>
        </form>
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

</div>

<script>
document.getElementById('color-picker').addEventListener('input', function() {
    document.getElementById('hex-input').value = this.value;
});
</script>

<?php
$content   = ob_get_clean();
$pageTitle = 'Settings';
$activeNav = 'settings';
include VIEWS_PATH . '/layouts/app.php';