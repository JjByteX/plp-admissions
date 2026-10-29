var PAGE = window.PAGE || {};
window._fvCurrentDocId = 0;

PLPFileViewer.init({
    onRender: function (f) {
        var approveForm = document.getElementById('fv-approve-form');
        var resubmitBtn = document.getElementById('fv-resubmit-btn');
        var statusMsg   = document.getElementById('fv-status-msg');
        window._fvCurrentDocId = f.doc_id || 0;

        if (f.status === 'uploaded' || f.status === 'under_review') {
            approveForm.action = PAGE.docUrl + f.doc_id;
            approveForm.style.display = '';
            resubmitBtn.style.display = '';
            statusMsg.style.display = 'none';
        } else {
            approveForm.style.display = 'none';
            resubmitBtn.style.display = 'none';
            statusMsg.style.display = '';
            if (f.status === 'approved') {
                statusMsg.textContent = 'This document has been approved.';
            } else if (f.status === 'rejected') {
                statusMsg.textContent = 'Resubmission requested for this document.';
            } else {
                statusMsg.textContent = '';
                statusMsg.style.display = 'none';
            }
        }
    }
});

// Phase 6: Category OK / Change type (jQuery). jQuery loads after this block,
// so wait for DOMContentLoaded. The list is re-fetched after each action, so
// no full page reload.
document.addEventListener('DOMContentLoaded', function () {
    var $ = window.jQuery;
    if (!$) return;
    var actionUrl = PAGE.docUrl;
    var csrf = PAGE.csrf;

    function refresh(msg, ok) {
        $('#doc-review').load(window.location.href + ' #doc-review > *', function () {
            if (!msg) return;
            $('<div class="alert"></div>')
                .addClass(ok ? 'alert-success' : 'alert-error')
                .css('margin-bottom', 'var(--space-4)')
                .text(msg)
                .prependTo('#doc-review');
        });
    }
    function send($flag, data) {
        $flag.find('button, select').prop('disabled', true);
        $.ajax({
            url: actionUrl + $flag.data('doc'),
            method: 'POST',
            data: $.extend({ _csrf: csrf }, data),
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            dataType: 'json'
        }).done(function (r) {
            refresh(r.message, r.ok);
        }).fail(function () {
            refresh('Something went wrong. Please try again.', false);
        });
    }

    $('#doc-review').on('click', '.js-cat-ok', function () {
        send($(this).closest('.ai-flag'), { action: 'category_ok' });
    });
    $('#doc-review').on('click', '.js-change-type', function () {
        var $flag = $(this).closest('.ai-flag');
        var target = $flag.find('.js-target').val();
        if (!target) { $flag.find('.js-target').focus(); return; }
        send($flag, { action: 'change_type', target_slot: target });
    });
});

function openResubmitModal(docId) {
    document.getElementById('resubmit-form').action = PAGE.docUrl + docId;
    document.getElementById('resubmit-modal').style.display = 'flex';
}

// AJAX Approve All — fast, non-blocking
function approveAllAjax() {
    var btn = document.getElementById('approve-all-btn');
    if (!btn) return;
    btn.disabled = true;
    btn.textContent = 'Approving…';

    var fd = new FormData();
    fd.append('_csrf', PAGE.csrf);
    fd.append('action', 'approve_all');

    fetch(PAGE.applicantUrl, {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: fd
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.ok) {
            window._lastApprovedIds = data.ids || [];
            showUndoToast(data.message || 'All documents approved.');
        } else {
            ui.alert(data.message || 'Something went wrong.').then(function() { window.location.reload(); });
        }
    })
    .catch(function() { window.location.reload(); });
}

// Undo toast
function showUndoToast(msg) {
    var existing = document.getElementById('undo-toast');
    if (existing) existing.remove();

    var toast = document.createElement('div');
    toast.id = 'undo-toast';
    toast.style.cssText = 'position:fixed;bottom:24px;left:50%;transform:translateX(-50%);z-index:10000;background:var(--bg-elevated);border:1px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow-lg);padding:12px 20px;display:flex;align-items:center;gap:12px;font-size:var(--text-sm);min-width:320px;animation:slideUp .25s ease';
    toast.innerHTML = '<span style="flex:1">' + msg + '</span>'
        + '<button onclick="undoApproveAll()" class="btn btn-secondary btn-sm">Undo</button>'
        + '<button onclick="dismissToast()" class="btn btn-icon" aria-label="Dismiss">&times;</button>';
    document.body.appendChild(toast);

    window._undoToastTimer = setTimeout(function() {
        dismissToast();
        window.location.reload();
    }, 6000);
}

function dismissToast() {
    clearTimeout(window._undoToastTimer);
    var t = document.getElementById('undo-toast');
    if (t) t.remove();
}

function undoApproveAll() {
    dismissToast();
    var fd = new FormData();
    fd.append('_csrf', PAGE.csrf);
    fd.append('action', 'undo_approve_all');
    (window._lastApprovedIds || []).forEach(function(id) { fd.append('doc_ids[]', id); });

    fetch(PAGE.applicantUrl, {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: fd
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data && data.ok === false) return ui.alert(data.message || 'Could not undo.').then(function() { window.location.reload(); });
        window.location.reload();
    })
    .catch(function() { window.location.reload(); });
}
