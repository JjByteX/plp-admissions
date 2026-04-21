<?php
// ============================================================
// modules/audit/log.php
// Audit Log — Admin sees all, Staff sees own actions only
// ============================================================

require_once CORE_PATH . '/bootstrap.php';
Auth::requireRole(ROLE_STAFF, ROLE_ADMIN);

$db      = db();
$isAdmin = Auth::isAdmin();
$userId  = Auth::id();

// ----------------------------------------------------------------
// Filters
// ----------------------------------------------------------------
$filterAction = trim($_GET['action'] ?? '');
$filterUser   = trim($_GET['user']   ?? '');
$filterDate   = trim($_GET['date']   ?? '');
$page         = max(1, (int)($_GET['page'] ?? 1));
$perPage      = 50;

// ----------------------------------------------------------------
// Action categories for filter dropdown
// ----------------------------------------------------------------
$actionGroups = [
    'Authentication' => ['login', 'logout', 'login_failed'],
    'Documents'      => ['document_approved', 'document_rejected', 'applicant_advanced_exam'],
    'Interview'      => ['interview_completed', 'interview_no_show', 'interview_started',
                         'interview_notes_saved', 'interview_slot_deleted',
                         'interview_slot_closed', 'interview_slot_reopened'],
    'Results'        => ['admission_result'],
    'Exam'           => ['exam_created', 'exam_updated', 'exam_activated'],
    'Users'          => ['user_created', 'user_status_changed', 'user_password_reset', 'user_deleted'],
    'Settings'       => ['settings_branding_updated', 'admin_password_changed',
                         'school_year_changed', 'new_cycle_started'],
];

// ----------------------------------------------------------------
// Build query
// ----------------------------------------------------------------
$where  = [];
$params = [];

if (!$isAdmin) {
    $where[]  = 'user_id = ?';
    $params[] = $userId;
}

if ($filterAction) {
    $where[]  = 'action = ?';
    $params[] = $filterAction;
}

if ($filterUser && $isAdmin) {
    $where[]  = 'user_name LIKE ?';
    $params[] = '%' . $filterUser . '%';
}

if ($filterDate) {
    $where[]  = 'DATE(created_at) = ?';
    $params[] = $filterDate;
}

$whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Count
$countStmt = $db->prepare("SELECT COUNT(*) FROM audit_logs {$whereClause}");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();
$pages = max(1, (int)ceil($total / $perPage));
$page  = min($page, $pages);
$offset = ($page - 1) * $perPage;

// Data
$dataStmt = $db->prepare(
    "SELECT * FROM audit_logs {$whereClause}
     ORDER BY created_at DESC
     LIMIT {$perPage} OFFSET {$offset}"
);
$dataStmt->execute($params);
$logs = $dataStmt->fetchAll();

// ----------------------------------------------------------------
// Badge colour helper
// ----------------------------------------------------------------
$actionBadge = function(string $action): string {
    return match(true) {
        str_starts_with($action, 'login')        => 'badge-info',
        $action === 'logout'                     => 'badge-secondary',
        str_starts_with($action, 'document'),
        str_starts_with($action, 'applicant')    => 'badge-warning',
        str_starts_with($action, 'interview')    => 'badge-primary',
        str_starts_with($action, 'admission')    => 'badge-success',
        str_starts_with($action, 'exam')         => 'badge-info',
        str_starts_with($action, 'user')         => 'badge-error',
        default                                  => 'badge-secondary',
    };
};

$actionLabel = fn(string $a) => ucwords(str_replace('_', ' ', $a));

// ----------------------------------------------------------------
// View
// ----------------------------------------------------------------
ob_start();
?>

<div class="page-header" style="margin-bottom:var(--space-6)">
    <div>
        <h1 class="page-title">Audit Log</h1>
        <p class="page-subtitle">
            <?= $isAdmin ? 'All system activity' : 'Your activity' ?> —
            <?= number_format($total) ?> record<?= $total !== 1 ? 's' : '' ?>
        </p>
    </div>
</div>

<!-- Filters -->
<div class="card" style="margin-bottom:var(--space-5);padding:var(--space-4)">
    <form method="GET" action="<?= url('/admin/audit-log') ?>" style="display:flex;flex-wrap:wrap;gap:var(--space-3);align-items:flex-end">
        <?php if ($isAdmin): ?>
        <div style="flex:1;min-width:160px">
            <label class="form-label">User</label>
            <input type="text" name="user" class="form-input" placeholder="Search by name…" value="<?= e($filterUser) ?>">
        </div>
        <?php endif; ?>

        <div style="flex:1;min-width:190px">
            <label class="form-label">Action</label>
            <select name="action" class="form-input">
                <option value="">All actions</option>
                <?php foreach ($actionGroups as $group => $actions): ?>
                    <optgroup label="<?= e($group) ?>">
                        <?php foreach ($actions as $a): ?>
                            <option value="<?= e($a) ?>" <?= $filterAction === $a ? 'selected' : '' ?>>
                                <?= e($actionLabel($a)) ?>
                            </option>
                        <?php endforeach; ?>
                    </optgroup>
                <?php endforeach; ?>
            </select>
        </div>

        <div style="flex:1;min-width:150px">
            <label class="form-label">Date</label>
            <input type="date" name="date" class="form-input" value="<?= e($filterDate) ?>">
        </div>

        <div style="display:flex;gap:var(--space-2)">
            <button type="submit" class="btn btn-primary">Filter</button>
            <?php if ($filterAction || $filterUser || $filterDate): ?>
                <a href="<?= url('/admin/audit-log') ?>" class="btn btn-secondary">Clear</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<!-- Log Table -->
<div class="card" style="padding:0;overflow:hidden">
    <?php if (empty($logs)): ?>
        <div style="padding:var(--space-12);text-align:center;color:var(--text-tertiary)">
            No activity records found.
        </div>
    <?php else: ?>
    <div style="overflow-x:auto">
        <table class="data-table">
            <thead>
                <tr>
                    <th style="width:160px">Date & Time</th>
                    <?php if ($isAdmin): ?>
                    <th style="width:160px">User</th>
                    <?php endif; ?>
                    <th style="width:190px">Action</th>
                    <th>Description</th>
                    <th style="width:130px">IP Address</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($logs as $log): ?>
                <tr>
                    <td style="font-size:var(--text-xs);color:var(--text-secondary);white-space:nowrap">
                        <?= e(date('M j, Y', strtotime($log['created_at']))) ?><br>
                        <span style="color:var(--text-tertiary)"><?= e(date('g:i:s A', strtotime($log['created_at']))) ?></span>
                    </td>
                    <?php if ($isAdmin): ?>
                    <td>
                        <div style="font-size:var(--text-sm);font-weight:var(--weight-medium)"><?= e($log['user_name']) ?></div>
                        <span class="badge badge-<?= $log['user_role'] === 'admin' ? 'error' : ($log['user_role'] === 'staff' ? 'info' : 'secondary') ?>" style="font-size:10px">
                            <?= e(ucfirst($log['user_role'] ?: 'system')) ?>
                        </span>
                    </td>
                    <?php endif; ?>
                    <td>
                        <span class="badge <?= $actionBadge($log['action']) ?>" style="font-size:10px;white-space:nowrap">
                            <?= e($actionLabel($log['action'])) ?>
                        </span>
                    </td>
                    <td style="font-size:var(--text-sm);color:var(--text-secondary)">
                        <?= e($log['description'] ?? '—') ?>
                    </td>
                    <td style="font-size:var(--text-xs);color:var(--text-tertiary);font-family:monospace">
                        <?= e($log['ip_address'] ?? '—') ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <?php if ($pages > 1): ?>
    <div style="padding:var(--space-4);display:flex;justify-content:space-between;align-items:center;border-top:1px solid var(--border)">
        <span style="font-size:var(--text-sm);color:var(--text-tertiary)">
            Showing <?= number_format(($page - 1) * $perPage + 1) ?>–<?= number_format(min($page * $perPage, $total)) ?> of <?= number_format($total) ?>
        </span>
        <div style="display:flex;gap:var(--space-1);flex-wrap:wrap">
            <?php
            $qBase = http_build_query(array_filter([
                'action' => $filterAction,
                'user'   => $filterUser,
                'date'   => $filterDate,
            ]));
            // Show at most 10 page buttons
            $startPage = max(1, $page - 4);
            $endPage   = min($pages, $startPage + 9);
            for ($p = $startPage; $p <= $endPage; $p++):
                $q = $qBase ? $qBase . '&page=' . $p : 'page=' . $p;
            ?>
                <a href="<?= url('/admin/audit-log?' . $q) ?>"
                   class="btn btn-sm <?= $p === $page ? 'btn-primary' : 'btn-secondary' ?>"
                   style="min-width:36px;text-align:center">
                    <?= $p ?>
                </a>
            <?php endfor; ?>
        </div>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<?php
$content   = ob_get_clean();
$pageTitle = 'Audit Log';
$activeNav = 'audit-log';
include VIEWS_PATH . '/layouts/app.php';
