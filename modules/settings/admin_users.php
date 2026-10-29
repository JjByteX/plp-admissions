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

if (($action = require_post_action()) !== null) {

    switch ($action) {

        case 'create_user':
            $name  = post_str('name');
            $email = strtolower(post_str('email'));
            $role  = $_POST['role'] ?? '';
            $pass  = $_POST['password'] ?? '';
            $dept  = post_str('department');

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
                AuditAction::USER_CREATED,
                "Created {$role} account for {$name} ({$email})"
                    . ($dept !== '' ? " in {$dept}" : ''),
                'user',
                $newId
            );
            $success[] = "$name ($role) account created.";
            break;

        case 'update_department':
            $uid  = post_int('user_id');
            $dept = post_str('department');
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
            $uid    = post_int('user_id');
            $active = post_int('is_active');
            if ($uid === $adminId) { $errors[] = 'You cannot deactivate your own account.'; break; }
            $db->prepare('UPDATE users SET is_active=? WHERE id=?')->execute([$active ? 0 : 1, $uid]);
            $newStatus = $active ? 'deactivated' : 'activated';
            audit_log(AuditAction::USER_STATUS_CHANGED, "User ID {$uid} {$newStatus}", 'user', $uid);
            $success[] = 'User status updated.';
            break;

        case 'reset_password':
            $uid     = post_int('user_id');
            $newPass = post_str('new_password');
            if (strlen($newPass) < 8) { $errors[] = 'Password must be at least 8 characters.'; break; }
            $hash = password_hash($newPass, PASSWORD_BCRYPT, ['cost' => 12]);
            $db->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([$hash, $uid]);
            audit_log(AuditAction::USER_PASSWORD_RESET, "Reset password for user ID {$uid}", 'user', $uid);
            Auth::rememberRevokeAll($uid);
            $success[] = 'Password reset successfully.';
            break;

        case 'delete_user':
            $uid = post_int('user_id');
            if ($uid === $adminId) { $errors[] = 'You cannot delete your own account.'; break; }
            $db->prepare('DELETE FROM users WHERE id=? AND role != \'student\'')->execute([$uid]);
            audit_log(AuditAction::USER_DELETED, "Deleted user ID {$uid}", 'user', $uid);
            $success[] = 'User deleted.';
            break;
    }
}

// Filters: search + department
$filterDept = get_str('department');
$filterQ    = get_str('q');
$availableDepts = departments_list();
if ($filterDept !== '' && !in_array($filterDept, $availableDepts, true)) {
    $filterDept = '';
}
$hasFilters = ($filterDept !== '' || $filterQ !== '');

// Page size — driven by AutoPageSize (app.js), same as the audit log.
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = isset($_GET['per_page'])
    ? (int)$_GET['per_page']
    : (int)($_COOKIE['plp_auto_page_size_admin_users'] ?? 0);
if ($perPage <= 0) {
    $perPage = 50;
}
$perPage = max(3, min(100, $perPage));

$where  = ['role IN (\'staff\',\'proctor\',\'sso\',\'dean\',\'admin\')'];
$params = [];
if ($filterDept !== '') {
    $where[]  = 'department = ?';
    $params[] = $filterDept;
}
if ($filterQ !== '') {
    $where[]  = '(name ILIKE ? OR email ILIKE ?)';
    $params[] = '%' . $filterQ . '%';
    $params[] = '%' . $filterQ . '%';
}
$whereClause = 'WHERE ' . implode(' AND ', $where);

$countStmt = $db->prepare("SELECT COUNT(*) FROM users {$whereClause}");
$countStmt->execute($params);
$total  = (int)$countStmt->fetchColumn();
$pages  = max(1, (int)ceil($total / $perPage));
$page   = min($page, $pages);
$offset = ($page - 1) * $perPage;

$stmt = $db->prepare(
    "SELECT * FROM users {$whereClause}
     ORDER BY CASE role WHEN 'student' THEN 1 WHEN 'staff' THEN 2 WHEN 'proctor' THEN 3 WHEN 'sso' THEN 4 WHEN 'dean' THEN 5 ELSE 6 END, name
     LIMIT {$perPage} OFFSET {$offset}"
);
$stmt->execute($params);
$staffUsers = $stmt->fetchAll();

function usersUrl(array $merge = []): string
{
    global $filterDept, $filterQ, $perPage;
    $base = ['q' => $filterQ, 'department' => $filterDept, 'per_page' => $perPage, 'page' => 1];
    return url('/admin/users') . '?' . http_build_query(array_filter(
        array_merge($base, $merge),
        fn($v) => $v !== '' && $v !== null
    ));
}

ob_start();
?>

<?php foreach ($errors as $err): ?>
    <div class="alert alert-error" style="margin-bottom:var(--space-3);flex-shrink:0"><?= e($err) ?></div>
<?php endforeach; ?>
<?php foreach ($success as $suc): ?>
    <div class="alert alert-success" style="margin-bottom:var(--space-3);flex-shrink:0"><?= e($suc) ?></div>
<?php endforeach; ?>

<div class="auto-table-wrap" data-auto-page-size="admin-users" data-current-per-page="<?= (int)$perPage ?>">
<div class="auto-table-card">

    <form method="GET" action="<?= url('/admin/users') ?>" class="auto-table-toolbar" id="users-filter-form">
        <input type="hidden" name="per_page" value="<?= (int)$perPage ?>">

        <div class="auto-table-search">
            <?= icon('ic_fluent_search_24_filled', 14) ?>
            <input type="text" name="q" class="form-input" aria-label="Search users" placeholder="Search by name or email…" value="<?= e($filterQ) ?>">
        </div>

        <select name="department" class="form-input" style="width:240px" onchange="this.form.submit()" aria-label="Department">
            <option value="">All departments</option>
            <?php foreach ($availableDepts as $deptName): ?>
                <option value="<?= e($deptName) ?>" <?= $filterDept === $deptName ? 'selected' : '' ?>>
                    <?= e($deptName) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <?php if ($hasFilters): ?>
            <a href="<?= url('/admin/users') ?>" class="btn btn-ghost btn-sm">Clear</a>
        <?php endif; ?>
    </form>

    <div class="auto-table-body">
        <table class="auto-table">
            <thead>
                <tr>
                    <th style="width:200px">Name</th>
                    <th>Email</th>
                    <th style="width:120px">Role</th>
                    <th style="width:240px">Department</th>
                    <th style="width:100px">Status</th>
                    <th style="width:120px">Created</th>
                    <th style="width:220px"></th>
                </tr>
            </thead>
            <?php if (!empty($staffUsers)): ?>
            <tbody>
            <?php foreach ($staffUsers as $u): ?>
                <tr>
                    <td style="font-weight:var(--weight-medium)"><span class="auto-table-clip"><?= e($u['name']) ?></span></td>
                    <td style="font-size:var(--text-sm);color:var(--text-secondary)"><span class="auto-table-clip"><?= e($u['email']) ?></span></td>
                    <td>
                        <?php
                            $roleBadge = role_badge_class($u['role']);
                        ?>
                        <span class="badge badge-<?= e($roleBadge) ?>"><?= e(Auth::roleLabel($u['role'])) ?></span>
                    </td>
                    <td style="font-size:var(--text-sm)">
                        <?php if (in_array($u['role'], [ROLE_ADMIN, ROLE_SSO], true)): ?>
                            <span style="color:var(--text-secondary);font-size:var(--text-xs)">-</span>
                        <?php else: ?>
                        <form method="POST" style="display:block">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="update_department">
                            <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                            <select name="department" class="form-control" style="width:100%" aria-label="Department"
                                    onchange="this.form.submit()">
                                <option value="">- none -</option>
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
                    <td style="font-size:var(--text-sm);color:var(--text-secondary)"><?= format_date($u['created_at'], 'M j, Y') ?></td>
                    <td>
                        <div style="display:flex;gap:var(--space-2);align-items:center">
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
            </tbody>
            <?php endif; ?>
        </table>

        <?php if (empty($staffUsers)): ?>
        <div class="auto-table-empty">
            <?= icon('ic_fluent_document_24_regular', 32) ?>
            <div><?= $hasFilters ? 'No accounts match your filters.' : 'No staff or admin accounts.' ?></div>
        </div>
        <?php endif; ?>
    </div>
</div><!-- /.auto-table-card -->

<?php if ($pages > 1): ?>
<div class="auto-table-pagination">
    <p class="auto-table-pagination-info">
        Showing <strong><?= number_format(($page - 1) * $perPage + 1) ?>–<?= number_format(min($page * $perPage, $total)) ?></strong>
        of <strong><?= number_format($total) ?></strong> accounts
    </p>
    <div class="auto-table-pagination-controls">
        <?php if ($page > 1): ?>
            <a href="<?= usersUrl(['page' => $page - 1]) ?>" class="auto-table-page-btn" aria-label="Previous page">
                <?= icon('ic_fluent_chevron_left_24_regular', 16) ?>
            </a>
        <?php else: ?>
            <span class="auto-table-page-btn" aria-disabled="true" aria-label="Previous page">
                <?= icon('ic_fluent_chevron_left_24_regular', 16) ?>
            </span>
        <?php endif; ?>

        <span style="padding:0 var(--space-1);font-size:var(--text-sm);color:var(--text-tertiary);white-space:nowrap">
            Page <strong style="color:var(--text-primary);font-weight:var(--weight-semibold)"><?= $page ?></strong>
            of <strong style="color:var(--text-primary);font-weight:var(--weight-semibold)"><?= $pages ?></strong>
        </span>

        <?php if ($page < $pages): ?>
            <a href="<?= usersUrl(['page' => $page + 1]) ?>" class="auto-table-page-btn" aria-label="Next page">
                <?= icon('ic_fluent_chevron_right_24_regular', 16) ?>
            </a>
        <?php else: ?>
            <span class="auto-table-page-btn" aria-disabled="true" aria-label="Next page">
                <?= icon('ic_fluent_chevron_right_24_regular', 16) ?>
            </span>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>
</div><!-- /.auto-table-wrap -->

<!-- New User — moved into the page heading (top right) by the layout -->
<div data-page-actions>
    <?= ui_button('New User', ['variant' => 'primary', 'icon' => 'ic_fluent_add_24_regular', 'attrs' => ['onclick' => "document.getElementById('create-user-modal').style.display='flex'"]]) ?>
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
                    <label class="form-label" for="create_user_name">Full Name <span style="color:var(--error)">*</span></label>
                    <input type="text" id="create_user_name" name="name" class="form-control" required>
                </div>
                <div>
                    <label class="form-label" for="create_user_email">Email <span style="color:var(--error)">*</span></label>
                    <input type="email" id="create_user_email" name="email" class="form-control" required>
                </div>
                <div>
                    <label class="form-label" for="role-select">Role <span style="color:var(--error)">*</span></label>
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
                    <label class="form-label" id="dept-label" for="dept-select">
                        Department
                        <span id="dept-hint" style="color:var(--text-tertiary);font-weight:400"> - optional for SSO and Admin</span>
                    </label>
                    <select name="department" id="dept-select" class="form-control">
                        <option value="">- none -</option>
                        <?php foreach ($availableDepts as $deptName): ?>
                            <option value="<?= e($deptName) ?>"><?= e($deptName) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="form-label" for="create_user_password">Password <span style="color:var(--error)">*</span></label>
                    <input type="password" id="create_user_password" name="password" class="form-control" minlength="8" required>
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
                <label class="form-label" for="reset_new_password">New Password <span style="color:var(--error)">*</span></label>
                <input type="password" id="reset_new_password" name="new_password" class="form-control" minlength="8" required>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-ghost" onclick="document.getElementById('reset-pw-modal').style.display='none'">Cancel</button>
                <button type="submit" class="btn btn-primary">Reset</button>
            </div>
        </form>
    </div>
</div>

<script>window.PAGE = <?= json_encode(['roles' => ['dean' => ROLE_DEAN, 'proctor' => ROLE_PROCTOR, 'staff' => ROLE_STAFF, 'admin' => ROLE_ADMIN, 'sso' => ROLE_SSO]]) ?>;</script>

<?php
$content   = ob_get_clean();
$pageTitle = 'User Management';
$pageScripts = ['js/pages/admin-users.js'];
$activeNav = 'users';
$pageWide  = true;
include VIEWS_PATH . '/layouts/app.php';