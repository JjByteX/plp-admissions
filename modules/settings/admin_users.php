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
            $insUser = $db->prepare('INSERT INTO users (name, email, password_hash, role, department) VALUES (?,?,?,?,?) RETURNING id');
            $insUser->execute([$name, $email, $hash, $role, $dept]);
            $newId = (int)$insUser->fetchColumn();
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
            $db->prepare('DELETE FROM users WHERE id=? AND role != \'student\'')->execute([$uid]);
            audit_log('user_deleted', "Deleted user ID {$uid}", 'user', $uid);
            $success[] = 'User deleted.';
            break;
    }
}

// Filters: department + search (name / email)
$filterDept = trim($_GET['department'] ?? '');
$search     = trim($_GET['q'] ?? '');
$page       = max(1, (int)($_GET['page'] ?? 1));
$availableDepts = departments_list();
if ($filterDept !== '' && !in_array($filterDept, $availableDepts, true)) {
    $filterDept = '';
}

$where  = ['role IN (\'staff\',\'proctor\',\'sso\',\'dean\',\'admin\')'];
$params = [];
if ($filterDept !== '') {
    $where[]          = 'department = :dept';
    $params[':dept']  = $filterDept;
}
if ($search !== '') {
    // Distinct names for each LIKE — PDO can't reuse one named placeholder.
    $where[]        = '(name ILIKE :q1 OR email ILIKE :q2)';
    $needle         = '%' . $search . '%';
    $params[':q1']  = $needle;
    $params[':q2']  = $needle;
}
$whereStr = implode(' AND ', $where);

// Rows per page — AutoPageSize (app.js) measures how many fixed-height rows
// fit the table and reloads with ?per_page=; see auto_per_page() in helpers.php.
$perPage = auto_per_page('admin-users');

$result = paginate(
    $db,
    "SELECT COUNT(*) FROM users WHERE $whereStr",
    "SELECT * FROM users WHERE $whereStr
     ORDER BY CASE role WHEN 'student' THEN 1 WHEN 'staff' THEN 2 WHEN 'proctor' THEN 3 WHEN 'sso' THEN 4 WHEN 'dean' THEN 5 ELSE 6 END, name",
    $params, $page, $perPage
);
$staffUsers = $result['data'];

// URL helper — keeps every current filter + per_page while overriding just
// the given keys (page links, the Clear link).
$usersUrl = function (array $merge = []) use ($filterDept, $search, $perPage): string {
    $base = ['department' => $filterDept, 'q' => $search, 'per_page' => $perPage, 'page' => 1];
    return url('/admin/users') . '?' . http_build_query(array_filter(
        array_merge($base, $merge),
        fn($v) => $v !== '' && $v !== null
    ));
};
$hasFilters = ($filterDept !== '' || $search !== '');

ob_start();
?>

<?php foreach ($errors as $err): ?>
    <div class="alert alert-error" style="margin-bottom:var(--space-3);flex-shrink:0"><?= e($err) ?></div>
<?php endforeach; ?>
<?php foreach ($success as $suc): ?>
    <div class="alert alert-success" style="margin-bottom:var(--space-3);flex-shrink:0"><?= e($suc) ?></div>
<?php endforeach; ?>

<?php
// Same structure as the audit log (and lakbay-pasig's AdminDataTable): one
// .auto-table-wrap that AutoPageSize measures, the card with the toolbar and
// the fixed-height table fused together, and the pagination bar under the card.
?>
<div class="auto-table-wrap" data-auto-page-size="admin-users" data-current-per-page="<?= (int)$perPage ?>">

<div class="auto-table-card">

    <!-- Toolbar: search + department filter (left), New User (right), one row -->
    <form method="GET" action="<?= url('/admin/users') ?>" class="auto-table-toolbar">
        <input type="hidden" name="per_page" value="<?= (int)$perPage ?>">

        <div class="auto-table-search">
            <?= icon('ic_fluent_search_24_filled', 14) ?>
            <input type="text" name="q" class="form-input" placeholder="Search name or email…" value="<?= e($search) ?>">
        </div>

        <select id="dept-filter" name="department" class="form-input" style="width:260px"
                onchange="this.form.submit()" aria-label="Department">
            <option value="">All departments</option>
            <?php foreach ($availableDepts as $deptName): ?>
                <option value="<?= e($deptName) ?>" <?= $filterDept === $deptName ? 'selected' : '' ?>>
                    <?= e($deptName) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <button type="submit" class="btn btn-secondary btn-sm">Filter</button>
        <?php if ($hasFilters): ?>
            <a href="<?= url('/admin/users') . '?' . http_build_query(['per_page' => $perPage]) ?>" class="btn btn-ghost btn-sm">Clear</a>
        <?php endif; ?>

        <span class="toolbar-spacer"></span>

        <button type="button" class="btn btn-primary btn-sm"
                onclick="document.getElementById('create-user-modal').style.display='flex'">
            <?= icon('ic_fluent_add_24_regular', 15) ?>
            New User
        </button>
    </form>

    <!-- Table body — sized so the rows AutoPageSize picks fit exactly; it
         only scrolls as a safety net (e.g. a very narrow window) -->
    <div class="auto-table-body">
        <table class="auto-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Email</th>
                    <th style="width:130px">Role</th>
                    <th style="width:250px">Department</th>
                    <th style="width:100px">Status</th>
                    <th style="width:120px">Created</th>
                    <th style="width:220px"></th>
                </tr>
            </thead>
            <?php if (!empty($staffUsers)): ?>
            <tbody>
            <?php foreach ($staffUsers as $u): ?>
                <tr>
                    <td style="font-weight:var(--weight-medium)" title="<?= e($u['name']) ?>"><span class="auto-table-clip"><?= e($u['name']) ?></span></td>
                    <td style="font-size:var(--text-sm);color:var(--text-tertiary)" title="<?= e($u['email']) ?>"><span class="auto-table-clip"><?= e($u['email']) ?></span></td>
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
                        <form method="POST" style="display:flex;align-items:center;margin:0">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="update_department">
                            <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                            <select name="department" class="form-control" style="font-size:var(--text-xs);padding:var(--space-1) var(--space-2);width:100%"
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
                        <div style="display:flex;align-items:center;gap:var(--space-2);flex-wrap:nowrap">
                            <button class="btn btn-secondary btn-sm"
                                    onclick="openResetModal(<?= $u['id'] ?>, <?= json_encode($u['name']) ?>)">
                                Reset PW
                            </button>
                            <?php if ($u['id'] !== $adminId): ?>
                                <form method="POST" style="margin:0">
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
            </tbody>
            <?php endif; ?>
        </table>

        <?php if (empty($staffUsers)): ?>
        <div class="auto-table-empty">
            <?= icon('ic_fluent_people_24_regular', 32) ?>
            <div><?= $hasFilters ? 'No accounts match your filters.' : 'No staff or admin accounts.' ?></div>
        </div>
        <?php endif; ?>
    </div>
</div><!-- /.auto-table-card -->

<!-- Pagination bar — under the card, inside the wrap, same as the audit log.
     Its 40px is reserved in AutoPageSize's math whether or not it renders. -->
<?php if ($result['last_page'] > 1): ?>
    <?= auto_table_footer($result, fn(int $p): string => $usersUrl(['page' => $p]), 'accounts') ?>
<?php endif; ?>

</div><!-- /.auto-table-wrap -->

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
