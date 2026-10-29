(function () {
    const POLL_INTERVAL = 30000;
    const statsArea = document.querySelector('.stat-grid');
    const pipelineArea = document.querySelector('.dashboard-grid');
    if (!statsArea && !pipelineArea) return;

    // Poll this same page by its path only. Never feed a full URL taken from the
    // address bar into a request: that is a client-side request forgery sink.
    function pollPath() {
        const path = window.location.pathname + window.location.search;
        return path.startsWith('/') && !path.startsWith('//') ? path : null;
    }

    function refreshArea(area, doc, selector) {
        const fresh = doc.querySelector(selector);
        if (fresh && area) area.innerHTML = fresh.innerHTML;
    }

    async function pollDashboard() {
        const path = pollPath();
        if (!path) return;
        try {
            const res = await fetch(path, {
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
            if (!res.ok) return;
            const doc = new DOMParser().parseFromString(await res.text(), 'text/html');
            refreshArea(statsArea, doc, '.stat-grid');
            refreshArea(pipelineArea, doc, '.dashboard-grid');
        } catch (_) {
            // Keep the numbers on screen; the next tick tries again.
        }
    }

    setInterval(function () { void pollDashboard(); }, POLL_INTERVAL);
})();
