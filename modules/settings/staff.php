<?php
// ============================================================
// modules/settings/staff.php
// M8 — Staff: school branding + password change
// ============================================================

require_once CORE_PATH . '/bootstrap.php';
Auth::requireRole(ROLE_STAFF, ROLE_ADMIN);

$db      = db();
$userId  = Auth::id();
$user    = Auth::user();
$errors  = [];
$success = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    // -- School branding -------------------------------------------
    if ($action === 'update_branding') {
        $schoolName  = trim($_POST['school_name'] ?? '');
        $accentColor = trim($_POST['accent_color'] ?? '#2d6a4f');

        if (!$schoolName) { $errors[] = 'School name is required.'; }

        // Logo upload
        $logoPath = null;
        if (!empty($_FILES['school_logo']['name'])) {
            $file    = $_FILES['school_logo'];
            $finfo   = new finfo(FILEINFO_MIME_TYPE);
            $mime    = $finfo->file($file['tmp_name']);
            $allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/svg+xml'];
            if (!in_array($mime, $allowed, true)) {
                $errors[] = 'Logo must be JPG, PNG, WEBP, or SVG.';
            } elseif ($file['size'] > 2 * 1024 * 1024) {
                $errors[] = 'Logo must be under 2 MB.';
            } else {
                $ext      = pathinfo($file['name'], PATHINFO_EXTENSION);
                $filename = 'school_logo_' . time() . '.' . strtolower($ext);
                $destDir  = UPLOAD_PATH . '/branding/';
                if (!is_dir($destDir)) mkdir($destDir, 0755, true);
                if (move_uploaded_file($file['tmp_name'], $destDir . $filename)) {
                    $logoPath = 'uploads/branding/' . $filename;
                } else {
                    $errors[] = 'Logo upload failed.';
                }
            }
        }

        if (empty($errors)) {
            $upsert = fn($key, $val) => $db->prepare(
                'INSERT INTO school_settings (setting_key, setting_value) VALUES (?,?)
                 ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)'
            )->execute([$key, $val]);

            $upsert('school_name',  $schoolName);
            $upsert('accent_color', $accentColor);
            if ($logoPath) $upsert('school_logo', $logoPath);

            $success[] = 'School branding updated.';
        }
    }

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

// Current settings
$schoolName  = school_setting('school_name', 'Pamantasan ng Lungsod ng Pasig');
$accentColor = school_setting('accent_color', '#2d6a4f');
$schoolLogo  = school_setting('school_logo', '');

ob_start();
?>

<div class="page-header">
    <h1 class="page-title">Settings</h1>
    <p class="page-description">School branding and account settings.</p>
</div>

<?php foreach ($errors as $e): ?>
    <div class="alert alert-error" style="margin-bottom:var(--space-3)"><?= e($e) ?></div>
<?php endforeach; ?>
<?php foreach ($success as $s): ?>
    <div class="alert alert-success" style="margin-bottom:var(--space-3)"><?= e($s) ?></div>
<?php endforeach; ?>

<div style="display:flex;flex-direction:column;gap:var(--space-6);max-width:560px">

    <!-- Appearance: theme toggle -->
    <div class="card" style="padding:var(--space-6)">
        <div style="font-weight:var(--weight-semibold);margin-bottom:var(--space-4)">Appearance</div>
        <div style="display:flex;align-items:center;justify-content:space-between">
            <div>
                <div style="font-size:var(--text-sm);font-weight:var(--weight-medium)">Dark Mode</div>
                <div style="font-size:var(--text-xs);color:var(--text-tertiary)">Saved to this device</div>
            </div>
            <button id="theme-toggle-btn" class="btn btn-secondary btn-sm" onclick="toggleThemeFromSettings()">
                <span id="theme-toggle-label">Toggle</span>
            </button>
        </div>
    </div>

    <!-- School branding -->
    <div class="card" style="padding:var(--space-6)">
        <div style="font-weight:var(--weight-semibold);margin-bottom:var(--space-5)">School Branding</div>
        <form method="POST" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update_branding">
            <div style="display:flex;flex-direction:column;gap:var(--space-4)">

                <!-- Current logo preview -->
                <?php if ($schoolLogo): ?>
                    <div>
                        <label class="form-label">Current Logo</label>
                        <img src="<?= url('/' . $schoolLogo) ?>" alt="Logo"
                             style="height:48px;display:block;border-radius:var(--radius-sm)">
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
                               style="width:44px;height:36px;padding:2px;border:1px solid var(--border);
                                      border-radius:var(--radius-md);cursor:pointer;background:none">
                        <input type="text" id="accent-hex" class="form-control" style="max-width:120px"
                               value="<?= e($accentColor) ?>" placeholder="#2d6a4f"
                               oninput="document.querySelector('[name=accent_color]').value=this.value">
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
// Sync color picker → hex input
document.querySelector('[name=accent_color]').addEventListener('input', function() {
    document.getElementById('accent-hex').value = this.value;
});

function toggleThemeFromSettings() {
    const html  = document.documentElement;
    const theme = html.dataset.theme === 'dark' ? 'light' : 'dark';
    html.dataset.theme = theme;
    localStorage.setItem('plp_theme', theme);
    document.getElementById('theme-toggle-label').textContent = theme === 'dark' ? 'Switch to Light' : 'Switch to Dark';
}
document.addEventListener('DOMContentLoaded', function() {
    const t = localStorage.getItem('plp_theme') || 'light';
    document.getElementById('theme-toggle-label').textContent = t === 'dark' ? 'Switch to Light' : 'Switch to Dark';
});
</script>

<?php
$content   = ob_get_clean();
$pageTitle = 'Settings';
$activeNav = 'settings';
include VIEWS_PATH . '/layouts/app.php';
