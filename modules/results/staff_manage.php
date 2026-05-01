<?php
// ============================================================
// modules/results/staff_manage.php
// M6 — Staff: release admission results
// Fix 2: score-tier filters, status filters, search, bulk select,
//         auto-waitlist, per-row inline status dropdown
// ============================================================

require_once CORE_PATH . '/bootstrap.php';
Auth::requireRole(ROLE_STAFF, ROLE_ADMIN);

$db      = db();
$staffId = Auth::id();

$search     = trim($_GET['q']      ?? '');
$filterRes  = $_GET['result']      ?? '';   // accepted | waitlisted | rejected | pending | ''
$filterTier = $_GET['tier']        ?? '';   // high | avg | low | ''
$page       = max(1, (int)($_GET['page'] ?? 1));

// ── WHERE builder ────────────────────────────────────────────
$where  = ["a.overall_status IN ('result','released','exam','interview')"];
$params = [];

if ($search) {
    $where[]      = "(u.name LIKE :q OR u.email LIKE :q OR a.application_number LIKE :q)";
    $params[':q'] = '%' . $search . '%';
}
if ($filterRes === 'pending') {
    $where[] = 'ar.result IS NULL';
} elseif ($filterRes) {
    $where[]           = 'ar.result = :result';
    $params[':result'] = $filterRes;
}
if ($filterTier === 'high') {
    $where[] = 'ROUND((er.score / NULLIF(er.total_items,0)) * 100) >= 85';
} elseif ($filterTier === 'avg') {
    $where[] = 'ROUND((er.score / NULLIF(er.total_items,0)) * 100) BETWEEN 60 AND 84';
} elseif ($filterTier === 'low') {
    $where[] = 'ROUND((er.score / NULLIF(er.total_items,0)) * 100) < 60';
}

$whereStr = implode(' AND ', $where);

// ── Tier/status counts (for filter pills) ───────────────────
$tierCounts = $db->query(
    "SELECT
       SUM(pct >= 85)                AS high,
       SUM(pct BETWEEN 60 AND 84)   AS avg,
       SUM(pct < 60)                AS low,
       SUM(result IS NULL)          AS pending_count,
       SUM(result='accepted')       AS accepted_count,
       SUM(result='waitlisted')     AS waitlisted_count,
       SUM(result='rejected')       AS rejected_count,
       COUNT(*)                     AS total_count
     FROM (
       SELECT ROUND((er.score / NULLIF(er.total_items,0)) * 100) AS pct, ar.result
       FROM applicants a
       JOIN users u ON u.id = a.user_id
       LEFT JOIN admission_results ar ON ar.applicant_id = a.id
       LEFT JOIN exam_results       er ON er.applicant_id = a.id
       WHERE a.overall_status IN ('result','released','exam','interview')
     ) sub"
)->fetch(PDO::FETCH_ASSOC);

// ── Paginate ─────────────────────────────────────────────────
$result = paginate(
    $db,
    "SELECT COUNT(*)
     FROM applicants a
     JOIN users u ON u.id = a.user_id
     LEFT JOIN admission_results ar ON ar.applicant_id = a.id
     LEFT JOIN exam_results       er ON er.applicant_id = a.id
     WHERE $whereStr",
    "SELECT a.*, u.name AS student_name, u.email,
            ar.result AS admission_result, ar.remarks AS admission_remarks, ar.released_at,
            er.score  AS exam_score, er.total_items AS exam_total,
            er.rank_score AS exam_rank, er.passed AS exam_passed,
            iq.status AS interview_status, iq.interview_notes,
            ROUND((er.score / NULLIF(er.total_items,0)) * 100) AS score_pct
     FROM applicants a
     JOIN users u ON u.id = a.user_id
     LEFT JOIN admission_results ar ON ar.applicant_id = a.id
     LEFT JOIN exam_results       er ON er.applicant_id = a.id
     LEFT JOIN interview_queue    iq ON iq.applicant_id = a.id
     WHERE $whereStr
     ORDER BY a.updated_at DESC",
    $params, $page, 25
);

// ── Filter URL helper ────────────────────────────────────────
function filterUrl(array $merge = []): string {
    global $search, $filterRes, $filterTier;
    $base = ['q' => $search, 'result' => $filterRes, 'tier' => $filterTier, 'page' => 1];
    return '?' . http_build_query(array_merge($base, $merge));
}

ob_start();
?>

<!-- Toast container -->
<div id="toast-container" style="position:fixed;bottom:1.5rem;right:1.5rem;z-index:9999;display:flex;flex-direction:column;gap:0.5rem;pointer-events:none"></div>

<!-- ── Top bar: tabs (left) + search & actions (right) ─── -->
<div style="
    display:flex;
    align-items:flex-end;
    justify-content:space-between;
    gap:var(--space-4);
    margin-bottom:var(--space-5);
    border-bottom:1px solid var(--border);
    flex-wrap:wrap;
">
    <!-- Score tier tabs -->
    <div style="display:flex;gap:var(--space-1);flex-wrap:wrap">
        <?php
        $tierTabs = [
            ''     => ['label' => 'All',       'count' => (int)$tierCounts['total_count']],
            'high' => ['label' => 'High ≥85%', 'count' => (int)$tierCounts['high']],
            'avg'  => ['label' => 'Avg 60–84%','count' => (int)$tierCounts['avg']],
            'low'  => ['label' => 'Low <60%',  'count' => (int)$tierCounts['low']],
        ];
        foreach ($tierTabs as $val => $tab):
            $active = ($filterTier === $val);
        ?>
            <a href="<?= filterUrl(['tier' => $val, 'result' => '']) ?>"
               style="
                   padding:var(--space-2) var(--space-4);
                   border-bottom:2px solid <?= $active ? 'var(--accent)' : 'transparent' ?>;
                   color:<?= $active ? 'var(--accent)' : 'var(--text-secondary)' ?>;
                   font-size:var(--text-sm);
                   font-weight:<?= $active ? 'var(--weight-semibold)' : 'var(--weight-normal)' ?>;
                   white-space:nowrap;text-decoration:none;margin-bottom:-1px;
                   transition:color var(--transition-fast);
               ">
                <?= $tab['label'] ?>
                <span style="margin-left:4px;font-size:var(--text-xs);color:var(--text-tertiary)"><?= $tab['count'] ?></span>
            </a>
        <?php endforeach; ?>
    </div>

    <!-- Search + Auto-waitlist -->
    <div style="display:flex;align-items:center;gap:var(--space-2);padding-bottom:var(--space-1);flex-shrink:0;flex-wrap:wrap">
        <div style="position:relative">
            <svg width="14" height="14" fill="none" viewBox="0 0 24 24"
                 style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:var(--text-tertiary);pointer-events:none">
                <path stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M21 21l-4.35-4.35m0 0A7 7 0 105.65 5.65a7 7 0 0011 11.35z"/>
            </svg>
            <input type="text" id="live-search" class="form-control"
                   style="padding-left:32px;height:34px;font-size:var(--text-sm);width:220px;border-radius:var(--radius-sm)"
                   placeholder="Search name or app #…"
                   value="<?= e($search) ?>">
        </div>
        <button class="btn btn-ghost btn-sm" onclick="confirmAutoWaitlist()"
                style="display:flex;align-items:center;gap:var(--space-1);white-space:nowrap">
            <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            Auto-waitlist &lt;60%
        </button>
    </div>
</div>

<!-- ── Status filter sub-tabs ─────────────────────────────── -->
<div style="display:flex;gap:var(--space-2);flex-wrap:wrap;margin-bottom:var(--space-4)">
    <?php
    $statusTabs = [
        ''           => ['label' => 'All results',  'count' => (int)$tierCounts['total_count']],
        'pending'    => ['label' => 'Pending',       'count' => (int)$tierCounts['pending_count']],
        'accepted'   => ['label' => 'Accepted',      'count' => (int)$tierCounts['accepted_count']],
        'waitlisted' => ['label' => 'Waitlisted',    'count' => (int)$tierCounts['waitlisted_count']],
        'rejected'   => ['label' => 'Rejected',      'count' => (int)$tierCounts['rejected_count']],
    ];
    $badgeMap = [
        'pending'    => 'badge-pending',
        'accepted'   => 'badge-accepted',
        'waitlisted' => 'badge-waitlisted',
        'rejected'   => 'badge-rejected',
    ];
    foreach ($statusTabs as $val => $tab):
        $active = ($filterRes === $val);
    ?>
        <a href="<?= filterUrl(['result' => $val]) ?>"
           style="
               display:inline-flex;align-items:center;gap:var(--space-2);
               padding:var(--space-1) var(--space-3);
               border:1px solid <?= $active ? 'var(--accent)' : 'var(--border)' ?>;
               border-radius:var(--radius-full);
               font-size:var(--text-sm);text-decoration:none;
               background:<?= $active ? 'var(--accent)' : 'var(--bg-elevated)' ?>;
               color:<?= $active ? 'var(--accent-text)' : 'var(--text-secondary)' ?>;
               transition:border-color var(--transition-fast),background var(--transition-fast),color var(--transition-fast);
           ">
            <?= $tab['label'] ?>
            <span style="
                font-size:var(--text-xs);
                background:<?= $active ? 'rgba(255,255,255,.25)' : 'var(--bg-subtle)' ?>;
                color:<?= $active ? 'var(--accent-text)' : 'var(--text-tertiary)' ?>;
                padding:1px 6px;border-radius:var(--radius-full);
            "><?= $tab['count'] ?></span>
        </a>
    <?php endforeach; ?>
</div>

<!-- ── Bulk action bar ──────────────────────────────────────── -->
<div id="bulk-bar" style="display:none;align-items:center;gap:var(--space-3);
     padding:var(--space-3) var(--space-4);margin-bottom:var(--space-3);
     background:var(--bg-elevated);border:1px solid var(--border);border-radius:var(--radius-md);flex-wrap:wrap">
    <span style="font-size:var(--text-sm);color:var(--text-secondary)">
        <span id="bulk-count" style="font-weight:var(--weight-semibold);color:var(--text-primary)">0</span> selected
    </span>
    <form method="POST" id="bulk-form" action="<?= url('/staff/results/bulk') ?>" style="display:contents">
        <?= csrf_field() ?>
        <input type="hidden" name="action" id="bulk-action-input" value="">
        <div id="bulk-ids-container"></div>
        <button type="button" class="btn btn-sm" onclick="submitBulk('accepted')"
                style="background:var(--status-approved-bg);color:var(--status-approved);border:1px solid transparent">
            <?= icon('ic_fluent_checkmark_24_regular', 13) ?> Accept
        </button>
        <button type="button" class="btn btn-sm" onclick="submitBulk('waitlisted')"
                style="background:var(--status-review-bg);color:var(--status-review);border:1px solid transparent">
            <?= icon('ic_fluent_clock_24_regular', 13) ?> Waitlist
        </button>
        <button type="button" class="btn btn-sm" onclick="submitBulk('rejected')"
                style="background:var(--status-rejected-bg);color:var(--status-rejected);border:1px solid transparent">
            <?= icon('ic_fluent_dismiss_24_regular', 13) ?> Reject
        </button>
    </form>
    <button type="button" class="btn btn-ghost btn-sm" onclick="clearSelection()" style="margin-left:auto">Clear</button>
</div>

<!-- ── Results table ───────────────────────────────────────── -->
<div class="card" style="padding:0;overflow:hidden">
    <table class="table" id="results-table" style="width:100%">
        <thead>
            <tr style="background:var(--bg-subtle);font-size:var(--text-xs);color:var(--text-tertiary);text-transform:uppercase;letter-spacing:.06em">
                <th style="padding:var(--space-3) var(--space-4);width:36px">
                    <input type="checkbox" id="check-all" title="Select all visible">
                </th>
                <th style="padding:var(--space-3) var(--space-4)">App #</th>
                <th style="padding:var(--space-3) var(--space-4)">Applicant</th>
                <th style="padding:var(--space-3) var(--space-4)">Type</th>
                <th style="padding:var(--space-3) var(--space-4)">Score</th>
                <th style="padding:var(--space-3) var(--space-4)">Rank</th>
                <th style="padding:var(--space-3) var(--space-4)">Result</th>
                <th style="padding:var(--space-3) var(--space-4)">Action</th>
            </tr>
        </thead>
        <tbody id="results-tbody">
        <?php if (empty($result['data'])): ?>
            <tr>
                <td colspan="8" style="padding:var(--space-12);text-align:center;color:var(--text-tertiary)">
                    <svg width="32" height="32" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.2"
                         style="display:block;margin:0 auto var(--space-3)">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                    </svg>
                    No applicants match the current filters.
                </td>
            </tr>
        <?php else: ?>
            <?php foreach ($result['data'] as $row):
                $pct      = ($row['exam_score'] !== null && (int)$row['exam_total'] > 0)
                            ? (int) round(($row['exam_score'] / $row['exam_total']) * 100)
                            : null;
                $tier     = ($pct === null) ? 'none'
                          : ($pct >= 85 ? 'high' : ($pct >= 60 ? 'avg' : 'low'));
                $rank     = null;
                $tierInfo = ['label' => '—', 'color' => 'var(--text-tertiary)', 'bg' => 'transparent'];
                if ($row['exam_score'] !== null) {
                    $rank     = $row['exam_rank'] > 0
                                ? (int)$row['exam_rank']
                                : score_to_rank((int)$row['exam_score'], (int)($row['exam_total'] ?: 1));
                    $tierInfo = rank_tier_info($rank);
                }
                $admResult = $row['admission_result'];
                $appNum    = $row['application_number'] ?? ('APP-' . str_pad($row['id'], 5, '0', STR_PAD_LEFT));

                $tierBadgeClass = [
                    'high' => 'badge-green',
                    'avg'  => 'badge-amber',
                    'low'  => 'badge-danger',
                    'none' => '',
                ][$tier] ?? '';
            ?>
            <tr class="result-row" data-id="<?= $row['id'] ?>"
                style="border-top:1px solid var(--border);font-size:var(--text-sm)">
                <td style="padding:var(--space-3) var(--space-4)">
                    <input type="checkbox" class="row-check" value="<?= $row['id'] ?>">
                </td>

                <td style="padding:var(--space-3) var(--space-4);font-family:monospace;font-size:var(--text-xs);color:var(--text-tertiary)">
                    <?= e($appNum) ?>
                </td>

                <td style="padding:var(--space-3) var(--space-4)">
                    <div style="font-weight:var(--weight-medium)"><?= e($row['student_name']) ?></div>
                    <div style="font-size:var(--text-xs);color:var(--text-tertiary)"><?= e($row['email']) ?></div>
                    <?php if ($row['course_applied']): ?>
                        <div style="font-size:var(--text-xs);color:var(--text-secondary);margin-top:2px"><?= e($row['course_applied']) ?></div>
                    <?php endif; ?>
                </td>

                <td style="padding:var(--space-3) var(--space-4);font-size:var(--text-xs);text-transform:capitalize;color:var(--text-secondary)">
                    <?= e($row['applicant_type']) ?>
                </td>

                <td style="padding:var(--space-3) var(--space-4)">
                    <?php if ($pct !== null): ?>
                        <span class="badge badge-<?= $tier === 'high' ? 'green' : ($tier === 'avg' ? 'amber' : 'danger') ?>"
                              style="font-size:var(--text-xs);font-weight:var(--weight-semibold)">
                            <?= $pct ?>%
                        </span>
                        <div style="font-family:monospace;font-size:var(--text-xs);color:var(--text-tertiary);margin-top:2px">
                            <?= (int)$row['exam_score'] ?>/<?= (int)$row['exam_total'] ?>
                        </div>
                    <?php else: ?>
                        <span style="color:var(--text-tertiary)">—</span>
                    <?php endif; ?>
                </td>

                <td style="padding:var(--space-3) var(--space-4)">
                    <?php if ($rank !== null): ?>
                        <span style="font-size:var(--text-sm);font-weight:var(--weight-medium);color:<?= $tierInfo['color'] ?>">
                            <?= $rank ?>
                        </span>
                    <?php else: ?>
                        <span style="color:var(--text-tertiary)">—</span>
                    <?php endif; ?>
                </td>

                <td style="padding:var(--space-3) var(--space-4)">
                    <?php if ($admResult): ?>
                        <span class="badge badge-<?= $admResult ?>"><?= ucfirst($admResult) ?></span>
                    <?php else: ?>
                        <span class="badge badge-pending">Pending</span>
                    <?php endif; ?>
                </td>

                <td style="padding:var(--space-3) var(--space-4)">
                    <div style="display:flex;gap:var(--space-2);align-items:center">
                        <select class="form-control inline-status-select" data-id="<?= $row['id'] ?>"
                                style="height:30px;font-size:var(--text-xs);padding:0 var(--space-2);min-width:90px">
                            <option value="">— Set —</option>
                            <option value="accepted"   <?= $admResult === 'accepted'   ? 'selected' : '' ?>>Accept</option>
                            <option value="waitlisted" <?= $admResult === 'waitlisted' ? 'selected' : '' ?>>Waitlist</option>
                            <option value="rejected"   <?= $admResult === 'rejected'   ? 'selected' : '' ?>>Reject</option>
                        </select>
                        <button class="btn btn-ghost btn-sm"
                                onclick="openReleaseModal(<?= $row['id'] ?>,<?= htmlspecialchars(json_encode($row['student_name']),ENT_QUOTES) ?>,<?= htmlspecialchars(json_encode($admResult),ENT_QUOTES) ?>,<?= htmlspecialchars(json_encode($row['admission_remarks'] ?? ''),ENT_QUOTES) ?>)">
                            <?= $admResult ? 'Edit' : 'Release' ?>
                        </button>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- Pagination -->
<?php if ($result['last_page'] > 1): ?>
    <div style="display:flex;justify-content:center;gap:var(--space-2);margin-top:var(--space-6)">
        <?php for ($i = 1; $i <= $result['last_page']; $i++): ?>
            <a href="<?= filterUrl(['page' => $i]) ?>"
               class="btn <?= $i === $result['current_page'] ? 'btn-primary' : 'btn-ghost' ?> btn-sm"
               style="min-width:36px"><?= $i ?></a>
        <?php endfor; ?>
    </div>
<?php endif; ?>

<!-- ── Release / Edit modal ─────────────────────────────────── -->
<div id="release-modal" class="modal-backdrop" style="display:none">
    <div class="modal" style="max-width:420px">
        <div class="modal-header">
            <div class="modal-title">Release Result</div>
            <button class="btn-icon" onclick="document.getElementById('release-modal').style.display='none'">
                <?= icon('ic_fluent_dismiss_24_regular', 18) ?>
            </button>
        </div>
        <form method="POST" id="release-form">
            <?= csrf_field() ?>
            <div class="modal-body" style="display:flex;flex-direction:column;gap:var(--space-4)">
                <p id="release-name" style="font-weight:var(--weight-medium)"></p>
                <div>
                    <label class="form-label">Decision <span style="color:var(--error)">*</span></label>
                    <select name="result" class="form-control" id="release-result" required>
                        <option value="">Select…</option>
                        <?php foreach (RESULT_LABELS as $val => $lbl): ?>
                            <option value="<?= $val ?>"><?= $lbl ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="form-label">Remarks (optional)</label>
                    <textarea name="remarks" class="form-control" rows="3" id="release-remarks"
                              placeholder="Additional notes for the applicant…"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-ghost"
                        onclick="document.getElementById('release-modal').style.display='none'">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Result</button>
            </div>
        </form>
    </div>
</div>

<!-- ── Auto-waitlist confirm modal ─────────────────────────── -->
<div id="auto-waitlist-modal" class="modal-backdrop" style="display:none">
    <div class="modal" style="max-width:400px">
        <div class="modal-header">
            <div class="modal-title">Auto-waitlist Low Scorers</div>
            <button class="btn-icon" onclick="document.getElementById('auto-waitlist-modal').style.display='none'">
                <?= icon('ic_fluent_dismiss_24_regular', 18) ?>
            </button>
        </div>
        <div class="modal-body">
            <p style="font-size:var(--text-sm)">
                This will automatically set all <strong>pending</strong> applicants with
                an exam score <strong>below 60%</strong> to <em>Waitlisted</em>.
            </p>
            <p style="font-size:var(--text-sm);color:var(--text-tertiary);margin-top:var(--space-2)">
                Applicants already assigned a result will not be affected.
            </p>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-ghost"
                    onclick="document.getElementById('auto-waitlist-modal').style.display='none'">Cancel</button>
            <form method="POST" action="<?= url('/staff/results/auto-waitlist') ?>" style="display:inline">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-primary">Confirm Auto-waitlist</button>
            </form>
        </div>
    </div>
</div>

<script>
// ── Toast ─────────────────────────────────────────────────────
function showToast(msg, type) {
    var tc = document.getElementById('toast-container');
    var t  = document.createElement('div');
    var bg = type === 'success' ? '#1D9E75' : (type === 'error' ? '#E24B4A' : '#378ADD');
    t.style.cssText = 'pointer-events:auto;padding:.625rem 1rem;border-radius:var(--radius-md);' +
                      'font-size:var(--text-sm);color:#fff;background:' + bg + ';' +
                      'box-shadow:0 4px 12px rgba(0,0,0,.15)';
    t.textContent = msg;
    tc.appendChild(t);
    setTimeout(function(){ t.remove(); }, 3500);
}

// ── Live client-side search ───────────────────────────────────
var searchInput = document.getElementById('live-search');
var searchTimer;
searchInput.addEventListener('input', function() {
    var q = this.value.toLowerCase();
    document.querySelectorAll('#results-tbody .result-row').forEach(function(row) {
        row.style.display = (!q || row.textContent.toLowerCase().includes(q)) ? '' : 'none';
    });
    updateBulkBar();
    clearTimeout(searchTimer);
    if (q.length > 2 || q.length === 0) {
        searchTimer = setTimeout(function() {
            var url = new URL(window.location.href);
            url.searchParams.set('q', searchInput.value);
            url.searchParams.set('page', 1);
            window.location.href = url.toString();
        }, 700);
    }
});

// ── Bulk selection ────────────────────────────────────────────
var checkAll  = document.getElementById('check-all');
var bulkBar   = document.getElementById('bulk-bar');
var bulkCount = document.getElementById('bulk-count');

checkAll.addEventListener('change', function() {
    document.querySelectorAll('.row-check').forEach(function(cb) {
        var row = cb.closest('tr');
        if (row && row.style.display !== 'none') cb.checked = checkAll.checked;
    });
    updateBulkBar();
});

document.addEventListener('change', function(e) {
    if (e.target.classList.contains('row-check')) updateBulkBar();
});

function updateBulkBar() {
    var n = document.querySelectorAll('.row-check:checked').length;
    bulkCount.textContent = n;
    bulkBar.style.display = n > 0 ? 'flex' : 'none';
}

function clearSelection() {
    document.querySelectorAll('.row-check').forEach(function(cb){ cb.checked = false; });
    checkAll.checked = false;
    updateBulkBar();
}

function submitBulk(action) {
    var ids = Array.from(document.querySelectorAll('.row-check:checked')).map(function(cb){ return cb.value; });
    if (!ids.length) return;
    document.getElementById('bulk-action-input').value = action;
    var cont = document.getElementById('bulk-ids-container');
    cont.innerHTML = ids.map(function(id){ return '<input type="hidden" name="ids[]" value="' + id + '">'; }).join('');
    document.getElementById('bulk-form').submit();
}

// ── Per-row inline status select ──────────────────────────────
document.querySelectorAll('.inline-status-select').forEach(function(sel) {
    sel.addEventListener('change', function() {
        var appId     = this.dataset.id;
        var newResult = this.value;
        if (!newResult) return;
        var self = this;
        self.disabled = true;

        var fd = new FormData();
        fd.append('result', newResult);
        var tokenEl = document.querySelector('[name="_token"]');
        if (tokenEl) fd.append('_token', tokenEl.value);

        fetch('<?= url('/staff/results/') ?>' + appId, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: fd
        })
        .then(function(r){ return r.ok ? r.json() : Promise.reject(); })
        .then(function() {
            self.disabled = false;
            var badge = self.closest('tr').querySelector('.badge[class*="badge-accepted"], .badge[class*="badge-waitlisted"], .badge[class*="badge-rejected"], .badge[class*="badge-pending"]');
            var labels = { accepted: 'Accepted', waitlisted: 'Waitlisted', rejected: 'Rejected' };
            if (badge) {
                badge.className = 'badge badge-' + newResult;
                badge.textContent = labels[newResult] || newResult;
            }
            showToast('Result updated to ' + newResult, 'success');
        })
        .catch(function() {
            self.disabled = false;
            var form = document.createElement('form');
            form.method = 'POST';
            form.action = '<?= url('/staff/results/') ?>' + appId;
            form.innerHTML = '<?= csrf_field() ?><input name="result" value="' + newResult + '">';
            document.body.appendChild(form);
            form.submit();
        });
    });
});

// ── Release modal ─────────────────────────────────────────────
function openReleaseModal(appId, name, currentResult, currentRemarks) {
    document.getElementById('release-form').action = '<?= url('/staff/results/') ?>' + appId;
    document.getElementById('release-name').textContent = name;
    document.getElementById('release-result').value = currentResult || '';
    document.getElementById('release-remarks').value = currentRemarks || '';
    document.getElementById('release-modal').style.display = 'flex';
}
document.getElementById('release-modal').addEventListener('click', function(e) {
    if (e.target === this) this.style.display = 'none';
});

// ── Auto-waitlist ─────────────────────────────────────────────
function confirmAutoWaitlist() {
    document.getElementById('auto-waitlist-modal').style.display = 'flex';
}
document.getElementById('auto-waitlist-modal').addEventListener('click', function(e) {
    if (e.target === this) this.style.display = 'none';
});
</script>

<?php
$content   = ob_get_clean();
$pageTitle = 'Admission Results';
$activeNav = 'results';
include VIEWS_PATH . '/layouts/app.php';
