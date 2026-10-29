(function () {
    const drawer  = document.getElementById('apDrawer');
    const backdrop= document.getElementById('apDrawerBackdrop');
    const body    = document.getElementById('apDrawerBody');
    // Server URLs come from data attributes on the drawer.
    const PRINT_URL = drawer.dataset.printUrl;
    const PANEL_URL = drawer.dataset.panelUrl;

    // Public open / close API ------------------------------------
    window.openApplicantPanel = function (id) {
        if (!id) return;
        drawer.classList.add('is-open');
        backdrop.classList.add('is-open');
        drawer.setAttribute('aria-hidden', 'false');
        document.body.classList.add('ap-drawer-locked');
        body.innerHTML = '<div class="ap-drawer-loading">Loading…</div>';
        // Runs on click, after jQuery has loaded. Link exists for SSO/Admin only.
        $('#apDrawerPrint').attr('href', PRINT_URL + encodeURIComponent(id));

        fetch(PANEL_URL + '?id=' + encodeURIComponent(id), {
            credentials: 'same-origin',
            headers: { 'Accept': 'text/html' }
        })
            .then(function (r) {
                if (!r.ok) throw new Error('HTTP ' + r.status);
                return r.text();
            })
            .then(function (html) { body.innerHTML = html; })
            .catch(function (err) {
                body.innerHTML =
                    '<div class="ap-drawer-error">'
                    + 'Could not load applicant detail. '
                    + '<button type="button" class="btn btn-secondary btn-sm" '
                    + 'onclick="openApplicantPanel(' + (Math.trunc(Number(id)) || 0) + ')">Retry</button>'
                    + '</div>';
            });
    };

    window.closeApplicantPanel = function () {
        drawer.classList.remove('is-open');
        backdrop.classList.remove('is-open');
        drawer.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('ap-drawer-locked');
    };

    // Auto-wire data-applicant-panel triggers --------------------
    document.addEventListener('click', function (ev) {
        const t = ev.target.closest('[data-applicant-panel]');
        if (!t) return;
        ev.preventDefault();
        window.openApplicantPanel(Number.parseInt(t.dataset.applicantPanel, 10));
    });

    // ESC closes
    document.addEventListener('keydown', function (ev) {
        if (ev.key === 'Escape' && drawer.classList.contains('is-open')) {
            window.closeApplicantPanel();
        }
    });

    // Confirm helper used by the inline Pass/Reject form -----------
    // Dialogs are async: block this submit, ask, then submit again with a flag.
    window.apEvalConfirm = function (form) {
        if (form.dataset.apOk) { delete form.dataset.apOk; return true; }
        const r = (form.querySelector('.ap-eval-input') || {}).value || '';
        if (!r) {
            void ui.alert('Please choose Pass or Decline.');
            return false;
        }
        if (r === 'pass' || r === 'reject') {
            void ui.confirm('Submit this evaluation as ' + r.toUpperCase() + '?', { danger: r === 'reject', ok: 'Submit' })
                .then(function (ok) { if (ok) { form.dataset.apOk = '1'; form.requestSubmit(); } });
            return false;
        }
        return true;
    };
})();
