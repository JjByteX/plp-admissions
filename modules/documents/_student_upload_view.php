<?php
// ============================================================
// modules/documents/_student_upload_view.php
// View for the student documents screen. Included by modules/documents/student_upload.php after its
// controller logic, inside the ob_start() buffer, so it shares that
// file's variables. HTML only: no request handling lives here.
// ============================================================
?>

<?php foreach ($errors as $err): ?>
    <div class="alert alert-error mb-4">
        <?= icon('ic_fluent_warning_24_regular', 16) ?>
        <?= e($err) ?>
    </div>
<?php endforeach; ?>

<?php foreach ($success as $msg): ?>
    <div class="alert alert-success mb-4">
        <?= icon('ic_fluent_checkmark_circle_24_regular', 16) ?>
        <?= e($msg) ?>
    </div>
<?php endforeach; ?>






<!-- Client notice -->
<div class="alert alert-info mb-4" style="display:block;line-height:1.6">
    <p style="margin:0 0 var(--space-2)">When uploading the REQUIRED DOCUMENTS, please ensure that the scanned copies or screenshots are CLEAR, LEGIBLE, and FREE FROM ANY ALTERATIONS or DIGITAL MANIPULATION.</p>
    <p style="margin:0 0 var(--space-2)">Applicants are reminded that ONLY THOSE with COMPLETE REQUIREMENTS will be entertained and scheduled for VALIDATION.</p>
    <p class="m-0">Qualifying applicants shall take the admission exam. The schedule of examination will be posted on the PLP Official Facebook Page.</p>
</div>

<?php if ($manualPage): ?>
<!-- Manual upload page: same step, one document at a time -->
<div class="mb-4">
    <h2 class="text-xl fw-semibold text-main" style="margin:var(--space-2) 0 var(--space-1)">Upload documents manually</h2>
</div>
<?php endif; ?>

<?php if (!$manualPage && !$isSubmitted && $applicant['overall_status'] === 'documents'): ?>
<!-- Applicant type selector -->
<div class="card py-4 px-5 mb-4 flex align-items-center justify-content-between flex-wrap gap-3">
    <div>
        <span class="text-xs text-uppercase text-tertiary" style="letter-spacing:.06em">Applicant Type</span>
        <div class="fw-semibold text-sm" style="text-transform:capitalize"><?= e($applicant['applicant_type']) ?></div>
    </div>
    <form method="POST" class="flex align-items-center gap-2">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="change_type">
        <select name="applicant_type" class="form-select" style="width:auto" aria-label="Applicant type">
            <option value="freshman" <?= $applicant['applicant_type'] === 'freshman' ? 'selected' : '' ?>>Freshman</option>
            <option value="transferee" <?= $applicant['applicant_type'] === 'transferee' ? 'selected' : '' ?>>Transferee</option>
            <option value="foreign" <?= $applicant['applicant_type'] === 'foreign' ? 'selected' : '' ?>>Foreign</option>
        </select>
        <button type="submit" class="btn btn-ghost text-sm">Change</button>
    </form>
</div>
<?php endif; ?>

<?php if (!$manualPage && $canEditFlags && !$isSubmitted): ?>
<!-- Conditional ticks: change until submit; jQuery refreshes the slot list below -->
<div class="card py-4 px-5 mb-4">
    <div class="fw-semibold text-sm mb-2">Which of these apply to you?</div>
    <form id="flags-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="update_flags">
        <?php $_ticks = doc_flags_decode($applicant['doc_flags'] ?? null); $_stage = doc_stage_of($_ticks); ?>
        <?php if ($applicant['applicant_type'] === 'freshman'): ?>
        <!-- Freshmen pick exactly one (required); it decides Form 138 or Form 137 -->
        <div role="radiogroup" aria-required="true" class="mb-3">
            <div class="text-xs fw-medium text-secondary mb-2">Select one <span class="text-error">*</span></div>
            <div class="flex flex-column gap-2">
                <label class="form-check">
                    <input type="radio" name="flags[stage]" value="grade12" <?= $_stage === 'grade12' ? 'checked' : '' ?>>
                    <span>I am currently in Grade 12</span>
                </label>
                <label class="form-check">
                    <input type="radio" name="flags[stage]" value="shs_grad" <?= $_stage === 'shs_grad' ? 'checked' : '' ?>>
                    <span>I am a Senior High School graduate</span>
                </label>
            </div>
        </div>
        <div class="text-xs fw-medium text-secondary mb-2">Also tick if they apply</div>
        <?php endif; ?>
        <div class="flex flex-column gap-2">
            <label class="form-check">
                <input type="checkbox" name="flags[married]" value="1" <?= $_ticks['married'] ? 'checked' : '' ?>>
                <span>I am married</span>
            </label>
            <label class="form-check">
                <input type="checkbox" name="flags[guardian]" value="1" <?= $_ticks['guardian'] ? 'checked' : '' ?>>
                <span>I am not living with my parents</span>
            </label>
        </div>
    </form>
    <div id="flags-msg" class="text-xs mt-2" style="display:none"></div>
</div>
<?php endif; ?>

<!-- Documents: one card. Drop box (AI sorting) on top, the required documents below it. -->
<div class="card py-4 px-5 mb-4" id="docs-card">
<?php if (!$manualPage && $hasBatch): ?>
    <div id="batch-box">
    <div class="batch-drop" id="batch-drop" role="button" tabindex="0" aria-label="Choose files to upload"
         onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();this.click();}">
        <p class="fw-medium m-0">Drop your files here</p>
        <p class="text-sm text-tertiary" style="margin:var(--space-1) 0 var(--space-3)">or click to choose files · PDF, JPG, PNG or WEBP · max 4 MB each</p>
        <button type="button" class="btn btn-secondary btn-sm" id="batch-camera-btn">Take a photo</button>
    </div>
    <input type="file" id="batch-input" aria-label="Choose files to upload" accept=".pdf,.jpg,.jpeg,.png,.webp" multiple style="display:none">
    <input type="file" id="batch-camera" aria-label="Take a photo" accept="image/*" capture="environment" style="display:none">
    <input type="file" id="batch-replace" aria-label="Replace file" accept=".pdf,.jpg,.jpeg,.png,.webp" style="display:none">
    <div class="text-sm text-tertiary mt-3">Prefer to do it one by one? <a class="batch-link" href="<?= e(url('/student/documents/manual')) ?>">Upload your documents manually</a></div>
    <div id="batch-rows" class="flex flex-column mt-3"></div>
    </div>
<?php endif; ?>

<!-- Document list -->
<div id="doc-list" style="display:flex;flex-direction:column;<?= (!$manualPage && $hasBatch) ? 'margin-top:var(--space-4)' : '' ?>">
<?php foreach ($requiredDocs as $slug => $label):
    $doc    = $docRows[$slug] ?? null;
    $status = $doc['status'] ?? 'pending';
    $badge      = doc_status_badge($status);
    $canUpload  = $isSubmitted
        ? in_array($status, ['rejected', 'resubmission_required'], true)
        : in_array($status, ['pending', 'rejected', 'resubmission_required', 'uploaded'], true);
    $uploadLabel = $status === 'pending' ? 'Upload' : 'Resubmit';
    $isApproved = $status === 'approved';
    $showUpload = $fullList && $canUpload;   // the list on the main page is read-only
?>
    <div class="py-3 px-0 bd-t">
        <div class="flex align-items-center gap-4">

            <!-- Icon -->
            <div style="width:40px;height:40px;border-radius:var(--radius);background:var(--bg-subtle);display:flex;align-items:center;justify-content:center;flex-shrink:0;<?= $isApproved ? 'background:var(--success-bg)' : '' ?>">
                <?php if ($isApproved): ?>
                    <?= icon('ic_fluent_checkmark_circle_24_regular', 18, 'color:var(--success)') ?>
                <?php else: ?>
                    <?= icon('ic_fluent_document_24_regular', 18, 'color:var(--text-tertiary)') ?>
                <?php endif; ?>
            </div>

            <!-- Info -->
            <div class="flex-1 min-w-0">
                <div class="fw-medium text-main"><?= e($label) ?></div>
                <?php if ($doc && $doc['staff_remarks']): ?>
                    <div class="text-sm text-error mt-1">
                        Staff note: <?= e($doc['staff_remarks']) ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Status badge — hide when Replace is shown (redundant) -->
            <?php if (!($showUpload && $status !== 'pending')): ?>
                <span class="badge <?= $badge['class'] ?>"><?= $badge['label'] ?></span>
            <?php endif; ?>

            <!-- View — always show when a file exists -->
            <?php if ($doc && $doc['file_path']): ?>
                <?php
                $fileIndex = -1;
                foreach ($viewableFiles as $fi => $vf) {
                    if ($vf['file_path'] === $doc['file_path']) { $fileIndex = $fi; break; }
                }
                ?>
                <button class="btn btn-ghost btn-sm"
                        onclick="openFileViewer(<?= $fileIndex ?>, <?= htmlspecialchars(json_encode($viewableFiles), ENT_QUOTES) ?>)"
                        type="button">View</button>
            <?php endif; ?>

            <!-- Upload / Replace button -->
            <?php if ($showUpload): ?>
                <button class="btn btn-secondary btn-sm"
                        onclick="openUploadModal('<?= $slug ?>', <?= htmlspecialchars(json_encode($label), ENT_QUOTES) ?>)">
                    <?= $uploadLabel ?>
                </button>
            <?php endif; ?>

        </div>
    </div>
<?php endforeach; ?>
</div>
</div>

<!-- Upload modal -->
<div id="upload-modal" class="modal-backdrop" style="display:none" aria-hidden="true">
    <div class="modal mwpx-420">
        <div class="modal-header">
            <div class="modal-title" id="modal-doc-name">Upload Document</div>
            <button class="btn-icon" onclick="closeUploadModal()" aria-label="Close">
                <?= icon('ic_fluent_dismiss_24_regular', 18) ?>
            </button>
        </div>
        <form id="upload-form" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="doc_slug" id="modal-slug">
            <div class="modal-body">
                <div class="file-drop-zone pointer" id="drop-zone"
                     data-no-auto-click>
                    <input type="file" name="doc_file" id="file-input" class="file-input" aria-label="Choose file"
                           accept=".pdf,.jpg,.jpeg,.png,.webp" style="display:none">
                    <div class="file-drop-content" id="drop-content">
                        <?= icon('image:fill', 32, 'color:var(--text-tertiary);margin-bottom:var(--space-3)') ?>
                        <p class="fw-medium">Drop your file here</p>
                        <p class="text-sm text-tertiary">or <span class="text-accent">browse</span></p>
                        <p class="text-xs text-tertiary mt-2">PDF, JPG, PNG or WEBP · max 4 MB</p>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-ghost" onclick="closeUploadModal()">Cancel</button>
                <button type="button" id="upload-submit-btn" class="btn btn-primary" onclick="submitUpload()">Upload</button>
            </div>
        </form>
    </div>
</div>

<!-- Hidden form for submit / withdraw — ensures CSRF token is always sent correctly -->
<form id="action-form" style="display:none">
    <?= csrf_field() ?>
    <input type="hidden" name="action" id="action-name" value="">
</form>

<!-- Result popup (success / error) -->
<div id="result-modal" class="modal-backdrop" style="display:none" aria-hidden="true">
    <div class="modal mwpx-380 text-center">
        <div class="modal-body py-8 px-6">
            <div id="result-icon" class="rounded-circle flex align-items-center justify-content-center" style="margin:0 auto var(--space-4);width:56px;height:56px"></div>
            <div id="result-title" class="text-lg mb-2" style="font-weight:var(--weight-semibold);color:var(--text-primary)"></div>
            <div id="result-msg" class="text-sm" style="color:var(--text-secondary)"></div>
        </div>
        <div class="modal-footer justify-content-center">
            <button class="btn btn-primary" onclick="closeResultModal()">Done</button>
        </div>
    </div>
</div>

<script>window.PAGE = <?= json_encode([
    'uploadUrl' => url('/student/documents'),
    'manualUrl' => url('/student/documents/manual'),
    'csrf'      => csrf_token(),
    'pdfJs'     => asset('js/pdf.min.js'),
    'pdfWorker' => asset('js/pdf.worker.min.js'),
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES) ?>;</script>

<!-- FILE VIEWER MODAL -->
<div id="file-viewer-modal" class="align-items-center justify-content-center" style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,0.82);backdrop-filter:blur(4px)" aria-modal="true" role="dialog" aria-label="Document Viewer">

    <div class="position-relative bg-elevated rounded flex flex-column overflow-hidden" style="width:min(94vw,1100px);height:min(90vh,860px);box-shadow:var(--shadow-lg)">
        <!-- Header -->
        <div class="flex align-items-center gap-3 py-3 px-5 bd-b flex-shrink-0">
            <button id="fv-prev" onclick="fvNavigate(-1)" type="button" class="btn btn-secondary p-0 flex-shrink-0" style="width:var(--control-height)" title="Previous (←)">
                <?= icon('ic_fluent_chevron_left_24_regular', 15) ?>
            </button>
            <div class="flex-1 min-w-0">
                <div id="fv-label" class="text-sm text-nowrap" style="font-weight:var(--weight-semibold);overflow:hidden;text-overflow:ellipsis"></div>
                <div id="fv-counter" class="text-xs" style="color:var(--text-tertiary);margin-top:var(--space-1)"></div>
            </div>
            <button id="fv-next" onclick="fvNavigate(1)" type="button" class="btn btn-secondary p-0 flex-shrink-0" style="width:var(--control-height)" title="Next (→)">
                <?= icon('ic_fluent_chevron_right_24_regular', 15) ?>
            </button>
            <div class="flex-shrink-0" style="width:1px;height:24px;background:var(--border)"></div>
            <button onclick="fvZoom(-0.25)" type="button" class="fv-ctrl-btn" title="Zoom out (−)">
                <?= icon('ic_fluent_subtract_24_regular', 14) ?>
            </button>
            <span id="fv-zoom-label" class="text-xs text-center" style="color:var(--text-secondary);min-width:38px;font-variant-numeric:tabular-nums">100%</span>
            <button onclick="fvZoom(0.25)" type="button" class="fv-ctrl-btn" title="Zoom in (+)">
                <?= icon('ic_fluent_add_24_regular', 14) ?>
            </button>
            <button onclick="fvResetZoom()" type="button" class="fv-ctrl-btn" title="Reset zoom (0)">
                <?= icon('magnifying-glass', 14) ?>
            </button>
            <div class="flex-shrink-0" style="width:1px;height:24px;background:var(--border)"></div>
            <button onclick="closeFileViewer()" type="button" class="fv-ctrl-btn" title="Close (Esc)" aria-label="Close">
                <?= icon('ic_fluent_dismiss_24_regular', 15) ?>
            </button>
        </div>
        <!-- Viewport -->
        <div id="fv-viewport" class="position-relative" style="flex:1;overflow:hidden;background:var(--bg-subtle);min-height:300px;cursor:default;user-select:none">
            <div id="fv-transform-wrap" class="position-absolute flex align-items-center justify-content-center" style="top:0;left:0;width:100%;height:100%;will-change:transform;transform-origin:center center">
                <!-- content injected by _render() -->
            </div>
            <div id="fv-hint" class="position-absolute text-xs py-1 px-4 rounded pe-none text-nowrap" style="bottom:var(--space-2);left:50%;transform:translateX(-50%);background:rgba(0,0,0,0.55);color:var(--white);opacity:0;transition:opacity .4s ease">Scroll to zoom · Drag to pan when zoomed in</div>
        </div>
        <div id="fv-dots" class="flex align-items-center justify-content-center gap-2 p-3 bd-t flex-shrink-0 flex-wrap"></div>
    </div>
</div>


<!-- Submit / Withdraw panel -->
<div id="doc-submit">
<?php if ($canSubmit): ?>
<div class="card mt-4 p-5 flex align-items-center gap-4">
    <div class="flex-1">
        <div class="fw-medium text-main">Ready to submit</div>
    </div>
    <button class="btn btn-primary" type="button" onclick="submitApplication()">Submit Application</button>
</div>
<?php elseif ($canWithdraw): ?>
<div class="card mt-4 p-5 flex align-items-center gap-4">
    <div class="flex-1">
        <div class="fw-medium text-main">Application submitted</div>
        <div class="text-sm text-secondary mt-1">Your documents are awaiting staff review.</div>
    </div>
    <div class="text-end flex-shrink-0">
        <button class="btn btn-ghost btn-sm" type="button" onclick="withdrawSubmission()">Withdraw Submission</button>
    </div>
</div>
<?php elseif ((!$allUploaded || ($stageMissing && !$pastDocuments)) && !$isSubmitted): ?>
<div class="card mt-4 p-5 flex align-items-center gap-4" style="opacity:.6">
    <div class="flex-1">
        <div class="text-sm text-secondary"><?= $stageMissing && !$pastDocuments
            ? 'Choose "currently in Grade 12" or "Senior High School graduate" above to enable submission.'
            : 'Upload all required documents to enable submission.' ?></div>
    </div>
    <button class="btn btn-primary" disabled style="cursor:not-allowed">Submit Application</button>
</div>
<?php endif; ?>
</div>



<!-- Step navigation -->
<div class="step-nav">
    <span></span>
    <?php if ($allApproved && $_examResult && !empty($_examResult['passed'])): ?>
        <a href="<?= url('/student/interview') ?>" class="btn btn-primary">Interview <?= icon('ic_fluent_arrow_right_24_regular', 16) ?></a>
    <?php elseif ($allApproved && !$_examResult): ?>
        <a href="<?= url('/student/exam') ?>" class="btn btn-primary">Entrance Exam <?= icon('ic_fluent_arrow_right_24_regular', 16) ?></a>
    <?php elseif ($allApproved && $_examResult): ?>
        <span class="btn btn-primary" style="opacity:.4;cursor:not-allowed" title="Your exam result is being reviewed by the admissions office">Interview <?= icon('ic_fluent_arrow_right_24_regular', 16) ?></span>
    <?php else: ?>
        <span class="btn btn-primary" style="opacity:.4;cursor:not-allowed" title="All documents must be approved first">Entrance Exam <?= icon('ic_fluent_arrow_right_24_regular', 16) ?></span>
    <?php endif; ?>
</div>

