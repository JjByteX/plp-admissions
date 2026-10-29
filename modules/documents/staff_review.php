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
        fail('No applicants selected.', '/staff/applicants');
    }

    $approved  = 0;
    $advanced  = 0;
    $confirmed = 0;
    $incomplete = 0;
    foreach ($ids as $aid) {
        // Incomplete submissions are never bulk-approved.
        if (!required_docs_all_submitted($db, (int)$aid)) {
            $incomplete++;
            continue;
        }
        // Staff picked these applicants on purpose, so AI-flagged documents are
        // approved too. Approving a flagged document confirms its category,
        // the same as the single Approve button does.
        $flags = doc_ai_flags($db, (int)$aid);
        if ($flags) {
            $stmt = $db->prepare(
                "SELECT id FROM documents
                  WHERE applicant_id = ? AND status IN ('uploaded','under_review')"
            );
            $stmt->execute([(int)$aid]);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $docId) {
                $docId = (int)$docId;
                if (!isset($flags[$docId])) continue;
                doc_ai_confirm($db, $flags[$docId]['validation_id'], 'approved', $staffId);
                $confirmed++;
            }
        }
        $res = doc_approve_unflagged($db, (int)$aid, $staffId);
        if ($res['approved'] > 0) $approved++;

        // Same rule as the single-applicant Approve All: advance only when every
        // required document is approved, and only from a pre-exam stage.
        if (advance_to_exam_if_docs_approved($db, (int)$aid)) {
            $advanced++;
            notify_stage_transition($aid, 'exam');
        }
        // Self-guarded: assigns only exam-stage applicants without a slot yet.
        if (required_docs_all_approved($db, (int)$aid)) {
            auto_assign_exam_slot($aid);
        }
    }
    audit_log(AuditAction::BULK_APPROVE_SELECTED, "Bulk-approved docs for {$approved} applicant(s), {$advanced} advanced to exam, {$confirmed} AI-flagged categories confirmed");
    $flashMsg = "Approved documents for {$approved} applicant(s). {$advanced} advanced to exam.";
    if ($confirmed > 0) {
        $flashMsg .= " {$confirmed} AI-flagged document" . ($confirmed != 1 ? 's' : '') . ' approved with the category confirmed.';
    }
    if ($incomplete > 0) {
        $flashMsg .= " {$incomplete} skipped because required documents are incomplete.";
    }
    Session::flash('success', $flashMsg);
    redirect('/staff/applicants');
}

// ----------------------------------------------------------------
// (Bulk reject handler removed — staff use "Request Resubmission"
// per-document instead, which lets the student re-upload AND notifies
// them. A blanket "reject" was redundant since it had the same end
// state as resubmission but skipped the notification.)
//
// The list-wide "Approve All in Review" action has also been removed —
// staff now use the bulk-select toolbar ("Approve Docs") to operate on
// an explicit set of applicants instead of blanket-approving everyone.
// ----------------------------------------------------------------

// Per-applicant view?
$applicantId = get_int('id');

if ($applicantId) {
    // ---- Single applicant document review ----
    $stmt = $db->prepare(
        'SELECT a.*, u.name AS student_name, u.email,
                u.first_name, u.middle_name, u.last_name, u.suffix,
                u.birthdate, u.sex, u.address, u.phone
         FROM applicants a JOIN users u ON u.id = a.user_id
         WHERE a.id = ?'
    );
    $stmt->execute([$applicantId]);
    $applicant = $stmt->fetch();
    if (!$applicant) { redirect('/staff/applicants'); }

    $stmt = $db->prepare('SELECT * FROM documents WHERE applicant_id = ?');
    $stmt->execute([$applicantId]);
    $docRows = array_column($stmt->fetchAll(), null, 'doc_type');
    $requiredDocs = docs_for_type($applicant['applicant_type'], $applicant['doc_flags'] ?? null);

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
                'doc_id'    => (int)$doc['id'],
                'status'    => $doc['status'],
            ];
        }
    }

    // AI flags (Phase 6): flagged documents are skipped by Approve All.
    $aiFlags = doc_ai_flags($db, $applicantId);
    $cats    = doc_categories($requiredDocs);
    $aiLabel = function (?string $key) use ($cats, $requiredDocs): string {
        if ($key === null || $key === '') return 'Unknown';
        return $cats[$key]['label'] ?? $requiredDocs[$key] ?? ucwords(str_replace('_', ' ', $key));
    };
    // Empty slots a flagged file can move to.
    $emptySlots = [];
    foreach ($requiredDocs as $slug => $label) {
        $r = $docRows[$slug] ?? null;
        if (!$r || (!$r['file_path'] && $r['status'] === 'pending')) $emptySlots[$slug] = $label;
    }

    // Count docs that Approve All will approve (flagged ones are skipped)
    $approvable = array_filter($docRows, fn($d) => in_array($d['status'], ['uploaded','under_review'], true));
    $approvableCount = count(array_filter($approvable, fn($d) => !isset($aiFlags[(int)$d['id']])));
    $flaggedPending  = count($approvable) - $approvableCount;
    // Approve All needs a complete submission (every required slot filled).
    $docsComplete    = required_docs_all_submitted($db, $applicantId);
    $approveAllTip   = 'Available once the student has uploaded all required documents.';

    ob_start();
?>
<div id="doc-review">
<div class="flex align-items-center gap-3 mb-6">
    <div class="ms-auto"></div>
    <?php if ($approvableCount > 0 && $docsComplete): ?>
        <button type="button" class="btn btn-success btn-sm" id="approve-all-btn"
                onclick="approveAllAjax()">
            <?= icon('ic_fluent_checkmark_circle_24_regular', 15) ?>
            Approve All (<?= $approvableCount ?>)
        </button>
    <?php elseif ($approvableCount > 0): ?>
        <button type="button" class="btn btn-success btn-sm" disabled
                title="<?= e($approveAllTip) ?>">
            <?= icon('ic_fluent_checkmark_circle_24_regular', 15) ?>
            Approve All (<?= $approvableCount ?>)
        </button>
    <?php endif; ?>
</div>

<?php if ($msg = Session::getFlash('success')): ?>
    <div class="alert alert-success mb-4"><?= e($msg) ?></div>
<?php endif; ?>
<?php if ($msg = Session::getFlash('error')): ?>
    <div class="alert alert-error mb-4"><?= e($msg) ?></div>
<?php endif; ?>

<div class="flex flex-column gap-3">
<?php foreach ($requiredDocs as $slug => $label):
    $doc    = $docRows[$slug] ?? null;
    $status = $doc['status'] ?? 'pending';
    $badge = doc_status_badge($status);
    $flag  = $doc ? ($aiFlags[(int)$doc['id']] ?? null) : null;

    // Find file index in viewable list for opening modal at correct position
    $fileIndex = -1;
    if ($doc && $doc['file_path']) {
        foreach ($viewableFiles as $fi => $vf) {
            if ($vf['file_path'] === $doc['file_path']) { $fileIndex = $fi; break; }
        }
    }
?>
    <div class="card py-4 px-5">
        <div class="flex align-items-center gap-4 flex-wrap">
            <div class="flex-1 min-w-0">
                <div class="fw-medium"><?= e($label) ?></div>
                <?php if ($doc && $doc['staff_remarks']): ?>
                    <div class="text-sm text-tertiary" style="margin-top:2px">Remark: <?= e($doc['staff_remarks']) ?></div>
                <?php endif; ?>
                <?php if ($flag): ?>
                    <div class="ai-flag mt-2" data-doc="<?= (int)$doc['id'] ?>">
                        <div class="flex align-items-center gap-2 flex-wrap text-sm">
                            <span class="badge badge-review">AI flag</span>
                            <span class="text-secondary">
                                <?php if ($flag['source'] === 'applicant_pick'): ?>
                                    Applicant pick: <?= e($aiLabel($flag['pick'])) ?>
                                    <?php if ($flag['guess']): ?> · AI guess: <?= e($aiLabel($flag['guess'])) ?><?php endif; ?>
                                <?php else: ?>
                                    Low confidence<?php if ($flag['guess']): ?> · AI guess: <?= e($aiLabel($flag['guess'])) ?><?php endif; ?>
                                <?php endif; ?>
                            </span>
                        </div>
                        <?php if (in_array($status, ['uploaded','under_review'], true)): ?>
                            <div class="flex align-items-center gap-2 flex-wrap mt-2">
                                <button type="button" class="btn btn-secondary btn-sm js-cat-ok">Category OK</button>
                                <?php if ($emptySlots): ?>
                                    <select class="form-control js-target" style="width:auto;max-width:260px" aria-label="Move to">
                                        <option value="">Move to…</option>
                                        <?php foreach ($emptySlots as $es => $el): if ($es === $slug) continue; ?>
                                            <option value="<?= e($es) ?>"><?= e($el) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="button" class="btn btn-secondary btn-sm js-change-type">Change type</button>
                                <?php else: ?>
                                    <span class="text-xs text-tertiary">No empty slot to move to</span>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
            <span class="badge <?= $badge['class'] ?>"><?= $badge['label'] ?></span>
            <?php if ($doc && $doc['file_path']): ?>
                <button
                    class="btn btn-secondary btn-sm"
                    onclick="openFileViewer(<?= $fileIndex ?>, <?= htmlspecialchars(json_encode($viewableFiles), ENT_QUOTES) ?>)"
                    type="button"
                >
                    <?= icon('ic_fluent_eye_show_24_regular', 14) ?>
                    Review
                </button>
            <?php else: ?>
                <span class="text-sm text-tertiary">No file</span>
            <?php endif; ?>
            <?php if ($doc && in_array($status, ['uploaded','under_review'], true)): ?>
                <div class="flex gap-2">
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
                      data-confirm="Undo approval for this document?" data-confirm-ok="Undo">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="unapprove">
                    <button class="btn btn-ghost btn-sm text-tertiary text-xs">Undo</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
<?php endforeach; ?>
</div>
</div><!-- /#doc-review -->

<!-- Advance to exam -->
<?php
$allDocs   = array_values($docRows);
$allApproved = count($allDocs) === count($requiredDocs)
    && count(array_filter($allDocs, fn($d) => $d['status'] === 'approved')) === count($requiredDocs);
if ($allApproved && $applicant['overall_status'] === 'documents'):
?>
    <div class="alert alert-success mt-6 flex align-items-center gap-4">
        <div class="flex-1"><strong>All documents approved.</strong> Advance applicant to entrance exam stage?</div>
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
                <p class="text-sm text-secondary mb-3">
                    The student will be notified and asked to upload a corrected version.
                </p>
                <label class="form-label" for="resubmit-remarks">What needs to be corrected? <span class="text-error">*</span></label>
                <textarea id="resubmit-remarks" name="remarks" class="form-control" rows="3"
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
     FILE VIEWER / REVIEW MODAL (two-panel layout)
============================================================ -->
<?php
    $studentFullName = trim(($applicant['first_name'] ?? '') . ' ' . ($applicant['middle_name'] ?? '') . ' ' . ($applicant['last_name'] ?? ''));
    if ($applicant['suffix'] ?? '') $studentFullName .= ', ' . $applicant['suffix'];
    if (!$studentFullName) $studentFullName = $applicant['student_name'] ?? '-';
    $strandLabel = isset(SHS_STRANDS[$applicant['shs_strand'] ?? '']) ? SHS_STRANDS[$applicant['shs_strand']] : ($applicant['shs_strand'] ?? '');
?>
<div id="file-viewer-modal" class="align-items-center justify-content-center" style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,0.82);backdrop-filter:blur(4px)" aria-modal="true" role="dialog" aria-label="Document Review">

    <div class="position-relative bg-elevated rounded flex flex-column overflow-hidden" style="width:min(96vw,1400px);height:min(92vh,900px);box-shadow:var(--shadow-lg)">
        <!-- Header -->
        <div class="flex align-items-center gap-3 py-3 px-5 bd-b flex-shrink-0">
            <!-- Prev -->
            <button id="fv-prev" onclick="fvNavigate(-1)" type="button" class="btn btn-secondary p-0 flex-shrink-0" style="width:var(--control-height)" title="Previous (←)">
                <?= icon('ic_fluent_chevron_left_24_regular', 15) ?>
            </button>

            <!-- Label + counter -->
            <div class="flex-1 min-w-0">
                <div id="fv-label" class="text-sm text-nowrap" style="font-weight:var(--weight-semibold);overflow:hidden;text-overflow:ellipsis"></div>
                <div id="fv-counter" class="text-xs" style="color:var(--text-tertiary);margin-top:1px"></div>
            </div>

            <!-- Next -->
            <button id="fv-next" onclick="fvNavigate(1)" type="button" class="btn btn-secondary p-0 flex-shrink-0" style="width:var(--control-height)" title="Next (→)">
                <?= icon('ic_fluent_chevron_right_24_regular', 15) ?>
            </button>

            <div class="flex-shrink-0" style="width:1px;height:24px;background:var(--border)"></div>

            <!-- Zoom controls -->
            <button onclick="fvZoom(-0.25)" type="button" class="fv-ctrl-btn" title="Zoom out (−)">
                <?= icon('ic_fluent_subtract_24_regular', 14) ?>
            </button>
            <span id="fv-zoom-label" class="text-xs text-center" style="color:var(--text-secondary);min-width:38px;font-variant-numeric:tabular-nums">100%</span>
            <button onclick="fvZoom(0.25)" type="button" class="fv-ctrl-btn" title="Zoom in (+)">
                <?= icon('ic_fluent_add_24_regular', 14) ?>
            </button>
            <button onclick="fvResetZoom()" type="button" class="fv-ctrl-btn" title="Reset zoom (0)">
                <?= icon('ic_fluent_arrow_sync_24_regular', 14) ?>
            </button>

            <div class="flex-shrink-0" style="width:1px;height:24px;background:var(--border)"></div>

            <!-- Close -->
            <button onclick="closeFileViewer()" type="button" class="fv-ctrl-btn" title="Close (Esc)" aria-label="Close">
                <?= icon('ic_fluent_dismiss_24_regular', 15) ?>
            </button>
        </div>

        <!-- Two-panel body -->
        <div class="flex-1 flex overflow-hidden" style="min-height:0">
            <!-- LEFT: Document preview -->
            <div class="flex-1 min-w-0 flex flex-column overflow-hidden">
                <div id="fv-viewport" class="position-relative" style="flex:1;overflow:hidden;background:var(--bg-subtle);cursor:default;user-select:none">
                    <div id="fv-transform-wrap" class="position-absolute flex align-items-center justify-content-center" style="top:0;left:0;width:100%;height:100%;will-change:transform;transform-origin:center center"></div>
                    <div id="fv-hint" class="position-absolute text-xs rounded pe-none text-nowrap" style="bottom:12px;left:50%;transform:translateX(-50%);background:rgba(0,0,0,0.55);color:var(--white);padding:5px 14px;opacity:0;transition:opacity .4s ease">Scroll to zoom · Drag to pan when zoomed in</div>
                </div>
                <!-- Nav dots -->
                <div id="fv-dots" class="flex align-items-center justify-content-center p-2 bd-t flex-shrink-0 flex-wrap" style="gap:6px"></div>
            </div>

            <!-- RIGHT: Student details + actions -->
            <div class="flex-shrink-0 flex flex-column overflow-y-auto" style="width:360px;border-left:1px solid var(--border);background:var(--bg)">
                <div class="p-5 flex-1">
                    <div class="fw-semibold text-base mb-4">Applicant Details</div>

                    <div class="flex flex-column gap-3 text-sm">
                        <div>
                            <div class="label-caps-tight">Full Name</div>
                            <div class="fw-medium"><?= e($studentFullName) ?></div>
                        </div>
                        <div>
                            <div class="label-caps-tight">Email</div>
                            <div><?= e($applicant['email'] ?? '-') ?></div>
                        </div>
                        <?php if (!empty($applicant['phone'])): ?>
                        <div>
                            <div class="label-caps-tight">Phone</div>
                            <div><?= e($applicant['phone']) ?></div>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($applicant['birthdate'])): ?>
                        <div>
                            <div class="label-caps-tight">Birthdate</div>
                            <div><?= e(format_date($applicant['birthdate'], 'M j, Y')) ?></div>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($applicant['sex'])): ?>
                        <div>
                            <div class="label-caps-tight">Sex</div>
                            <div><?= $applicant['sex'] === 'M' ? 'Male' : 'Female' ?></div>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($applicant['address'])): ?>
                        <div>
                            <div class="label-caps-tight">Address</div>
                            <div><?= e($applicant['address']) ?></div>
                        </div>
                        <?php endif; ?>

                        <div class="bd-t my-2 mx-0"></div>

                        <div>
                            <div class="label-caps-tight">Applicant Type</div>
                            <div><?= e(ucfirst($applicant['applicant_type'] ?? '-')) ?></div>
                        </div>
                        <div>
                            <div class="label-caps-tight">Course Applied</div>
                            <div class="fw-medium"><?= e($applicant['course_applied'] ?? '-') ?></div>
                        </div>
                        <?php if ($strandLabel): ?>
                        <div>
                            <div class="label-caps-tight">SHS Strand</div>
                            <div><?= e($strandLabel) ?></div>
                        </div>
                        <?php endif; ?>
                        <div>
                            <div class="label-caps-tight">School Year</div>
                            <div><?= e($applicant['school_year'] ?? '-') ?></div>
                        </div>
                    </div>
                </div>

                <!-- Action buttons (shown/hidden based on doc status) -->
                <div id="fv-actions" class="py-4 px-5 bd-t flex flex-column gap-2 flex-shrink-0">
                    <form method="POST" id="fv-approve-form" action="" style="display:none">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="approve">
                        <button type="submit" class="btn btn-success w-100">
                            <?= icon('ic_fluent_checkmark_circle_24_regular', 15, 'margin-right:4px') ?>
                            Approve
                        </button>
                    </form>
                    <button type="button" id="fv-resubmit-btn" class="btn btn-warning" style="width:100%;display:none"
                            onclick="closeFileViewer(); openResubmitModal(window._fvCurrentDocId)">
                        <?= icon('ic_fluent_arrow_undo_24_regular', 15, 'margin-right:4px') ?>
                        Request Resubmission
                    </button>
                    <div id="fv-status-msg" class="text-center text-sm" style="color:var(--text-tertiary);display:none"></div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>window.PAGE = <?= json_encode([
    'docUrl'       => url('/staff/documents/'),
    'applicantUrl' => url('/staff/documents/' . $applicantId),
    'listUrl'      => url('/staff/applicants'),
    'csrf'         => csrf_token(),
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES) ?>;</script>


<?php
    $pageStyles  = ['css/file-viewer.css', 'css/pages/documents-review.css'];
    $pageScripts = ['js/file-viewer.js', 'js/pages/documents-review.js'];
    $content   = ob_get_clean();
    $pageTitle = 'Review: ' . format_full_name($applicant);  
    $pageBreadcrumb = [['label' => 'Documents', 'to' => '/staff/applicants']];
    $pageActions = ui_button('Print', ['variant' => 'secondary', 'icon' => 'printer', 'href' => url('/print/admission?id=' . (int)$applicantId), 'attrs' => ['target' => '_blank', 'rel' => 'noopener']])
        . ($approvableCount > 0
            ? ui_button('Approve All (' . (int)$approvableCount . ')', ['variant' => 'success', 'icon' => 'ic_fluent_checkmark_circle_24_regular',
                'attrs' => $docsComplete
                    ? ['id' => 'approve-all-btn', 'onclick' => 'approveAllAjax()']
                    : ['disabled' => 'disabled', 'title' => $approveAllTip]])
            : '');
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
$search       = get_str('q');
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
    $where[]        = '(u.name ILIKE :q1 OR u.email ILIKE :q2 OR a.course_applied ILIKE :q3)';
    $params[':q1']  = '%' . $search . '%';
    $params[':q2']  = '%' . $search . '%';
    $params[':q3']  = '%' . $search . '%';
}
$whereStr = implode(' AND ', $where);

$colMap = [
    'applicant'    => 'u.name',
    'type'         => 'CASE a.applicant_type WHEN \'freshman\' THEN 1 WHEN \'transferee\' THEN 2 ELSE 3 END',
    'course'       => 'a.course_applied',
    'status'       => 'a.overall_status',
    'docs_pending' => 'pending_review',
    'applied'      => 'a.created_at',
];
$orderCol = $colMap[$sortCol] ?? 'a.created_at';
$orderDir = strtoupper($sortDir);
$orderBy  = "$orderCol $orderDir";

// Rows per page — AutoPageSize (app.js) measures how many fixed-height rows
// fit the table and reloads with ?per_page=; see auto_per_page() in helpers.php.
$perPage = auto_per_page('doc-review');

$result = paginate(
    $db,
    "SELECT COUNT(*) FROM applicants a JOIN users u ON u.id=a.user_id WHERE $whereStr",
    "SELECT a.*, u.name AS student_name, u.email,
            u.first_name, u.middle_name, u.last_name, u.suffix,
            (SELECT COUNT(*) FROM documents d WHERE d.applicant_id=a.id AND d.status='uploaded') as pending_review
     FROM applicants a JOIN users u ON u.id=a.user_id
     WHERE $whereStr ORDER BY $orderBy",
    $params, $page, $perPage
);

// Course list for the Course filter — merged PLP + admin custom courses.
$courseList = get_all_courses();
sort($courseList, SORT_NATURAL | SORT_FLAG_CASE);

ob_start();
?>

<?php if ($msg = Session::getFlash('success')): ?>
    <div class="alert alert-success mb-4 flex-shrink-0"><?= e($msg) ?></div>
<?php endif; ?>
<?php if ($msg = Session::getFlash('error')): ?>
    <div class="alert alert-error mb-4 flex-shrink-0"><?= e($msg) ?></div>
<?php endif; ?>

<?php
// URL helper — keeps every current filter + sort + per_page while overriding
// just the given keys (page links, the Clear link). Same idea as the audit
// log's auditUrl().
$docFilterUrl = function (array $merge = []) use ($statusFilter, $typeFilter, $courseFilter, $search, $sortCol, $sortDir, $perPage): string {
    $base = [
        'status'   => $statusFilter,
        'type'     => $typeFilter,
        'course'   => $courseFilter,
        'q'        => $search,
        'sort_col' => $sortCol,
        'sort_dir' => $sortDir,
        'per_page' => $perPage,
    ];
    return '?' . http_build_query(array_merge($base, $merge));
};

// Pieces the toolbar and the sortable headers below need.
$typeOptions = [
    ''           => 'All Types',
    'freshman'   => 'Freshman',
    'transferee' => 'Transferee',
    'foreign'    => 'Foreign Student',
];
$hasFilters = ($typeFilter !== '' || $courseFilter !== '' || $search !== '');
// Carried by every sortable header link: the filters + page size, back to page 1.
$sortParams = ['status' => $statusFilter, 'type' => $typeFilter, 'course' => $courseFilter, 'q' => $search, 'per_page' => $perPage, 'page' => 1];
?>
<?php
// Same structure as the audit log and the results page: one .auto-table-wrap
// that AutoPageSize measures, the card with the toolbar and the fixed-height
// table fused together, and the pagination bar under the card.
?>
<div class="auto-table-wrap" data-auto-page-size="doc-review" data-current-per-page="<?= (int)$perPage ?>">

<div class="auto-table-card">

    <!-- Toolbar: search + filters, one row, inside the card -->
    <form method="GET" action="<?= url('/staff/applicants') ?>" class="auto-table-toolbar">
        <input type="hidden" name="status"   value="<?= e($statusFilter) ?>">
        <input type="hidden" name="sort_col" value="<?= e($sortCol) ?>">
        <input type="hidden" name="sort_dir" value="<?= e($sortDir) ?>">
        <input type="hidden" name="per_page" value="<?= (int)$perPage ?>">

        <div class="auto-table-search">
            <?= icon('ic_fluent_search_24_filled', 14) ?>
            <input type="text" name="q" class="form-input" aria-label="Search applicants" placeholder="Search name, email, course…" value="<?= e($search) ?>">
        </div>

        <select name="type" class="form-input wpx-160" onchange="this.form.submit()" aria-label="Applicant type">
            <?php foreach ($typeOptions as $val => $label): ?>
                <option value="<?= e($val) ?>" <?= $typeFilter === $val ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>

        <select name="course" class="form-input wpx-240" onchange="this.form.submit()" aria-label="Course">
            <option value="">All Courses</option>
            <?php foreach ($courseList as $c): ?>
                <option value="<?= e($c) ?>" <?= $courseFilter === $c ? 'selected' : '' ?>><?= e($c) ?></option>
            <?php endforeach; ?>
        </select>

        <?php if ($hasFilters): ?>
            <a href="<?= e($docFilterUrl(['type' => '', 'course' => '', 'q' => '', 'page' => 1])) ?>" class="btn btn-ghost btn-sm">Clear</a>
        <?php endif; ?>
    </form>

    <!-- Table body — sized so the rows AutoPageSize picks fit exactly; it
         only scrolls as a safety net (e.g. a very narrow window) -->
    <div class="auto-table-body">
        <table class="auto-table" id="applicants-table">
            <thead>
                <tr>
                    <th class="col-check">
                        <input type="checkbox" id="bulk-select-all" aria-label="Select all applicants" onchange="bulkToggleAll(this)"
                               class="pointer accent-c" style="width:16px;height:16px">
                    </th>
                    <?= sortable_th('applicant',    'Applicant',    $sortCol, $sortDir, $sortParams) ?>
                    <?= sortable_th('type',         'Type',         $sortCol, $sortDir, $sortParams, 'width:120px') ?>
                    <?= sortable_th('course',       'Course',       $sortCol, $sortDir, $sortParams, 'width:220px') ?>
                    <?= sortable_th('status',       'Status',       $sortCol, $sortDir, $sortParams, 'width:130px') ?>
                    <?= sortable_th('docs_pending', 'Docs Pending', $sortCol, $sortDir, $sortParams, 'width:150px') ?>
                    <?= sortable_th('applied',      'Applied',      $sortCol, $sortDir, $sortParams, 'width:130px') ?>
                    <th style="width:150px">Actions</th>
                </tr>
            </thead>
            <?php if (!empty($result['data'])): ?>
            <tbody>
                <?php foreach ($result['data'] as $row):
                    $hasPending = (int)$row['pending_review'] > 0;
                ?>
                <tr class="bulk-row <?= $hasPending ? 'has-pending-docs' : '' ?>" data-id="<?= (int)$row['id'] ?>">
                    <td class="col-check">
                        <input type="checkbox" class="bulk-check pointer accent-c" aria-label="Select applicant" value="<?= (int)$row['id'] ?>"
                               onchange="bulkUpdateSelection()"
                               style="width:16px;height:16px">
                    </td>
                    <td>
                        <?php // Single-line row - the email lives in the review detail
                              // page; the tooltip surfaces it on hover for quick context. ?>
                        <span class="auto-table-clip fw-medium" title="<?= e($row['email']) ?>"><?= e(format_full_name($row)) ?></span>
                    </td>
                    <td><span class="badge badge-neutral"><?= e(ucfirst($row['applicant_type'])) ?></span></td>
                    <td class="text-sm"><span class="auto-table-clip" title="<?= e($row['course_applied']) ?>"><?= e($row['course_applied']) ?></span></td>
                    <td><span class="badge badge-<?= e($row['overall_status']) ?>"><?= e(ucfirst(str_replace('_',' ',$row['overall_status']))) ?></span></td>
                    <td>
                        <?php if ($hasPending): ?>
                            <span class="text-warning fw-semibold text-sm"><?= (int)$row['pending_review'] ?> to review</span>
                        <?php else: ?>
                            <span class="text-tertiary text-sm">-</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-sm text-tertiary"><?= format_date($row['created_at'], 'M j, Y') ?></td>
                    <td>
                        <a href="<?= url('/staff/applicants/' . $row['id']) ?>" class="btn btn-secondary btn-sm">Review</a>
                        <a href="<?= url('/print/admission?id=' . (int)$row['id']) ?>" class="btn btn-secondary btn-sm" target="_blank" rel="noopener" title="Print admission" aria-label="Print admission"><?= icon('printer', 14) ?></a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <?php endif; ?>
        </table>

        <?php if (empty($result['data'])): ?>
        <div class="auto-table-empty">
            <?= icon('ic_fluent_people_24_regular', 32) ?>
            <div><?= $hasFilters ? 'No applicants match your filters.' : 'No applicants found.' ?></div>
        </div>
        <?php endif; ?>
    </div>
</div><!-- /.auto-table-card -->

<!-- Pagination bar — under the card, inside the wrap, same as the audit log.
     Its 40px is reserved in AutoPageSize's math whether or not it renders. -->
<?php if ($result['last_page'] > 1): ?>
    <?= auto_table_footer($result, fn(int $p): string => $docFilterUrl(['page' => $p])) ?>
<?php endif; ?>

</div><!-- /.auto-table-wrap -->

<!-- ============================================================
     BULK ACTION TOOLBAR (floating, appears on selection)
============================================================ -->
<div id="bulk-toolbar" class="bd rounded py-3 px-5 align-items-center gap-4" style="display:none;position:fixed;bottom:var(--space-6);left:50%;transform:translateX(-50%);z-index:500;background:var(--bg-elevated);box-shadow:var(--shadow-lg);display:none;animation:bulkToolbarSlideUp .2s ease-out">
    <div class="flex align-items-center gap-2">
        <span id="bulk-count" class="inline-flex align-items-center justify-content-center py-0 px-2 rounded text-xs" style="min-width:24px;height:24px;background:var(--accent);color:var(--accent-text);font-weight:var(--weight-semibold)">0</span>
        <span class="text-sm text-secondary text-nowrap">selected</span>
    </div>

    <div style="width:1px;height:24px;background:var(--border)"></div>

    <button type="button" class="btn btn-success btn-sm flex align-items-center text-nowrap" onclick="bulkApproveSelected()"
            style="gap:5px">
        <?= icon('ic_fluent_checkmark_circle_24_regular', 14) ?>
        Approve Docs
    </button>
    <button type="button" class="btn btn-secondary btn-sm flex align-items-center text-nowrap" onclick="bulkExportCsv()"
            style="gap:5px">
        <?= icon('ic_fluent_arrow_download_24_regular', 14) ?>
        Export CSV
    </button>

    <div style="width:1px;height:24px;background:var(--border)"></div>

    <button type="button" class="btn btn-ghost btn-sm text-tertiary text-xs text-nowrap" onclick="bulkClearSelection()"
           >
        Clear
    </button>
</div>

<!-- Bulk approve hidden form. The explicit action= matters: without it the
     form would POST to the current URL (/staff/applicants), which used to
     not have a POST route registered and would 404 / redirect. -->
<form id="bulk-approve-form" method="POST" action="<?= url('/staff/applicants') ?>" style="display:none">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="bulk_approve_selected">
</form>



<?php
$pageStyles  = ['css/pages/documents-list.css'];
$pageScripts = ['js/pages/documents-bulk.js', 'js/pages/live-search.js'];
$content   = ob_get_clean();
$pageTitle = 'Documents';
$activeNav = 'documents';
$pageWide  = true;
include VIEWS_PATH . '/layouts/app.php';
