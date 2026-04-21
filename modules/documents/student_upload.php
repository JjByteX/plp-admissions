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
$applicantId = $applicant['id'];

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
// POST — handle file upload
// ----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $docSlug = trim($_POST['doc_slug'] ?? '');

    if (!array_key_exists($docSlug, $requiredDocs)) {
        $errors[] = 'Invalid document type.';
    }

    // Only allow re-upload if pending or rejected
    $currentStatus = $docRows[$docSlug]['status'] ?? 'pending';
    if (!in_array($currentStatus, ['pending', 'rejected'], true)) {
        $errors[] = 'This document has already been submitted and cannot be replaced.';
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
        } else {
            $filePath = 'uploads/' . $applicantId . '/' . $filename;

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

// ----------------------------------------------------------------
// View
// ----------------------------------------------------------------
ob_start();
?>

<?php foreach ($errors as $err): ?>
    <div class="alert alert-error" style="margin-bottom:var(--space-4)">
        <svg width="16" height="16" fill="none" viewBox="0 0 24 24"><path stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M12 9v4m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/></svg>
        <?= e($err) ?>
    </div>
<?php endforeach; ?>

<?php foreach ($success as $msg): ?>
    <div class="alert alert-success" style="margin-bottom:var(--space-4)">
        <svg width="16" height="16" fill="none" viewBox="0 0 24 24"><path stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        <?= e($msg) ?>
    </div>
<?php endforeach; ?>

<?php if ($allApproved && !$_examResult): ?>
    <div class="alert alert-success" style="margin-bottom:var(--space-6);display:flex;align-items:center;justify-content:space-between;gap:var(--space-4)">
        <div style="display:flex;align-items:center;gap:var(--space-2)">
            <svg width="16" height="16" fill="none" viewBox="0 0 24 24"><path stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            <span><strong>All documents approved!</strong> You may now proceed to the entrance exam.</span>
        </div>
        <a href="<?= url('/student/exam') ?>" class="btn btn-primary btn-sm" style="white-space:nowrap;flex-shrink:0">
            Take Entrance Exam →
        </a>
    </div>
<?php elseif ($allApproved && $_examResult): ?>

    <!-- Proceed to interview banner -->
    <div class="alert alert-success" style="display:flex;align-items:center;justify-content:space-between;gap:var(--space-4);margin-bottom:var(--space-6)">
        <span><strong>All documents approved</strong> and entrance exam completed. Proceed to your interview scheduling.</span>
        <a href="<?= url('/student/interview') ?>" class="btn btn-primary btn-sm" style="white-space:nowrap;flex-shrink:0">Interview →</a>
    </div>
<?php endif; ?>


<!-- Progress bar -->
<?php
$uploaded = count(array_filter($docRows, fn($d) => $d['status'] !== 'pending'));
$total    = count($requiredDocs);
$pct      = $total > 0 ? round(($uploaded / $total) * 100) : 0;
?>
<div style="margin-bottom:var(--space-6)">
    <div style="display:flex;justify-content:space-between;margin-bottom:var(--space-2)">
        <span style="font-size:var(--text-sm);font-weight:var(--weight-medium);color:var(--text-primary)"><?= $uploaded ?> of <?= $total ?> documents submitted</span>
        <span style="font-size:var(--text-sm);color:var(--text-tertiary)"><?= $pct ?>%</span>
    </div>
    <div style="height:6px;background:var(--neutral-200);border-radius:var(--radius-full);overflow:hidden">
        <div style="height:100%;width:<?= $pct ?>%;background:var(--accent);border-radius:var(--radius-full);transition:width .4s ease"></div>
    </div>
</div>

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
    $canUpload  = in_array($status, ['pending', 'rejected'], true);
    $isApproved = $status === 'approved';
?>
    <div class="card" style="padding:var(--space-4) var(--space-5)">
        <div style="display:flex;align-items:center;gap:var(--space-4)">

            <!-- Icon -->
            <div style="width:40px;height:40px;border-radius:var(--radius-md);background:var(--neutral-100);display:flex;align-items:center;justify-content:center;flex-shrink:0;<?= $isApproved ? 'background:var(--success-bg)' : '' ?>">
                <?php if ($isApproved): ?>
                    <svg width="18" height="18" fill="none" viewBox="0 0 24 24" style="color:var(--success)"><path stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                <?php else: ?>
                    <svg width="18" height="18" fill="none" viewBox="0 0 24 24" style="color:var(--text-tertiary)"><path stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414A1 1 0 0121 9.414V19a2 2 0 01-2 2z"/></svg>
                <?php endif; ?>
            </div>

            <!-- Info -->
            <div style="flex:1;min-width:0">
                <div style="font-weight:var(--weight-medium);color:var(--text-primary)"><?= e($label) ?></div>
                <?php if ($doc && $doc['staff_remarks']): ?>
                    <div style="font-size:var(--text-sm);color:var(--error);margin-top:2px">
                        Staff note: <?= e($doc['staff_remarks']) ?>
                    </div>
                <?php elseif ($doc && $doc['file_path']): ?>
                    <div style="font-size:var(--text-sm);color:var(--text-tertiary);margin-top:2px">
                        <?= $status === 'approved' ? 'Approved by admissions staff' : 'File uploaded — awaiting review' ?>
                    </div>
                <?php else: ?>
                    <div style="font-size:var(--text-sm);color:var(--text-tertiary);margin-top:2px">No file uploaded yet</div>
                <?php endif; ?>
            </div>

            <!-- Status badge -->
            <span class="badge <?= $badge['class'] ?>"><?= $badge['label'] ?></span>

            <!-- Upload button -->
            <?php if ($canUpload): ?>
                <button class="btn btn-secondary btn-sm"
                        onclick="openUploadModal('<?= $slug ?>', <?= htmlspecialchars(json_encode($label), ENT_QUOTES) ?>)">
                    <?= $status === 'rejected' ? 'Re-upload' : 'Upload' ?>
                </button>
            <?php elseif ($doc && $doc['file_path']): ?>
                <a href="<?= e(str_starts_with($doc['file_path'], 'http') ? $doc['file_path'] : url('/' . $doc['file_path'])) ?>" target="_blank"
                   class="btn btn-ghost btn-sm">View</a>
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
                <svg width="18" height="18" fill="none" viewBox="0 0 24 24"><path stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M6 18L18 6M6 6l12 12"/></svg>
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