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
            audit_log('settings_branding_updated', "Updated branding: school_name={$schoolName}");
            $success[] = 'Branding updated.';
        }
    }

    if ($action === 'update_exam_capacity') {
        $roomCap   = max(1,  (int)($_POST['exam_room_capacity'] ?? 35));
        $dailyCap  = max(1,  (int)($_POST['exam_daily_cap']     ?? 3000));
        $intDailyCap = max(1,(int)($_POST['interview_daily_cap'] ?? 45));
        $ups = fn($k,$v) => $db->prepare(
            'INSERT INTO school_settings (setting_key,setting_value) VALUES (?,?)
             ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)'
        )->execute([$k,$v]);
        $ups('exam_room_capacity',  $roomCap);
        $ups('exam_daily_cap',      $dailyCap);
        $ups('interview_daily_cap', $intDailyCap);
        audit_log('settings_capacity_updated', "Room cap={$roomCap}, Exam daily={$dailyCap}, Interview daily={$intDailyCap}");
        $success[] = 'Capacity settings updated.';
    }

    if ($action === 'update_course_caps') {
        $schoolYear = school_setting('current_school_year', date('Y').'-'.(date('Y')+1));
        $caps = $_POST['caps'] ?? [];
        foreach ($caps as $courseName => $maxVal) {
            if (!in_array($courseName, PLP_COURSES, true)) continue;
            $max = ($maxVal === '' || $maxVal === null) ? null : max(0, (int)$maxVal);
            $db->prepare(
                'INSERT INTO course_caps (course_name, school_year, max_slots)
                 VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE max_slots=VALUES(max_slots)'
            )->execute([$courseName, $schoolYear, $max]);
        }
        audit_log('course_caps_updated', 'Updated course enrollment caps');
        $success[] = 'Course enrollment caps saved.';
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
            audit_log('admin_password_changed', 'Admin changed their own password', 'user', $userId);
            $success[] = 'Password changed.';
        }
    }
}

$schoolName  = school_setting('school_name', 'Pamantasan ng Lungsod ng Pasig');
$accentColor = school_setting('accent_color', '#2d6a4f');
$schoolLogo  = school_setting('school_logo', '');
$examRoomCap  = (int) school_setting('exam_room_capacity',  EXAM_ROOM_CAPACITY);
$examDailyCap = (int) school_setting('exam_daily_cap',      EXAM_DAILY_CAP);
$intDailyCap  = (int) school_setting('interview_daily_cap', INTERVIEW_DAILY_CAP);

// Load current course caps
$schoolYear = school_setting('current_school_year', date('Y').'-'.(date('Y')+1));
$capRows = [];
try {
    $capStmt = $db->prepare('SELECT course_name, max_slots FROM course_caps WHERE school_year=?');
    $capStmt->execute([$schoolYear]);
    foreach ($capStmt->fetchAll() as $row) $capRows[$row['course_name']] = $row['max_slots'];
} catch (\Throwable $e) { /* table may not exist yet */ }

// Count accepted applicants per course this school year
$acceptedCounts = [];
try {
    $acStmt = $db->prepare(
        "SELECT a.course_applied, COUNT(*) AS cnt
         FROM applicants a
         JOIN admission_results r ON r.applicant_id = a.id
         WHERE a.school_year = ? AND r.result = 'accepted'
         GROUP BY a.course_applied"
    );
    $acStmt->execute([$schoolYear]);
    foreach ($acStmt->fetchAll() as $row) $acceptedCounts[$row['course_applied']] = (int)$row['cnt'];
} catch (\Throwable $e) { /* table may not exist yet */ }

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

    <!-- Capacity Settings -->
    <div class="card" style="padding:var(--space-6)">
        <div style="font-weight:var(--weight-semibold);margin-bottom:var(--space-1)">Capacity Settings</div>
        <div style="font-size:var(--text-xs);color:var(--text-tertiary);margin-bottom:var(--space-5)">
            Controls exam slot auto-assignment and interview scheduling limits.
        </div>
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update_exam_capacity">
            <div style="display:flex;flex-direction:column;gap:var(--space-4)">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--space-3)">
                    <div>
                        <label class="form-label">Applicants per Exam Room</label>
                        <input type="number" name="exam_room_capacity" class="form-control"
                               value="<?= $examRoomCap ?>" min="1" max="200">
                        <p style="font-size:var(--text-xs);color:var(--text-tertiary);margin-top:4px">
                            System auto-assigns up to this many per room. Default: 35.
                        </p>
                    </div>
                    <div>
                        <label class="form-label">Max Exam Applicants per Day</label>
                        <input type="number" name="exam_daily_cap" class="form-control"
                               value="<?= $examDailyCap ?>" min="1" max="10000">
                        <p style="font-size:var(--text-xs);color:var(--text-tertiary);margin-top:4px">
                            Overflow rolls to the next available day. Default: 3,000.
                        </p>
                    </div>
                </div>
                <div style="max-width:240px">
                    <label class="form-label">Max Interview Applicants per Day</label>
                    <input type="number" name="interview_daily_cap" class="form-control"
                           value="<?= $intDailyCap ?>" min="1" max="500">
                    <p style="font-size:var(--text-xs);color:var(--text-tertiary);margin-top:4px">
                        Recommended: 40–50. Default: 45.
                    </p>
                </div>
                <?php
                $roomsNeeded = $examRoomCap > 0 ? ceil($examDailyCap / $examRoomCap) : '—';
                ?>
                <div style="background:var(--bg-subtle);border-radius:var(--radius-md);padding:var(--space-3) var(--space-4);font-size:var(--text-xs);color:var(--text-secondary)">
                    💡 With current settings: <?= $examDailyCap ?> applicants ÷ <?= $examRoomCap ?>/room
                    = <strong>~<?= $roomsNeeded ?> rooms needed per exam day</strong>.
                </div>
            </div>
            <div style="margin-top:var(--space-5)">
                <button type="submit" class="btn btn-primary">Save Capacity Settings</button>
            </div>
        </form>
    </div>

    <!-- Course Enrollment Caps -->
    <div class="card" style="padding:var(--space-6)">
        <div style="font-weight:var(--weight-semibold);margin-bottom:var(--space-1)">Course Enrollment Caps</div>
        <div style="font-size:var(--text-xs);color:var(--text-tertiary);margin-bottom:var(--space-2)">
            School year: <strong><?= e($schoolYear) ?></strong>. When a course reaches its cap it is automatically disabled in the registration form.
            Leave blank for unlimited.
        </div>
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update_course_caps">
            <div style="display:grid;grid-template-columns:1fr auto auto;border:1px solid var(--border);border-radius:var(--radius-md);overflow:hidden;font-size:var(--text-xs);margin-bottom:var(--space-4)">
                <div style="padding:var(--space-2) var(--space-3);background:var(--bg-subtle);font-weight:var(--weight-semibold);border-bottom:1px solid var(--border)">Course</div>
                <div style="padding:var(--space-2) var(--space-3);background:var(--bg-subtle);font-weight:var(--weight-semibold);border-bottom:1px solid var(--border);text-align:center">Accepted</div>
                <div style="padding:var(--space-2) var(--space-3);background:var(--bg-subtle);font-weight:var(--weight-semibold);border-bottom:1px solid var(--border);text-align:center">Max Slots</div>
                <?php foreach (PLP_COURSES as $ci => $course):
                    $maxSlots = array_key_exists($course, $capRows) ? $capRows[$course] : null;
                    $accepted = $acceptedCounts[$course] ?? 0;
                    $isFull   = $maxSlots !== null && $accepted >= $maxSlots;
                    $isLast   = $ci === count(PLP_COURSES) - 1;
                ?>
                <div style="padding:var(--space-2) var(--space-3);display:flex;align-items:center;gap:var(--space-2);<?= !$isLast?'border-bottom:1px solid var(--border)':'' ?>">
                    <?= e($course) ?>
                    <?php if ($isFull): ?>
                        <span class="badge badge-rejected" style="font-size:10px">Full</span>
                    <?php endif; ?>
                </div>
                <div style="padding:var(--space-2) var(--space-3);text-align:center;<?= !$isLast?'border-bottom:1px solid var(--border)':'' ?>">
                    <strong><?= $accepted ?></strong>
                </div>
                <div style="padding:var(--space-2) var(--space-3);<?= !$isLast?'border-bottom:1px solid var(--border)':'' ?>">
                    <input type="number"
                           name="caps[<?= htmlspecialchars($course, ENT_QUOTES) ?>]"
                           class="form-control"
                           value="<?= $maxSlots !== null ? $maxSlots : '' ?>"
                           placeholder="∞"
                           min="0"
                           style="width:80px;padding:4px 8px;font-size:var(--text-xs);text-align:center">
                </div>
                <?php endforeach; ?>
            </div>
            <button type="submit" class="btn btn-primary">Save Enrollment Caps</button>
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