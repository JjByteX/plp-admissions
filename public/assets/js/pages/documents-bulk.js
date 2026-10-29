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

async function bulkApproveSelected() {
    var ids = getSelectedIds();
    if (ids.length === 0) return;
    if (!await ui.confirm('Approve all pending documents for ' + ids.length + ' selected applicant(s)?\n\nAI-flagged documents are approved too and their category is confirmed. Applicants with all docs approved will advance to the exam stage.', { ok: 'Approve' })) return;

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
            (cells[1]?.textContent||'').trim(),
            (cells[1]?.querySelector('[title]')?.getAttribute('title')||'').trim(),
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

