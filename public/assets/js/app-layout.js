// Global layout behaviour for views/layouts/app.php. Server values come from
// the #layout-data JSON block (parsed in app-boot.js into window.__layoutData).
(function () {
    const DATA = window.__layoutData || {};
    const SESSION = DATA.session;

    // Inject accent from DB
    setAccentColor(DATA.accent || '');

    // Session timeout warning (signed-in, non-embedded pages only)
    if (SESSION && document.getElementById('session-timeout-modal')) {
    (function () {
        const WARN_BEFORE   = SESSION.warnBefore;                 // seconds before expiry to warn
        let secsRemaining   = SESSION.secsRemaining;              // seconds left right now
        let countdownTimer  = null;
        let warnTimer       = null;
        const modal         = document.getElementById('session-timeout-modal');
        const countdownEl   = document.getElementById('session-countdown');

        function fmtTime(s) {
            const m = Math.floor(s / 60), r = s % 60;
            return m > 0 ? `${m}m ${r}s` : `${r}s`;
        }

        function showWarning() {
            let secs = WARN_BEFORE;
            countdownEl.textContent = fmtTime(secs);
            modal.style.display = 'flex';
            countdownTimer = setInterval(() => {
                secs--;
                if (secs <= 0) {
                    clearInterval(countdownTimer);
                    window.location.href = DATA.loginUrl;
                } else {
                    countdownEl.textContent = fmtTime(secs);
                }
            }, 1000);
        }

        function scheduleWarning() {
            if (warnTimer) clearTimeout(warnTimer);
            const fireIn = Math.max(0, (secsRemaining - WARN_BEFORE) * 1000);
            warnTimer = setTimeout(showWarning, fireIn);
        }

        async function keepAlive() {
            modal.style.display = 'none';
            clearInterval(countdownTimer);
            try {
                const res  = await fetch(SESSION.keepaliveUrl, {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest',
                               'Content-Type': 'application/x-www-form-urlencoded' },
                    body: '_csrf=' + encodeURIComponent(SESSION.csrf)
                });
                const data = await res.json();
                secsRemaining = data.seconds_remaining ?? secsRemaining;
            } catch (e) { /* ignore */ }
            scheduleWarning();
        }

        function logOutNow() {
            window.location.href = DATA.logoutUrl;
        }

        // The buttons inside the modal use inline onclick="keepAlive()" /
        // onclick="logOutNow()", so the IIFE-scoped functions need to be
        // hoisted to the global scope or the clicks throw ReferenceError.
        window.keepAlive = keepAlive;
        window.logOutNow = logOutNow;

        // Expose so login page can trigger the "session expired" modal
        window.showTimeoutModal = function () {
            countdownEl.textContent = '0s';
            modal.style.display = 'flex';
            // Replace buttons with just a "Log In Again" button
            modal.querySelector('div[style*="flex-direction:column"]').innerHTML =
                `<a href="${DATA.loginUrl}" class="btn btn-primary" style="width:100%">Log In Again</a>`;
            modal.querySelector('#session-countdown').closest('div').textContent =
                'Your session expired due to inactivity. Please log in again.';
            modal.querySelector('div[style*="font-weight"]').textContent = 'Session Expired';
        };

        scheduleWarning();
    })();
    }

    // Global withdraw application modal (students with a withdrawable application)
    const withdrawModal = document.getElementById('withdraw-modal');
    if (withdrawModal) {
        withdrawModal.addEventListener('click', function(e) {
            if (e.target === this) this.style.display = 'none';
        });
    }

    // Logout confirmation (opened by any [data-logout] link)
    if (document.getElementById('logout-modal')) {
    (function () {
        const modal = document.getElementById('logout-modal');
        const confirmBtn = document.getElementById('logout-confirm-btn');
        const href = DATA.logoutUrl;
        function close() { modal.style.display = 'none'; }
        document.addEventListener('click', function (e) {
            const link = e.target.closest ? e.target.closest('[data-logout]') : null;
            if (!link) return;
            e.preventDefault();
            modal.style.display = 'flex';
            confirmBtn.focus(); // Enter confirms, Esc closes (global handler)
        });
        document.getElementById('logout-cancel-btn').addEventListener('click', close);
        modal.addEventListener('click', function (e) { if (e.target === modal) close(); });
        confirmBtn.addEventListener('click', function () {
            // Exam page: drop the browser answer copy only now, after confirming.
            // The server draft stays until submit or close.
            try {
                Object.keys(localStorage).forEach(function (k) {
                    if (k.indexOf('plp_exam_draft:') === 0) localStorage.removeItem(k);
                });
            } catch (err) {}
            window.location.href = href;
        });
    })();
    }

    // Inside the settings modal iframe
    if (document.body.classList.contains('is-embed')) {
    // Inside the settings modal: tell the page around us when something was saved
    // (it reloads on close so the name / branding / theme shown behind updates)
    // and forward Escape, since key presses in an iframe never reach the parent.
    (function () {
        if (window.parent === window) return;
        function tell(type) { try { window.parent.postMessage({ type: type }, location.origin); } catch (e) {} }
        if (document.querySelector('.alert-success')) tell('settings:saved');
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape') tell('settings:close'); });
    })();
    }

    // Global Escape-to-close for any open modal. Modals across the app
    // use the same .modal-backdrop wrapper toggled via inline display:
    // this listens once at the document level so every modal benefits
    // without per-page wiring.
    document.addEventListener('keydown', function(e) {
        if (e.key !== 'Escape') return;
        document.querySelectorAll('.modal-backdrop').forEach(function(m) {
            const visible = m.style.display && m.style.display !== 'none';
            if (visible) m.style.display = 'none';
        });
    });
})();
