<?php
// ============================================================
// modules/documents/staff_review.php
// M3 — Staff: list applicants, review their documents
// ============================================================

require_once CORE_PATH . '/bootstrap.php';
Auth::requireRole(ROLE_SSO, ROLE_ADMIN);

$db = db();

// ----------------------------------------------------------------
// Bulk approve SELECTED applicants' documents
// ----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'bulk_approve_selected') {
    csrf_check();
    $staffId = Auth::id();
    $ids = array_values(array_unique(array_filter(array_map('intval', $_POST['applicant_ids'] ?? []))));

    if (empty($ids)) {
        Session::flash('error', 'No applicants selected.');
        redirect('/staff/applicants');
    }

    $approved = 0;
    $advanced = 0;
    foreach ($ids as $aid) {
        $db->prepare(
            'UPDATE documents SET status="approved", staff_remarks=NULL, reviewed_by=?
              WHERE applicant_id=? AND status IN ("uploaded","under_review")'
        )->execute([$staffId, $aid]);
        $cnt = (int)($db->query('SELECT ROW_COUNT()')->fetchColumn() ?: 0);
        if ($cnt > 0) $approved++;

        $rem = $db->prepare('SELECT COUNT(*) FROM documents WHERE applicant_id=? AND status != "approved"');
        $rem->execute([$aid]);
        if ((int)$rem->fetchColumn() === 0) {
            $db->prepare(
                'UPDATE applicants SET overall_status="exam", documents_approved_at=COALESCE(documents_approved_at,NOW())
                  WHERE id=? AND overall_status NOT IN ("exam","interview","result")'
            )->execute([$aid]);
            $advanced++;
            notify_stage_transition($aid, 'exam');
            auto_assign_exam_slot($aid);
        }
    }
    audit_log('bulk_approve_selected', "Bulk-approved docs for {$approved} applicant(s), {$advanced} advanced to exam");
    Session::flash('success', "Approved documents for {$approved} applicant(s). {$advanced} advanced to exam.");
    redirect('/staff/applicants');
}

// ----------------------------------------------------------------
// (Bulk reject handler removed — staff use "Request Resubmission"
// per-document instead, which lets the student re-upload AND notifies
// them. A blanket "reject" was redundant since it had the same end
// state as resubmission but skipped the notification.)
// ----------------------------------------------------------------

// ----------------------------------------------------------------
// Bulk approve all applicants with pending documents
// ----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'approve_all_in_review') {
    csrf_check();
    $staffId = Auth::id();

    // Find all applicants with documents pending review
    $pending = $db->query(
        'SELECT DISTINCT d.applicant_id
           FROM documents d
           JOIN applicants a ON a.id = d.applicant_id
          WHERE d.status IN ("uploaded","under_review")
            AND a.overall_status IN ("submitted","documents")'
    )->fetchAll(PDO::FETCH_COLUMN);

    $approved = 0;
    $advanced = 0;
    foreach ($pending as $aid) {
        $db->prepare(
            'UPDATE documents SET status="approved", staff_remarks=NULL, reviewed_by=?
              WHERE applicant_id=? AND status IN ("uploaded","under_review")'
        )->execute([$staffId, $aid]);
        $approved += $db->prepare('SELECT ROW_COUNT()')->fetchColumn() ?: 0;

        // Check if all docs are now approved — advance to exam
        $rem = $db->prepare('SELECT COUNT(*) FROM documents WHERE applicant_id=? AND status != "approved"');
        $rem->execute([$aid]);
        if ((int)$rem->fetchColumn() === 0) {
            $db->prepare(
                'UPDATE applicants SET overall_status="exam", documents_approved_at=COALESCE(documents_approved_at,NOW())
                  WHERE id=? AND overall_status NOT IN ("exam","interview","result")'
            )->execute([$aid]);
            $advanced++;

            // Automation: notify & auto-assign exam slot for each advanced applicant
            notify_stage_transition($aid, 'exam');
            auto_assign_exam_slot($aid);
        }
    }
    audit_log('bulk_approve_all_in_review', "Bulk-approved docs for " . count($pending) . " applicant(s), {$advanced} advanced to exam");
    Session::flash('success', "Approved documents for " . count($pending) . " applicant(s). {$advanced} advanced to exam.");
    redirect('/staff/applicants');
}

// Per-applicant view?
$applicantId = (int)($_GET['id'] ?? 0);

if ($applicantId) {
    // ---- Single applicant document review ----
    $stmt = $db->prepare(
        'SELECT a.*, u.name AS student_name, u.email,
                u.first_name, u.middle_name, u.last_name, u.suffix
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

    // Staff can only review docs after applicant has submitted
    $isSubmitted = in_array($applicant['overall_status'], ['submitted','exam','interview','released'], true);

    // Undo is allowed if applicant hasn't actually started the exam yet
    $stmt = $db->prepare('SELECT COUNT(*) FROM exam_results WHERE applicant_id = ?');
    $stmt->execute([$applicantId]);
    $examTaken = (int)$stmt->fetchColumn() > 0;
    $canUndo = $isSubmitted && !$examTaken && in_array($applicant['overall_status'], ['submitted','documents','exam'], true);

    // Build ordered list of viewable files for modal navigation
    $viewableFiles = [];
    foreach ($requiredDocs as $slug => $label) {
        $doc = $docRows[$slug] ?? null;
        if ($doc && $doc['file_path']) {
            $viewableFiles[] = [
                'label'     => $label,
                'file_path' => $doc['file_path'],
                'url'       => file_url($doc['file_path']),
            ];
        }
    }

    // Count docs that can still be approved
    $approvableCount = count(array_filter($docRows, fn($d) => in_array($d['status'], ['uploaded','under_review'], true)));

    // Build list of pending docs with image URLs for AI validation collage
    $pendingDocsForAi = [];
    foreach ($requiredDocs as $slug => $label) {
        $d = $docRows[$slug] ?? null;
        if ($d && in_array($d['status'], ['uploaded', 'under_review'], true) && $d['file_path']) {
            $pendingDocsForAi[] = [
                'id'    => (int)$d['id'],
                'label' => $label,
                'url'   => file_url($d['file_path']),
            ];
        }
    }

    ob_start();
?>
<div style="display:flex;align-items:center;gap:var(--space-3);margin-bottom:var(--space-6)">
    <a href="<?= url('/staff/applicants') ?>" class="btn btn-ghost btn-sm">
        <?= icon('ic_fluent_arrow_left_24_regular', 16) ?>
        Back
    </a>
    <span class="badge badge-<?= $applicant['overall_status'] ?>" style="margin-left:auto">
        <?= e(ucfirst(str_replace('_',' ',$applicant['overall_status']))) ?>
    </span>
    <?php if ($approvableCount > 0): ?>
        <button type="button" class="btn btn-sm" onclick="openAiValidateAllModal()" id="ai-validate-all-btn">
            <?= icon('ic_fluent_sparkle_24_regular', 14, 'margin-right:3px') ?>
            AI Validate All
        </button>
        <form method="POST" action="<?= url('/staff/documents/' . $applicantId) ?>"
              onsubmit="return confirm('Approve all <?= $approvableCount ?> document(s) at once?')">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="approve_all">
            <button type="submit" class="btn btn-success btn-sm">
                <?= icon('ic_fluent_checkmark_circle_24_regular', 15) ?>
                Approve All (<?= $approvableCount ?>)
            </button>
        </form>
    <?php endif; ?>
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
        'uploaded'     => ['label'=>'Uploaded',     'class'=>'badge-uploaded'],
        'under_review' => ['label'=>'Under Review', 'class'=>'badge-review'],
        'approved'     => ['label'=>'Approved',     'class'=>'badge-approved'],
        'rejected'     => ['label'=>'Rejected',     'class'=>'badge-rejected'],
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
            <?php
            // Show auto-validation badge if document was validated
            if ($doc) {
                try {
                    ensure_document_validations_table();
                    $valStmt = db()->prepare('SELECT status, confidence FROM document_validations WHERE document_id = ? ORDER BY validated_at DESC LIMIT 1');
                    $valStmt->execute([$doc['id']]);
                    $valRow = $valStmt->fetch();
                    if ($valRow): ?>
                        <span class="auto-badge auto-badge-<?= e($valRow['status']) ?>">
                            <?= $valRow['status'] === 'passed' ? 'Auto-validated' : ($valRow['status'] === 'failed' ? 'Validation failed' : 'Needs review') ?>
                            <?php if ($valRow['confidence']): ?>(<?= round($valRow['confidence']) ?>%)<?php endif; ?>
                        </span>
                    <?php endif;
                } catch (\Throwable) {}
            }
            ?>
            <?php if ($doc && $doc['file_path']): ?>
                <button
                    class="btn btn-secondary btn-sm"
                    onclick="openFileViewer(<?= $fileIndex ?>, <?= htmlspecialchars(json_encode($viewableFiles), ENT_QUOTES) ?>)"
                    type="button"
                >
                    <?= icon('ic_fluent_eye_show_24_regular', 14) ?>
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
                    <button class="btn btn-warning btn-sm"
                            onclick="openResubmitModal(<?= $doc['id'] ?>)">Resubmit</button>
                </div>
            <?php endif; ?>
            <?php if ($canUndo && $doc && $status === 'approved'): ?>
                <form method="POST" action="<?= url('/staff/documents/' . $doc['id']) ?>"
                      onsubmit="return confirm('Undo approval for this document?')">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="unapprove">
                    <button class="btn btn-ghost btn-sm" style="color:var(--text-tertiary);font-size:var(--text-xs)">Undo</button>
                </form>
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

<!-- A7: Request Resubmission modal -->
<div id="resubmit-modal" class="modal-backdrop" style="display:none" aria-hidden="true">
    <div class="modal" style="max-width:400px">
        <div class="modal-header">
            <div class="modal-title">Request Resubmission</div>
            <button class="btn-icon" onclick="document.getElementById('resubmit-modal').style.display='none'">
                <?= icon('ic_fluent_dismiss_24_regular', 18) ?>
            </button>
        </div>
        <form method="POST" id="resubmit-form" action="">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="request_resubmission">
            <div class="modal-body">
                <p style="font-size:var(--text-sm);color:var(--text-secondary);margin-bottom:var(--space-3)">
                    The student will be notified and asked to upload a corrected version.
                </p>
                <label class="form-label">What needs to be corrected? <span style="color:var(--error)">*</span></label>
                <textarea name="remarks" class="form-control" rows="3"
                          placeholder="e.g. Document is blurry, wrong page uploaded, expired ID" required></textarea>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-ghost" onclick="document.getElementById('resubmit-modal').style.display='none'">Cancel</button>
                <button type="submit" class="btn btn-warning">Request Resubmission</button>
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
        width:min(92vw,1000px);
        max-height:92vh;
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
                <?= icon('ic_fluent_chevron_left_24_regular', 15) ?>
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
                <?= icon('ic_fluent_chevron_right_24_regular', 15) ?>
            </button>

            <div style="width:1px;height:24px;background:var(--border);flex-shrink:0"></div>

            <!-- Zoom out -->
            <button onclick="fvZoom(-0.25)" type="button" class="fv-ctrl-btn" title="Zoom out (−)">
                <?= icon('ic_fluent_subtract_24_regular', 14) ?>
            </button>
            <span id="fv-zoom-label" style="font-size:var(--text-xs);color:var(--text-secondary);min-width:38px;text-align:center;font-variant-numeric:tabular-nums">100%</span>
            <!-- Zoom in -->
            <button onclick="fvZoom(0.25)" type="button" class="fv-ctrl-btn" title="Zoom in (+)">
                <?= icon('ic_fluent_add_24_regular', 14) ?>
            </button>
            <!-- Reset zoom -->
            <button onclick="fvResetZoom()" type="button" class="fv-ctrl-btn" title="Reset zoom (0)">
                <?= icon('ic_fluent_arrow_sync_24_regular', 14) ?>
            </button>

            <div style="width:1px;height:24px;background:var(--border);flex-shrink:0"></div>

            <!-- Close -->
            <button onclick="closeFileViewer()" type="button" class="fv-ctrl-btn" title="Close (Esc)" aria-label="Close">
                <?= icon('ic_fluent_dismiss_24_regular', 15) ?>
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
                <!-- content injected by _render() -->
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
        document.getElementById('fv-transform-wrap').innerHTML='';
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
        var wrap=document.getElementById('fv-transform-wrap');
        var isPdf=f.url.toLowerCase().split('?')[0].endsWith('.pdf');
        if(isPdf){
            wrap.innerHTML='<iframe src="'+f.url+'" style="width:100%;height:72vh;border:none;border-radius:var(--radius-sm);background:#fff;"></iframe>';
        } else {
            wrap.innerHTML='<img src="'+f.url+'" alt="Document preview" style="max-width:100%;max-height:72vh;border-radius:var(--radius-sm);box-shadow:var(--shadow-md);display:block;pointer-events:none;user-select:none;-webkit-user-drag:none;">';
        }
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
function openResubmitModal(docId) {
    document.getElementById('resubmit-form').action = '<?= url('/staff/documents/') ?>' + docId;
    document.getElementById('resubmit-modal').style.display = 'flex';
}
</script>

<!-- ════════════════════════════════════════════════════════════
     AI VALIDATE ALL — collages all docs into one image, sends to Puter AI
════════════════════════════════════════════════════════════ -->
<script>var _aiPendingDocs = <?= json_encode($pendingDocsForAi, JSON_HEX_TAG) ?>;</script>

<div id="ai-validate-modal" class="modal-backdrop" style="display:none">
    <div class="modal" style="max-width:520px;display:flex;flex-direction:column">
        <div class="modal-header" style="padding:var(--space-4) var(--space-5);border-bottom:1px solid var(--border)">
            <div style="display:flex;align-items:center;gap:var(--space-3)">
                <div style="width:34px;height:34px;border-radius:var(--radius-md);background:var(--accent);display:flex;align-items:center;justify-content:center;flex-shrink:0">
                    <?= icon('ic_fluent_sparkle_24_regular', 17, 'color:#fff') ?>
                </div>
                <div>
                    <div style="font-weight:var(--weight-semibold);font-size:var(--text-base)">AI Document Validation</div>
                    <div style="font-size:var(--text-xs);color:var(--text-tertiary);margin-top:1px">Powered by Puter AI — collaged for efficiency</div>
                </div>
            </div>
            <button class="btn-icon" onclick="closeAiValidateModal()">
                <?= icon('ic_fluent_dismiss_24_regular', 18) ?>
            </button>
        </div>
        <div class="modal-body" style="overflow-y:auto;display:flex;flex-direction:column;gap:var(--space-4)">
            <!-- Puter connection status -->
            <div id="ai-doc-puter-status" class="ai-status-bar disconnected" style="display:flex;align-items:center;gap:10px;padding:10px 14px;border-radius:var(--radius-md);font-size:var(--text-sm);border:1px solid var(--border)">
                <div class="ai-status-dot" style="width:8px;height:8px;border-radius:50%;flex-shrink:0;background:var(--neutral-400)"></div>
                <div id="ai-doc-status-text" style="flex:1">Checking Puter connection...</div>
                <button id="ai-doc-signin-btn" onclick="docPuterSignIn()" style="display:none;font-size:var(--text-xs);font-weight:var(--weight-medium);color:var(--accent);background:none;border:none;cursor:pointer;padding:0">Sign in</button>
                <button id="ai-doc-signout-btn" onclick="docPuterSignOut()" style="display:none;font-size:var(--text-xs);color:var(--text-tertiary);background:none;border:none;cursor:pointer;padding:0">Sign out</button>
            </div>

            <!-- Processing step -->
            <div id="ai-doc-step-processing" style="display:none;flex-direction:column;gap:var(--space-3);padding:var(--space-4) 0;text-align:center">
                <div style="font-weight:var(--weight-medium);font-size:var(--text-sm)" id="ai-doc-processing-label">Analyzing documents...</div>
                <div style="height:5px;background:var(--border);border-radius:99px;overflow:hidden">
                    <div id="ai-doc-progress-fill" style="height:100%;background:var(--accent);border-radius:99px;width:0%;transition:width .4s cubic-bezier(.4,0,.2,1)"></div>
                </div>
                <div id="ai-doc-progress-step" style="font-size:var(--text-xs);color:var(--text-tertiary)">Preparing...</div>
                <div style="font-size:var(--text-xs);color:var(--text-tertiary);opacity:.7">A Puter sign-in popup may appear</div>
            </div>

            <!-- Result step -->
            <div id="ai-doc-step-result" style="display:none;flex-direction:column;gap:var(--space-3)">
                <div id="ai-doc-results-list"></div>
            </div>

            <!-- Error step -->
            <div id="ai-doc-step-error" style="display:none;flex-direction:column;gap:var(--space-3)">
                <div style="display:flex;align-items:flex-start;gap:var(--space-2);background:var(--error-bg);border:1px solid var(--error);border-radius:var(--radius-md);padding:var(--space-3) var(--space-4);font-size:var(--text-sm);color:var(--error)">
                    <?= icon('ic_fluent_info_24_regular', 16, 'flex-shrink:0;margin-top:1px') ?>
                    <span id="ai-doc-error-msg"></span>
                </div>
                <button class="btn btn-ghost btn-sm" style="align-self:flex-start" onclick="resetAiDocModal()">Try again</button>
            </div>

            <!-- Info -->
            <div id="ai-doc-info" style="font-size:var(--text-xs);color:var(--text-tertiary);line-height:1.5">
                <strong id="ai-doc-count-label"></strong><br>
                Documents will be collaged into a single image and sent to AI in one request to save tokens.
                OCR checks run first on upload — AI is the fallback for uncertain results.
            </div>
        </div>
        <div class="modal-footer" style="border-top:1px solid var(--border)">
            <button type="button" class="btn btn-ghost" onclick="closeAiValidateModal()">Close</button>
            <button type="button" class="btn btn-primary" id="ai-doc-validate-btn" onclick="startAiValidateAll()">
                <?= icon('ic_fluent_sparkle_24_regular', 14, 'margin-right:5px') ?>
                Validate All
            </button>
        </div>
    </div>
</div>

<style>
.ai-status-bar.connected { background:#f0faf4;border-color:#a7d9b8;color:#1a5c32; }
.ai-status-bar.disconnected { background:#fafafa;border-color:var(--border);color:var(--text-secondary); }
.ai-status-bar.connected .ai-status-dot { background:#22c55e; }
.ai-result-card { border-radius:var(--radius-md);padding:var(--space-3);border:1px solid var(--border);margin-bottom:var(--space-2);display:flex;align-items:center;gap:var(--space-3) }
.ai-result-card .ai-result-label { font-weight:var(--weight-medium);font-size:var(--text-sm);flex:1 }
.ai-result-card .ai-result-reason { font-size:var(--text-xs);color:var(--text-tertiary) }
</style>

<script>
// ── Puter SDK loader (same as exam builder) ─────────────────
let _docPuterLoaded = false;
function loadDocPuter() {
    return new Promise(res => {
        if (_docPuterLoaded || window.puter) { _docPuterLoaded = true; res(); return; }
        const s = document.createElement('script');
        s.src = 'https://js.puter.com/v2/';
        s.onload = () => { _docPuterLoaded = true; res(); };
        document.head.appendChild(s);
    });
}

function openAiValidateAllModal() {
    if (!_aiPendingDocs || _aiPendingDocs.length === 0) { alert('No pending documents to validate.'); return; }
    resetAiDocModal();
    document.getElementById('ai-doc-count-label').textContent = _aiPendingDocs.length + ' document(s) pending: ' + _aiPendingDocs.map(d => d.label).join(', ');
    document.getElementById('ai-validate-modal').style.display = 'flex';
    refreshDocPuterStatus();
}
function closeAiValidateModal() {
    document.getElementById('ai-validate-modal').style.display = 'none';
}
document.getElementById('ai-validate-modal').addEventListener('click', function(e) {
    if (e.target === this) closeAiValidateModal();
});

function resetAiDocModal() {
    ['processing', 'result', 'error'].forEach(s => {
        var el = document.getElementById('ai-doc-step-' + s);
        if (el) el.style.display = 'none';
    });
    document.getElementById('ai-doc-info').style.display = '';
    document.getElementById('ai-doc-validate-btn').disabled = false;
    document.getElementById('ai-doc-validate-btn').style.display = '';
    document.getElementById('ai-doc-progress-fill').style.width = '0%';
    document.getElementById('ai-doc-results-list').innerHTML = '';
}

async function refreshDocPuterStatus() {
    const bar = document.getElementById('ai-doc-puter-status');
    const label = document.getElementById('ai-doc-status-text');
    const signinBtn = document.getElementById('ai-doc-signin-btn');
    const signoutBtn = document.getElementById('ai-doc-signout-btn');
    label.textContent = 'Checking Puter connection...';
    [signinBtn, signoutBtn].forEach(el => el.style.display = 'none');
    try {
        await loadDocPuter();
        const ok = await puter.auth.isSignedIn();
        if (ok) {
            let name = ''; try { const u = await puter.auth.getUser(); name = u?.username ? ' as @' + u.username : ''; } catch(_){}
            bar.className = 'ai-status-bar connected';
            label.textContent = 'Connected to Puter' + name;
            signoutBtn.style.display = '';
        } else {
            bar.className = 'ai-status-bar disconnected';
            label.textContent = 'Not signed in to Puter';
            signinBtn.style.display = '';
        }
    } catch(_) {
        bar.className = 'ai-status-bar disconnected';
        label.textContent = 'Could not reach Puter';
        signinBtn.style.display = '';
    }
}
async function docPuterSignIn() { await loadDocPuter(); try { await puter.auth.signIn(); refreshDocPuterStatus(); } catch(_){} }
async function docPuterSignOut() { await loadDocPuter(); try { await puter.auth.signOut(); refreshDocPuterStatus(); } catch(_){} }

// ── Load image as HTMLImageElement ───────────────────────────
function loadImage(url) {
    return new Promise((resolve, reject) => {
        const img = new Image();
        img.crossOrigin = 'anonymous';
        img.onload = () => resolve(img);
        img.onerror = () => reject(new Error('Failed to load: ' + url));
        img.src = url;
    });
}

// ── Create a collage of all document images ─────────────────
async function createCollage(docs) {
    const images = [];
    for (const doc of docs) {
        try { images.push({ img: await loadImage(doc.url), label: doc.label }); }
        catch(_) { images.push({ img: null, label: doc.label }); }
    }

    const validImages = images.filter(i => i.img);
    if (validImages.length === 0) throw new Error('Could not load any document images.');

    // Calculate collage layout: grid arrangement
    const cols = Math.min(validImages.length, 3);
    const rows = Math.ceil(validImages.length / cols);

    // Normalize each image to a cell size (max 800px wide per cell)
    const cellW = 800, labelH = 30, padding = 10;
    let cellH = 600;

    // Calculate max cell height from aspect ratios
    for (const item of validImages) {
        const scale = cellW / item.img.naturalWidth;
        const h = Math.min(item.img.naturalHeight * scale, 1000);
        if (h > cellH) cellH = Math.round(h);
    }

    const canvasW = cols * cellW + (cols + 1) * padding;
    const canvasH = rows * (cellH + labelH) + (rows + 1) * padding;

    const canvas = document.createElement('canvas');
    canvas.width = canvasW;
    canvas.height = canvasH;
    const ctx = canvas.getContext('2d');
    ctx.fillStyle = '#ffffff';
    ctx.fillRect(0, 0, canvasW, canvasH);

    let idx = 0;
    for (let r = 0; r < rows; r++) {
        for (let c = 0; c < cols; c++) {
            if (idx >= validImages.length) break;
            const item = validImages[idx];
            const x = padding + c * (cellW + padding);
            const y = padding + r * (cellH + labelH + padding);

            // Draw label
            ctx.fillStyle = '#374151';
            ctx.font = 'bold 18px sans-serif';
            ctx.fillText((idx + 1) + '. ' + item.label, x + 4, y + 20);

            // Draw image scaled to fit cell
            const scale = Math.min(cellW / item.img.naturalWidth, cellH / item.img.naturalHeight);
            const drawW = item.img.naturalWidth * scale;
            const drawH = item.img.naturalHeight * scale;
            ctx.drawImage(item.img, x + (cellW - drawW) / 2, y + labelH, drawW, drawH);

            // Border around cell
            ctx.strokeStyle = '#d1d5db';
            ctx.lineWidth = 1;
            ctx.strokeRect(x, y, cellW, cellH + labelH);

            idx++;
        }
    }

    return new Promise(resolve => canvas.toBlob(resolve, 'image/jpeg', 0.85));
}

// ── Main: validate all docs via collage ─────────────────────
async function startAiValidateAll() {
    if (!_aiPendingDocs || _aiPendingDocs.length === 0) return;

    document.getElementById('ai-doc-info').style.display = 'none';
    document.getElementById('ai-doc-step-processing').style.display = 'flex';
    document.getElementById('ai-doc-validate-btn').disabled = true;

    const fill = document.getElementById('ai-doc-progress-fill');
    const stepLabel = document.getElementById('ai-doc-progress-step');
    const procLabel = document.getElementById('ai-doc-processing-label');

    try {
        await loadDocPuter();
        const signedIn = await puter.auth.isSignedIn();
        if (!signedIn) await puter.auth.signIn();

        // Step 1: Create collage
        fill.style.width = '15%';
        procLabel.textContent = 'Loading document images...';
        stepLabel.textContent = 'Fetching ' + _aiPendingDocs.length + ' document(s)';

        fill.style.width = '30%';
        procLabel.textContent = 'Creating collage...';
        stepLabel.textContent = 'Combining documents into single image';

        const collageBlob = await createCollage(_aiPendingDocs);

        // Step 2: Upload collage to Puter
        fill.style.width = '45%';
        procLabel.textContent = 'Uploading collage to AI...';
        stepLabel.textContent = 'Preparing for analysis';

        const tmpName = 'doc_collage_' + Date.now() + '.jpg';
        let puterFile;
        try { puterFile = await puter.fs.write(tmpName, collageBlob); }
        catch(e) { throw new Error('Could not upload collage to Puter: ' + e.message); }

        // Step 3: Send to AI — one call for all documents
        fill.style.width = '60%';
        procLabel.textContent = 'AI is analyzing all documents...';
        stepLabel.textContent = 'Verifying ' + _aiPendingDocs.length + ' document(s)';

        const docList = _aiPendingDocs.map((d, i) => (i+1) + '. "' + d.label + '"').join('\n');
        const prompt = 'You are a document validation assistant for a university admissions system.\n\n' +
            'This image is a collage of ' + _aiPendingDocs.length + ' document(s) submitted by an applicant. ' +
            'Each document is labeled with a number and its expected type.\n\n' +
            'Documents in this collage:\n' + docList + '\n\n' +
            'For EACH document, determine:\n' +
            '- Is it a real document (not a random photo or blank page)?\n' +
            '- Does it appear to match the expected document type?\n' +
            '- Is the image clear and readable?\n\n' +
            'Respond with ONLY a JSON array (one object per document, in order):\n' +
            '[{"doc_number": 1, "valid": true/false, "confidence": 0-100, "reason": "brief explanation"}, ...]';

        let aiResponse;
        try {
            aiResponse = await puter.ai.chat(
                [{role: 'user', content: [{type: 'file', puter_path: puterFile.path}, {type: 'text', text: prompt}]}],
                {model: 'claude-sonnet-4-6'}
            );
        } finally {
            try { await puter.fs.delete(puterFile.path); } catch(_){}
        }

        fill.style.width = '85%';
        procLabel.textContent = 'Processing results...';
        stepLabel.textContent = 'Parsing AI response';

        // Parse AI response
        const text = aiResponse?.message?.content?.[0]?.text || aiResponse?.message?.content || '';
        if (!text) throw new Error('AI returned an empty response.');

        let results;
        try {
            let clean = text.trim().replace(/^```(?:json)?\s*/i, '').replace(/\s*```\s*$/i, '').trim();
            const start = clean.indexOf('['), end = clean.lastIndexOf(']');
            if (start !== -1 && end !== -1) clean = clean.slice(start, end + 1);
            results = JSON.parse(clean);
        } catch(e) {
            throw new Error('Could not parse AI response.');
        }

        if (!Array.isArray(results)) throw new Error('AI did not return a list of results.');

        // Step 4: Save each result to server
        fill.style.width = '90%';
        procLabel.textContent = 'Saving results...';
        stepLabel.textContent = 'Updating database';

        const csrf = document.querySelector('input[name="_csrf"]')?.value || '';
        const resultCards = document.getElementById('ai-doc-results-list');
        resultCards.innerHTML = '';
        let anyApproved = false;

        for (let i = 0; i < _aiPendingDocs.length; i++) {
            const doc = _aiPendingDocs[i];
            const r = results[i] || {};
            const isValid = !!r.valid;
            const confidence = Math.max(0, Math.min(100, r.confidence || 50));
            const reason = r.reason || 'No details';
            let status = 'uncertain';
            if (isValid && confidence >= 70) status = 'passed';
            else if (!isValid && confidence >= 80) status = 'failed';

            // Save to server
            await fetch(window.__baseUrl + '/api/auto-validate', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest'},
                body: 'action=ai_result&document_id=' + doc.id + '&status=' + status + '&confidence=' + confidence + '&reason=' + encodeURIComponent(reason) + '&_csrf=' + encodeURIComponent(csrf)
            });

            if (status === 'passed') anyApproved = true;

            // Render result card
            const badgeClass = status === 'passed' ? 'auto-badge-passed' : (status === 'failed' ? 'auto-badge-failed' : 'auto-badge-uncertain');
            const badgeText = status === 'passed' ? 'Valid' : (status === 'failed' ? 'Invalid' : 'Uncertain');
            resultCards.innerHTML += '<div class="ai-result-card">' +
                '<div class="ai-result-label">' + doc.label + '</div>' +
                '<span class="auto-badge ' + badgeClass + '">' + badgeText + '</span>' +
                '<span style="font-size:var(--text-xs);color:var(--text-tertiary);min-width:40px;text-align:right">' + confidence + '%</span>' +
                '</div>' +
                '<div style="font-size:var(--text-xs);color:var(--text-tertiary);margin:-6px 0 8px var(--space-3)">' + reason + '</div>';
        }

        fill.style.width = '100%';

        // Show results
        document.getElementById('ai-doc-step-processing').style.display = 'none';
        document.getElementById('ai-doc-step-result').style.display = 'flex';
        document.getElementById('ai-doc-validate-btn').style.display = 'none';

        // Reload after delay if any were approved
        if (anyApproved) {
            setTimeout(() => location.reload(), 2000);
        }

    } catch(err) {
        document.getElementById('ai-doc-step-processing').style.display = 'none';
        document.getElementById('ai-doc-step-error').style.display = 'flex';
        document.getElementById('ai-doc-error-msg').textContent = typeof err === 'string' ? err : (err?.message || 'Unknown error');
        document.getElementById('ai-doc-validate-btn').disabled = false;
    }
}
</script>

<?php
    $content   = ob_get_clean();
    $pageTitle = 'Review: ' . format_full_name($applicant);
    $activeNav = 'documents';
    include VIEWS_PATH . '/layouts/app.php';
    return;
}

// ----------------------------------------------------------------
// List all applicants with filters
// ----------------------------------------------------------------
// Page is dedicated to document review — show every applicant whose docs
// are reviewable. That includes both `overall_status = 'submitted'`
// (just submitted, docs not yet adjudicated) and `'documents'` (already
// in the doc-review stage). The Approve-All count uses the same set, so
// the two stay consistent.
$statusFilter = 'documents';                 // kept for filter-link URL backward-compat
$reviewableStatuses = ['submitted', 'documents'];
$typeFilter   = $_GET['type']   ?? '';
$courseFilter = $_GET['course'] ?? '';
$search       = trim($_GET['q'] ?? '');
$sortCol      = $_GET['sort_col'] ?? 'applied';
$sortDir      = strtolower($_GET['sort_dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
$page         = max(1, (int)($_GET['page'] ?? 1));

$where   = ['1=1'];
$params  = [];
$where[]            = 'a.overall_status IN (:status_submitted, :status_documents)';
$params[':status_submitted'] = 'submitted';
$params[':status_documents'] = 'documents';
if ($typeFilter) {
    $where[]         = 'a.applicant_type = :atype';
    $params[':atype'] = $typeFilter;
}
if ($courseFilter) {
    $where[]           = 'a.course_applied = :course';
    $params[':course'] = $courseFilter;
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
            u.first_name, u.middle_name, u.last_name, u.suffix,
            (SELECT COUNT(*) FROM documents d WHERE d.applicant_id=a.id AND d.status='uploaded') as pending_review
     FROM applicants a JOIN users u ON u.id=a.user_id
     WHERE $whereStr ORDER BY $orderBy",
    $params, $page, 25
);

// Count applicants with docs pending review (for Approve All button)
$pendingReviewCount = (int)$db->query(
    'SELECT COUNT(DISTINCT d.applicant_id)
       FROM documents d
       JOIN applicants a ON a.id = d.applicant_id
      WHERE d.status IN ("uploaded","under_review")
        AND a.overall_status IN ("submitted","documents")'
)->fetchColumn();

// Course list for the Course filter — merged PLP + admin custom courses.
$courseList = get_all_courses();
sort($courseList, SORT_NATURAL | SORT_FLAG_CASE);

ob_start();
?>

<?php if ($msg = Session::getFlash('success')): ?>
    <div class="alert alert-success" style="margin-bottom:var(--space-4)"><?= e($msg) ?></div>
<?php endif; ?>
<?php if ($msg = Session::getFlash('error')): ?>
    <div class="alert alert-error" style="margin-bottom:var(--space-4)"><?= e($msg) ?></div>
<?php endif; ?>

<!-- ============================================================
     TOP BAR: Search + Filter (left)  ·  Approve All (right)
============================================================ -->
<?php
$docFilterUrl = function (array $merge = []) use ($statusFilter, $typeFilter, $courseFilter, $search, $sortCol, $sortDir): string {
    $base = [
        'status'   => $statusFilter,
        'type'     => $typeFilter,
        'course'   => $courseFilter,
        'q'        => $search,
        'sort_col' => $sortCol,
        'sort_dir' => $sortDir,
    ];
    return '?' . http_build_query(array_merge($base, $merge));
};
?>
<div style="
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:var(--space-4);
    margin-bottom:var(--space-5);
    flex-wrap:wrap;
">
    <!-- Search + Filter (LEFT) -->
    <form method="GET" style="display:flex;align-items:center;gap:var(--space-2);flex-shrink:0">
        <input type="hidden" name="status"   value="<?= e($statusFilter) ?>">
        <input type="hidden" name="type"     value="<?= e($typeFilter) ?>">
        <input type="hidden" name="course"   value="<?= e($courseFilter) ?>">
        <input type="hidden" name="sort_col" value="<?= e($sortCol) ?>">
        <input type="hidden" name="sort_dir" value="<?= e($sortDir) ?>">

        <!-- Search -->
        <div style="position:relative">
            <?= icon('ic_fluent_search_24_filled', 14, 'position:absolute;left:10px;top:50%;transform:translateY(-50%);color:var(--text-tertiary);pointer-events:none') ?>
            <input type="text" name="q" value="<?= e($search) ?>" class="form-control"
                   style="padding:0 var(--space-3) 0 32px;height:32px;min-height:32px;font-size:var(--text-sm);width:220px;border-radius:var(--radius-sm)"
                   placeholder="Search name, email, course…">
        </div>

        <!-- Filter dropdown -->
        <?php $activeFilterCount = ($typeFilter ? 1 : 0) + ($courseFilter ? 1 : 0) + ($search ? 1 : 0); ?>
        <div style="position:relative" id="filter-dropdown-wrapper">
            <button type="button" id="filter-toggle-btn" onclick="toggleFilterDropdown()" style="
                display:flex;align-items:center;gap:var(--space-2);
                height:32px;padding:0 var(--space-3);
                border:1px solid var(--border);border-radius:var(--radius-sm);
                background:var(--bg-elevated);color:var(--text-secondary);
                font-size:var(--text-sm);cursor:pointer;white-space:nowrap;
                transition:border-color var(--transition-fast),color var(--transition-fast);
            " aria-haspopup="true" aria-expanded="false">
                <?= icon('ic_fluent_filter_24_filled', 14) ?>
                Filter
                <?php if ($activeFilterCount > 0): ?>
                    <span style="
                        display:inline-flex;align-items:center;justify-content:center;
                        width:16px;height:16px;border-radius:50%;
                        background:var(--accent);color:var(--accent-text);
                        font-size:var(--text-xs);font-weight:var(--weight-semibold);
                    "><?= $activeFilterCount ?></span>
                <?php endif; ?>
            </button>

            <div id="filter-dropdown" style="
                display:none;position:absolute;left:0;top:calc(100% + 6px);z-index:200;
                background:var(--bg-elevated);border:1px solid var(--border);
                border-radius:var(--radius-md);box-shadow:var(--shadow-md);
                min-width:260px;max-height:480px;overflow-y:auto;padding:var(--space-3);
            ">
                <!-- Applicant Type -->
                <div style="font-size:var(--text-xs);font-weight:var(--weight-semibold);color:var(--text-tertiary);text-transform:uppercase;letter-spacing:.06em;margin-bottom:var(--space-2);padding:0 var(--space-1)">Applicant Type</div>
                <?php
                $typeOptions = [
                    ''           => 'All Types',
                    'freshman'   => 'Freshman',
                    'transferee' => 'Transferee',
                    'foreign'    => 'Foreign Student',
                ];
                foreach ($typeOptions as $val => $label):
                    $isActive = ($typeFilter === $val);
                ?>
                <a href="<?= e($docFilterUrl(['type' => $val, 'page' => 1])) ?>" style="
                    display:flex;align-items:center;justify-content:space-between;
                    width:100%;padding:var(--space-2) var(--space-3);
                    border-radius:var(--radius-sm);
                    background:<?= $isActive ? 'var(--accent-muted)' : 'transparent' ?>;
                    color:<?= $isActive ? 'var(--accent)' : 'var(--text-secondary)' ?>;
                    font-size:var(--text-sm);
                    font-weight:<?= $isActive ? 'var(--weight-semibold)' : 'var(--weight-regular)' ?>;
                    text-decoration:none;
                    transition:background var(--transition-fast);
                " onmouseover="this.style.background='var(--bg-overlay)'"
                   onmouseout="this.style.background='<?= $isActive ? 'var(--accent-muted)' : 'transparent' ?>'">
                    <?= e($label) ?>
                    <?php if ($isActive): ?>
                        <?= icon('ic_fluent_checkmark_24_regular', 13) ?>
                    <?php endif; ?>
                </a>
                <?php endforeach; ?>

                <!-- Course -->
                <div style="font-size:var(--text-xs);font-weight:var(--weight-semibold);color:var(--text-tertiary);text-transform:uppercase;letter-spacing:.06em;margin:var(--space-3) 0 var(--space-2);padding:0 var(--space-1)">Course</div>
                <?php
                $courseOpts = array_merge([''], $courseList);
                foreach ($courseOpts as $val):
                    $isActive = ($courseFilter === $val);
                    $label    = $val === '' ? 'All Courses' : $val;
                ?>
                <a href="<?= e($docFilterUrl(['course' => $val, 'page' => 1])) ?>" style="
                    display:flex;align-items:center;justify-content:space-between;
                    width:100%;padding:var(--space-2) var(--space-3);
                    border-radius:var(--radius-sm);
                    background:<?= $isActive ? 'var(--accent-muted)' : 'transparent' ?>;
                    color:<?= $isActive ? 'var(--accent)' : 'var(--text-secondary)' ?>;
                    font-size:var(--text-sm);
                    font-weight:<?= $isActive ? 'var(--weight-semibold)' : 'var(--weight-regular)' ?>;
                    text-decoration:none;
                    transition:background var(--transition-fast);
                " onmouseover="this.style.background='var(--bg-overlay)'"
                   onmouseout="this.style.background='<?= $isActive ? 'var(--accent-muted)' : 'transparent' ?>'">
                    <?= e($label) ?>
                    <?php if ($isActive): ?>
                        <?= icon('ic_fluent_checkmark_24_regular', 13) ?>
                    <?php endif; ?>
                </a>
                <?php endforeach; ?>

                <?php if ($typeFilter || $courseFilter || $search): ?>
                    <div style="border-top:1px solid var(--border);margin-top:var(--space-2);padding-top:var(--space-2)">
                        <a href="<?= e($docFilterUrl(['type' => '', 'course' => '', 'q' => '', 'page' => 1])) ?>" style="
                            display:flex;align-items:center;gap:var(--space-2);
                            padding:var(--space-2) var(--space-3);font-size:var(--text-sm);
                            color:var(--text-tertiary);border-radius:var(--radius-sm);text-decoration:none;
                            transition:background var(--transition-fast);
                        " onmouseover="this.style.background='var(--bg-overlay)'" onmouseout="this.style.background='transparent'">
                            <?= icon('ic_fluent_dismiss_24_regular', 13) ?>
                            Clear filters
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <button type="submit" style="display:none" aria-hidden="true"></button>
    </form>

    <!-- Approve All (RIGHT) -->
    <div style="display:flex;align-items:center;gap:var(--space-2);flex-shrink:0">
    <?php if ($pendingReviewCount > 0): ?>
        <form method="POST" style="margin:0"
              onsubmit="return confirm('Approve all documents for <?= $pendingReviewCount ?> applicant(s) in review? This will advance them to the exam stage.')">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="approve_all_in_review">
            <button type="submit" class="btn btn-success btn-sm" style="display:flex;align-items:center;gap:5px;white-space:nowrap">
                <?= icon('ic_fluent_checkmark_circle_24_regular', 14) ?>
                Approve All in Review (<?= $pendingReviewCount ?>)
            </button>
        </form>
    <?php endif; ?>
    </div>
</div>

<?php
// Helper: render a sortable column header
function sortable_th(string $col, string $label, string $currentCol, string $currentDir, string $statusFilter, string $search, string $typeFilter = ''): string {
    $isActive  = ($currentCol === $col);
    $nextDir   = ($isActive && $currentDir === 'asc') ? 'desc' : 'asc';
    $url = '?status=' . urlencode($statusFilter)
         . '&type='     . urlencode($typeFilter)
         . '&q='        . urlencode($search)
         . '&sort_col=' . urlencode($col)
         . '&sort_dir=' . urlencode($isActive ? $nextDir : 'asc');

    $sortIcon = icon('ic_fluent_chevron_up_down_24_filled', 13);
    $sortColor = $isActive ? 'var(--accent)' : 'var(--text-tertiary)';

    return '<th><a href="' . $url . '" style="display:inline-flex;align-items:center;gap:4px;text-decoration:none;color:inherit;white-space:nowrap;">'
         . htmlspecialchars($label)
         . '<span style="color:' . $sortColor . ';display:flex;align-items:center;margin-left:2px;">' . $sortIcon . '</span>'
         . '</a></th>';
}
?>

<style>
/* Make the table card stretch to fill the .page area so the gap below the
   card matches the .page horizontal padding (var(--space-8) = 32px). */
.page:has(.applicants-table-card) { display:flex; flex-direction:column; }
.applicants-table-card { flex:1; min-height:300px; }
</style>

<!-- Table -->
<div class="card applicants-table-card" style="padding:0;overflow:hidden;display:flex;flex-direction:column">
    <table class="table" id="applicants-table">
        <thead>
            <tr>
                <th style="width:40px;padding-left:var(--space-3)">
                    <input type="checkbox" id="bulk-select-all" onchange="bulkToggleAll(this)"
                           style="width:16px;height:16px;cursor:pointer;accent-color:var(--accent)">
                </th>
                <?= sortable_th('applicant',    'Applicant',    $sortCol, $sortDir, $statusFilter, $search, $typeFilter) ?>
                <?= sortable_th('type',         'Type',         $sortCol, $sortDir, $statusFilter, $search, $typeFilter) ?>
                <?= sortable_th('course',       'Course',       $sortCol, $sortDir, $statusFilter, $search, $typeFilter) ?>
                <?= sortable_th('status',       'Status',       $sortCol, $sortDir, $statusFilter, $search, $typeFilter) ?>
                <?= sortable_th('docs_pending', 'Docs Pending', $sortCol, $sortDir, $statusFilter, $search, $typeFilter) ?>
                <?= sortable_th('applied',      'Applied',      $sortCol, $sortDir, $statusFilter, $search, $typeFilter) ?>
                <th style="width:80px">Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php if (!empty($result['data'])): ?>
            <?php foreach ($result['data'] as $row):
                $hasPending = (int)$row['pending_review'] > 0;
            ?>
                <tr class="bulk-row <?= $hasPending ? 'has-pending-docs' : '' ?>" data-id="<?= (int)$row['id'] ?>">
                    <td style="padding-left:var(--space-3)">
                        <input type="checkbox" class="bulk-check" value="<?= (int)$row['id'] ?>"
                               onchange="bulkUpdateSelection()"
                               style="width:16px;height:16px;cursor:pointer;accent-color:var(--accent)">
                    </td>
                    <td>
                        <div style="font-weight:var(--weight-medium)"><?= e(format_full_name($row)) ?></div>
                        <div style="font-size:var(--text-sm);color:var(--text-tertiary)"><?= e($row['email']) ?></div>
                    </td>
                    <td><span class="badge badge-neutral"><?= e(ucfirst($row['applicant_type'])) ?></span></td>
                    <td style="font-size:var(--text-sm)"><?= e($row['course_applied']) ?></td>
                    <td><span class="badge badge-<?= $row['overall_status'] ?>"><?= e(ucfirst(str_replace('_',' ',$row['overall_status']))) ?></span></td>
                    <td>
                        <?php if ($hasPending): ?>
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

    <?php if (empty($result['data'])): ?>
        <!-- Empty state — fills remaining card height, centered both axes, no hover -->
        <div class="empty-state" style="flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:var(--space-3);color:var(--text-tertiary);padding:var(--space-8)">
            <?= icon('ic_fluent_people_24_regular', 32) ?>
            <div>No applicants found.</div>
        </div>
    <?php else: ?>
        <!-- Filler below the last row so the empty space inherits a top divider line -->
        <div style="flex:1;border-top:1px solid var(--border)"></div>
    <?php endif; ?>
</div>

<!-- Pagination -->
<?php if ($result['last_page'] > 1): ?>
    <div style="display:flex;justify-content:center;gap:var(--space-2);margin-top:var(--space-6)">
        <?php for ($i = 1; $i <= $result['last_page']; $i++): ?>
            <a href="?status=<?= urlencode($statusFilter) ?>&type=<?= urlencode($typeFilter) ?>&q=<?= urlencode($search) ?>&sort_col=<?= urlencode($sortCol) ?>&sort_dir=<?= urlencode($sortDir) ?>&page=<?= $i ?>"
               class="btn <?= $i === $result['current_page'] ? 'btn-primary' : 'btn-ghost' ?> btn-sm"
               style="min-width:36px"><?= $i ?></a>
        <?php endfor; ?>
    </div>
<?php endif; ?>

<!-- ============================================================
     BULK ACTION TOOLBAR (floating, appears on selection)
============================================================ -->
<div id="bulk-toolbar" style="
    display:none;
    position:fixed;bottom:var(--space-6);left:50%;transform:translateX(-50%);z-index:500;
    background:var(--bg-elevated);border:1px solid var(--border);
    border-radius:var(--radius-lg);box-shadow:var(--shadow-lg);
    padding:var(--space-3) var(--space-5);
    display:none;align-items:center;gap:var(--space-4);
    animation:bulkToolbarSlideUp .2s ease-out;
">
    <div style="display:flex;align-items:center;gap:var(--space-2)">
        <span id="bulk-count" style="
            display:inline-flex;align-items:center;justify-content:center;
            min-width:24px;height:24px;padding:0 var(--space-2);
            border-radius:var(--radius-full);
            background:var(--accent);color:var(--accent-text);
            font-size:var(--text-xs);font-weight:var(--weight-semibold);
        ">0</span>
        <span style="font-size:var(--text-sm);color:var(--text-secondary);white-space:nowrap">selected</span>
    </div>

    <div style="width:1px;height:24px;background:var(--border)"></div>

    <button type="button" class="btn btn-success btn-sm" onclick="bulkApproveSelected()"
            style="display:flex;align-items:center;gap:5px;white-space:nowrap">
        <?= icon('ic_fluent_checkmark_circle_24_regular', 14) ?>
        Approve Docs
    </button>
    <button type="button" class="btn btn-secondary btn-sm" onclick="bulkExportCsv()"
            style="display:flex;align-items:center;gap:5px;white-space:nowrap">
        <?= icon('ic_fluent_arrow_download_24_regular', 14) ?>
        Export CSV
    </button>

    <div style="width:1px;height:24px;background:var(--border)"></div>

    <button type="button" class="btn btn-ghost btn-sm" onclick="bulkClearSelection()"
            style="color:var(--text-tertiary);font-size:var(--text-xs);white-space:nowrap">
        Clear
    </button>
</div>

<!-- Bulk approve hidden form -->
<form id="bulk-approve-form" method="POST" style="display:none">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="bulk_approve_selected">
</form>

<style>
@keyframes bulkToolbarSlideUp {
    from { opacity:0; transform:translateX(-50%) translateY(16px); }
    to   { opacity:1; transform:translateX(-50%) translateY(0); }
}
tr.bulk-row.bulk-selected { background:var(--accent-muted); }
tr.bulk-row.bulk-selected td:first-child { box-shadow:inset 3px 0 0 var(--accent); }
</style>

<script>
/* ── Bulk selection logic ──────────────────────────────── */
function getSelectedIds() {
    return Array.from(document.querySelectorAll('.bulk-check:checked')).map(cb => cb.value);
}

function bulkUpdateSelection() {
    var ids     = getSelectedIds();
    var count   = ids.length;
    var toolbar = document.getElementById('bulk-toolbar');
    var badge   = document.getElementById('bulk-count');
    var allCb   = document.getElementById('bulk-select-all');
    var total   = document.querySelectorAll('.bulk-check').length;

    badge.textContent  = count;
    toolbar.style.display = count > 0 ? 'flex' : 'none';
    allCb.checked      = count > 0 && count === total;
    allCb.indeterminate = count > 0 && count < total;

    document.querySelectorAll('.bulk-row').forEach(function(tr) {
        var cb = tr.querySelector('.bulk-check');
        if (cb && cb.checked) { tr.classList.add('bulk-selected'); }
        else { tr.classList.remove('bulk-selected'); }
    });
}

function bulkToggleAll(masterCb) {
    document.querySelectorAll('.bulk-check').forEach(function(cb) {
        cb.checked = masterCb.checked;
    });
    bulkUpdateSelection();
}

function bulkClearSelection() {
    document.getElementById('bulk-select-all').checked = false;
    document.querySelectorAll('.bulk-check').forEach(function(cb) { cb.checked = false; });
    bulkUpdateSelection();
}

function bulkApproveSelected() {
    var ids = getSelectedIds();
    if (ids.length === 0) return;
    if (!confirm('Approve all pending documents for ' + ids.length + ' selected applicant(s)?\n\nApplicants with all docs approved will advance to the exam stage.')) return;

    var form = document.getElementById('bulk-approve-form');
    // Remove old hidden inputs
    form.querySelectorAll('input[name="applicant_ids[]"]').forEach(function(el) { el.remove(); });
    ids.forEach(function(id) {
        var input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'applicant_ids[]';
        input.value = id;
        form.appendChild(input);
    });
    form.submit();
}

function bulkExportCsv() {
    var ids = getSelectedIds();
    if (ids.length === 0) return;
    var rows = [['Name','Email','Type','Course','Status','Applied']];
    ids.forEach(function(id) {
        var tr = document.querySelector('.bulk-row[data-id="'+id+'"]');
        if (!tr) return;
        var cells = tr.querySelectorAll('td');
        rows.push([
            (cells[1]?.querySelector('div')?.textContent||'').trim(),
            (cells[1]?.querySelectorAll('div')[1]?.textContent||'').trim(),
            (cells[2]?.textContent||'').trim(),
            (cells[3]?.textContent||'').trim(),
            (cells[4]?.textContent||'').trim(),
            (cells[6]?.textContent||'').trim()
        ]);
    });
    var csv = rows.map(function(r){return r.map(function(c){return '"'+c.replace(/"/g,'""')+'"';}).join(',');}).join('\n');
    var blob = new Blob([csv], {type:'text/csv'});
    var a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'applicants_export_'+new Date().toISOString().slice(0,10)+'.csv';
    a.click();
}

</script>

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
$pageTitle = 'Documents';
$activeNav = 'documents';
$pageWide  = true;
include VIEWS_PATH . '/layouts/app.php';
