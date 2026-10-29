<?php
// ============================================================
// modules/settings/student.php
// M8 — Student: update profile and change password
// ============================================================

require_once CORE_PATH . '/bootstrap.php';
Auth::requireRole(ROLE_STUDENT);
settings_modal_gate();

$db     = db();
$userId = Auth::id();
$user   = Auth::user();

// Auth::user() only carries id/name/email/role from the session, so the profile
// fields come from the DB. Without this the form below renders empty.
$pf = $db->prepare('SELECT first_name, middle_name, last_name, suffix, birthdate, sex, address, phone, email
                    FROM users WHERE id = ?');
$pf->execute([$userId]);
$user = array_merge($user ?? [], $pf->fetch() ?: []);

$errors  = [];
$success = [];

if (($action = require_post_action()) !== null) {

    if ($action === 'update_profile') {
        $firstName  = post_str('first_name');
        $middleName = post_str('middle_name');
        $lastName   = post_str('last_name');
        $suffix     = post_str('suffix');
        $birthdate  = post_str('birthdate');
        $sex        = post_str('sex');
        $address    = post_str('address');
        $phone      = post_str('phone');

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

        $stmt = $db->prepare('SELECT password_hash FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $dbUser = $stmt->fetch();

        if (!password_verify($current, $dbUser['password_hash'] ?? '')) {
            $errors[] = 'Current password is incorrect.';
        } elseif (strlen($new) < 8) {
            $errors[] = 'New password must be at least 8 characters.';
        } elseif ($new !== $confirm) {
            $errors[] = 'New passwords do not match.';
        } else {
            $hash = password_hash($new, PASSWORD_BCRYPT, ['cost' => 12]);
            $db->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([$hash, $userId]);
            Auth::rememberRevokeAll((int) $userId);
            $success[] = 'Password changed successfully.';
        }
    }
}

// Google users have no password of their own yet (they set one through Forgot password).
// ponytail: stays hidden for any user with google_sub, even after they set a password. Upgrade: a password_set flag.
$gs = $db->prepare('SELECT google_sub FROM users WHERE id = ?');
$gs->execute([$userId]);
$isGoogleUser = !empty($gs->fetchColumn());

// Strip ", Pasig City" suffix for display in the address input
$addressDisplay = preg_replace('/,?\s*Pasig City\s*$/i', '', $user['address'] ?? '');

ob_start();
?>

<?php foreach ($errors as $e): ?>
    <div class="alert alert-error mb-3"><?= e($e) ?></div>
<?php endforeach; ?>
<?php foreach ($success as $s): ?>
    <div class="alert alert-success mb-3"><?= e($s) ?></div>
<?php endforeach; ?>

<div class="flex flex-column gap-6 mwpx-560 my-0 mx-auto">

    <!-- Appearance (per-device text size) -->
    <div class="card p-6" data-settings-section="appearance">
        <div class="fw-semibold mb-4">Appearance</div>
        <?php include VIEWS_PATH . '/partials/font_size_setting.php'; ?>
        <?php include VIEWS_PATH . '/partials/dark_mode_setting.php'; ?>
    </div>

    <!-- Profile -->
    <div class="card p-6" data-settings-section="profile">
        <div class="fw-semibold mb-5">Profile</div>
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update_profile">
            <div class="flex flex-column gap-4">

                <!-- Row 1: First / Middle -->
                <div class="row row-cols-1 row-cols-md-2 gutter-4">
                    <div>
                        <label class="form-label" for="st_first_name">First Name *</label>
                        <input type="text" id="st_first_name" name="first_name" class="form-control"
                            value="<?= e($user['first_name'] ?? '') ?>" required>
                    </div>
                    <div>
                        <label class="form-label" for="st_middle_name">Middle Name</label>
                        <input type="text" id="st_middle_name" name="middle_name" class="form-control"
                            value="<?= e($user['middle_name'] ?? '') ?>">
                    </div>
                </div>

                <!-- Row 2: Last / Suffix -->
                <div class="row row-cols-1 row-cols-md-2 gutter-4">
                    <div>
                        <label class="form-label" for="st_last_name">Last Name *</label>
                        <input type="text" id="st_last_name" name="last_name" class="form-control"
                            value="<?= e($user['last_name'] ?? '') ?>" required>
                    </div>
                    <div>
                        <label class="form-label" for="st_suffix">Suffix</label>
                        <input type="text" id="st_suffix" name="suffix" class="form-control"
                            value="<?= e($user['suffix'] ?? '') ?>"
                            placeholder="e.g. Jr., III">
                    </div>
                </div>

                <!-- Row 3: Birthdate / Sex -->
                <div class="row row-cols-1 row-cols-md-2 gutter-4">
                    <div>
                        <label class="form-label" for="st_birthdate">Birthdate *</label>
                        <input type="date" id="st_birthdate" name="birthdate" class="form-control"
                            value="<?= e($user['birthdate'] ?? '') ?>"
                            max="<?= date('Y-m-d', strtotime('-15 years')) ?>" required>
                    </div>
                    <div>
                        <span class="form-label">Sex *</span>
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
                    <label class="form-label" for="st_address">Address *</label>
                    <div class="input-wrapper has-suffix position-relative">
                        <input type="text" id="st_address" name="address" class="form-control"
                            value="<?= e($addressDisplay) ?>"
                            placeholder="Street / Barangay"
                            style="padding-right:100px" required>
                        <span class="position-absolute text-sm text-tertiary pe-none" style="right:10px;top:50%;transform:translateY(-50%)">
                            , Pasig City
                        </span>
                    </div>
                </div>

                <!-- Row 5: Email (read-only) / Phone -->
                <div class="row row-cols-1 row-cols-md-2 gutter-4">
                    <div>
                        <label class="form-label" for="st_email">Email</label>
                        <input type="email" id="st_email" class="form-control" value="<?= e($user['email']) ?>" disabled>
                        <p class="text-xs text-tertiary mt-1">
                            Email cannot be changed.
                        </p>
                    </div>
                    <div>
                        <label class="form-label" for="st_phone">Phone Number *</label>
                        <input type="tel" id="st_phone" name="phone" class="form-control"
                            value="<?= e($user['phone'] ?? '') ?>"
                            placeholder="09XX XXX XXXX" required>
                    </div>
                </div>

            </div>
            <div class="mt-5">
                <button type="submit" class="btn btn-primary">Save Changes</button>
            </div>
        </form>
    </div>

    <?php if ($isGoogleUser): ?>
    <!-- Password (Google account) -->
    <div class="card p-6" data-settings-section="password">
        <div class="fw-semibold mb-3">Password</div>
        <p class="text-secondary-sm">
            You sign in with Google. To set a password as well, use
            <a href="<?= url('/forgot-password') ?>">Forgot password</a> on the sign in page.
        </p>
    </div>
    <?php else: ?>
    <!-- Password -->
    <div class="card p-6" data-settings-section="password">
        <div class="fw-semibold mb-5">Change Password</div>
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="change_password">
            <div class="flex flex-column gap-4">
                <div>
                    <label class="form-label" for="st_current_password">Current Password</label>
                    <input type="password" id="st_current_password" name="current_password" class="form-control" required>
                </div>
                <div>
                    <label class="form-label" for="st_new_password">New Password</label>
                    <input type="password" id="st_new_password" name="new_password" class="form-control" minlength="8" required>
                </div>
                <div>
                    <label class="form-label" for="st_confirm_password">Confirm New Password</label>
                    <input type="password" id="st_confirm_password" name="confirm_password" class="form-control" required>
                </div>
            </div>
            <div class="mt-5">
                <button type="submit" class="btn btn-primary">Update Password</button>
            </div>
        </form>
    </div>
    <?php endif; ?>

</div>


<?php
$content   = ob_get_clean();
$pageTitle = 'Settings';
$pageStyles  = ['css/pages/student-settings.css'];
$pageScripts = ['js/pages/student-settings.js'];
require_once VIEWS_PATH . '/partials/page_header.php';
$pageBreadcrumb = [page_home_crumb()];
$activeNav = 'settings';
include VIEWS_PATH . '/layouts/app.php';