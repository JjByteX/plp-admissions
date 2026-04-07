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
if (!$applicant) { redirect('/student/dashboard'); }
$applicantId = $applicant['id'];

// Fetch existing document rows
$stmt = $db->prepare('SELECT * FROM documents WHERE applicant_id = ?');
$stmt->execute([$applicantId]);
$docRows = array_column($stmt->fetchAll(), null, 'doc_type');

$requiredDocs = docs_for_type($applicant['applicant_type']);

$errors   = [];
$success  = [];

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
        $destDir  = UPLOAD_PATH . '/' . $applicantId . '/';

        if (!is_dir($destDir)) {
            mkdir($destDir, 0755, true);
        }

        $destPath = $destDir . $filename;

        if (!move_uploaded_file($tmpPath, $destPath)) {
            $errors[] = 'File upload failed. Please try again.';
        } else {
            $filePath = 'uploads/' . $applicantId . '/' . $filename;

            if (isset($docRows[$docSlug])) {
                // Delete old physical file if exists
                $oldFile = PUBLIC_PATH . '/' . $docRows[$docSlug]['file_path'];
                if ($docRows[$docSlug]['file_path'] && file_exists($oldFile)) {
                    @unlink($oldFile);
                }
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
}

// Count statuses
$statusCounts = array_count_values(array_column($docRows, 'status'));
$allApproved  = count($docRows) === count($requiredDocs)
    && ($statusCounts['approved'] ?? 0) === count($requiredDocs);

// ----------------------------------------------------------------
// View
// ----------------------------------------------------------------
ob_start();
?>

<div class="page-header">
    <div>
        <h1 class="page-title">My Documents</h1>
        <p class="page-description">Upload all required documents to advance your application.</p>
    </div>
</div>

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

<?php if ($allApproved): ?>
    <div class="alert alert-success" style="margin-bottom:var(--space-6)">
        <svg width="16" height="16" fill="none" viewBox="0 0 24 24"><path stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        <strong>All documents approved!</strong> You may now proceed to the entrance exam.
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
                        onclick="openUploadModal('<?= $slug ?>', <?= json_encode($label) ?>)">
                    <?= $status === 'rejected' ? 'Re-upload' : 'Upload' ?>
                </button>
            <?php elseif ($doc && $doc['file_path']): ?>
                <a href="<?= url('/' . $doc['file_path']) ?>" target="_blank"
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
        <form method="POST" action="<?= url('/student/documents') ?>" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="doc_slug" id="modal-slug">
            <div class="modal-body">
                <div class="file-drop-zone" id="drop-zone">
                    <input type="file" name="doc_file" id="file-input" class="file-input" accept=".pdf,.jpg,.jpeg,.png,.webp">
                    <div class="file-drop-content" id="drop-content">
                        <svg width="32" height="32" fill="none" viewBox="0 0 24 24" style="color:var(--text-tertiary);margin-bottom:var(--space-3)"><path stroke="currentColor" stroke-width="1.5" d="M4 16l4-4 4 4 4-8 4 4"/><path stroke="currentColor" stroke-width="1.5" stroke-linecap="round" d="M4 20h16"/></svg>
                        <p style="font-weight:var(--weight-medium)">Drop your file here</p>
                        <p style="font-size:var(--text-sm);color:var(--text-tertiary)">or <span style="color:var(--accent);cursor:pointer" onclick="document.getElementById('file-input').click()">browse</span></p>
                        <p style="font-size:var(--text-xs);color:var(--text-tertiary);margin-top:var(--space-2)">PDF, JPG, PNG or WEBP · max 5 MB</p>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-ghost" onclick="closeUploadModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">Upload</button>
            </div>
        </form>
    </div>
</div>

<script>
function openUploadModal(slug, label) {
    document.getElementById('modal-slug').value = slug;
    document.getElementById('modal-doc-name').textContent = 'Upload: ' + label;
    const modal = document.getElementById('upload-modal');
    modal.style.display = 'flex';
    modal.setAttribute('aria-hidden', 'false');
}
function closeUploadModal() {
    const modal = document.getElementById('upload-modal');
    modal.style.display = 'none';
    modal.setAttribute('aria-hidden', 'true');
}
document.getElementById('upload-modal').addEventListener('click', function(e) {
    if (e.target === this) closeUploadModal();
});
// Show chosen filename
document.getElementById('file-input').addEventListener('change', function() {
    const name = this.files[0]?.name;
    if (name) {
        document.getElementById('drop-content').innerHTML =
            '<p style="font-weight:var(--weight-medium);color:var(--accent)">' + name + '</p>' +
            '<p style="font-size:var(--text-sm);color:var(--text-tertiary)">Ready to upload</p>';
    }
});
</script>

<?php
$content     = ob_get_clean();
$pageTitle   = 'My Documents';
$activeNav   = 'documents';
$showStepper = true;
include VIEWS_PATH . '/layouts/app.php';