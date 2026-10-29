const UPLOAD_URL = window.PAGE.uploadUrl;
const CSRF_TOKEN = document.querySelector('#upload-form [name="_token"]')?.value ?? '';

function openUploadModal(slug, label) {
    document.getElementById('modal-slug').value = slug;
    document.getElementById('modal-doc-name').textContent = 'Upload: ' + label;
    // Reset drop zone
    document.getElementById('file-input').value = '';
    document.getElementById('drop-content').innerHTML =
        '<i class="ph-fill ph-image" aria-hidden="true" style="font-size:var(--text-icon-lg);color:var(--text-tertiary);margin-bottom:var(--space-3)"></i>' +
        '<p style="font-weight:var(--weight-medium)">Drop your file here</p>' +
        '<p style="font-size:var(--text-sm);color:var(--text-tertiary)">or <span style="color:var(--accent)">browse</span></p>' +
        '<p style="font-size:var(--text-xs);color:var(--text-tertiary);margin-top:var(--space-2)">PDF, JPG, PNG or WEBP · max 4 MB</p>';
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
        iconEl.innerHTML = '<i class="ph-fill ph-check" aria-hidden="true" style="font-size:var(--text-icon);color:var(--success)"></i>';
    } else {
        iconEl.style.background = 'var(--error-bg, #fee2e2)';
        iconEl.innerHTML = '<i class="ph-fill ph-x" aria-hidden="true" style="font-size:var(--text-icon);color:var(--error)"></i>';
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
            window.location.reload();
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
    if (!await ui.confirm('Submit your application for staff review?\n\nYou can withdraw the submission if you need to make changes.', { ok: 'Submit' })) return;
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
    if (!await ui.confirm('Withdraw your submission?\n\nYou can make changes and re-submit whenever you\'re ready.', { danger: true, ok: 'Withdraw' })) return;
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
        '<i class="ph-fill ph-file-text" aria-hidden="true" style="font-size:var(--text-icon);color:var(--accent);margin-bottom:var(--space-2)"></i>' +
        '<p style="font-weight:var(--weight-medium);color:var(--accent)">' + name + '</p>' +
        '<p style="font-size:var(--text-sm);color:var(--text-tertiary)">Ready to upload</p>';
}
