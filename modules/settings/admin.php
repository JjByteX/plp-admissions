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
                $dir     = UPLOAD_PATH.'/branding/';
                if (!is_dir($dir)) mkdir($dir, 0755, true);
                if (move_uploaded_file($file['tmp_name'], $dir.$fname)) {
                    $logoPath = 'uploads/branding/'.$fname;
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

<div style="display:flex;flex-direction:column;gap:var(--space-6);max-width:560px">

    <!-- Quick links -->
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--space-3)">
        <a href="<?= url('/admin/users') ?>" class="card"
           style="padding:var(--space-4) var(--space-5);text-decoration:none;display:flex;align-items:center;gap:var(--space-3)">
            <svg width="20" height="20" fill="none" viewBox="0 0 24 24" style="color:var(--accent)"><path stroke="currentColor" stroke-width="1.8" stroke-linecap="round" d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2m22 0v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/></svg>
            <div>
                <div style="font-weight:var(--weight-medium);font-size:var(--text-sm)">User Management</div>
                <div style="font-size:var(--text-xs);color:var(--text-tertiary)">Staff & admin accounts</div>
            </div>
        </a>
        <a href="<?= url('/admin/school-year') ?>" class="card"
           style="padding:var(--space-4) var(--space-5);text-decoration:none;display:flex;align-items:center;gap:var(--space-3)">
            <svg width="20" height="20" fill="none" viewBox="0 0 24 24" style="color:var(--accent)"><path stroke="currentColor" stroke-width="1.8" stroke-linecap="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
            <div>
                <div style="font-weight:var(--weight-medium);font-size:var(--text-sm)">School Year</div>
                <div style="font-size:var(--text-xs);color:var(--text-tertiary)">Manage admission cycles</div>
            </div>
        </a>
        <a href="<?= url('/admin/results') ?>" class="card"
           style="padding:var(--space-4) var(--space-5);text-decoration:none;display:flex;align-items:center;gap:var(--space-3)">
            <svg width="20" height="20" fill="none" viewBox="0 0 24 24" style="color:var(--accent)"><path stroke="currentColor" stroke-width="1.8" stroke-linecap="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
            <div>
                <div style="font-weight:var(--weight-medium);font-size:var(--text-sm)">Export Results</div>
                <div style="font-size:var(--text-xs);color:var(--text-tertiary)">CSV download</div>
            </div>
        </a>
        <div class="card" style="padding:var(--space-4) var(--space-5);display:flex;align-items:center;gap:var(--space-3)">
            <svg width="20" height="20" fill="none" viewBox="0 0 24 24" style="color:var(--accent)"><path stroke="currentColor" stroke-width="1.8" d="M9 3H5a2 2 0 00-2 2v4m6-6h10a2 2 0 012 2v4M9 3v18m0 0h10a2 2 0 002-2V9M9 21H5a2 2 0 01-2-2V9m0 0h18"/></svg>
            <div>
                <div style="font-weight:var(--weight-medium);font-size:var(--text-sm)">Current Year</div>
                <div style="font-size:var(--text-xs);color:var(--text-tertiary)"><?= e(school_setting('current_school_year','—')) ?></div>
            </div>
        </div>
    </div>

    <!-- Appearance -->
    <div class="card" style="padding:var(--space-6)">
        <div style="font-weight:var(--weight-semibold);margin-bottom:var(--space-4)">Appearance</div>
        <div style="display:flex;align-items:center;justify-content:space-between">
            <div>
                <div style="font-size:var(--text-sm);font-weight:var(--weight-medium)">Dark Mode</div>
                <div style="font-size:var(--text-xs);color:var(--text-tertiary)">Saved to this device</div>
            </div>
            <button class="btn btn-secondary btn-sm" onclick="
                const t = document.documentElement.dataset.theme==='dark'?'light':'dark';
                document.documentElement.dataset.theme=t;
                localStorage.setItem('plp_theme',t);
                this.textContent=t==='dark'?'Switch to Light':'Switch to Dark';">
                Toggle Theme
            </button>
        </div>
    </div>

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
                        <img src="<?= url('/'.$schoolLogo) ?>" alt="Logo"
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

    <!-- About -->
    <div class="card" style="padding:var(--space-6)">
        <div style="font-weight:var(--weight-semibold);margin-bottom:var(--space-4)">Help & About</div>
        <div style="display:flex;flex-direction:column;gap:var(--space-3)">
            <div style="display:flex;justify-content:space-between">
                <span style="font-size:var(--text-sm);color:var(--text-secondary)">System Version</span>
                <span style="font-size:var(--text-sm);font-weight:var(--weight-medium)"><?= e(school_setting('system_version','1.0.0')) ?></span>
            </div>
            <div style="display:flex;justify-content:space-between">
                <span style="font-size:var(--text-sm);color:var(--text-secondary)">PHP Version</span>
                <span style="font-size:var(--text-sm);font-weight:var(--weight-medium)"><?= PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION ?></span>
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
document.getElementById('color-picker').addEventListener('input', function() {
    document.getElementById('hex-input').value = this.value;
});
</script>

<?php
$content   = ob_get_clean();
$pageTitle = 'Settings';
$activeNav = 'settings';
include VIEWS_PATH . '/layouts/app.php';
