/* ── Undo toast (bulk release) ──────────────────────────────── */
function showResUndoToast(msg, onCommit) {
    var existing = document.getElementById('res-undo-toast');
    if (existing) { existing._cancelFn && existing._cancelFn(); existing.remove(); }

    var DELAY = 6000;
    var toast = document.createElement('div');
    toast.id = 'res-undo-toast';
    toast.style.cssText = [
        'position:fixed;bottom:calc(var(--space-6) + 56px);left:50%;transform:translateX(-50%);',
        'z-index:600;background:var(--bg-elevated);border:1px solid var(--border);',
        'border-radius:var(--radius);box-shadow:var(--shadow-lg);',
        'padding:12px 20px;display:flex;align-items:center;gap:12px;',
        'font-size:var(--text-sm);min-width:320px;',
        'animation:resUndoSlideUp .25s ease'
    ].join('');

    // Progress bar
    var bar = document.createElement('div');
    bar.style.cssText = 'position:absolute;bottom:0;left:0;height:3px;background:var(--accent);border-radius:0 0 var(--radius) var(--radius);width:100%;transition:width ' + DELAY + 'ms linear';

    toast.innerHTML = '<span style="flex:1">' + msg + '</span>'
        + '<button id="res-undo-btn" class="btn btn-secondary btn-sm">Undo</button>'
        + '<button onclick="dismissResUndoToast()" class="btn btn-icon" aria-label="Dismiss">&times;</button>';
    toast.appendChild(bar);
    document.body.appendChild(toast);

    // Kick off the progress bar shrink
    requestAnimationFrame(function() { requestAnimationFrame(function() { bar.style.width = '0'; }); });

    var timer = setTimeout(function() {
        dismissResUndoToast();
        onCommit();
    }, DELAY);

    toast._cancelFn = function() { clearTimeout(timer); };

    document.getElementById('res-undo-btn').addEventListener('click', function() {
        dismissResUndoToast();
        resClearSelection();
    });
}

function dismissResUndoToast() {
    var t = document.getElementById('res-undo-toast');
    if (t) { t._cancelFn && t._cancelFn(); t.remove(); }
}

/* ── Single-release undo intercept ──────────────────────────── */
// Intercept individual "Release as Accept/Reject" form submits and
// show a short undo window before actually posting.
document.addEventListener('submit', function(e) {
    var form = e.target;
    if (!form || form.id === 'res-bulk-form') return;
    var actionInput = form.querySelector('input[name="action"]');
    if (!actionInput || actionInput.value !== 'release') return;

    e.preventDefault();

    var btn = form.querySelector('button[type="submit"]');
    var label = btn ? btn.textContent.trim() : 'Releasing…';

    showResUndoToast(label + ' - releasing…', function() { form.submit(); });
}, true);

function resGetSelectedIds() {
    return Array.from(document.querySelectorAll('.res-check:checked')).map(function(cb) { return cb.value; });
}

function resUpdateSelection() {
    var ids     = resGetSelectedIds();
    var count   = ids.length;
    var toolbar = document.getElementById('res-bulk-toolbar');
    var badge   = document.getElementById('res-bulk-count');
    var allCb   = document.getElementById('res-select-all');
    var total   = document.querySelectorAll('.res-check').length;

    badge.textContent     = count;
    toolbar.style.display = count > 0 ? 'flex' : 'none';
    if (allCb) {
        allCb.checked       = count > 0 && count === total;
        allCb.indeterminate = count > 0 && count < total;
    }

    document.querySelectorAll('.res-bulk-row').forEach(function(tr) {
        var cb = tr.querySelector('.res-check');
        if (cb && cb.checked) { tr.classList.add('res-selected'); }
        else { tr.classList.remove('res-selected'); }
    });
}

function resToggleAll(masterCb) {
    document.querySelectorAll('.res-check').forEach(function(cb) {
        cb.checked = masterCb.checked;
    });
    resUpdateSelection();
}

function resClearSelection() {
    var allCb = document.getElementById('res-select-all');
    if (allCb) allCb.checked = false;
    document.querySelectorAll('.res-check').forEach(function(cb) { cb.checked = false; });
    resUpdateSelection();
}

async function resBulkRelease(decision) {
    var ids = resGetSelectedIds();
    if (ids.length === 0) return;
    if (decision !== 'accepted' && decision !== 'rejected') return;

    // Tally how many rows match the chosen decision vs. how many will count
    // as overrides of the Professor's recommendation.
    var matchBucket = (decision === 'accepted') ? 'ready_accept' : 'ready_reject';
    var matches = 0, overrides = 0;
    ids.forEach(function(id) {
        var tr = document.querySelector('tr.res-bulk-row[data-id="' + id + '"]');
        if (!tr) return;
        if (tr.getAttribute('data-bucket') === matchBucket) matches++;
        else overrides++;
    });

    var label = (decision === 'accepted') ? 'Accepted' : 'Declined';
    var msg = 'Release ' + ids.length + ' selected applicant(s) as ' + label + '?\n\n'
            + '\u2022 ' + matches   + ' match the Professor\u2019s recommendation\n'
            + '\u2022 ' + overrides + ' will override the Professor\u2019s recommendation\n\n'
            + 'The applicants will be notified by email.';

    if (!await ui.confirm(msg, { danger: decision !== 'accepted', ok: 'Release' })) return;

    // If any rows override the recommendation, ask for a single shared
    // reason that gets recorded against every override in the audit log.
    var reason = '';
    if (overrides > 0) {
        reason = await ui.prompt(
            overrides + ' of these applicants will be released against the Professor\u2019s '
            + 'recommendation. Enter a reason for the override (recorded in the audit log):',
            { title: 'Override reason', ok: 'Continue' }
        );
        if (reason === null) return; // cancelled
        reason = reason.trim();
    }

    var form = document.getElementById('res-bulk-form');
    document.getElementById('res-bulk-action').value =
        (decision === 'accepted') ? 'bulk_accept' : 'bulk_reject';

    // Clear any previous dynamic inputs.
    form.querySelectorAll('input[name="ids[]"], input[name="reason"]').forEach(function(el) { el.remove(); });

    ids.forEach(function(id) {
        var input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'ids[]';
        input.value = id;
        form.appendChild(input);
    });
    if (reason) {
        var rInput = document.createElement('input');
        rInput.type = 'hidden';
        rInput.name = 'reason';
        rInput.value = reason;
        form.appendChild(rInput);
    }

    // Show undo toast — submits form after 6s unless Undo is clicked.
    showResUndoToast(
        'Releasing ' + ids.length + ' applicant(s) as ' + label + '\u2026',
        function() { form.submit(); }
    );
}

// ── Single-row override modal opener ────────────────────────
// Triggered when the releaser clicks Accept on a Recommended:Reject row
// (or Reject on a Recommended:Accept row). Demands a written reason.
function openReleaseOverrideModal(appId, name, decision, bucket) {
    var modal = document.getElementById('release-override-modal');
    if (!modal) return;
    var form  = document.getElementById('release-override-form');
    form.action = window.PAGE.resultsUrl + appId;
    document.getElementById('release-override-decision').value = decision;
    document.getElementById('release-override-name').textContent = name;

    var label = (decision === 'accepted')
        ? 'Release as \u201cAccepted\u201d (Professor recommended Decline)'
        : 'Release as \u201cDeclined\u201d (Professor recommended Accept)';
    document.getElementById('release-override-result-label').textContent = label;
    document.getElementById('release-override-reason').value = '';
    modal.style.display = 'flex';
}

// Click-outside-to-close for the override modal + close-admissions modal.
(function() {
    var m = document.getElementById('release-override-modal');
    if (m) m.addEventListener('click', function(e){ if(e.target===this) this.style.display='none'; });
    var c = document.getElementById('close-admissions-modal');
    if (c) c.addEventListener('click', function(e){ if(e.target===this) this.style.display='none'; });
})();

function openCloseAdmissionsModal() {
    var modal = document.getElementById('close-admissions-modal');
    if (!modal) return;
    document.getElementById('close-admissions-reason').value = '';
    modal.style.display = 'flex';
}
