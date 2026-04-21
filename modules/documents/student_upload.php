<?php
// ============================================================
// modules/documents/student_upload.php
// M3 — Student: view & upload required documents
// ============================================================

require_once CORE_PATH . '/bootstrap.php';
Auth::requireRole(ROLE_STUDENT);

$userId = Auth::id();
$db     = db();

// Fetch applicant
$stmt = $db->prepare('SELECT * FROM applicants WHERE user_id = ? ORDER BY id DESC LIMIT 1');
$stmt->execute([$userId]);
$applicant = $stmt->fetch();
if (!$applicant) { redirect('/student/documents'); }
$applicantId  = $applicant['id'];
$isSubmitted  = ($applicant['overall_status'] ?? '') === 'submitted';

// Fetch existing document rows
$stmt = $db->prepare('SELECT * FROM documents WHERE applicant_id = ?');
$stmt->execute([$applicantId]);
$docRows = array_column($stmt->fetchAll(), null, 'doc_type');

$requiredDocs = docs_for_type($applicant['applicant_type']);

$errors   = [];
$success  = [];

// Detect AJAX upload
$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
    && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

// ----------------------------------------------------------------
// POST — handle file upload, submit, or withdraw
// ----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $action = $_POST['action'] ?? 'upload';

    // ---- Submit application ----
    if ($action === 'submit_application') {
        $uploadedCount = 0;
        foreach ($requiredDocs as $slug => $_) {
            $s = $docRows[$slug]['status'] ?? 'pending';
            if (in_array($s, ['uploaded', 'approved'], true)) $uploadedCount++;
        }
        $readyToSubmit = $uploadedCount === count($requiredDocs);

        if ($readyToSubmit && !$isSubmitted) {
            $db->prepare('UPDATE applicants SET overall_status = "submitted" WHERE id = ?')
               ->execute([$applicantId]);
            $isSubmitted = true;
            $applicant['overall_status'] = 'submitted';
            Session::flash('success', 'Your application has been submitted successfully!');
        } elseif ($isSubmitted) {
            $errors[] = 'Application is already submitted.';
        } else {
            $errors[] = 'All documents must be uploaded before submitting.';
        }

        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => empty($errors), 'message' => empty($errors) ? 'Application submitted!' : implode(' ', $errors)]);
            exit;
        }
        redirect('/student/documents');
    }

    // ---- Withdraw submission ----
    if ($action === 'withdraw_submission') {
        if ($isSubmitted) {
            $db->prepare('UPDATE applicants SET overall_status = "documents" WHERE id = ?')
               ->execute([$applicantId]);
            $isSubmitted = false;
            $applicant['overall_status'] = 'documents';
            Session::flash('success', 'Submission withdrawn. You can make changes and re-submit.');
        }

        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => true, 'message' => 'Submission withdrawn.']);
            exit;
        }
        redirect('/student/documents');
    }

    // ---- File upload ----
    $docSlug = trim($_POST['doc_slug'] ?? '');

    if (!array_key_exists($docSlug, $requiredDocs)) {
        $errors[] = 'Invalid document type.';
    }

    // Only allow replace based on submission state
    $currentStatus  = $docRows[$docSlug]['status'] ?? 'pending';
    $allowedStatuses = $isSubmitted ? ['rejected'] : ['pending', 'rejected', 'uploaded'];
    if (!in_array($currentStatus, $allowedStatuses, true)) {
        $errors[] = 'This document cannot be replaced at this time.';
    }

    if (empty($_FILES['doc_file']['name'])) {
        $errors[] = 'Please select a file to upload.';
    }

    if (empty($errors)) {
        $file     = $_FILES['doc_file'];
        $fileSize = $file['size'];
        $tmpPath  = $file['tmp_name'];

        if ($fileSize > MAX_UPLOAD_BYTES) {
            $errors[] = 'File size exceeds the 5 MB limit.';
        }

        // Check MIME via finfo
        $finfo    = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($tmpPath);
        if (!in_array($mimeType, ALLOWED_MIME_TYPES, true)) {
            $errors[] = 'Only PDF, JPG, PNG, and WEBP files are accepted.';
        }
    }

    if (empty($errors)) {
        $ext      = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = $applicantId . '_' . $docSlug . '_' . time() . '.' . strtolower($ext);

        // Upload to Uploadcare
        $fileUrl = uploadcare_upload($tmpPath, $filename, $mimeType);

        if (!$fileUrl) {
            $errors[] = 'File upload failed. Please try again.';
        } else {
            $filePath = $fileUrl; // Store full CDN URL

            if (isset($docRows[$docSlug])) {
                $stmt = $db->prepare(
                    'UPDATE documents SET file_path=?, status="uploaded", staff_remarks=NULL, reviewed_by=NULL
                     WHERE applicant_id=? AND doc_type=?'
                );
                $stmt->execute([$filePath, $applicantId, $docSlug]);
            } else {
                $stmt = $db->prepare(
                    'INSERT INTO documents (applicant_id, doc_type, file_path, status) VALUES (?,?,?,"uploaded")'
                );
                $stmt->execute([$applicantId, $docSlug, $filePath]);
            }

            // Re-fetch updated doc rows
            $stmt = $db->prepare('SELECT * FROM documents WHERE applicant_id = ?');
            $stmt->execute([$applicantId]);
            $docRows = array_column($stmt->fetchAll(), null, 'doc_type');

            $success[] = $requiredDocs[$docSlug] . ' uploaded successfully.';
        }
    }

    // Return JSON for AJAX requests
    if ($isAjax) {
        header('Content-Type: application/json');
        if (empty($errors)) {
            echo json_encode(['ok' => true, 'message' => $success[0] ?? 'Uploaded successfully.']);
        } else {
            echo json_encode(['ok' => false, 'message' => implode(' ', $errors)]);
        }
        exit;
    }
}

// Count statuses
$statusCounts = array_count_values(array_column($docRows, 'status'));
$allApproved  = count($docRows) === count($requiredDocs)
    && ($statusCounts['approved'] ?? 0) === count($requiredDocs);

// Submission state helpers
$uploadedOrApproved = ($statusCounts['uploaded'] ?? 0) + ($statusCounts['approved'] ?? 0);
$allUploaded  = count($docRows) === count($requiredDocs) && $uploadedOrApproved === count($requiredDocs);
$canSubmit    = $allUploaded && !$isSubmitted;
$canWithdraw  = $isSubmitted && !$allApproved;

// Stepper current step
$stmt = $db->prepare('SELECT * FROM exam_results WHERE applicant_id=? LIMIT 1');
$stmt->execute([$applicantId]);
$_examResult = $stmt->fetch() ?: null;

$stmt = $db->prepare('SELECT q.* FROM interview_queue q WHERE q.applicant_id=? LIMIT 1');
$stmt->execute([$applicantId]);
$_interviewSlot = $stmt->fetch() ?: null;

$stmt = $db->prepare('SELECT * FROM admission_results WHERE applicant_id=? LIMIT 1');
$stmt->execute([$applicantId]);
$_admissionResult = $stmt->fetch() ?: null;

$stepperCurrent = current_step($applicant, $_examResult, $_interviewSlot, $_admissionResult);

// ----------------------------------------------------------------
// Interview data (needed when step = interview)
// ----------------------------------------------------------------
$interviewErrors = [];
$myEntry         = null;
$openSessions    = [];
$queuePosition   = null;

if ($_examResult) {
    // Load the student's booking with full slot details
    $stmt = $db->prepare(
        'SELECT q.*,
                s.slot_date, s.slot_time, s.end_time, s.capacity,
                u.name AS staff_name, u.desk_label, u.desk_notes
         FROM   interview_queue q
         JOIN   interview_slots s ON s.id = q.slot_id
         JOIN   users u           ON u.id = s.created_by
         WHERE  q.applicant_id = ?
         LIMIT 1'
    );
    $stmt->execute([$applicantId]);
    $myEntry = $stmt->fetch() ?: null;

    // POST actions — book or check in
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['interview_action'])) {
        csrf_check();
        $iAction = $_POST['interview_action'];

        if ($iAction === 'book') {
            $slotId = (int)($_POST['slot_id'] ?? 0);
            $db->beginTransaction();
            try {
                $stmt = $db->prepare(
                    'SELECT s.id, s.capacity, COUNT(q.id) AS booked
                     FROM   interview_slots s
                     LEFT JOIN interview_queue q ON q.slot_id = s.id
                     WHERE  s.id = ? AND s.status = "open"
                     GROUP BY s.id FOR UPDATE'
                );
                $stmt->execute([$slotId]);
                $slot = $stmt->fetch();

                if (!$slot || (int)$slot['booked'] >= (int)$slot['capacity']) {
                    $db->rollBack();
                    $interviewErrors[] = 'That session is no longer available or is full.';
                } else {
                    $db->prepare(
                        'INSERT INTO interview_queue (slot_id, applicant_id, status) VALUES (?, ?, "scheduled")'
                    )->execute([$slotId, $applicantId]);
                    $db->prepare(
                        'UPDATE applicants SET overall_status="interview" WHERE id=?'
                    )->execute([$applicantId]);
                    $db->commit();
                    Session::flash('success', 'Your interview session has been booked!');
                    redirect('/student/documents');
                }
            } catch (Throwable $e) {
                $db->rollBack();
                $interviewErrors[] = 'Booking failed. Please try again.';
            }
        }

        if ($iAction === 'checkin' && $myEntry && $myEntry['slot_date'] === date('Y-m-d') && $myEntry['status'] === 'scheduled') {
            $db->beginTransaction();
            try {
                $stmt = $db->prepare(
                    'SELECT COALESCE(MAX(q.queue_number), 0) + 1
                     FROM   interview_queue q
                     JOIN   interview_slots s ON s.id = q.slot_id
                     WHERE  s.slot_date = ? AND s.created_by = (
                         SELECT created_by FROM interview_slots WHERE id = ?
                     ) AND q.queue_number IS NOT NULL'
                );
                $stmt->execute([date('Y-m-d'), $myEntry['slot_id']]);
                $nextNum = (int)$stmt->fetchColumn();

                $db->prepare(
                    'UPDATE interview_queue
                     SET    status = "checked_in", queue_number = ?, checked_in_at = NOW()
                     WHERE  id = ? AND status = "scheduled"'
                )->execute([$nextNum, $myEntry['id']]);
                $db->commit();
                Session::flash('success', 'You are now in the queue!');
            } catch (Throwable $e) {
                $db->rollBack();
                $interviewErrors[] = 'Check-in failed. Please try again.';
            }
            // Reload
            $stmt = $db->prepare(
                'SELECT q.*, s.slot_date, s.slot_time, s.end_time, s.capacity,
                        u.name AS staff_name, u.desk_label, u.desk_notes
                 FROM   interview_queue q
                 JOIN   interview_slots s ON s.id = q.slot_id
                 JOIN   users u           ON u.id = s.created_by
                 WHERE  q.applicant_id = ? LIMIT 1'
            );
            $stmt->execute([$applicantId]);
            $myEntry = $stmt->fetch() ?: null;
        }
    }

    // Load open sessions if not booked yet
    if (!$myEntry) {
        $nowTime = date('H:i:s');
        $stmt = $db->prepare(
            'SELECT s.*, u.name AS staff_name, u.desk_label, u.desk_notes, COUNT(q.id) AS booked
             FROM   interview_slots s
             JOIN   users u ON u.id = s.created_by
             LEFT JOIN interview_queue q ON q.slot_id = s.id
             WHERE  s.slot_date >= ? AND s.status = "open"
               AND  NOT (s.slot_date = ? AND s.end_time IS NOT NULL AND s.end_time <= ?)
             GROUP BY s.id HAVING booked < s.capacity
             ORDER BY s.slot_date ASC, s.slot_time ASC'
        );
        $stmt->execute([date('Y-m-d'), date('Y-m-d'), $nowTime]);
        $openSessions = $stmt->fetchAll();
    }

    // Queue position
    if ($myEntry && $myEntry['status'] === 'checked_in') {
        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM interview_queue q
             JOIN   interview_slots s ON s.id = q.slot_id
             WHERE  s.slot_date = ? AND s.created_by = (
                 SELECT created_by FROM interview_slots WHERE id = ?
             ) AND q.status = "checked_in" AND q.queue_number < ?'
        );
        $stmt->execute([date('Y-m-d'), $myEntry['slot_id'], $myEntry['queue_number']]);
        $queuePosition = (int)$stmt->fetchColumn() + 1;
    }
}

// Build viewable files for modal
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

// ----------------------------------------------------------------
// View
// ----------------------------------------------------------------
ob_start();
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






<!-- Document list -->
<div style="display:flex;flex-direction:column;gap:var(--space-3)">
<?php foreach ($requiredDocs as $slug => $label):
    $doc    = $docRows[$slug] ?? null;
    $status = $doc['status'] ?? 'pending';
    $statusMap = [
        'pending'      => ['label' => 'Pending',      'class' => 'badge-pending'],
        'uploaded'     => ['label' => 'Uploaded',     'class' => 'badge-info'],
        'under_review' => ['label' => 'Under Review', 'class' => 'badge-warning'],
        'approved'     => ['label' => 'Approved',     'class' => 'badge-success'],
        'rejected'     => ['label' => 'Rejected',     'class' => 'badge-error'],
    ];
    $badge      = $statusMap[$status] ?? $statusMap['pending'];
    $canUpload  = $isSubmitted
        ? $status === 'rejected'
        : in_array($status, ['pending', 'rejected', 'uploaded'], true);
    $uploadLabel = $status === 'pending' ? 'Upload' : 'Replace';
    $isApproved = $status === 'approved';
?>
    <div class="card" style="padding:var(--space-4) var(--space-5)">
        <div style="display:flex;align-items:center;gap:var(--space-4)">

            <!-- Icon -->
            <div style="width:40px;height:40px;border-radius:var(--radius-md);background:var(--neutral-100);display:flex;align-items:center;justify-content:center;flex-shrink:0;<?= $isApproved ? 'background:var(--success-bg)' : '' ?>">
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
                    <div style="font-size:var(--text-sm);color:var(--error);margin-top:2px">
                        Staff note: <?= e($doc['staff_remarks']) ?>
                    </div>
                <?php elseif ($status === 'approved'): ?>
                    <div style="font-size:var(--text-sm);color:var(--text-tertiary);margin-top:2px">
                        Approved by admissions staff
                    </div>
                <?php endif; ?>
            </div>

            <!-- Status badge — hide when Replace is shown (redundant) -->
            <?php if (!($canUpload && $status !== 'pending')): ?>
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
            <?php if ($canUpload): ?>
                <button class="btn btn-secondary btn-sm"
                        onclick="openUploadModal('<?= $slug ?>', <?= htmlspecialchars(json_encode($label), ENT_QUOTES) ?>)">
                    <?= $uploadLabel ?>
                </button>
            <?php endif; ?>

        </div>
    </div>
<?php endforeach; ?>
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
                    <input type="file" name="doc_file" id="file-input" class="file-input"
                           accept=".pdf,.jpg,.jpeg,.png,.webp" style="display:none">
                    <div class="file-drop-content" id="drop-content">
                        <svg width="32" height="32" fill="none" viewBox="0 0 24 24" style="color:var(--text-tertiary);margin-bottom:var(--space-3)"><path stroke="currentColor" stroke-width="1.5" d="M4 16l4-4 4 4 4-8 4 4"/><path stroke="currentColor" stroke-width="1.5" stroke-linecap="round" d="M4 20h16"/></svg>
                        <p style="font-weight:var(--weight-medium)">Drop your file here</p>
                        <p style="font-size:var(--text-sm);color:var(--text-tertiary)">or <span style="color:var(--accent)">browse</span></p>
                        <p style="font-size:var(--text-xs);color:var(--text-tertiary);margin-top:var(--space-2)">PDF, JPG, PNG or WEBP · max 5 MB</p>
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

<script>
const UPLOAD_URL = '<?= url('/student/documents') ?>';
const CSRF_TOKEN = document.querySelector('#upload-form [name="_token"]')?.value ?? '';

function openUploadModal(slug, label) {
    document.getElementById('modal-slug').value = slug;
    document.getElementById('modal-doc-name').textContent = 'Upload: ' + label;
    // Reset drop zone
    document.getElementById('file-input').value = '';
    document.getElementById('drop-content').innerHTML =
        '<svg width="32" height="32" fill="none" viewBox="0 0 24 24" style="color:var(--text-tertiary);margin-bottom:var(--space-3)"><path stroke="currentColor" stroke-width="1.5" d="M4 16l4-4 4 4 4-8 4 4"/><path stroke="currentColor" stroke-width="1.5" stroke-linecap="round" d="M4 20h16"/></svg>' +
        '<p style="font-weight:var(--weight-medium)">Drop your file here</p>' +
        '<p style="font-size:var(--text-sm);color:var(--text-tertiary)">or <span style="color:var(--accent)">browse</span></p>' +
        '<p style="font-size:var(--text-xs);color:var(--text-tertiary);margin-top:var(--space-2)">PDF, JPG, PNG or WEBP · max 5 MB</p>';
    const modal = document.getElementById('upload-modal');
    modal.style.display = 'flex';
    modal.setAttribute('aria-hidden', 'false');
}

function closeUploadModal() {
    const modal = document.getElementById('upload-modal');
    modal.style.display = 'none';
    modal.setAttribute('aria-hidden', 'true');
}

function showResultModal(ok, title, msg) {
    const iconEl  = document.getElementById('result-icon');
    const titleEl = document.getElementById('result-title');
    const msgEl   = document.getElementById('result-msg');

    if (ok) {
        iconEl.style.background = 'var(--success-bg, #d1fae5)';
        iconEl.innerHTML = '<svg width="28" height="28" fill="none" viewBox="0 0 24 24" style="color:var(--success,#059669)"><path stroke="currentColor" stroke-width="2.5" stroke-linecap="round" d="M5 13l4 4L19 7"/></svg>';
    } else {
        iconEl.style.background = 'var(--error-bg, #fee2e2)';
        iconEl.innerHTML = '<svg width="28" height="28" fill="none" viewBox="0 0 24 24" style="color:var(--error,#dc2626)"><path stroke="currentColor" stroke-width="2.5" stroke-linecap="round" d="M6 18L18 6M6 6l12 12"/></svg>';
    }

    titleEl.textContent = title;
    msgEl.textContent   = msg;

    const modal = document.getElementById('result-modal');
    modal.style.display = 'flex';
    modal.setAttribute('aria-hidden', 'false');
}

function closeResultModal() {
    document.getElementById('result-modal').style.display = 'none';
    document.getElementById('result-modal').setAttribute('aria-hidden', 'true');
    // Reload page to reflect updated doc statuses
    window.location.reload();
}

async function submitUpload() {
    const fileInput = document.getElementById('file-input');
    if (!fileInput.files.length) {
        showResultModal(false, 'No file selected', 'Please choose a file before uploading.');
        closeUploadModal();
        return;
    }

    const btn = document.getElementById('upload-submit-btn');
    btn.disabled = true;
    btn.textContent = 'Uploading…';

    const formData = new FormData(document.getElementById('upload-form'));

    try {
        const res  = await fetch(UPLOAD_URL, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: formData,
        });
        const data = await res.json();
        closeUploadModal();
        if (data.ok) {
            showResultModal(true, 'Upload successful!', data.message);
        } else {
            showResultModal(false, 'Upload failed', data.message);
        }
    } catch (err) {
        closeUploadModal();
        showResultModal(false, 'Network error', 'Something went wrong. Please try again.');
    } finally {
        btn.disabled = false;
        btn.textContent = 'Upload';
    }
}

async function postAction(action) {
    document.getElementById('action-name').value = action;
    const fd = new FormData(document.getElementById('action-form'));
    const res = await fetch(UPLOAD_URL, {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: fd,
    });
    const text = await res.text();
    try { return JSON.parse(text); } catch (e) {
        console.error('Non-JSON response:', text);
        throw new Error('Invalid server response');
    }
}

async function submitApplication() {
    if (!confirm('Submit your application for staff review?\n\nYou can withdraw the submission if you need to make changes.')) return;
    try {
        const data = await postAction('submit_application');
        if (data.ok) {
            showResultModal(true, 'Application submitted!', 'Your documents are now under staff review.');
        } else {
            showResultModal(false, 'Could not submit', data.message);
        }
    } catch (err) {
        showResultModal(false, 'Error', err.message);
    }
}

async function withdrawSubmission() {
    if (!confirm('Withdraw your submission?\n\nYou can make changes and re-submit whenever you\'re ready.')) return;
    try {
        const data = await postAction('withdraw_submission');
        if (data.ok) {
            showResultModal(true, 'Submission withdrawn', 'You can make changes and re-submit when ready.');
        } else {
            showResultModal(false, 'Could not withdraw', data.message);
        }
    } catch (err) {
        showResultModal(false, 'Error', err.message);
    }
}

// Close upload modal on backdrop click
document.getElementById('upload-modal').addEventListener('click', function(e) {
    if (e.target === this) closeUploadModal();
});

// Drag-and-drop support
const dropZone = document.getElementById('drop-zone');
dropZone.addEventListener('dragover', e => { e.preventDefault(); dropZone.style.borderColor = 'var(--accent)'; });
dropZone.addEventListener('dragleave', () => { dropZone.style.borderColor = ''; });
dropZone.addEventListener('drop', e => {
    e.preventDefault();
    dropZone.style.borderColor = '';
    const files = e.dataTransfer?.files;
    if (files?.length) {
        document.getElementById('file-input').files = files;
        updateDropLabel(files[0].name);
    }
});

// Single click handler — open file picker once.
// We handle it here so app.js FileDropZone doesn't fire a second input.click().
dropZone.addEventListener('click', (e) => {
    if (e.target !== document.getElementById('file-input')) {
        e.stopPropagation();
        document.getElementById('file-input').click();
    }
});

// Show chosen filename
document.getElementById('file-input').addEventListener('change', function() {
    if (this.files[0]) updateDropLabel(this.files[0].name);
});

function updateDropLabel(name) {
    document.getElementById('drop-content').innerHTML =
        '<svg width="28" height="28" fill="none" viewBox="0 0 24 24" style="color:var(--accent);margin-bottom:var(--space-2)"><path stroke="currentColor" stroke-width="1.5" stroke-linecap="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414A1 1 0 0121 9.414V19a2 2 0 01-2 2z"/></svg>' +
        '<p style="font-weight:var(--weight-medium);color:var(--accent)">' + name + '</p>' +
        '<p style="font-size:var(--text-sm);color:var(--text-tertiary)">Ready to upload</p>';
}
</script>

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
            <button id="fv-prev" onclick="fvNavigate(-1)" type="button" style="
                display:flex;align-items:center;justify-content:center;
                width:32px;height:32px;border-radius:var(--radius-sm);
                border:1px solid var(--border);background:var(--bg);
                color:var(--text-secondary);cursor:pointer;flex-shrink:0;
                transition:background var(--transition-fast),color var(--transition-fast);
            " title="Previous (←)">
                <?= icon('ic_fluent_chevron_left_24_regular', 15) ?>
            </button>
            <div style="flex:1;min-width:0">
                <div id="fv-label" style="font-weight:var(--weight-semibold);font-size:var(--text-sm);white-space:nowrap;overflow:hidden;text-overflow:ellipsis"></div>
                <div id="fv-counter" style="font-size:var(--text-xs);color:var(--text-tertiary);margin-top:1px"></div>
            </div>
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
            <button onclick="fvZoom(-0.25)" type="button" class="fv-ctrl-btn" title="Zoom out (−)">
                <?= icon('ic_fluent_subtract_24_regular', 14) ?>
            </button>
            <span id="fv-zoom-label" style="font-size:var(--text-xs);color:var(--text-secondary);min-width:38px;text-align:center;font-variant-numeric:tabular-nums">100%</span>
            <button onclick="fvZoom(0.25)" type="button" class="fv-ctrl-btn" title="Zoom in (+)">
                <?= icon('ic_fluent_add_24_regular', 14) ?>
            </button>
            <button onclick="fvResetZoom()" type="button" class="fv-ctrl-btn" title="Reset zoom (0)">
                <svg width="14" height="14" fill="none" viewBox="0 0 24 24"><path stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M21 21l-4.35-4.35m0 0A7 7 0 105.65 5.65a7 7 0 0011 11.35z"/></svg>
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
                <img id="fv-img" src="" alt="Document preview" style="
                    max-width:100%;max-height:78vh;
                    border-radius:var(--radius-sm);
                    box-shadow:var(--shadow-md);
                    display:block;pointer-events:none;
                    user-select:none;-webkit-user-drag:none;
                ">
            </div>
            <div id="fv-hint" style="
                position:absolute;bottom:12px;left:50%;transform:translateX(-50%);
                background:rgba(0,0,0,0.55);color:#fff;
                font-size:var(--text-xs);padding:5px 14px;border-radius:var(--radius-full);
                pointer-events:none;opacity:0;transition:opacity .4s ease;white-space:nowrap;
            ">Scroll to zoom · Drag to pan when zoomed in</div>
        </div>
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
        document.getElementById('file-viewer-modal').addEventListener('click',function(e){
            if(e.target===this) closeFileViewer();
        });
    });

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

<!-- Submit / Withdraw panel -->
<?php if ($canSubmit): ?>
<div class="card" style="margin-top:var(--space-4);padding:var(--space-5);display:flex;align-items:center;gap:var(--space-4)">
    <div style="flex:1">
        <div style="font-weight:var(--weight-medium);color:var(--text-primary)">Ready to submit</div>
        <div style="font-size:var(--text-sm);color:var(--text-secondary);margin-top:2px">All documents uploaded. Submit your application for staff review.</div>
    </div>
    <button class="btn btn-primary" type="button" onclick="submitApplication()">Submit Application</button>
</div>
<?php elseif ($canWithdraw): ?>
<div class="card" style="margin-top:var(--space-4);padding:var(--space-5);display:flex;align-items:center;gap:var(--space-4)">
    <div style="flex:1">
        <div style="font-weight:var(--weight-medium);color:var(--text-primary)">Application submitted</div>
        <div style="font-size:var(--text-sm);color:var(--text-secondary);margin-top:2px">Your documents are awaiting staff review.</div>
    </div>
    <div style="text-align:right;flex-shrink:0">
        <button class="btn btn-ghost btn-sm" type="button" onclick="withdrawSubmission()">Withdraw Submission</button>
        <div style="font-size:var(--text-xs);color:var(--text-tertiary);margin-top:4px">You can re-submit after making changes.</div>
    </div>
</div>
<?php elseif (!$allUploaded && !$isSubmitted): ?>
<div class="card" style="margin-top:var(--space-4);padding:var(--space-5);display:flex;align-items:center;gap:var(--space-4);opacity:.6">
    <div style="flex:1">
        <div style="font-weight:var(--weight-medium);color:var(--text-primary)">Submit Application</div>
        <div style="font-size:var(--text-sm);color:var(--text-secondary);margin-top:2px">Upload all required documents to enable submission.</div>
    </div>
    <button class="btn btn-primary" disabled style="cursor:not-allowed">Submit Application</button>
</div>
<?php endif; ?>

<!-- Step navigation -->
<div class="step-nav">
    <span></span>
    <?php if ($allApproved && $_examResult): ?>
        <a href="<?= url('/student/interview') ?>" class="btn btn-primary">Interview →</a>
    <?php elseif ($allApproved && !$_examResult): ?>
        <a href="<?= url('/student/exam') ?>" class="btn btn-primary">Entrance Exam →</a>
    <?php else: ?>
        <span class="btn btn-primary" style="opacity:.4;cursor:not-allowed" title="All documents must be approved first">Entrance Exam →</span>
    <?php endif; ?>
</div>

<?php
$content     = ob_get_clean();
$pageTitle   = 'My Documents';
$activeNav   = 'documents';
$showStepper = true;
include VIEWS_PATH . '/layouts/app.php';