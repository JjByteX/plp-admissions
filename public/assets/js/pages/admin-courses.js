function openEditModal(id, name, strands, isActive, deptId) {
    document.getElementById('edit-course-id').value   = id;
    document.getElementById('edit-course-name').value = name;
    document.getElementById('edit-course-college').value = deptId ? String(deptId) : '';
    document.getElementById('edit-is-active').checked = !!isActive;
    document.querySelectorAll('.edit-strand-cb').forEach(cb => {
        cb.checked = Array.isArray(strands) && strands.includes(cb.value);
    });
    document.getElementById('edit-course-modal').style.display = 'flex';
}

// Inline row edit: one <tr> per course. Its inputs are tied to the row's own
// <form id="row-form-N"> (in the last cell) with the form="" attribute.
function courseRow(i) { return document.getElementById('course-row-' + i); }

function refreshCourseTable(tr) {
    var card = tr.closest('[data-scroll-table]');
    if (card) card.dispatchEvent(new Event('st:refresh'));
}

function startRowEdit(i) {
    var tr = courseRow(i);
    if (!tr) return;
    tr.classList.add('is-editing');
}

function cancelRowEdit(i) {
    var tr = courseRow(i);
    if (!tr) return;
    tr.classList.remove('is-editing');
    // Back to the saved values, including the live Low label.
    tr.querySelectorAll('input[form="row-form-' + i + '"]').forEach(function(inp) {
        inp.value = inp.defaultValue;
    });
    var low = tr.querySelector('.low-label');
    if (low && low.dataset.orig) low.textContent = low.dataset.orig;
    refreshCourseTable(tr);
}

function saveRowEdit(i) {
    var form = document.getElementById('row-form-' + i);
    if (!form) return;
    var capInput = document.querySelector('input[form="row-form-' + i + '"][name^="caps["]');
    if (capInput) {
        // Also submit caps via a separate fetch, then submit tiers form
        var capsData = new FormData();
        capsData.append('action', 'update_course_caps');
        // Add CSRF token
        var csrfInput = form.querySelector('input[name="_csrf"]');
        if (csrfInput) capsData.append('_csrf', csrfInput.value);
        capsData.append(capInput.name, capInput.value);
        fetch(window.location.pathname, { method: 'POST', body: capsData })
            .then(function() { form.submit(); })
            .catch(function() { form.submit(); });
    } else {
        form.submit();
    }
}

// Live constraints: avg < high; live-update Low band label.
(function() {
    document.addEventListener('input', function(e) {
        var el = e.target;
        if (!el.classList.contains('tier-high') && !el.classList.contains('tier-avg')) return;
        var rowIdx = el.dataset.row;
        var editRow = courseRow(rowIdx);
        if (!editRow) return;
        var highEl = editRow.querySelector('.tier-high');
        var avgEl  = editRow.querySelector('.tier-avg');
        var lowEl  = editRow.querySelector('.low-label');
        if (!highEl || !avgEl || !lowEl) return;
        var highVal = parseInt(highEl.value, 10) || 7;
        var avgVal  = parseInt(avgEl.value,  10) || 4;
        if (el.classList.contains('tier-high') && avgVal >= highVal) {
            avgEl.value = Math.max(1, highVal - 1);
        }
        if (el.classList.contains('tier-avg')  && avgVal >= highVal) {
            highEl.value = Math.min(10, avgVal + 1);
        }
        var finalAvg = parseInt(avgEl.value, 10) || 4;
        lowEl.textContent = finalAvg <= 1 ? '-' : '1–' + (finalAvg - 1);
    });
})();

// Close modals on backdrop click
['add-course-modal','edit-course-modal'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.addEventListener('click', function(e) {
        if (e.target === this) this.style.display = 'none';
    });
});
