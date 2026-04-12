<?php
// ============================================================
// modules/documents/staff_review.php
// M3 — Staff: list applicants, review their documents
// ============================================================

require_once CORE_PATH . '/bootstrap.php';
Auth::requireRole(ROLE_STAFF, ROLE_ADMIN);

$db = db();

// Per-applicant view?
$applicantId = (int)($_GET['id'] ?? 0);

if ($applicantId) {
    // ---- Single applicant document review ----
    $stmt = $db->prepare(
        'SELECT a.*, u.name AS student_name, u.email
         FROM applicants a JOIN users u ON u.id = a.user_id
         WHERE a.id = ?'
    );
    $stmt->execute([$applicantId]);
    $applicant = $stmt->fetch();
    if (!$applicant) { redirect('/staff/applicants'); }

    $stmt = $db->prepare('SELECT * FROM documents WHERE applicant_id = ?');
    $stmt->execute([$applicantId]);
    $docRows = array_column($stmt->fetchAll(), null, 'doc_type');
    $requiredDocs = docs_for_type($applicant['applicant_type']);

    ob_start();
?>
<div style="margin-bottom:var(--space-6)"><a href="<?= url('/staff/applicants') ?>" class="btn btn-ghost btn-sm">
        <svg width="16" height="16" fill="none" viewBox="0 0 24 24"><path stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M19 12H5m7-7-7 7 7 7"/></svg>
        Back
    </a>
    <span class="badge badge-<?= $applicant['overall_status'] ?>" style="margin-left:auto">
        <?= e(ucfirst(str_replace('_',' ',$applicant['overall_status']))) ?>
    </span>
</div>

<?php if ($msg = Session::getFlash('success')): ?>
    <div class="alert alert-success" style="margin-bottom:var(--space-4)"><?= e($msg) ?></div>
<?php endif; ?>
<?php if ($msg = Session::getFlash('error')): ?>
    <div class="alert alert-error" style="margin-bottom:var(--space-4)"><?= e($msg) ?></div>
<?php endif; ?>

<div style="display:flex;flex-direction:column;gap:var(--space-3)">
<?php foreach ($requiredDocs as $slug => $label):
    $doc    = $docRows[$slug] ?? null;
    $status = $doc['status'] ?? 'pending';
    $statusMap = [
        'pending'      => ['label'=>'Pending',      'class'=>'badge-pending'],
        'uploaded'     => ['label'=>'Uploaded',     'class'=>'badge-info'],
        'under_review' => ['label'=>'Under Review', 'class'=>'badge-warning'],
        'approved'     => ['label'=>'Approved',     'class'=>'badge-success'],
        'rejected'     => ['label'=>'Rejected',     'class'=>'badge-error'],
    ];
    $badge = $statusMap[$status] ?? $statusMap['pending'];
?>
    <div class="card" style="padding:var(--space-4) var(--space-5)">
        <div style="display:flex;align-items:center;gap:var(--space-4);flex-wrap:wrap">
            <div style="flex:1;min-width:0">
                <div style="font-weight:var(--weight-medium)"><?= e($label) ?></div>
                <?php if ($doc && $doc['staff_remarks']): ?>
                    <div style="font-size:var(--text-sm);color:var(--text-tertiary);margin-top:2px">Remark: <?= e($doc['staff_remarks']) ?></div>
                <?php endif; ?>
            </div>
            <span class="badge <?= $badge['class'] ?>"><?= $badge['label'] ?></span>
            <?php if ($doc && $doc['file_path']): ?>
                <a href="<?= url('/' . $doc['file_path']) ?>" target="_blank" class="btn btn-secondary btn-sm">View File</a>
            <?php else: ?>
                <span style="font-size:var(--text-sm);color:var(--text-tertiary)">No file</span>
            <?php endif; ?>
            <?php if ($doc && in_array($status, ['uploaded','under_review'], true)): ?>
                <div style="display:flex;gap:var(--space-2)">
                    <form method="POST" action="<?= url('/staff/documents/' . $doc['id']) ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="approve">
                        <button class="btn btn-success btn-sm">Approve</button>
                    </form>
                    <button class="btn btn-danger btn-sm"
                            onclick="openRejectModal(<?= $doc['id'] ?>)">Reject</button>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php endforeach; ?>
</div>

<!-- Check if all approved → offer to advance status to exam -->
<?php
$allDocs   = array_values($docRows);
$allApproved = count($allDocs) === count($requiredDocs)
    && count(array_filter($allDocs, fn($d) => $d['status'] === 'approved')) === count($requiredDocs);
if ($allApproved && $applicant['overall_status'] === 'documents'):
?>
    <div class="alert alert-success" style="margin-top:var(--space-6);display:flex;align-items:center;gap:var(--space-4)">
        <div style="flex:1"><strong>All documents approved.</strong> Advance applicant to entrance exam stage?</div>
        <form method="POST" action="<?= url('/staff/documents/' . $applicantId) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="advance_to_exam">
            <button class="btn btn-primary btn-sm">Advance to Exam</button>
        </form>
    </div>
<?php endif; ?>

<!-- Reject modal -->
<div id="reject-modal" class="modal-backdrop" style="display:none" aria-hidden="true">
    <div class="modal" style="max-width:400px">
        <div class="modal-header">
            <div class="modal-title">Reject Document</div>
            <button class="btn-icon" onclick="closeRejectModal()">
                <svg width="18" height="18" fill="none" viewBox="0 0 24 24"><path stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>
        <form method="POST" id="reject-form" action="">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="reject">
            <div class="modal-body">
                <label class="form-label">Reason for rejection <span style="color:var(--error)">*</span></label>
                <textarea name="remarks" class="form-control" rows="3"
                          placeholder="e.g. Document is blurry or unreadable" required></textarea>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-ghost" onclick="closeRejectModal()">Cancel</button>
                <button type="submit" class="btn btn-danger">Reject</button>
            </div>
        </form>
    </div>
</div>
<script>
function openRejectModal(docId) {
    document.getElementById('reject-form').action = '<?= url('/staff/documents/') ?>' + docId;
    document.getElementById('reject-modal').style.display = 'flex';
}
function closeRejectModal() {
    document.getElementById('reject-modal').style.display = 'none';
}
document.getElementById('reject-modal').addEventListener('click', function(e){
    if(e.target===this) closeRejectModal();
});
</script>

<?php
    $content   = ob_get_clean();
    $pageTitle = 'Review: ' . $applicant['student_name'];
    $activeNav = 'applicants';
    include VIEWS_PATH . '/layouts/app.php';
    return;
}

// ----------------------------------------------------------------
// List all applicants with filters
// ----------------------------------------------------------------
$statusFilter = $_GET['status'] ?? '';
$search       = trim($_GET['q'] ?? '');
$page         = max(1, (int)($_GET['page'] ?? 1));

$where   = ['1=1'];
$params  = [];
if ($statusFilter) {
    $where[]            = 'a.overall_status = :status';
    $params[':status']  = $statusFilter;
}
if ($search) {
    $where[]            = '(u.name LIKE :q OR u.email LIKE :q OR a.course_applied LIKE :q)';
    $params[':q']       = '%' . $search . '%';
}
$whereStr = implode(' AND ', $where);

$result = paginate(
    $db,
    "SELECT COUNT(*) FROM applicants a JOIN users u ON u.id=a.user_id WHERE $whereStr",
    "SELECT a.*, u.name AS student_name, u.email,
            (SELECT COUNT(*) FROM documents d WHERE d.applicant_id=a.id AND d.status='uploaded') as pending_review
     FROM applicants a JOIN users u ON u.id=a.user_id
     WHERE $whereStr ORDER BY a.created_at DESC",
    $params, $page, 25
);

// Counts for filter tabs
$counts = [];
foreach (['pending','documents','exam','interview','released'] as $s) {
    $c = $db->prepare('SELECT COUNT(*) FROM applicants WHERE overall_status=?');
    $c->execute([$s]);
    $counts[$s] = (int)$c->fetchColumn();
}

ob_start();
?>

<!-- Filter tabs -->
<div style="display:flex;gap:var(--space-1);margin-bottom:var(--space-5);border-bottom:1px solid var(--border);overflow-x:auto">
    <?php
    $tabs = ['' => 'All'] + array_map('ucfirst', array_combine(
        ['pending','documents','exam','interview','released'],
        ['pending','documents','exam','interview','released']
    ));
    foreach ($tabs as $val => $lbl):
        $active = ($statusFilter === $val);
        $cnt    = $val ? ($counts[$val] ?? 0) : array_sum($counts);
    ?>
        <a href="?status=<?= urlencode($val) ?>&q=<?= urlencode($search) ?>"
           style="padding:var(--space-2) var(--space-4);border-bottom:2px solid <?= $active ? 'var(--accent)' : 'transparent' ?>;
                  color:<?= $active ? 'var(--accent)' : 'var(--text-secondary)' ?>;
                  font-size:var(--text-sm);font-weight:<?= $active ? 'var(--weight-semibold)' : 'var(--weight-normal)' ?>;
                  white-space:nowrap;text-decoration:none">
            <?= e(ucfirst(str_replace('_',' ',$lbl))) ?>
            <span style="margin-left:4px;font-size:var(--text-xs);color:var(--text-tertiary)"><?= $cnt ?></span>
        </a>
    <?php endforeach; ?>
</div>

<!-- Search -->
<form method="GET" style="margin-bottom:var(--space-4)">
    <input type="hidden" name="status" value="<?= e($statusFilter) ?>">
    <div style="position:relative;max-width:360px">
        <svg width="16" height="16" fill="none" viewBox="0 0 24 24"
             style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--text-tertiary)">
            <path stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M21 21l-4.35-4.35m0 0A7 7 0 105.65 5.65a7 7 0 0011 11.35z"/>
        </svg>
        <input type="text" name="q" value="<?= e($search) ?>" class="form-control"
               style="padding-left:38px" placeholder="Search name, email, course…">
    </div>
</form>

<!-- Table -->
<div class="card" style="padding:0;overflow:hidden">
    <table class="table">
        <thead>
            <tr>
                <th>Applicant</th>
                <th>Type</th>
                <th>Course</th>
                <th>Status</th>
                <th>Docs Pending</th>
                <th>Applied</th>
                <th style="width:80px"></th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($result['data'])): ?>
            <tr><td colspan="7" style="text-align:center;color:var(--text-tertiary);padding:var(--space-8)">No applicants found.</td></tr>
        <?php else: ?>
            <?php foreach ($result['data'] as $row): ?>
                <tr>
                    <td>
                        <div style="font-weight:var(--weight-medium)"><?= e($row['student_name']) ?></div>
                        <div style="font-size:var(--text-sm);color:var(--text-tertiary)"><?= e($row['email']) ?></div>
                    </td>
                    <td><span class="badge badge-neutral"><?= e(ucfirst($row['applicant_type'])) ?></span></td>
                    <td style="font-size:var(--text-sm)"><?= e($row['course_applied']) ?></td>
                    <td><span class="badge badge-<?= $row['overall_status'] ?>"><?= e(ucfirst(str_replace('_',' ',$row['overall_status']))) ?></span></td>
                    <td>
                        <?php if ($row['pending_review'] > 0): ?>
                            <span style="color:var(--warning);font-weight:var(--weight-semibold);font-size:var(--text-sm)"><?= $row['pending_review'] ?> to review</span>
                        <?php else: ?>
                            <span style="color:var(--text-tertiary);font-size:var(--text-sm)">—</span>
                        <?php endif; ?>
                    </td>
                    <td style="font-size:var(--text-sm);color:var(--text-tertiary)"><?= format_date($row['created_at'], 'M j, Y') ?></td>
                    <td>
                        <a href="<?= url('/staff/applicants/' . $row['id']) ?>" class="btn btn-secondary btn-sm">Review</a>
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
            <a href="?status=<?= urlencode($statusFilter) ?>&q=<?= urlencode($search) ?>&page=<?= $i ?>"
               class="btn <?= $i === $result['current_page'] ? 'btn-primary' : 'btn-ghost' ?> btn-sm"
               style="min-width:36px"><?= $i ?></a>
        <?php endfor; ?>
    </div>
<?php endif; ?>

<?php
$content   = ob_get_clean();
$pageTitle = 'Applicants';
$activeNav = 'applicants';
$pageWide  = true;
include VIEWS_PATH . '/layouts/app.php';
