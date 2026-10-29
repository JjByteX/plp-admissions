// ── Date filter on the session card grid ─────────────────────
// Mirrors the interview queue's date dropdown. Pure client-side:
// hide cards whose data-date doesn't match the selected value, then
// rebuild the "N sessions" badge so it always reflects what's visible.
(function() {
    const dateEl  = document.getElementById('sess-date-filter');
    const countEl = document.getElementById('sess-count');
    if (!dateEl) return;

    function applySessDateFilter() {
        const picked  = dateEl.value;
        let visible = 0;
        document.querySelectorAll('.sess-dir-grid .sess-dir-card').forEach(function(card) {
            const d = card.dataset.date || '';
            const match = !picked || d === picked;
            card.style.display = match ? '' : 'none';
            if (match) visible++;
        });
        if (countEl) {
            countEl.textContent = visible + ' session' + (visible === 1 ? '' : 's');
        }
    }
    dateEl.addEventListener('change', applySessDateFilter);
})();

// ── Bulk select mode for session cards ───────────────────────
// ── Bulk select mode for interview session cards ──────────────
// Shared "select mode" logic lives in createCardBulkSelector()
// (public/assets/js/app.js), which loads after this inline block.
// _sessSelector() builds it lazily on first call instead of at parse
// time, so load order doesn't matter. These wrappers keep the same
// global names the markup already calls via onclick=, so no HTML
// changed.
let __sessSelectorInstance = null;
function _sessSelector() {
    if (!__sessSelectorInstance) {
        __sessSelectorInstance = createCardBulkSelector({
            gridSel:     '.sess-dir-grid',
            cardSel:     '.sess-dir-card',
            checkboxSel: '.sess-select-checkbox',
            barId:       'sess-bulk-bar',
            toggleBtnId: 'sess-select-toggle',
            countId:     'sess-bulk-count',
            deleteBtnId: 'sess-bulk-delete-btn',
            idsInputId:  'sess-bulk-ids',
            confirmMsg:  function (n) { return 'Remove ' + n + ' session(s)? This cannot be undone.'; },
        });
    }
    return __sessSelectorInstance;
}
function _sessGrid()              { return document.querySelector('.sess-dir-grid'); }
function toggleSessSelectMode()   { _sessSelector().toggleSelectMode(); }
function cancelSessSelectMode()   { _sessSelector().cancelSelectMode(); }
function onSessCheckboxChange(cb) { _sessSelector().onCheckboxChange(cb); }
function confirmSessBulkDelete(b) { return _sessSelector().confirmBulkDelete(b); }
function handleSessCardClick(event, payload) {
    const grid = _sessGrid();
    if (grid && grid.classList.contains('is-selecting')) {
        // In select mode the card toggles its own checkbox instead of opening
        // the edit modal. Disabled (booked) cards do nothing.
        const cb = event.currentTarget.querySelector('.sess-select-checkbox');
        if (cb && !cb.disabled) {
            cb.checked = !cb.checked;
            onSessCheckboxChange(cb);
        }
        return;
    }
    openEditSession(payload);
}

// ── Add modal: switch between Single and Recurring fields ─────
function setAddMode(mode) {
    const single   = document.getElementById('add-single-fields');
    const batch    = document.getElementById('add-batch-fields');
    const btnS     = document.getElementById('add-mode-single');
    const btnB     = document.getElementById('add-mode-batch');
    const action   = document.getElementById('add-action');
    const submit   = document.getElementById('add-submit');

    const isBatch = mode === 'batch';
    single.style.display = isBatch ? 'none' : 'flex';
    batch.style.display  = isBatch ? 'flex' : 'none';
    btnS.classList.toggle('active', !isBatch);
    btnB.classList.toggle('active',  isBatch);
    action.value = isBatch ? 'batch_create' : 'add_session';
    submit.textContent = isBatch ? 'Create Sessions' : 'Add Session';

    // Disable inputs in the hidden block so they don't block submission
    // (and so capacity from the wrong block doesn't get sent).
    document.querySelectorAll('#add-single-fields [name]').forEach(function(el){ el.disabled = isBatch; });
    document.querySelectorAll('#add-batch-fields [name]').forEach(function(el){ el.disabled = !isBatch; });

    // Capacity in the batch block is named `capacity_batch` to avoid
    // colliding with the single field; rewrite it on submit so the
    // server still receives `capacity`.
    const capBatch = document.querySelector('#add-batch-fields [name="capacity_batch"], #add-batch-fields [name="capacity"]');
    if (capBatch) capBatch.name = isBatch ? 'capacity' : 'capacity_batch';
}
// Initialize on first render
document.addEventListener('DOMContentLoaded', function(){ setAddMode('single'); });

// ── Edit modal: open + prefill ────────────────────────────────
function openEditSession(data) {
    document.getElementById('edit-sess-id').value      = data.id;
    document.getElementById('edit-sess-date').value    = data.slot_date;
    document.getElementById('edit-sess-time').value    = data.slot_time;
    document.getElementById('edit-sess-end').value     = data.end_time;
    document.getElementById('edit-sess-cap').value     = data.capacity;
    document.getElementById('edit-sess-label').value   = data.location_label || '';
    document.getElementById('edit-sess-notes').value   = data.location_notes || '';
    const sel = document.getElementById('edit-sess-assigned');
    if (sel) sel.value = data.assigned_to || '';

    // Close vs Reopen label
    const toggle = document.getElementById('edit-sess-toggle');
    if (data.status === 'closed') {
        toggle.textContent = 'Reopen session';
        toggle.dataset.next = 'open_slot';
    } else {
        toggle.textContent = 'Close session';
        toggle.dataset.next = 'close_slot';
    }

    // Disable Remove if there are bookings
    const del = document.getElementById('edit-sess-delete');
    const info = document.getElementById('edit-sess-bookinfo');
    if (data.booked > 0) {
        del.disabled = true;
        del.style.opacity = '.45';
        del.title = 'Cannot remove a session with bookings';
        info.textContent = data.booked + ' applicant(s) booked - Remove disabled.';
    } else {
        del.disabled = false;
        del.style.opacity = '';
        del.title = '';
        info.textContent = '';
    }

    document.getElementById('edit-session-modal').style.display = 'flex';
}

function submitSessionAction(actionName) {
    const slotId = document.getElementById('edit-sess-id').value;
    if (!slotId || !actionName) return;
    document.getElementById('edit-action-name').value = actionName;
    document.getElementById('edit-action-id').value   = slotId;
    document.getElementById('edit-action-form').submit();
}

// Click outside the modal closes it
['add-session-modal','edit-session-modal'].forEach(function(id){
    const m = document.getElementById(id);
    if(m) m.addEventListener('click', function(e){ if(e.target===this) this.style.display='none'; });
});
