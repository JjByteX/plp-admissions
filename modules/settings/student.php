<?php
// ============================================================
// modules/settings/student.php
// M8 — Student: update profile and change password
// ============================================================

require_once CORE_PATH . '/bootstrap.php';
Auth::requireRole(ROLE_STUDENT);

$db     = db();
$userId = Auth::id();
$user   = Auth::user();
$errors  = [];
$success = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'update_profile') {
        $firstName  = trim($_POST['first_name']  ?? '');
        $middleName = trim($_POST['middle_name'] ?? '');
        $lastName   = trim($_POST['last_name']   ?? '');
        $suffix     = trim($_POST['suffix']      ?? '');
        $birthdate  = trim($_POST['birthdate']   ?? '');
        $sex        = trim($_POST['sex']         ?? '');
        $address    = trim($_POST['address']     ?? '');
        $phone      = trim($_POST['phone']       ?? '');

        if (!$firstName)  $errors[] = 'First name is required.';
        if (!$lastName)   $errors[] = 'Last name is required.';
        if (!$birthdate || !strtotime($birthdate)) $errors[] = 'Enter a valid birthdate.';
        if (!in_array($sex, ['M', 'F'], true))   $errors[] = 'Select a sex.';
        if (!$address)    $errors[] = 'Address is required.';
        if (!$phone)      $errors[] = 'Phone number is required.';

        if (empty($errors)) {
            $parts = array_filter([$firstName, $middleName ?: null, $lastName, $suffix ?: null]);
            $displayName = implode(' ', $parts);

            $fullAddress = $address;
            if (!preg_match('/pasig/i', $fullAddress)) {
                $fullAddress = rtrim($fullAddress, ', ') . ', Pasig City';
            }

            $db->prepare('UPDATE users SET
                name=?, first_name=?, middle_name=?, last_name=?, suffix=?,
                birthdate=?, sex=?, address=?, phone=?
                WHERE id=?')
                ->execute([$displayName, $firstName, $middleName, $lastName, $suffix,
                           $birthdate, $sex, $fullAddress, $phone, $userId]);

            Session::set('user_name', $displayName);
            $success[] = 'Profile updated.';
            $user = array_merge($user, [
                'name' => $displayName, 'first_name' => $firstName, 'middle_name' => $middleName,
                'last_name' => $lastName, 'suffix' => $suffix, 'birthdate' => $birthdate,
                'sex' => $sex, 'address' => $fullAddress, 'phone' => $phone,
            ]);
        }
    }

    if ($action === 'change_password') {
        $current = $_POST['current_password'] ?? '';
        $new     = $_POST['new_password']     ?? '';
        $confirm = $_POST['confirm_password']  ?? '';

        if (!password_verify($current, $user['password_hash'] ?? '')) {
            $errors[] = 'Current password is incorrect.';
        } elseif (strlen($new) < 8) {
            $errors[] = 'New password must be at least 8 characters.';
        } elseif ($new !== $confirm) {
            $errors[] = 'New passwords do not match.';
        } else {
            $hash = password_hash($new, PASSWORD_BCRYPT, ['cost' => 12]);
            $db->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([$hash, $userId]);
            $success[] = 'Password changed successfully.';
        }
    }
}

// Strip ", Pasig City" suffix for display in the address input
$addressDisplay = preg_replace('/,?\s*Pasig City\s*$/i', '', $user['address'] ?? '');

ob_start();
?>

<?php foreach ($errors as $e): ?>
    <div class="alert alert-error" style="margin-bottom:var(--space-3)"><?= e($e) ?></div>
<?php endforeach; ?>
<?php foreach ($success as $s): ?>
    <div class="alert alert-success" style="margin-bottom:var(--space-3)"><?= e($s) ?></div>
<?php endforeach; ?>

<div style="display:flex;flex-direction:column;gap:var(--space-6);max-width:560px">

    <!-- Profile -->
    <div class="card" style="padding:var(--space-6)">
        <div style="font-weight:var(--weight-semibold);margin-bottom:var(--space-5)">Profile</div>
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update_profile">
            <div style="display:flex;flex-direction:column;gap:var(--space-4)">

                <!-- Row 1: First / Middle -->
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--space-4)">
                    <div>
                        <label class="form-label">First Name *</label>
                        <input type="text" name="first_name" class="form-control"
                            value="<?= e($user['first_name'] ?? '') ?>" required>
                    </div>
                    <div>
                        <label class="form-label">Middle Name</label>
                        <input type="text" name="middle_name" class="form-control"
                            value="<?= e($user['middle_name'] ?? '') ?>">
                    </div>
                </div>

                <!-- Row 2: Last / Suffix -->
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--space-4)">
                    <div>
                        <label class="form-label">Last Name *</label>
                        <input type="text" name="last_name" class="form-control"
                            value="<?= e($user['last_name'] ?? '') ?>" required>
                    </div>
                    <div>
                        <label class="form-label">Suffix</label>
                        <input type="text" name="suffix" class="form-control"
                            value="<?= e($user['suffix'] ?? '') ?>"
                            placeholder="e.g. Jr., III">
                    </div>
                </div>

                <!-- Row 3: Birthdate / Sex -->
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--space-4)">
                    <div>
                        <label class="form-label">Birthdate *</label>
                        <input type="date" name="birthdate" class="form-control"
                            value="<?= e($user['birthdate'] ?? '') ?>"
                            max="<?= date('Y-m-d', strtotime('-15 years')) ?>" required>
                    </div>
                    <div>
                        <label class="form-label">Sex *</label>
                        <div class="sex-toggle-settings">
                            <input type="hidden" name="sex" id="settings_sex_value" value="<?= e($user['sex'] ?? '') ?>">
                            <button type="button" class="sex-btn-s <?= ($user['sex'] ?? '') === 'M' ? 'active' : '' ?>"
                                onclick="setSettingsSex('M')">Male</button>
                            <button type="button" class="sex-btn-s <?= ($user['sex'] ?? '') === 'F' ? 'active' : '' ?>"
                                onclick="setSettingsSex('F')">Female</button>
                        </div>
                    </div>
                </div>

                <!-- Row 4: Address -->
                <div>
                    <label class="form-label">Address *</label>
                    <div class="input-wrapper has-suffix" style="position:relative">
                        <input type="text" name="address" class="form-control"
                            value="<?= e($addressDisplay) ?>"
                            placeholder="Street / Barangay"
                            style="padding-right:100px" required>
                        <span style="position:absolute;right:10px;top:50%;transform:translateY(-50%);
                            font-size:var(--text-sm);color:var(--text-tertiary);pointer-events:none">
                            , Pasig City
                        </span>
                    </div>
                </div>

                <!-- Row 5: Email (read-only) / Phone -->
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--space-4)">
                    <div>
                        <label class="form-label">Email</label>
                        <input type="email" class="form-control" value="<?= e($user['email']) ?>" disabled>
                        <p style="font-size:var(--text-xs);color:var(--text-tertiary);margin-top:4px">
                            Email cannot be changed.
                        </p>
                    </div>
                    <div>
                        <label class="form-label">Phone Number *</label>
                        <input type="tel" name="phone" class="form-control"
                            value="<?= e($user['phone'] ?? '') ?>"
                            placeholder="09XX XXX XXXX" required>
                    </div>
                </div>

            </div>
            <div style="margin-top:var(--space-5)">
                <button type="submit" class="btn btn-primary">Save Changes</button>
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
function setSettingsSex(val) {
    document.getElementById('settings_sex_value').value = val;
    document.querySelectorAll('.sex-btn-s').forEach(function(btn) {
        btn.classList.toggle('active', btn.textContent.trim() === (val === 'M' ? 'Male' : 'Female'));
    });
}
</script>
<style>
.sex-toggle-settings { display:flex; gap:8px; height:38px; }
.sex-btn-s {
    flex:1; border:1px solid var(--border); border-radius:var(--radius-md);
    background:var(--bg-primary); color:var(--text-secondary);
    font-size:var(--text-sm); font-weight:var(--weight-medium); cursor:pointer;
    transition:background 0.15s,color 0.15s,border-color 0.15s;
}
.sex-btn-s.active { background:var(--accent); color:#fff; border-color:var(--accent); }
</style>

<?php
$content   = ob_get_clean();
$pageTitle = 'Settings';
$activeNav = 'settings';
include VIEWS_PATH . '/layouts/app.php';
