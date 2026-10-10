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

// Page size — driven by AutoPageSize (app.js): it measures how many
// fixed-height rows fit the table's on-screen region and, if that is not
// what was rendered, reloads with ?per_page=<rows that fit>, so the table
// fills the viewport with no empty space at the bottom and no internal
// scrollbar. Where the size comes from, in order:
//   1. ?per_page — set by AutoPageSize's own reload, and carried by every
//      page / filter link on this page.
//   2. the plp_auto_page_size_audit_log cookie — the last size AutoPageSize
//      measured in this browser. Without it a plain visit (sidebar link)
//      would render the fallback below, overflow, and reload every time.
//   3. 50 — only the very first visit, before anything was measured (and
//      for no-JS / print).
// Clamped to [3, 100], the same range AutoPageSize enforces, so a
// hand-edited URL or cookie can't request an unbounded row count.
$perPage = isset($_GET['per_page'])
    ? (int)$_GET['per_page']
    : (int)($_COOKIE['plp_auto_page_size_audit_log'] ?? 0);
if ($perPage <= 0) {
    $perPage = 50;
}
$perPage = max(3, min(100, $perPage));

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
    $where[]  = 'user_name ILIKE ?';
    $params[] = '%' . $filterUser . '%';
}

if ($filterDate) {
    $where[]  = 'created_at::date = ?';
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
// Same structure as lakbay-pasig's AdminDataTable: one outer container
// (.auto-table-wrap) holding the bordered card (toolbar + table fused
// into it, the `toolbar` slot) and, under the card, the pagination bar.
// The wrap fills the page height (.page:has(.auto-table-wrap), app.css),
// so it is the bounded region AutoPageSize (app.js) measures: it works
// out how many fixed-height rows fit, and if that is not the size this
// page was rendered with it reloads with ?per_page=<rows that fit>, so
// the table fills the screen with no empty space at the bottom and no
// internal scrollbar. Same end result as the React original's
// client-side row slicing — just via a reload, since this app has no
// in-memory row array to re-slice.
ob_start();
?>

<div class="auto-table-wrap" data-auto-page-size="audit-log" data-current-per-page="<?= (int)$perPage ?>">

<div class="auto-table-card">

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

    <!-- Table body — sized so the rows AutoPageSize picks fit exactly; it
         only scrolls as a safety net (e.g. a very narrow window) -->
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
</div><!-- /.auto-table-card -->

<!-- Pagination bar — under the card, inside the wrap, "Showing X–Y of Z" +
     Prev/Next, same shape as lakbay-pasig's AdminDataTable footer. Its 40px
     (32px bar + 8px gap) is reserved in AutoPageSize's math whether or not
     it renders, so a single page leaves the same room. -->
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

</div><!-- /.auto-table-wrap -->

<?php
$content   = ob_get_clean();
$pageTitle = 'Audit Log';
$activeNav = 'audit-log';
$pageWide  = true;
include VIEWS_PATH . '/layouts/app.php';
