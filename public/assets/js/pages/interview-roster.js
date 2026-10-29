// When the "Absent" checkbox is toggled on:
//   - uncheck + disable the Pass/Reject radios on that row
// When toggled off:
//   - re-enable the radios (staff will still need to pick one)
document.querySelectorAll('.js-absent-toggle').forEach(function (cb) {
    cb.addEventListener('change', function () {
        const row = cb.closest('tr');
        row.querySelectorAll('.js-result-radio').forEach(function (r) {
            if (cb.checked) {
                r.checked = false;
                r.disabled = true;
            } else {
                r.disabled = false;
            }
        });
    });
});

// ------------------------------------------------------------
// Pager — search + status filter + auto page size, all client-side.
// Same math as AutoPageSize (app.js): rows are a fixed height
// (--height-table-row / --height-table-header), so the number that
// fit is worked out from the wrap's height alone:
//   rows = floor((wrap - toolbar - header - footer - 2px border) / row)
// Rows that are filtered out or on another page are display:none,
// never removed, so the form still submits every one of them.
// ------------------------------------------------------------
(function () {
    var wrap      = document.getElementById('eval-form');
    var toolbar   = document.getElementById('sv-toolbar');
    var searchEl  = document.getElementById('sv-search');
    var statusEl  = document.getElementById('sv-status');
    var clearEl   = document.getElementById('sv-clear');
    var emptyEl   = document.getElementById('sv-empty');
    var pagerEl   = document.getElementById('sv-pagination');
    var infoEl    = document.getElementById('sv-info');
    var pageEl    = document.getElementById('sv-page');
    var pagesEl   = document.getElementById('sv-pages');
    var prevEl    = document.getElementById('sv-prev');
    var nextEl    = document.getElementById('sv-next');
    var allRows   = Array.prototype.slice.call(document.querySelectorAll('#sv-table tbody tr'));
    var page      = 1;
    var perPage   = 10;

    function cssPx(name, fallback) {
        var raw = getComputedStyle(document.documentElement).getPropertyValue(name).trim();
        var n = parseInt(raw, 10);
        return isNaN(n) ? fallback : n;
    }

    function computePerPage() {
        var avail   = wrap.getBoundingClientRect().height;
        var toolH   = toolbar.getBoundingClientRect().height;
        var rowH    = cssPx('--height-table-row', 56);
        var headerH = cssPx('--height-table-header', 40);
        var footerH = cssPx('--control-height', 36) + 8; // pagination bar + 8px gap
        var rows    = Math.floor((avail - toolH - headerH - footerH - 2) / rowH);
        return Math.max(3, Math.min(100, rows));
    }

    function matching() {
        var term   = searchEl.value.toLowerCase().trim();
        var status = statusEl.value;
        return allRows.filter(function (tr) {
            return (!term   || (tr.dataset.search || '').indexOf(term) !== -1)
                && (!status || tr.dataset.status === status);
        });
    }

    function render() {
        var list  = matching();
        var total = list.length;
        var pages = Math.max(1, Math.ceil(total / perPage));
        if (page > pages) page = pages;
        if (page < 1) page = 1;
        var from = (page - 1) * perPage;
        var to   = Math.min(from + perPage, total);

        var shown = {};
        list.slice(from, to).forEach(function (tr) { shown[tr.dataset.queueId] = true; });
        allRows.forEach(function (tr) {
            tr.style.display = shown[tr.dataset.queueId] ? '' : 'none';
        });

        emptyEl.style.display = total === 0 ? '' : 'none';
        clearEl.style.display = (searchEl.value.trim() || statusEl.value) ? '' : 'none';

        // Bar only when there is more than one page; its 40px is
        // reserved in computePerPage() either way.
        pagerEl.style.display = pages > 1 ? '' : 'none';
        infoEl.innerHTML = 'Showing <strong>' + (total ? from + 1 : 0) + '–' + to
                         + '</strong> of <strong>' + total + '</strong> students';
        pageEl.textContent  = page;
        pagesEl.textContent = pages;
        prevEl.setAttribute('aria-disabled', page > 1 ? 'false' : 'true');
        nextEl.setAttribute('aria-disabled', page < pages ? 'false' : 'true');
    }

    // Re-measure. Keeps the first row that was on screen on screen, so a
    // resize does not throw the reader to a different part of the list.
    function remeasure() {
        var next = computePerPage();
        if (next === perPage) return;
        var firstIndex = (page - 1) * perPage;
        perPage = next;
        page = Math.floor(firstIndex / perPage) + 1;
        render();
    }

    function go(p) { page = p; render(); }
    prevEl.addEventListener('click', function () { if (page > 1) go(page - 1); });
    nextEl.addEventListener('click', function () {
        if (page < Math.ceil(matching().length / perPage)) go(page + 1);
    });

    searchEl.addEventListener('input', function () { page = 1; render(); });
    // Enter in the search box must not submit the evaluation form.
    searchEl.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') e.preventDefault();
    });
    statusEl.addEventListener('change', function () { page = 1; render(); });
    clearEl.addEventListener('click', function () {
        searchEl.value = '';
        statusEl.value = '';
        page = 1;
        render();
    });

    // Window / sidebar resizes and the toolbar wrapping onto a second
    // line change the wrap's size; a Settings > font size change moves
    // the row-height vars without resizing anything, so watch that too.
    var timer = null;
    function schedule() { clearTimeout(timer); timer = setTimeout(remeasure, 100); }
    new ResizeObserver(schedule).observe(wrap);
    new ResizeObserver(schedule).observe(toolbar);
    new MutationObserver(schedule).observe(document.documentElement,
        { attributes: true, attributeFilter: ['data-font-size'] });

    perPage = computePerPage();
    render();

    // Exposed for the submit check below: jump to the page holding a row.
    window.svShowRow = function (tr) {
        searchEl.value = '';
        statusEl.value = '';
        var idx = allRows.indexOf(tr);
        page = Math.floor(idx / perPage) + 1;
        render();
    };
})();

// Client-side validation mirrors the server rule — every non-absent
// student must have a Pass/Reject selected. It checks ALL rows, not just
// the page on screen, and jumps to the first one that is missing.
document.getElementById('eval-form').addEventListener('submit', function (evt) {
    const missing = [];
    let firstRow = null;
    document.querySelectorAll('tr[data-queue-id]').forEach(function (tr) {
        const absent = tr.querySelector('.js-absent-toggle');
        if (absent && absent.checked) return;
        const radios = tr.querySelectorAll('.js-result-radio');
        const checked = Array.from(radios).some(function (r) { return r.checked; });
        if (!checked && radios.length > 0 && !radios[0].disabled) {
            missing.push(tr.dataset.student);
            if (!firstRow) firstRow = tr;
        }
    });
    if (missing.length > 0) {
        evt.preventDefault();
        if (firstRow && window.svShowRow) window.svShowRow(firstRow);
        ui.alert('Please select Pass or Decline for every present student:\n\n' + missing.join('\n'));
    }
});
