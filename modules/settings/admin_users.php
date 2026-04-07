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

            if (!$name || !$email || !in_array($role, ['staff', 'admin'], true) || strlen($pass) < 8) {
                $errors[] = 'All fields are required. Password must be at least 8 characters.';
                break;
            }

            $check = $db->prepare('SELECT id FROM users WHERE email=?');
            $check->execute([$email]);
            if ($check->fetch()) { $errors[] = 'Email already exists.'; break; }

            $hash = password_hash($pass, PASSWORD_BCRYPT, ['cost' => 12]);
            $db->prepare('INSERT INTO users (name, email, password_hash, role) VALUES (?,?,?,?)')
               ->execute([$name, $email, $hash, $role]);
            $success[] = "$name ($role) account created.";
            break;

        case 'toggle_active':
            $uid    = (int)($_POST['user_id'] ?? 0);
            $active = (int)($_POST['is_active'] ?? 0);
            if ($uid === $adminId) { $errors[] = 'You cannot deactivate your own account.'; break; }
            $db->prepare('UPDATE users SET is_active=? WHERE id=?')->execute([$active ? 0 : 1, $uid]);
            $success[] = 'User status updated.';
            break;

        case 'reset_password':
            $uid     = (int)($_POST['user_id'] ?? 0);
            $newPass = trim($_POST['new_password'] ?? '');
            if (strlen($newPass) < 8) { $errors[] = 'Password must be at least 8 characters.'; break; }
            $hash = password_hash($newPass, PASSWORD_BCRYPT, ['cost' => 12]);
            $db->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([$hash, $uid]);
            $success[] = 'Password reset successfully.';
            break;

        case 'delete_user':
            $uid = (int)($_POST['user_id'] ?? 0);
            if ($uid === $adminId) { $errors[] = 'You cannot delete your own account.'; break; }
            $db->prepare('DELETE FROM users WHERE id=? AND role != "student"')->execute([$uid]);
            $success[] = 'User deleted.';
            break;
    }
}

// Load staff + admin users
$stmt = $db->prepare(
    'SELECT * FROM users WHERE role IN ("staff","admin") ORDER BY role, name'
);
$stmt->execute();
$staffUsers = $stmt->fetchAll();

ob_start();
?>

<div class="page-header" style="display:flex;align-items:flex-start;justify-content:space-between">
    <div>
        <h1 class="page-title">User Management</h1>
        <p class="page-description">Staff and admin accounts.</p>
    </div>
    <button class="btn btn-primary" onclick="document.getElementById('create-user-modal').style.display='flex'">
        + New User
    </button>
</div>

<?php foreach ($errors as $e): ?>
    <div class="alert alert-error" style="margin-bottom:var(--space-3)"><?= e($e) ?></div>
<?php endforeach; ?>
<?php foreach ($success as $s): ?>
    <div class="alert alert-success" style="margin-bottom:var(--space-3)"><?= e($s) ?></div>
<?php endforeach; ?>

<div class="card" style="padding:0;overflow:hidden">
    <table class="table">
        <thead>
            <tr>
                <th>Name</th>
                <th>Email</th>
                <th>Role</th>
                <th>Status</th>
                <th>Created</th>
                <th style="width:140px"></th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($staffUsers)): ?>
            <tr><td colspan="6" style="text-align:center;padding:var(--space-8);color:var(--text-tertiary)">No staff or admin accounts.</td></tr>
        <?php else: ?>
            <?php foreach ($staffUsers as $u): ?>
                <tr>
                    <td style="font-weight:var(--weight-medium)"><?= e($u['name']) ?></td>
                    <td style="font-size:var(--text-sm);color:var(--text-tertiary)"><?= e($u['email']) ?></td>
                    <td><span class="badge badge-<?= $u['role'] === 'admin' ? 'error' : 'info' ?>"><?= ucfirst($u['role']) ?></span></td>
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

<!-- Create user modal -->
<div id="create-user-modal" class="modal-backdrop" style="display:none">
    <div class="modal" style="max-width:420px">
        <div class="modal-header">
            <div class="modal-title">New User</div>
            <button class="btn-icon" onclick="document.getElementById('create-user-modal').style.display='none'">
                <svg width="18" height="18" fill="none" viewBox="0 0 24 24"><path stroke="currentColor" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
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
                    <select name="role" class="form-control" required>
                        <option value="">Select role…</option>
                        <option value="staff">Staff</option>
                        <option value="admin">Admin</option>
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
                <svg width="18" height="18" fill="none" viewBox="0 0 24 24"><path stroke="currentColor" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
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
</script>

<?php
$content   = ob_get_clean();
$pageTitle = 'User Management';
$activeNav = 'users';
include VIEWS_PATH . '/layouts/app.php';
