<?php
// ============================================================
// modules/documents/_student_upload_view.php
// View for the student documents screen. Included by modules/documents/student_upload.php after its
// controller logic, inside the ob_start() buffer, so it shares that
// file's variables. HTML only: no request handling lives here.
// ============================================================
?>

<?php foreach ($errors as $err): ?>
    <div class="alert alert-error" style="margin-bottom:var(--space-4)">
        <?= icon('ic_fluent_warning_24_regular', 16) ?>
        <?= e($err) ?>
    </div>
<?php endforeach; ?>

<?php foreach ($success as $msg): ?>
    <div class="alert alert-success" style="margin-bottom:var(--space-4)">
        <?= icon('ic_fluent_checkmark_circle_24_regular', 16) ?>
        <?= e($msg) ?>
    </div>
<?php endforeach; ?>






<!-- Client notice -->
<div class="alert alert-info" style="margin-bottom:var(--space-4);display:block;line-height:1.6">
    <p style="margin:0 0 var(--space-2)">When uploading the REQUIRED DOCUMENTS, please ensure that the scanned copies or screenshots are CLEAR, LEGIBLE, and FREE FROM ANY ALTERATIONS or DIGITAL MANIPULATION.</p>
    <p style="margin:0 0 var(--space-2)">Applicants are reminded that ONLY THOSE with COMPLETE REQUIREMENTS will be entertained and scheduled for VALIDATION.</p>
    <p style="margin:0">Qualifying applicants shall take the admission exam. The schedule of examination will be posted on the PLP Official Facebook Page.</p>
</div>

<?php if ($manualPage): ?>
<!-- Manual upload page: same step, one document at a time -->
<div style="margin-bottom:var(--space-4)">
    <h2 style="font-size:var(--text-xl);font-weight:var(--weight-semibold);margin:var(--space-2) 0 var(--space-1);color:var(--text-primary)">Upload documents manually</h2>
</div>
<?php endif; ?>

<?php if (!$manualPage && !$isSubmitted && $applicant['overall_status'] === 'documents'): ?>
<!-- Applicant type selector -->
<div class="card" style="padding:var(--space-4) var(--space-5);margin-bottom:var(--space-4);display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:var(--space-3)">
    <div>
        <span style="font-size:var(--text-xs);text-transform:uppercase;letter-spacing:.06em;color:var(--text-tertiary)">Applicant Type</span>
        <div style="font-weight:var(--weight-semibold);font-size:var(--text-sm);text-transform:capitalize"><?= e($applicant['applicant_type']) ?></div>
    </div>
    <form method="POST" style="display:flex;align-items:center;gap:var(--space-2)">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="change_type">
        <select name="applicant_type" class="form-select" style="width:auto" aria-label="Applicant type">
            <option value="freshman" <?= $applicant['applicant_type'] === 'freshman' ? 'selected' : '' ?>>Freshman</option>
            <option value="transferee" <?= $applicant['applicant_type'] === 'transferee' ? 'selected' : '' ?>>Transferee</option>
            <option value="foreign" <?= $applicant['applicant_type'] === 'foreign' ? 'selected' : '' ?>>Foreign</option>
        </select>
        <button type="submit" class="btn btn-ghost" style="font-size:var(--text-sm)">Change</button>
    </form>
</div>
<?php endif; ?>

<?php if (!$manualPage && $canEditFlags && !$isSubmitted): ?>
<!-- Conditional ticks: change until submit; jQuery refreshes the slot list below -->
<div class="card" style="padding:var(--space-4) var(--space-5);margin-bottom:var(--space-4)">
    <div style="font-weight:var(--weight-semibold);font-size:var(--text-sm);margin-bottom:var(--space-2)">Which of these apply to you?</div>
    <form id="flags-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="update_flags">
        <?php $_ticks = doc_flags_decode($applicant['doc_flags'] ?? null); $_stage = doc_stage_of($_ticks); ?>
        <?php if ($applicant['applicant_type'] === 'freshman'): ?>
        <!-- Freshmen pick exactly one (required); it decides Form 138 or Form 137 -->
        <div role="radiogroup" aria-required="true" style="margin-bottom:var(--space-3)">
            <div style="font-size:var(--text-xs);font-weight:var(--weight-medium);color:var(--text-secondary);margin-bottom:var(--space-2)">Select one <span style="color:var(--error)">*</span></div>
            <div style="display:flex;flex-direction:column;gap:var(--space-2)">
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
        <div style="font-size:var(--text-xs);font-weight:var(--weight-medium);color:var(--text-secondary);margin-bottom:var(--space-2)">Also tick if they apply</div>
        <?php endif; ?>
        <div style="display:flex;flex-direction:column;gap:var(--space-2)">
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
    <div id="flags-msg" style="font-size:var(--text-xs);margin-top:var(--space-2);display:none"></div>
</div>
<?php endif; ?>

<!-- Documents: one card. Drop box (AI sorting) on top, the required documents below it. -->
<div class="card" id="docs-card" style="padding:var(--space-4) var(--space-5);margin-bottom:var(--space-4)">
<?php if (!$manualPage && $hasBatch): ?>
    <div id="batch-box">
    <div class="batch-drop" id="batch-drop" role="button" tabindex="0" aria-label="Choose files to upload"
         onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();this.click();}">
        <p style="font-weight:var(--weight-medium);margin:0">Drop your files here</p>
        <p style="font-size:var(--text-sm);color:var(--text-tertiary);margin:var(--space-1) 0 var(--space-3)">or click to choose files · PDF, JPG, PNG or WEBP · max 4 MB each</p>
        <button type="button" class="btn btn-secondary btn-sm" id="batch-camera-btn">Take a photo</button>
    </div>
    <input type="file" id="batch-input" aria-label="Choose files to upload" accept=".pdf,.jpg,.jpeg,.png,.webp" multiple style="display:none">
    <input type="file" id="batch-camera" aria-label="Take a photo" accept="image/*" capture="environment" style="display:none">
    <input type="file" id="batch-replace" aria-label="Replace file" accept=".pdf,.jpg,.jpeg,.png,.webp" style="display:none">
    <div style="font-size:var(--text-sm);color:var(--text-tertiary);margin-top:var(--space-3)">Prefer to do it one by one? <a class="batch-link" href="<?= e(url('/student/documents/manual')) ?>">Upload your documents manually</a></div>
    <div id="batch-rows" style="display:flex;flex-direction:column;margin-top:var(--space-3)"></div>
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
    <div style="padding:var(--space-3) 0;border-top:1px solid var(--border)">
        <div style="display:flex;align-items:center;gap:var(--space-4)">

            <!-- Icon -->
            <div style="width:40px;height:40px;border-radius:var(--radius);background:var(--bg-subtle);display:flex;align-items:center;justify-content:center;flex-shrink:0;<?= $isApproved ? 'background:var(--success-bg)' : '' ?>">
                <?php if ($isApproved): ?>
                    <?= icon('ic_fluent_checkmark_circle_24_regular', 18, 'color:var(--success)') ?>
                <?php else: ?>
                    <?= icon('ic_fluent_document_24_regular', 18, 'color:var(--text-tertiary)') ?>
                <?php endif; ?>
            </div>

            <!-- Info -->
            <div style="flex:1;min-width:0">
                <div style="font-weight:var(--weight-medium);color:var(--text-primary)"><?= e($label) ?></div>
                <?php if ($doc && $doc['staff_remarks']): ?>
                    <div style="font-size:var(--text-sm);color:var(--error);margin-top:var(--space-1)">
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
    <div class="modal" style="max-width:420px">
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
                <div class="file-drop-zone" id="drop-zone"
                     data-no-auto-click style="cursor:pointer">
                    <input type="file" name="doc_file" id="file-input" class="file-input" aria-label="Choose file"
                           accept=".pdf,.jpg,.jpeg,.png,.webp" style="display:none">
                    <div class="file-drop-content" id="drop-content">
                        <?= icon('image:fill', 32, 'color:var(--text-tertiary);margin-bottom:var(--space-3)') ?>
                        <p style="font-weight:var(--weight-medium)">Drop your file here</p>
                        <p style="font-size:var(--text-sm);color:var(--text-tertiary)">or <span style="color:var(--accent)">browse</span></p>
                        <p style="font-size:var(--text-xs);color:var(--text-tertiary);margin-top:var(--space-2)">PDF, JPG, PNG or WEBP · max 4 MB</p>
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
    <div class="modal" style="max-width:380px;text-align:center">
        <div class="modal-body" style="padding:var(--space-8) var(--space-6)">
            <div id="result-icon" style="margin:0 auto var(--space-4);width:56px;height:56px;border-radius:50%;display:flex;align-items:center;justify-content:center"></div>
            <div id="result-title" style="font-size:var(--text-lg);font-weight:var(--weight-semibold);color:var(--text-primary);margin-bottom:var(--space-2)"></div>
            <div id="result-msg" style="font-size:var(--text-sm);color:var(--text-secondary)"></div>
        </div>
        <div class="modal-footer" style="justify-content:center">
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
        border-radius:var(--radius);
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
            <button id="fv-prev" onclick="fvNavigate(-1)" type="button" class="btn btn-secondary" style="width:var(--control-height);padding:0;flex-shrink:0" title="Previous (←)">
                <?= icon('ic_fluent_chevron_left_24_regular', 15) ?>
            </button>
            <div style="flex:1;min-width:0">
                <div id="fv-label" style="font-weight:var(--weight-semibold);font-size:var(--text-sm);white-space:nowrap;overflow:hidden;text-overflow:ellipsis"></div>
                <div id="fv-counter" style="font-size:var(--text-xs);color:var(--text-tertiary);margin-top:var(--space-1)"></div>
            </div>
            <button id="fv-next" onclick="fvNavigate(1)" type="button" class="btn btn-secondary" style="width:var(--control-height);padding:0;flex-shrink:0" title="Next (→)">
                <?= icon('ic_fluent_chevron_right_24_regular', 15) ?>
            </button>
            <div style="width:1px;height:24px;background:var(--border);flex-shrink:0"></div>
            <button onclick="fvZoom(-0.25)" type="button" class="fv-ctrl-btn" title="Zoom out (−)">
                <?= icon('ic_fluent_subtract_24_regular', 14) ?>
            </button>
            <span id="fv-zoom-label" style="font-size:var(--text-xs);color:var(--text-secondary);min-width:38px;text-align:center;font-variant-numeric:tabular-nums">100%</span>
            <button onclick="fvZoom(0.25)" type="button" class="fv-ctrl-btn" title="Zoom in (+)">
                <?= icon('ic_fluent_add_24_regular', 14) ?>
            </button>
            <button onclick="fvResetZoom()" type="button" class="fv-ctrl-btn" title="Reset zoom (0)">
                <?= icon('magnifying-glass', 14) ?>
            </button>
            <div style="width:1px;height:24px;background:var(--border);flex-shrink:0"></div>
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
            <div id="fv-hint" style="
                position:absolute;bottom:var(--space-2);left:50%;transform:translateX(-50%);
                background:rgba(0,0,0,0.55);color:var(--white);
                font-size:var(--text-xs);padding:var(--space-1) var(--space-4);border-radius:var(--radius);
                pointer-events:none;opacity:0;transition:opacity .4s ease;white-space:nowrap;
            ">Scroll to zoom · Drag to pan when zoomed in</div>
        </div>
        <div id="fv-dots" style="
            display:flex;align-items:center;justify-content:center;gap:var(--space-2);
            padding:var(--space-3);border-top:1px solid var(--border);flex-shrink:0;flex-wrap:wrap;
        "></div>
    </div>
</div>


<!-- Submit / Withdraw panel -->
<div id="doc-submit">
<?php if ($canSubmit): ?>
<div class="card" style="margin-top:var(--space-4);padding:var(--space-5);display:flex;align-items:center;gap:var(--space-4)">
    <div style="flex:1">
        <div style="font-weight:var(--weight-medium);color:var(--text-primary)">Ready to submit</div>
    </div>
    <button class="btn btn-primary" type="button" onclick="submitApplication()">Submit Application</button>
</div>
<?php elseif ($canWithdraw): ?>
<div class="card" style="margin-top:var(--space-4);padding:var(--space-5);display:flex;align-items:center;gap:var(--space-4)">
    <div style="flex:1">
        <div style="font-weight:var(--weight-medium);color:var(--text-primary)">Application submitted</div>
        <div style="font-size:var(--text-sm);color:var(--text-secondary);margin-top:var(--space-1)">Your documents are awaiting staff review.</div>
    </div>
    <div style="text-align:right;flex-shrink:0">
        <button class="btn btn-ghost btn-sm" type="button" onclick="withdrawSubmission()">Withdraw Submission</button>
    </div>
</div>
<?php elseif ((!$allUploaded || ($stageMissing && !$pastDocuments)) && !$isSubmitted): ?>
<div class="card" style="margin-top:var(--space-4);padding:var(--space-5);display:flex;align-items:center;gap:var(--space-4);opacity:.6">
    <div style="flex:1">
        <div style="font-size:var(--text-sm);color:var(--text-secondary)"><?= $stageMissing && !$pastDocuments
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

