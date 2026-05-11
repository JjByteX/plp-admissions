<?php
// ============================================================
// modules/settings/admin_users.php
// M8 — Admin: create, deactivate, reset staff & admin accounts
// ============================================================

require_once CORE_PATH . '/bootstrap.php';
Auth::requireRole(ROLE_ADMIN);

$db      = db();
$adminId = Auth::id();
$errors  = [];
$success = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    switch ($action) {

        case 'create_user':
            $name  = trim($_POST['name'] ?? '');
            $email = strtolower(trim($_POST['email'] ?? ''));
            $role  = $_POST['role'] ?? '';
            $pass  = $_POST['password'] ?? '';
            $dept  = trim($_POST['department'] ?? '');

            $allowedRoles = [ROLE_STAFF, ROLE_PROCTOR, ROLE_SSO, ROLE_DEAN, ROLE_ADMIN];
            if (!$name || !$email || !in_array($role, $allowedRoles, true) || strlen($pass) < 8) {
                $errors[] = 'All fields are required. Password must be at least 8 characters.';
                break;
            }

            if ($dept !== '' && !in_array($dept, departments_list(), true)) {
                $errors[] = 'Invalid department selected.';
                break;
            }

            // Dean and Proctor accounts must be scoped to a department.
            if ($role === ROLE_DEAN && $dept === '') {
                $errors[] = 'Dean accounts must be assigned to a department.';
                break;
            }
            if ($role === ROLE_PROCTOR && $dept === '') {
                $errors[] = 'Proctor accounts must be assigned to a department.';
                break;
            }

            $check = $db->prepare('SELECT id FROM users WHERE email=?');
            $check->execute([$email]);
            if ($check->fetch()) { $errors[] = 'Email already exists.'; break; }

            $hash = password_hash($pass, PASSWORD_BCRYPT, ['cost' => 12]);
            $db->prepare('INSERT INTO users (name, email, password_hash, role, department) VALUES (?,?,?,?,?)')
               ->execute([$name, $email, $hash, $role, $dept]);
            $newId = (int)$db->lastInsertId();
            audit_log(
                'user_created',
                "Created {$role} account for {$name} ({$email})"
                    . ($dept !== '' ? " in {$dept}" : ''),
                'user',
                $newId
            );
            $success[] = "$name ($role) account created.";
            break;

        case 'update_department':
            $uid  = (int)($_POST['user_id'] ?? 0);
            $dept = trim($_POST['department'] ?? '');
            if ($uid <= 0) {
                $errors[] = 'Invalid user.';
                break;
            }
            if ($dept !== '' && !in_array($dept, departments_list(), true)) {
                $errors[] = 'Invalid department selected.';
                break;
            }
            set_user_department($uid, $dept, $adminId);
            $success[] = 'Department updated.';
            break;

        case 'toggle_active':
            $uid    = (int)($_POST['user_id'] ?? 0);
            $active = (int)($_POST['is_active'] ?? 0);
            if ($uid === $adminId) { $errors[] = 'You cannot deactivate your own account.'; break; }
            $db->prepare('UPDATE users SET is_active=? WHERE id=?')->execute([$active ? 0 : 1, $uid]);
            $newStatus = $active ? 'deactivated' : 'activated';
            audit_log('user_status_changed', "User ID {$uid} {$newStatus}", 'user', $uid);
            $success[] = 'User status updated.';
            break;

        case 'reset_password':
            $uid     = (int)($_POST['user_id'] ?? 0);
            $newPass = trim($_POST['new_password'] ?? '');
            if (strlen($newPass) < 8) { $errors[] = 'Password must be at least 8 characters.'; break; }
            $hash = password_hash($newPass, PASSWORD_BCRYPT, ['cost' => 12]);
            $db->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([$hash, $uid]);
            audit_log('user_password_reset', "Reset password for user ID {$uid}", 'user', $uid);
            $success[] = 'Password reset successfully.';
            break;

        case 'delete_user':
            $uid = (int)($_POST['user_id'] ?? 0);
            if ($uid === $adminId) { $errors[] = 'You cannot delete your own account.'; break; }
            $db->prepare('DELETE FROM users WHERE id=? AND role != "student"')->execute([$uid]);
            audit_log('user_deleted', "Deleted user ID {$uid}", 'user', $uid);
            $success[] = 'User deleted.';
            break;
    }
}

// Filter: department
$filterDept = trim($_GET['department'] ?? '');
$availableDepts = departments_list();
if ($filterDept !== '' && !in_array($filterDept, $availableDepts, true)) {
    $filterDept = '';
}

$sql     = 'SELECT * FROM users WHERE role IN ("staff","proctor","sso","dean","admin")';
$params  = [];
if ($filterDept !== '') {
    $sql    .= ' AND department = ?';
    $params[] = $filterDept;
}
$sql    .= ' ORDER BY role, name';

$stmt = $db->prepare($sql);
$stmt->execute($params);
$staffUsers = $stmt->fetchAll();

ob_start();
?>

<?php foreach ($errors as $err): ?>
    <div class="alert alert-error" style="margin-bottom:var(--space-3)"><?= e($err) ?></div>
<?php endforeach; ?>
<?php foreach ($success as $suc): ?>
    <div class="alert alert-success" style="margin-bottom:var(--space-3)"><?= e($suc) ?></div>
<?php endforeach; ?>

<div style="margin-bottom:var(--space-6)">
    <form method="GET" style="display:flex;align-items:center;gap:var(--space-2)">
        <label for="dept-filter" style="font-size:var(--text-sm);color:var(--text-secondary)">Department:</label>
        <select id="dept-filter" name="department" class="form-control"
                style="width:auto;min-width:240px" onchange="this.form.submit()">
            <option value="">All departments</option>
            <?php foreach ($availableDepts as $deptName): ?>
                <option value="<?= e($deptName) ?>" <?= $filterDept === $deptName ? 'selected' : '' ?>>
                    <?= e($deptName) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php if ($filterDept !== ''): ?>
            <a href="<?= url('/admin/users') ?>" class="btn btn-ghost btn-sm">Clear</a>
        <?php endif; ?>
    </form>
</div>

<?php foreach ($errors as $e): ?>
    <div class="alert alert-error" style="margin-bottom:var(--space-3)"><?= e($e) ?></div>
<?php endforeach; ?>
<?php foreach ($success as $s): ?>
    <div class="alert alert-success" style="margin-bottom:var(--space-3)"><?= e($s) ?></div>
<?php endforeach; ?>

<div class="card" style="padding:0;overflow:hidden;width:100%">
    <table class="table" style="width:100%">
        <thead>
            <tr>
                <th>Name</th>
                <th>Email</th>
                <th>Role</th>
                <th>Department</th>
                <th>Status</th>
                <th>Created</th>
                <th style="width:140px"></th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($staffUsers)): ?>
            <tr><td colspan="7" style="text-align:center;padding:var(--space-8);color:var(--text-tertiary)">No staff or admin accounts.</td></tr>
        <?php else: ?>
            <?php foreach ($staffUsers as $u): ?>
                <tr>
                    <td style="font-weight:var(--weight-medium)"><?= e($u['name']) ?></td>
                    <td style="font-size:var(--text-sm);color:var(--text-tertiary)"><?= e($u['email']) ?></td>
                    <td>
                        <?php
                            $roleBadge = match ($u['role']) {
                                ROLE_ADMIN   => 'error',
                                ROLE_DEAN    => 'warning',
                                ROLE_SSO     => 'success',
                                ROLE_STAFF   => 'info',
                                ROLE_PROCTOR => 'info',
                                default      => 'neutral',
                            };
                        ?>
                        <span class="badge badge-<?= e($roleBadge) ?>"><?= e(Auth::roleLabel($u['role'])) ?></span>
                    </td>
                    <td style="font-size:var(--text-sm)">
                        <?php if (in_array($u['role'], [ROLE_ADMIN, ROLE_SSO], true)): ?>
                            <span style="color:var(--text-tertiary);font-size:var(--text-xs)">—</span>
                        <?php else: ?>
                        <form method="POST" style="display:inline-flex;align-items:center;gap:var(--space-1)">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="update_department">
                            <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                            <select name="department" class="form-control" style="font-size:var(--text-xs);padding:var(--space-1) var(--space-2);min-width:200px"
                                    onchange="this.form.submit()">
                                <option value="">— none —</option>
                                <?php foreach ($availableDepts as $deptName): ?>
                                    <option value="<?= e($deptName) ?>"
                                            <?= ($u['department'] ?? '') === $deptName ? 'selected' : '' ?>>
                                        <?= e($deptName) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </form>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($u['is_active']): ?>
                            <span class="badge badge-success">Active</span>
                        <?php else: ?>
                            <span class="badge badge-neutral">Inactive</span>
                        <?php endif; ?>
                    </td>
                    <td style="font-size:var(--text-sm);color:var(--text-tertiary)"><?= format_date($u['created_at'], 'M j, Y') ?></td>
                    <td>
                        <div style="display:flex;gap:var(--space-2)">
                            <button class="btn btn-secondary btn-sm"
                                    onclick="openResetModal(<?= $u['id'] ?>, <?= json_encode($u['name']) ?>)">
                                Reset PW
                            </button>
                            <?php if ($u['id'] !== $adminId): ?>
                                <form method="POST">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="toggle_active">
                                    <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                    <input type="hidden" name="is_active" value="<?= $u['is_active'] ?>">
                                    <button class="btn btn-ghost btn-sm">
                                        <?= $u['is_active'] ? 'Deactivate' : 'Activate' ?>
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- New User button below table -->
<div style="margin-top:var(--space-4);display:flex;justify-content:center">
    <button class="btn btn-primary" onclick="document.getElementById('create-user-modal').style.display='flex'">
        <?= icon('ic_fluent_add_24_regular', 15) ?>
        New User
    </button>
</div>

<!-- Create user modal -->
<div id="create-user-modal" class="modal-backdrop" style="display:none">
    <div class="modal" style="max-width:420px">
        <div class="modal-header">
            <div class="modal-title">New User</div>
            <button class="btn-icon" onclick="document.getElementById('create-user-modal').style.display='none'">
                <?= icon('ic_fluent_dismiss_24_regular', 18) ?>
            </button>
        </div>
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create_user">
            <div class="modal-body" style="display:flex;flex-direction:column;gap:var(--space-4)">
                <div>
                    <label class="form-label">Full Name <span style="color:var(--error)">*</span></label>
                    <input type="text" name="name" class="form-control" required>
                </div>
                <div>
                    <label class="form-label">Email <span style="color:var(--error)">*</span></label>
                    <input type="email" name="email" class="form-control" required>
                </div>
                <div>
                    <label class="form-label">Role <span style="color:var(--error)">*</span></label>
                    <select name="role" id="role-select" class="form-control" required>
                        <option value="">Select role…</option>
                        <option value="<?= ROLE_STAFF ?>">Professor (conduct interviews)</option>
                        <option value="<?= ROLE_PROCTOR ?>">Proctor (proctor exams, generate access codes)</option>
                        <option value="<?= ROLE_SSO ?>">SSO (documents, scheduling, exam content, results release)</option>
                        <option value="<?= ROLE_DEAN ?>">Dean (per-college oversight, courses &amp; tier thresholds)</option>
                        <option value="<?= ROLE_ADMIN ?>">Admin (full access)</option>
                    </select>
                </div>
                <div>
                    <label class="form-label" id="dept-label">
                        Department
                        <span id="dept-hint" style="color:var(--text-tertiary);font-weight:400"> — optional for SSO and Admin</span>
                    </label>
                    <select name="department" id="dept-select" class="form-control">
                        <option value="">— none —</option>
                        <?php foreach ($availableDepts as $deptName): ?>
                            <option value="<?= e($deptName) ?>"><?= e($deptName) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="form-label">Password <span style="color:var(--error)">*</span></label>
                    <input type="password" name="password" class="form-control" minlength="8" required>
                    <p style="font-size:var(--text-xs);color:var(--text-tertiary);margin-top:4px">Minimum 8 characters.</p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-ghost" onclick="document.getElementById('create-user-modal').style.display='none'">Cancel</button>
                <button type="submit" class="btn btn-primary">Create User</button>
            </div>
        </form>
    </div>
</div>

<!-- Reset password modal -->
<div id="reset-pw-modal" class="modal-backdrop" style="display:none">
    <div class="modal" style="max-width:380px">
        <div class="modal-header">
            <div class="modal-title">Reset Password</div>
            <button class="btn-icon" onclick="document.getElementById('reset-pw-modal').style.display='none'">
                <?= icon('ic_fluent_dismiss_24_regular', 18) ?>
            </button>
        </div>
        <form method="POST" id="reset-pw-form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="reset_password">
            <input type="hidden" name="user_id" id="reset-uid">
            <div class="modal-body">
                <p id="reset-name" style="font-weight:var(--weight-medium);margin-bottom:var(--space-4)"></p>
                <label class="form-label">New Password <span style="color:var(--error)">*</span></label>
                <input type="password" name="new_password" class="form-control" minlength="8" required>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-ghost" onclick="document.getElementById('reset-pw-modal').style.display='none'">Cancel</button>
                <button type="submit" class="btn btn-primary">Reset</button>
            </div>
        </form>
    </div>
</div>

<script>
function openResetModal(uid, name) {
    document.getElementById('reset-uid').value = uid;
    document.getElementById('reset-name').textContent = 'Resetting password for: ' + name;
    document.getElementById('reset-pw-modal').style.display = 'flex';
}
[document.getElementById('create-user-modal'), document.getElementById('reset-pw-modal')].forEach(m => {
    m.addEventListener('click', function(e){ if(e.target===this) this.style.display='none'; });
});

// Make department required when role is Dean, optional otherwise.
(function(){
    var roleSel = document.getElementById('role-select');
    var deptSel = document.getElementById('dept-select');
    var deptHint = document.getElementById('dept-hint');
    if (!roleSel || !deptSel) return;
    function sync() {
        var isDean = roleSel.value === '<?= ROLE_DEAN ?>';
        var isProctor = roleSel.value === '<?= ROLE_PROCTOR ?>';
        var isStaff = roleSel.value === '<?= ROLE_STAFF ?>';
        var needsDept = isDean || isProctor || isStaff;
        var isAdmin = roleSel.value === '<?= ROLE_ADMIN ?>';
        var isSSO = roleSel.value === '<?= ROLE_SSO ?>';
        var hideDept = isAdmin || isSSO;
        var deptRow = deptSel.closest('div');
        if (hideDept) {
            deptRow.style.display = 'none';
            deptSel.required = false;
            deptSel.value = '';
        } else {
            deptRow.style.display = '';
            deptSel.required = needsDept;
        }
        if (deptHint && !hideDept) {
            deptHint.textContent = needsDept
                ? ' — required'
                : '';
        }
    }
    roleSel.addEventListener('change', sync);
    sync();
})();
</script>

<?php
$content   = ob_get_clean();
$pageTitle = 'User Management';
$activeNav = 'users';
$pageWide  = true;
include VIEWS_PATH . '/layouts/app.php';
