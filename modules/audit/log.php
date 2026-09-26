<?php
// ============================================================
// modules/audit/log.php
// Audit Log — Admin sees all, Staff sees own actions only
// ============================================================

require_once CORE_PATH . '/bootstrap.php';
Auth::requireRole(ROLE_ADMIN);

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

// Page size — normally driven by AutoPageSize (app.js): it measures
// the table's actual on-screen height on load and reloads once with
// ?per_page=<rows that fit>, so the table fills the viewport with no
// internal scrollbar instead of showing a fixed 50 regardless of
// window size. 50 is just the pre-JS fallback for the very first
// render (and for no-JS / print). Clamped to the same [3, 200] range
// AutoPageSize itself enforces, so a hand-edited URL can't request
// an unbounded row count.
$perPage = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 50;
$perPage = max(3, min(200, $perPage));

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
// NOTE: previously returned 'badge-primary' / 'badge-secondary', which
// don't exist in app.css (only -pending/-uploaded/-review/-approved/
// -rejected/-accepted/-waitlisted and the -info/-warning/-success/
// -error/-neutral aliases do) — those rows rendered as an unstyled
// pill. Remapped to real classes below, keeping the same color intent.
$actionBadge = function(string $action): string {
    return match(true) {
        str_starts_with($action, 'login')        => 'badge-info',
        $action === 'logout'                     => 'badge-neutral',
        str_starts_with($action, 'document'),
        str_starts_with($action, 'applicant')    => 'badge-warning',
        str_starts_with($action, 'interview')    => 'badge-uploaded',
        str_starts_with($action, 'admission')    => 'badge-success',
        str_starts_with($action, 'exam')         => 'badge-info',
        str_starts_with($action, 'user')         => 'badge-error',
        default                                  => 'badge-neutral',
    };
};

$actionLabel = fn(string $a) => ucwords(str_replace('_', ' ', $a));

// ----------------------------------------------------------------
// URL helper — preserves every current filter + sort + per_page
// while overriding just the given keys (page links, clear-filter
// links, etc). Same shape as staff_manage.php's filterUrl().
// ----------------------------------------------------------------
function auditUrl(array $merge = []): string
{
    global $filterAction, $filterUser, $filterDate, $perPage;
    $base = [
        'action'   => $filterAction,
        'user'     => $filterUser,
        'date'     => $filterDate,
        'per_page' => $perPage,
        'page'     => 1,
    ];
    return url('/admin/audit-log') . '?' . http_build_query(array_filter(
        array_merge($base, $merge),
        fn($v) => $v !== '' && $v !== null
    ));
}

$hasFilters = ($filterAction || $filterUser || $filterDate);

// ----------------------------------------------------------------
// View
// ----------------------------------------------------------------
// Toolbar (search + filters) and table are fused into ONE bordered
// card — same shape as lakbay-pasig's AdminDataTable, whose `toolbar`
// slot renders inside the table's own card rather than a separate
// filter card above it. The card stretches to fill the page height
// (.page:has(.auto-table-card), app.css) so AutoPageSize (app.js) has
// a real, bounded height to measure on load; it then reloads once
// with ?per_page=<rows that fit> so the table fills the screen with
// no internal scrollbar, the same end result as the React original's
// client-side row slicing — just done with one reload instead of a
// re-render, since this app has no in-memory row array to re-slice.
ob_start();
?>

<div class="auto-table-card" data-auto-page-size="audit-log" data-current-per-page="<?= (int)$perPage ?>">

    <!-- Toolbar: search + filters, one row, inside the card -->
    <form method="GET" action="<?= url('/admin/audit-log') ?>" class="auto-table-toolbar">
        <input type="hidden" name="per_page" value="<?= (int)$perPage ?>">

        <?php if ($isAdmin): ?>
        <div class="auto-table-search">
            <?= icon('ic_fluent_search_24_filled', 14) ?>
            <input type="text" name="user" class="form-input" placeholder="Search by name…" value="<?= e($filterUser) ?>">
        </div>
        <?php endif; ?>

        <select name="action" class="form-input" style="width:200px" onchange="this.form.submit()">
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

        <input type="date" name="date" class="form-input" style="width:160px" value="<?= e($filterDate) ?>"
               onchange="this.form.submit()">

        <button type="submit" class="btn btn-secondary btn-sm">Filter</button>
        <?php if ($hasFilters): ?>
            <a href="<?= url('/admin/audit-log') ?>" class="btn btn-ghost btn-sm">Clear</a>
        <?php endif; ?>
    </form>

    <!-- Scrollable body — AutoPageSize measures this region's height -->
    <div class="auto-table-body">
        <table class="auto-table">
            <thead>
                <tr>
                    <th style="width:180px">Date &amp; Time</th>
                    <?php if ($isAdmin): ?>
                    <th style="width:220px">User</th>
                    <?php endif; ?>
                    <th style="width:200px">Action</th>
                    <th>Description</th>
                </tr>
            </thead>
            <?php if (!empty($logs)): ?>
            <tbody>
                <?php foreach ($logs as $log): ?>
                <tr>
                    <td style="font-size:var(--text-xs);color:var(--text-secondary)">
                        <?= e(date('M j, Y g:i A', strtotime($log['created_at']))) ?>
                    </td>
                    <?php if ($isAdmin): ?>
                    <td>
                        <?php
                            $roleBadge = match ($log['user_role']) {
                                ROLE_ADMIN   => 'error',
                                ROLE_DEAN    => 'warning',
                                ROLE_SSO     => 'success',
                                ROLE_STAFF   => 'info',
                                default      => 'neutral',
                            };
                            $roleLabel = $log['user_role']
                                ? Auth::roleLabel($log['user_role'])
                                : 'System';
                        ?>
                        <span style="display:inline-flex;align-items:center;gap:var(--space-2);max-width:100%">
                            <span class="auto-table-clip" style="font-size:var(--text-sm);font-weight:var(--weight-medium)"><?= e($log['user_name']) ?></span>
                            <span class="badge badge-<?= $roleBadge ?>" style="font-size:10px;flex-shrink:0"><?= e($roleLabel) ?></span>
                        </span>
                    </td>
                    <?php endif; ?>
                    <td>
                        <span class="badge <?= $actionBadge($log['action']) ?>" style="font-size:10px">
                            <?= e($actionLabel($log['action'])) ?>
                        </span>
                    </td>
                    <td style="font-size:var(--text-sm);color:var(--text-secondary)"
                        title="<?= e($log['description'] ?? '') ?>">
                        <span class="auto-table-clip"><?= e($log['description'] ?? '—') ?></span>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <?php endif; ?>
        </table>

        <?php if (empty($logs)): ?>
        <div class="auto-table-empty">
            <?= icon('ic_fluent_document_24_regular', 32) ?>
            <div><?= $hasFilters ? 'No activity matches your filters.' : 'No activity records found.' ?></div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Pagination footer — OUTSIDE the card, "Showing X–Y of Z" + Prev/Next,
     same shape as lakbay-pasig's AdminDataTable footer. -->
<?php if ($pages > 1): ?>
<div class="auto-table-pagination">
    <p class="auto-table-pagination-info">
        Showing <strong><?= number_format(($page - 1) * $perPage + 1) ?>–<?= number_format(min($page * $perPage, $total)) ?></strong>
        of <strong><?= number_format($total) ?></strong> records
    </p>
    <div class="auto-table-pagination-controls">
        <?php if ($page > 1): ?>
            <a href="<?= auditUrl(['page' => $page - 1]) ?>" class="auto-table-page-btn" aria-label="Previous page">
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
            <a href="<?= auditUrl(['page' => $page + 1]) ?>" class="auto-table-page-btn" aria-label="Next page">
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

<?php
$content   = ob_get_clean();
$pageTitle = 'Audit Log';
$activeNav = 'audit-log';
$pageWide  = true;
include VIEWS_PATH . '/layouts/app.php';
