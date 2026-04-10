<?php
// ============================================================
// modules/results/staff_manage.php
// M6 — Staff: release admission results
// ============================================================

require_once CORE_PATH . '/bootstrap.php';
Auth::requireRole(ROLE_STAFF, ROLE_ADMIN);

$db      = db();
$staffId = Auth::id();

$search  = trim($_GET['q'] ?? '');
$filter  = $_GET['result'] ?? '';
$page    = max(1, (int)($_GET['page'] ?? 1));

$where  = ["a.overall_status IN ('released','exam','interview')"];
$params = [];

if ($search) {
    $where[]       = '(u.name LIKE :q OR u.email LIKE :q OR a.course_applied LIKE :q)';
    $params[':q']  = '%' . $search . '%';
}
if ($filter) {
    $where[]           = 'ar.result = :result';
    $params[':result'] = $filter;
}
$whereStr = implode(' AND ', $where);

$result = paginate(
    $db,
    "SELECT COUNT(*) FROM applicants a
     JOIN users u ON u.id=a.user_id
     LEFT JOIN admission_results ar ON ar.applicant_id=a.id
     WHERE $whereStr",
    "SELECT a.*, u.name AS student_name, u.email,
            ar.result AS admission_result, ar.remarks AS admission_remarks, ar.released_at
     FROM applicants a
     JOIN users u ON u.id=a.user_id
     LEFT JOIN admission_results ar ON ar.applicant_id=a.id
     WHERE $whereStr
     ORDER BY a.updated_at DESC",
    $params, $page, 25
);

ob_start();
?>

<?php if ($msg = Session::getFlash('success')): ?>
    <div class="alert alert-success" style="margin-bottom:var(--space-4)"><?= e($msg) ?></div>
<?php endif; ?>
<?php if ($msg = Session::getFlash('error')): ?>
    <div class="alert alert-error" style="margin-bottom:var(--space-4)"><?= e($msg) ?></div>
<?php endif; ?>

<!-- Filters -->
<div style="display:flex;gap:var(--space-3);margin-bottom:var(--space-5);flex-wrap:wrap">
    <form method="GET" style="flex:1;max-width:360px">
        <input type="hidden" name="result" value="<?= e($filter) ?>">
        <div style="position:relative">
            <svg width="16" height="16" fill="none" viewBox="0 0 24 24"
                 style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--text-tertiary)">
                <path stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M21 21l-4.35-4.35m0 0A7 7 0 105.65 5.65a7 7 0 0011 11.35z"/>
            </svg>
            <input type="text" name="q" value="<?= e($search) ?>" class="form-control"
                   style="padding-left:38px" placeholder="Search…">
        </div>
    </form>
    <div style="display:flex;gap:var(--space-2)">
        <?php foreach (['' => 'All', 'accepted' => 'Accepted', 'waitlisted' => 'Waitlisted', 'rejected' => 'Rejected'] as $val => $lbl): ?>
            <a href="?result=<?= urlencode($val) ?>&q=<?= urlencode($search) ?>"
               class="btn <?= $filter===$val ? 'btn-primary' : 'btn-ghost' ?> btn-sm"><?= $lbl ?></a>
        <?php endforeach; ?>
    </div>
</div>

<div class="card" style="padding:0;overflow:hidden">
    <table class="table">
        <thead>
            <tr>
                <th>Applicant</th>
                <th>Course</th>
                <th>Stage</th>
                <th>Result</th>
                <th>Released</th>
                <th style="width:100px"></th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($result['data'])): ?>
            <tr><td colspan="6" style="text-align:center;color:var(--text-tertiary);padding:var(--space-8)">No applicants found.</td></tr>
        <?php else: ?>
            <?php foreach ($result['data'] as $row): ?>
                <tr>
                    <td>
                        <div style="font-weight:var(--weight-medium)"><?= e($row['student_name']) ?></div>
                        <div style="font-size:var(--text-sm);color:var(--text-tertiary)"><?= e($row['email']) ?></div>
                    </td>
                    <td style="font-size:var(--text-sm)"><?= e($row['course_applied']) ?></td>
                    <td><span class="badge badge-<?= $row['overall_status'] ?>"><?= e(ucfirst(str_replace('_',' ',$row['overall_status']))) ?></span></td>
                    <td>
                        <?php if ($row['admission_result']): ?>
                            <span class="badge badge-<?= $row['admission_result'] ?>"><?= e(RESULT_LABELS[$row['admission_result']]) ?></span>
                        <?php else: ?>
                            <span style="color:var(--text-tertiary);font-size:var(--text-sm)">Pending</span>
                        <?php endif; ?>
                    </td>
                    <td style="font-size:var(--text-sm);color:var(--text-tertiary)">
                        <?= $row['released_at'] ? format_date($row['released_at'], 'M j, Y') : '—' ?>
                    </td>
                    <td>
                        <button class="btn btn-secondary btn-sm"
                                onclick="openReleaseModal(<?= $row['id'] ?>, <?= htmlspecialchars(json_encode($row['student_name']), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode($row['admission_result']), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode($row['admission_remarks'] ?? ''), ENT_QUOTES) ?>)">
                            <?= $row['admission_result'] ? 'Edit' : 'Release' ?>
                        </button>
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
            <a href="?result=<?= urlencode($filter) ?>&q=<?= urlencode($search) ?>&page=<?= $i ?>"
               class="btn <?= $i === $result['current_page'] ? 'btn-primary' : 'btn-ghost' ?> btn-sm" style="min-width:36px"><?= $i ?></a>
        <?php endfor; ?>
    </div>
<?php endif; ?>

<!-- Release modal -->
<div id="release-modal" class="modal-backdrop" style="display:none">
    <div class="modal" style="max-width:420px">
        <div class="modal-header">
            <div class="modal-title">Release Result</div>
            <button class="btn-icon" onclick="document.getElementById('release-modal').style.display='none'">
                <svg width="18" height="18" fill="none" viewBox="0 0 24 24"><path stroke="currentColor" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>
        <form method="POST" id="release-form" action="">
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
                <button type="button" class="btn btn-ghost" onclick="document.getElementById('release-modal').style.display='none'">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Result</button>
            </div>
        </form>
    </div>
</div>
<script>
function openReleaseModal(appId, name, currentResult, currentRemarks) {
    document.getElementById('release-form').action = '<?= url('/staff/results/') ?>' + appId;
    document.getElementById('release-name').textContent = name;
    document.getElementById('release-result').value = currentResult || '';
    document.getElementById('release-remarks').value = currentRemarks || '';
    document.getElementById('release-modal').style.display = 'flex';
}
document.getElementById('release-modal').addEventListener('click', function(e){
    if(e.target===this) this.style.display='none';
});
</script>

<?php
$content   = ob_get_clean();
$pageTitle = 'Admission Results';
$activeNav = 'results';
include VIEWS_PATH . '/layouts/app.php';