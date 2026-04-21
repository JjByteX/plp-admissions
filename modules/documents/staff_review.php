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

    // Build ordered list of viewable files for modal navigation
    $viewableFiles = [];
    foreach ($requiredDocs as $slug => $label) {
        $doc = $docRows[$slug] ?? null;
        if ($doc && $doc['file_path']) {
            $viewableFiles[] = [
                'label'     => $label,
                'file_path' => $doc['file_path'],
                'url'       => str_starts_with($doc['file_path'], 'http') ? $doc['file_path'] : url('/' . $doc['file_path']),
            ];
        }
    }

    ob_start();
?>
<div style="margin-bottom:var(--space-6)"><a href="<?= url('/staff/applicants') ?>" class="btn btn-ghost btn-sm">
        <svg width="16" height="16" fill="none" viewBox="0 0 24 24"><path stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M19 12H5m7-7-7 7 7 7"/></svg>
        Back
    </a>
</div>

<?php if ($msg = Session::getFlash('success')): ?>
    <div class="alert alerht-success" style="margin-bottom:var(--space-4)"><?= e($msg) ?></div>
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

    // Find file index in viewable list for opening modal at correct position
    $fileIndex = -1;
    if ($doc && $doc['file_path']) {
        foreach ($viewableFiles as $fi => $vf) {
            if ($vf['file_path'] === $doc['file_path']) { $fileIndex = $fi; break; }
        }
    }
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
                <button
                    class="btn btn-secondary btn-sm"
                    onclick="openFileViewer(<?= $fileIndex ?>, <?= htmlspecialchars(json_encode($viewableFiles), ENT_QUOTES) ?>)"
                    type="button"
                >
                    <svg width="14" height="14" fill="none" viewBox="0 0 24 24" style="margin-right:4px"><path stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="2"/></svg>
                    View File
                </button>
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

<!-- Advance to exam -->
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

<!-- ============================================================
     FILE VIEWER MODAL
============================================================ -->
<div id="file-viewer-modal" style="
    display:none;
    position:fixed;inset:0;z-index:9999;
    background:rgba(0,0,0,0.82);
    backdrop-filter:blur(4px);
    align-items:center;justify-content:center;
" aria-modal="true" role="dialog" aria-label="Document Viewer">

    <div style="
        position:relative;
        width:min(94vw,1100px);
        height:min(90vh,860px);
        background:var(--bg-elevated);
        border-radius:var(--radius-lg);
        box-shadow:var(--shadow-lg);
        display:flex;flex-direction:column;
        overflow:hidden;
    ">
        <!-- Header -->
        <div style="
            display:flex;align-items:center;gap:var(--space-3);
            padding:var(--space-3) var(--space-5);
            border-bottom:1px solid var(--border);
            flex-shrink:0;
        ">
            <!-- Prev -->
            <button id="fv-prev" onclick="fvNavigate(-1)" type="button" style="
                display:flex;align-items:center;justify-content:center;
                width:32px;height:32px;border-radius:var(--radius-sm);
                border:1px solid var(--border);background:var(--bg);
                color:var(--text-secondary);cursor:pointer;flex-shrink:0;
                transition:background var(--transition-fast),color var(--transition-fast);
            " title="Previous (←)">
                <svg width="15" height="15" fill="none" viewBox="0 0 24 24"><path stroke="currentColor" stroke-width="2.2" stroke-linecap="round" d="M15 18l-6-6 6-6"/></svg>
            </button>

            <!-- Label + counter -->
            <div style="flex:1;min-width:0">
                <div id="fv-label" style="font-weight:var(--weight-semibold);font-size:var(--text-sm);white-space:nowrap;overflow:hidden;text-overflow:ellipsis"></div>
                <div id="fv-counter" style="font-size:var(--text-xs);color:var(--text-tertiary);margin-top:1px"></div>
            </div>

            <!-- Next -->
            <button id="fv-next" onclick="fvNavigate(1)" type="button" style="
                display:flex;align-items:center;justify-content:center;
                width:32px;height:32px;border-radius:var(--radius-sm);
                border:1px solid var(--border);background:var(--bg);
                color:var(--text-secondary);cursor:pointer;flex-shrink:0;
                transition:background var(--transition-fast),color var(--transition-fast);
            " title="Next (→)">
                <svg width="15" height="15" fill="none" viewBox="0 0 24 24"><path stroke="currentColor" stroke-width="2.2" stroke-linecap="round" d="M9 18l6-6-6-6"/></svg>
            </button>

            <div style="width:1px;height:24px;background:var(--border);flex-shrink:0"></div>

            <!-- Zoom out -->
            <button onclick="fvZoom(-0.25)" type="button" class="fv-ctrl-btn" title="Zoom out (−)">
                <svg width="14" height="14" fill="none" viewBox="0 0 24 24"><path stroke="currentColor" stroke-width="2.2" stroke-linecap="round" d="M5 12h14"/></svg>
            </button>
            <span id="fv-zoom-label" style="font-size:var(--text-xs);color:var(--text-secondary);min-width:38px;text-align:center;font-variant-numeric:tabular-nums">100%</span>
            <!-- Zoom in -->
            <button onclick="fvZoom(0.25)" type="button" class="fv-ctrl-btn" title="Zoom in (+)">
                <svg width="14" height="14" fill="none" viewBox="0 0 24 24"><path stroke="currentColor" stroke-width="2.2" stroke-linecap="round" d="M12 5v14M5 12h14"/></svg>
            </button>
            <!-- Reset zoom -->
            <button onclick="fvResetZoom()" type="button" class="fv-ctrl-btn" title="Reset zoom (0)">
                <svg width="14" height="14" fill="none" viewBox="0 0 24 24"><path stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M21 21l-4.35-4.35m0 0A7 7 0 105.65 5.65a7 7 0 0011 11.35z"/></svg>
            </button>

            <div style="width:1px;height:24px;background:var(--border);flex-shrink:0"></div>

            <!-- Close -->
            <button onclick="closeFileViewer()" type="button" class="fv-ctrl-btn" title="Close (Esc)" aria-label="Close">
                <svg width="15" height="15" fill="none" viewBox="0 0 24 24"><path stroke="currentColor" stroke-width="2.2" stroke-linecap="round" d="M18 6L6 18M6 6l12 12"/></svg>
            </button>
        </div>

        <!-- Viewport -->
        <div id="fv-viewport" style="
            flex:1;overflow:hidden;position:relative;
            background:var(--bg-subtle);min-height:300px;
            cursor:default;user-select:none;
        ">
            <div id="fv-transform-wrap" style="
                position:absolute;top:0;left:0;
                width:100%;height:100%;
                display:flex;align-items:center;justify-content:center;
                will-change:transform;
                transform-origin:center center;
            ">
                <img id="fv-img" src="" alt="Document preview" style="
                    max-width:100%;max-height:78vh;
                    border-radius:var(--radius-sm);
                    box-shadow:var(--shadow-md);
                    display:block;pointer-events:none;
                    user-select:none;-webkit-user-drag:none;
                ">
            </div>
            <!-- Hint -->
            <div id="fv-hint" style="
                position:absolute;bottom:12px;left:50%;transform:translateX(-50%);
                background:rgba(0,0,0,0.55);color:#fff;
                font-size:var(--text-xs);padding:5px 14px;border-radius:var(--radius-full);
                pointer-events:none;opacity:0;transition:opacity .4s ease;white-space:nowrap;
            ">Scroll to zoom · Drag to pan when zoomed in</div>
        </div>

        <!-- Nav dots -->
        <div id="fv-dots" style="
            display:flex;align-items:center;justify-content:center;gap:6px;
            padding:var(--space-3);border-top:1px solid var(--border);flex-shrink:0;flex-wrap:wrap;
        "></div>
    </div>
</div>

<style>
.fv-ctrl-btn {
    display:flex;align-items:center;justify-content:center;
    width:30px;height:30px;border-radius:var(--radius-sm);
    border:1px solid var(--border);background:var(--bg);
    color:var(--text-secondary);cursor:pointer;
    transition:background var(--transition-fast),color var(--transition-fast);
}
.fv-ctrl-btn:hover { background:var(--bg-overlay); color:var(--text-primary); }
#fv-prev:hover, #fv-next:hover { background:var(--bg-overlay); color:var(--text-primary); }
#fv-viewport[data-zoomed="true"]   { cursor:grab; }
#fv-viewport[data-dragging="true"] { cursor:grabbing !important; }
</style>

<script>
(function(){
    var _files=[], _idx=0, _scale=1, _tx=0, _ty=0;
    var _drag=false, _ds={x:0,y:0}, _touch=null;

    window.openFileViewer = function(idx, files) {
        _files=files; _idx=idx; _scale=1; _tx=0; _ty=0;
        _render();
        document.getElementById('file-viewer-modal').style.display='flex';
        document.body.style.overflow='hidden';
        var h=document.getElementById('fv-hint');
        if(h){ h.style.opacity='1'; setTimeout(function(){ h.style.opacity='0'; },2800); }
    };

    window.closeFileViewer = function() {
        document.getElementById('file-viewer-modal').style.display='none';
        document.body.style.overflow='';
        document.getElementById('fv-img').src='';
    };

    window.fvNavigate = function(d) {
        var n=_idx+d;
        if(n<0||n>=_files.length) return;
        _idx=n; _scale=1; _tx=0; _ty=0; _render();
    };

    window.fvZoom = function(delta) {
        _scale=Math.min(5,Math.max(0.5,_scale+delta));
        if(_scale<=1){ _tx=0; _ty=0; }
        _apply();
    };

    window.fvResetZoom = function() { _scale=1; _tx=0; _ty=0; _apply(); };

    function _render() {
        var f=_files[_idx];
        if(!f) return;
        document.getElementById('fv-img').src=f.url;
        document.getElementById('fv-label').textContent=f.label;
        document.getElementById('fv-counter').textContent=(_idx+1)+' of '+_files.length;
        var p=document.getElementById('fv-prev');
        var n=document.getElementById('fv-next');
        p.disabled=(_idx===0); p.style.opacity=(_idx===0)?'0.35':'1';
        n.disabled=(_idx===_files.length-1); n.style.opacity=(_idx===_files.length-1)?'0.35':'1';
        _apply();
        _dots();
        var h=document.getElementById('fv-hint');
        if(h){ h.style.opacity='1'; setTimeout(function(){ h.style.opacity='0'; },2800); }
    }

    function _apply() {
        var w=document.getElementById('fv-transform-wrap');
        w.style.transform='translate('+_tx+'px,'+_ty+'px) scale('+_scale+')';
        document.getElementById('fv-zoom-label').textContent=Math.round(_scale*100)+'%';
        var vp=document.getElementById('fv-viewport');
        vp.dataset.zoomed=(_scale>1)?'true':'false';
    }

    function _dots() {
        var c=document.getElementById('fv-dots');
        c.innerHTML='';
        _files.forEach(function(f,i){
            var b=document.createElement('button');
            b.type='button';
            b.style.cssText='width:8px;height:8px;border-radius:50%;border:none;padding:0;cursor:pointer;flex-shrink:0;transition:background .15s,transform .15s;';
            b.style.background=(i===_idx)?'var(--accent)':'var(--border-strong)';
            b.style.transform=(i===_idx)?'scale(1.35)':'scale(1)';
            b.title=f.label;
            b.onclick=function(){ _idx=i; _scale=1; _tx=0; _ty=0; _render(); };
            c.appendChild(b);
        });
    }

    // Pan
    document.addEventListener('DOMContentLoaded',function(){
        var vp=document.getElementById('fv-viewport');
        vp.addEventListener('mousedown',function(e){
            if(_scale<=1) return;
            _drag=true; _ds={x:e.clientX-_tx,y:e.clientY-_ty};
            vp.dataset.dragging='true'; e.preventDefault();
        });
        window.addEventListener('mousemove',function(e){
            if(!_drag) return;
            _tx=e.clientX-_ds.x; _ty=e.clientY-_ds.y; _apply();
        });
        window.addEventListener('mouseup',function(){
            if(_drag){ _drag=false; document.getElementById('fv-viewport').dataset.dragging='false'; }
        });
        vp.addEventListener('touchstart',function(e){
            if(_scale<=1) return;
            var t=e.touches[0]; _touch={x:t.clientX-_tx,y:t.clientY-_ty}; e.preventDefault();
        },{passive:false});
        vp.addEventListener('touchmove',function(e){
            if(!_touch) return;
            var t=e.touches[0]; _tx=t.clientX-_touch.x; _ty=t.clientY-_touch.y; _apply(); e.preventDefault();
        },{passive:false});
        vp.addEventListener('touchend',function(){ _touch=null; });
        vp.addEventListener('wheel',function(e){
            e.preventDefault();
            var d=e.deltaY>0?-0.15:0.15;
            _scale=Math.min(5,Math.max(0.5,_scale+d));
            if(_scale<=1){ _tx=0; _ty=0; }
            _apply();
        },{passive:false});
        // Click backdrop to close
        document.getElementById('file-viewer-modal').addEventListener('click',function(e){
            if(e.target===this) closeFileViewer();
        });
    });

    // Keyboard
    document.addEventListener('keydown',function(e){
        if(document.getElementById('file-viewer-modal').style.display!=='flex') return;
        if(e.key==='Escape') closeFileViewer();
        if(e.key==='ArrowLeft') fvNavigate(-1);
        if(e.key==='ArrowRight') fvNavigate(1);
        if(e.key==='+'||e.key==='=') fvZoom(0.25);
        if(e.key==='-') fvZoom(-0.25);
        if(e.key==='0') fvResetZoom();
    });
})();
</script>

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
$sortCol      = $_GET['sort_col'] ?? 'applied';
$sortDir      = strtolower($_GET['sort_dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
$page         = max(1, (int)($_GET['page'] ?? 1));

$where   = ['1=1'];
$params  = [];
if ($statusFilter) {
    $where[]           = 'a.overall_status = :status';
    $params[':status'] = $statusFilter;
}
if ($search) {
    $where[]       = '(u.name LIKE :q OR u.email LIKE :q OR a.course_applied LIKE :q)';
    $params[':q']  = '%' . $search . '%';
}
$whereStr = implode(' AND ', $where);

$colMap = [
    'applicant'    => 'u.name',
    'type'         => 'a.applicant_type',
    'course'       => 'a.course_applied',
    'status'       => 'a.overall_status',
    'docs_pending' => 'pending_review',
    'applied'      => 'a.created_at',
];
$orderCol = $colMap[$sortCol] ?? 'a.created_at';
$orderDir = strtoupper($sortDir);
$orderBy  = "$orderCol $orderDir";

$result = paginate(
    $db,
    "SELECT COUNT(*) FROM applicants a JOIN users u ON u.id=a.user_id WHERE $whereStr",
    "SELECT a.*, u.name AS student_name, u.email,
            (SELECT COUNT(*) FROM documents d WHERE d.applicant_id=a.id AND d.status='uploaded') as pending_review
     FROM applicants a JOIN users u ON u.id=a.user_id
     WHERE $whereStr ORDER BY $orderBy",
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

<!-- ============================================================
     TOP BAR: Tabs (left) + Search & Filter (right)
============================================================ -->
<div style="
    display:flex;
    align-items:flex-end;
    justify-content:space-between;
    gap:var(--space-4);
    margin-bottom:var(--space-5);
    border-bottom:1px solid var(--border);
    flex-wrap:wrap;
">
    <!-- Filter tabs -->
    <div style="display:flex;gap:var(--space-1);flex-wrap:wrap">
        <?php
        $tabs = ['' => 'All'] + array_map('ucfirst', array_combine(
            ['pending','documents','exam','interview','released'],
            ['pending','documents','exam','interview','released']
        ));
        foreach ($tabs as $val => $lbl):
            $active = ($statusFilter === $val);
            $cnt    = $val ? ($counts[$val] ?? 0) : array_sum($counts);
        ?>
            <a href="?status=<?= urlencode($val) ?>&q=<?= urlencode($search) ?>&sort_col=<?= urlencode($sortCol) ?>&sort_dir=<?= urlencode($sortDir) ?>"
               style="
                   padding:var(--space-2) var(--space-4);
                   border-bottom:2px solid <?= $active ? 'var(--accent)' : 'transparent' ?>;
                   color:<?= $active ? 'var(--accent)' : 'var(--text-secondary)' ?>;
                   font-size:var(--text-sm);
                   font-weight:<?= $active ? 'var(--weight-semibold)' : 'var(--weight-normal)' ?>;
                   white-space:nowrap;text-decoration:none;margin-bottom:-1px;
                   transition:color var(--transition-fast);
               ">
                <?= e(ucfirst(str_replace('_',' ',$lbl))) ?>
                <span style="margin-left:4px;font-size:var(--text-xs);color:var(--text-tertiary)"><?= $cnt ?></span>
            </a>
        <?php endforeach; ?>
    </div>

    <!-- Search + Filter (right, vertically aligned with tabs) -->
    <form method="GET" style="display:flex;align-items:center;gap:var(--space-2);padding-bottom:var(--space-1);flex-shrink:0">
        <input type="hidden" name="status" value="<?= e($statusFilter) ?>">
        <input type="hidden" name="sort_col" value="<?= e($sortCol) ?>">
        <input type="hidden" name="sort_dir" value="<?= e($sortDir) ?>">

        <!-- Search -->
        <div style="position:relative">
            <svg width="14" height="14" fill="none" viewBox="0 0 24 24"
                 style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:var(--text-tertiary);pointer-events:none">
                <path stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M21 21l-4.35-4.35m0 0A7 7 0 105.65 5.65a7 7 0 0011 11.35z"/>
            </svg>
            <input type="text" name="q" value="<?= e($search) ?>" class="form-control"
                   style="padding-left:32px;height:34px;font-size:var(--text-sm);width:220px;border-radius:var(--radius-sm)"
                   placeholder="Search name, email, course…">
        </div>

        <!-- Filter dropdown (status only) -->
        <div style="position:relative" id="filter-dropdown-wrapper">
            <button type="button" id="filter-toggle-btn" onclick="toggleFilterDropdown()" style="
                display:flex;align-items:center;gap:var(--space-2);
                height:34px;padding:0 var(--space-3);
                border:1px solid var(--border);border-radius:var(--radius-sm);
                background:var(--bg-elevated);color:var(--text-secondary);
                font-size:var(--text-sm);cursor:pointer;white-space:nowrap;
                transition:border-color var(--transition-fast),color var(--transition-fast);
            " aria-haspopup="true" aria-expanded="false">
                <svg width="14" height="14" fill="none" viewBox="0 0 24 24">
                    <path stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M3 6h18M7 12h10M11 18h2"/>
                </svg>
                Filter
                <?php if ($search): ?>
                    <span style="
                        display:inline-flex;align-items:center;justify-content:center;
                        width:16px;height:16px;border-radius:50%;
                        background:var(--accent);color:var(--accent-text);
                        font-size:10px;font-weight:var(--weight-semibold);
                    ">1</span>
                <?php endif; ?>
                <svg width="12" height="12" fill="none" viewBox="0 0 24 24" style="transition:transform .15s" id="filter-chevron">
                    <path stroke="currentColor" stroke-width="2.2" stroke-linecap="round" d="M6 9l6 6 6-6"/>
                </svg>
            </button>

            <div id="filter-dropdown" style="
                display:none;position:absolute;right:0;top:calc(100% + 6px);z-index:200;
                background:var(--bg-elevated);border:1px solid var(--border);
                border-radius:var(--radius-md);box-shadow:var(--shadow-md);
                min-width:220px;padding:var(--space-3);
            ">
                <div style="font-size:var(--text-xs);font-weight:var(--weight-semibold);color:var(--text-tertiary);text-transform:uppercase;letter-spacing:.06em;margin-bottom:var(--space-2);padding:0 var(--space-1)">Filter by Status</div>
                <?php
                $filterOptions = [
                    ''           => 'All',
                    'pending'    => 'Pending',
                    'documents'  => 'Documents',
                    'exam'       => 'Exam',
                    'interview'  => 'Interview',
                    'released'   => 'Released',
                ];
                foreach ($filterOptions as $val => $label):
                    $isActive = ($statusFilter === $val);
                ?>
                <a href="?status=<?= urlencode($val) ?>&q=<?= urlencode($search) ?>&sort_col=<?= urlencode($sortCol) ?>&sort_dir=<?= urlencode($sortDir) ?>" style="
                    display:flex;align-items:center;justify-content:space-between;
                    width:100%;padding:var(--space-2) var(--space-3);
                    border-radius:var(--radius-sm);
                    background:<?= $isActive ? 'var(--accent-muted)' : 'transparent' ?>;
                    color:<?= $isActive ? 'var(--accent)' : 'var(--text-secondary)' ?>;
                    font-size:var(--text-sm);
                    font-weight:<?= $isActive ? 'var(--weight-semibold)' : 'var(--weight-regular)' ?>;
                    text-decoration:none;
                    transition:background var(--transition-fast);
                " onmouseover="if(!this.classList.contains('active')) this.style.background='var(--bg-overlay)'"
                   onmouseout="this.style.background='<?= $isActive ? 'var(--accent-muted)' : 'transparent' ?>'">
                    <?= e($label) ?>
                    <?php if ($isActive): ?>
                        <svg width="13" height="13" fill="none" viewBox="0 0 24 24"><path stroke="currentColor" stroke-width="2.2" stroke-linecap="round" d="M5 13l4 4L19 7"/></svg>
                    <?php endif; ?>
                </a>
                <?php endforeach; ?>

                <?php if ($search): ?>
                    <div style="border-top:1px solid var(--border);margin-top:var(--space-2);padding-top:var(--space-2)">
                        <a href="?status=<?= urlencode($statusFilter) ?>&sort_col=<?= urlencode($sortCol) ?>&sort_dir=<?= urlencode($sortDir) ?>" style="
                            display:flex;align-items:center;gap:var(--space-2);
                            padding:var(--space-2) var(--space-3);font-size:var(--text-sm);
                            color:var(--text-tertiary);border-radius:var(--radius-sm);text-decoration:none;
                            transition:background var(--transition-fast);
                        " onmouseover="this.style.background='var(--bg-overlay)'" onmouseout="this.style.background='transparent'">
                            <svg width="13" height="13" fill="none" viewBox="0 0 24 24"><path stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M6 18L18 6M6 6l12 12"/></svg>
                            Clear search
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <button type="submit" style="display:none" aria-hidden="true"></button>
    </form>
</div>

<?php
// Helper: render a sortable column header
function sortable_th(string $col, string $label, string $currentCol, string $currentDir, string $statusFilter, string $search): string {
    $isActive  = ($currentCol === $col);
    $nextDir   = ($isActive && $currentDir === 'asc') ? 'desc' : 'asc';
    $url = '?status=' . urlencode($statusFilter)
         . '&q='        . urlencode($search)
         . '&sort_col=' . urlencode($col)
         . '&sort_dir=' . urlencode($isActive ? $nextDir : 'asc');

    $arrowUp = '<svg width="10" height="10" viewBox="0 0 24 24" fill="none" style="display:block"><path stroke="currentColor" stroke-width="2.5" stroke-linecap="round" d="M12 19V5M5 12l7-7 7 7"/></svg>';
    $arrowDn = '<svg width="10" height="10" viewBox="0 0 24 24" fill="none" style="display:block"><path stroke="currentColor" stroke-width="2.5" stroke-linecap="round" d="M12 5v14M5 12l7 7 7-7"/></svg>';

    if ($isActive) {
        $upColor = $currentDir === 'asc'  ? 'var(--accent)' : 'var(--text-tertiary)';
        $dnColor = $currentDir === 'desc' ? 'var(--accent)' : 'var(--text-tertiary)';
    } else {
        $upColor = $dnColor = 'var(--text-tertiary)';
    }

    return '<th><a href="' . $url . '" style="display:inline-flex;align-items:center;gap:4px;text-decoration:none;color:inherit;white-space:nowrap;">'
         . htmlspecialchars($label)
         . '<span style="display:flex;flex-direction:column;gap:1px;margin-left:2px;">'
         . '<span style="color:' . $upColor . '">' . $arrowUp . '</span>'
         . '<span style="color:' . $dnColor . '">' . $arrowDn . '</span>'
         . '</span></a></th>';
}
?>

<!-- Table -->
<div class="card" style="padding:0;overflow:hidden">
    <table class="table">
        <thead>
            <tr>
                <?= sortable_th('applicant',    'Applicant',    $sortCol, $sortDir, $statusFilter, $search) ?>
                <?= sortable_th('type',         'Type',         $sortCol, $sortDir, $statusFilter, $search) ?>
                <?= sortable_th('course',       'Course',       $sortCol, $sortDir, $statusFilter, $search) ?>
                <?= sortable_th('status',       'Status',       $sortCol, $sortDir, $statusFilter, $search) ?>
                <?= sortable_th('docs_pending', 'Docs Pending', $sortCol, $sortDir, $statusFilter, $search) ?>
                <?= sortable_th('applied',      'Applied',      $sortCol, $sortDir, $statusFilter, $search) ?>
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
            <a href="?status=<?= urlencode($statusFilter) ?>&q=<?= urlencode($search) ?>&sort_col=<?= urlencode($sortCol) ?>&sort_dir=<?= urlencode($sortDir) ?>&page=<?= $i ?>"
               class="btn <?= $i === $result['current_page'] ? 'btn-primary' : 'btn-ghost' ?> btn-sm"
               style="min-width:36px"><?= $i ?></a>
        <?php endfor; ?>
    </div>
<?php endif; ?>

<script>
function toggleFilterDropdown() {
    var dd      = document.getElementById('filter-dropdown');
    var btn     = document.getElementById('filter-toggle-btn');
    var chevron = document.getElementById('filter-chevron');
    var isOpen  = dd.style.display === 'block';
    dd.style.display  = isOpen ? 'none' : 'block';
    btn.setAttribute('aria-expanded', String(!isOpen));
    chevron.style.transform = isOpen ? '' : 'rotate(180deg)';
}
document.addEventListener('click', function(e) {
    var wrapper = document.getElementById('filter-dropdown-wrapper');
    if (wrapper && !wrapper.contains(e.target)) {
        var dd = document.getElementById('filter-dropdown');
        var btn = document.getElementById('filter-toggle-btn');
        var chevron = document.getElementById('filter-chevron');
        if (dd) dd.style.display = 'none';
        if (btn) btn.setAttribute('aria-expanded','false');
        if (chevron) chevron.style.transform = '';
    }
});
</script>

<?php
$content   = ob_get_clean();
$pageTitle = 'Applicants';
$activeNav = 'applicants';
$pageWide  = true;
include VIEWS_PATH . '/layouts/app.php';
