(function () {
    const m = document.getElementById('audit-modal');
    if (!m) return;
    function $(id) { return document.getElementById(id); }
    function setBadge(el, text, cls) {
        if (!el) return;
        el.textContent = text;
        el.className = 'badge ' + cls;
    }
    function openDetail(d) {
        $('ad-when').textContent = d.when;
        if ($('ad-user')) {
            $('ad-user').textContent = d.user;
            setBadge($('ad-role'), d.role, 'badge-' + d.roleBadge);
        }
        setBadge($('ad-action'), d.action, d.actionBadge);
        $('ad-entity').textContent = d.entity;
        $('ad-desc').textContent = d.description;
        $('ad-id').textContent = '#' + d.id;
        m.style.display = 'flex';
    }
    function closeDetail() { m.style.display = 'none'; }

    document.querySelectorAll('.audit-view').forEach(function (b) {
        b.addEventListener('click', function () {
            try { openDetail(JSON.parse(b.dataset.log)); } catch (e) {}
        });
    });
    m.querySelectorAll('[data-audit-close]').forEach(function (b) { b.addEventListener('click', closeDetail); });
    m.addEventListener('click', function (e) { if (e.target === m) closeDetail(); });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && m.style.display !== 'none') closeDetail();
    });
})();
