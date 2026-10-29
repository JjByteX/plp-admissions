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
$filterAction = get_str('action');
$filterUser   = get_str('user');
$filterDate   = get_str('date');
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
    'Authentication' => [AuditAction::LOGIN, AuditAction::LOGOUT, AuditAction::LOGIN_FAILED],
    'Documents'      => [AuditAction::DOCUMENT_APPROVED, AuditAction::DOCUMENT_REJECTED, AuditAction::APPLICANT_ADVANCED_EXAM],
    'Interview'      => [AuditAction::INTERVIEW_COMPLETED, AuditAction::INTERVIEW_NO_SHOW, AuditAction::INTERVIEW_STARTED,
                         AuditAction::INTERVIEW_NOTES_SAVED, AuditAction::INTERVIEW_SLOT_DELETED,
                         AuditAction::INTERVIEW_SLOT_CLOSED, AuditAction::INTERVIEW_SLOT_REOPENED],
    'Results'        => [AuditAction::ADMISSION_RESULT],
    'Exam'           => [AuditAction::EXAM_CREATED, AuditAction::EXAM_UPDATED, AuditAction::EXAM_ACTIVATED],
    'Users'          => [AuditAction::USER_CREATED, AuditAction::USER_STATUS_CHANGED, AuditAction::USER_PASSWORD_RESET, AuditAction::USER_DELETED],
    'Settings'       => [AuditAction::SETTINGS_BRANDING_UPDATED, AuditAction::ADMIN_PASSWORD_CHANGED,
                         AuditAction::SCHOOL_YEAR_CHANGED, AuditAction::NEW_CYCLE_STARTED],
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
            <input type="text" id="audit_user" name="user" aria-label="Search by name" class="form-input" placeholder="Search by name…" value="<?= e($filterUser) ?>">
        </div>
        <?php endif; ?>

        <select id="audit_action" name="action" aria-label="Filter by action" class="form-input wpx-200" onchange="this.form.submit()">
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

        <input type="date" id="audit_date" name="date" aria-label="Filter by date" class="form-input wpx-160" value="<?= e($filterDate) ?>"
               onchange="this.form.submit()">

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
                    <th class="wpx-170">Date &amp; Time</th>
                    <?php if ($isAdmin): ?>
                    <th style="width:20%">User</th>
                    <?php endif; ?>
                    <th style="width:22%">Action</th>
                    <th>Description</th>
                    <th class="text-end" style="width:96px">Details</th>
                </tr>
            </thead>
            <?php if (!empty($logs)): ?>
            <tbody>
                <?php foreach ($logs as $log): ?>
                <tr>
                    <td class="text-xs text-secondary">
                        <?= e(format_datetime($log['created_at'])) ?>
                    </td>
                    <?php if ($isAdmin): ?>
                    <td>
                        <?php
                            $roleBadge = role_badge_class($log['user_role']);
                            $roleLabel = $log['user_role']
                                ? Auth::roleLabel($log['user_role'])
                                : 'System';
                        ?>
                        <span class="auto-table-clip text-sm fw-medium" style="line-height:1.3"
                              title="<?= e($log['user_name']) ?>"><?= e($log['user_name']) ?></span>
                        <span class="auto-table-clip text-xs text-tertiary" style="line-height:1.3"><?= e($roleLabel) ?></span>
                    </td>
                    <?php endif; ?>
                    <td>
                        <span class="badge <?= $actionBadge($log['action']) ?>" style="font-size:var(--text-2xs)">
                            <?= e($actionLabel($log['action'])) ?>
                        </span>
                    </td>
                    <td class="text-sm text-secondary"
                        title="<?= e($log['description'] ?? '') ?>">
                        <span class="auto-table-clip"><?= e($log['description'] ?? '-') ?></span>
                    </td>
                    <td class="text-end">
                        <?php
                            $entity = $log['entity_type']
                                ? ucwords(str_replace('_', ' ', $log['entity_type'])) . ($log['entity_id'] ? ' #' . $log['entity_id'] : '')
                                : '';
                            $detail = [
                                'id'          => (int)$log['id'],
                                'when'        => date('F j, Y g:i:s A', strtotime($log['created_at'])),
                                'user'        => (string)$log['user_name'],
                                'role'        => $isAdmin ? $roleLabel : '',
                                'roleBadge'   => $isAdmin ? $roleBadge : 'neutral',
                                'action'      => $actionLabel($log['action']),
                                'actionBadge' => $actionBadge($log['action']),
                                'description' => (string)($log['description'] ?? ''),
                                'entity'      => $entity,
                            ];
                        ?>
                        <button type="button" class="btn btn-ghost btn-sm audit-view"
                                data-log="<?= e(json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)) ?>">View</button>
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

        <span class="py-0 px-1 text-sm text-tertiary text-nowrap">
            Page <strong class="text-main fw-semibold"><?= $page ?></strong>
            of <strong class="text-main fw-semibold"><?= $pages ?></strong>
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

<!-- Details modal: the same on every screen size, so nothing in the table has to be read from a clipped cell -->
<div id="audit-modal" class="modal-backdrop" style="display:none" role="dialog" aria-modal="true" aria-labelledby="audit-modal-title">
    <div class="modal mwpx-560 overflow-y-auto" style="max-height:92vh">
        <div class="modal-header">
            <div class="modal-title" id="audit-modal-title">Log details</div>
            <button type="button" class="btn-icon" data-audit-close aria-label="Close">
                <?= icon('ic_fluent_dismiss_24_regular', 18) ?>
            </button>
        </div>
        <div class="modal-body">
            <dl class="audit-detail">
                <dt>Date &amp; time</dt><dd id="ad-when"></dd>
                <?php if ($isAdmin): ?>
                <dt>User</dt><dd><span id="ad-user"></span> <span id="ad-role" class="badge"></span></dd>
                <?php endif; ?>
                <dt>Action</dt><dd><span id="ad-action" class="badge"></span></dd>
                <dt>Related to</dt><dd id="ad-entity"></dd>
                <dt>Description</dt><dd id="ad-desc" class="audit-detail-desc"></dd>
                <dt>Log ID</dt><dd id="ad-id"></dd>
            </dl>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-ghost" data-audit-close>Close</button>
        </div>
    </div>
</div>

<?php
$content   = ob_get_clean();
$pageTitle = 'Audit Log';
$pageStyles  = ['css/pages/audit-log.css'];
$pageScripts = ['js/pages/audit-log.js'];
$activeNav = 'audit-log';
$pageWide  = true;
include VIEWS_PATH . '/layouts/app.php';
