// User manual page: the Jump to select, and the contents link that follows the section being read.
document.addEventListener('DOMContentLoaded', function () {
    var jump = document.getElementById('manual-jump');
    if (jump) {
        jump.addEventListener('change', function () {
            if (jump.value) window.location.hash = jump.value;
        });
    }

    var links = {};
    document.querySelectorAll('[data-manual-link]').forEach(function (a) {
        links[a.getAttribute('data-manual-link')] = a;
    });
    var sections = document.querySelectorAll('[data-manual-section]');
    if (!sections.length || !('IntersectionObserver' in window)) return;

    function setActive(id) {
        Object.keys(links).forEach(function (key) {
            links[key].classList.toggle('is-active', key === id);
        });
    }

    var visible = {};
    var observer = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) { visible[entry.target.id] = entry.isIntersecting; });
        for (var i = 0; i < sections.length; i++) {
            if (visible[sections[i].id]) { setActive(sections[i].id); break; }
        }
    }, { rootMargin: '0px 0px -60% 0px' });

    sections.forEach(function (s) { observer.observe(s); });
});
