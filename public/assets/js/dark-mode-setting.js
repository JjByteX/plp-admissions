(function () {
    var btn   = document.querySelector('[data-dark-mode-btn]');
    var label = document.querySelector('[data-dark-mode-label]');
    if (!btn || !label) return;

    function sync() {
        var dark = document.documentElement.dataset.theme === 'dark';
        label.textContent = dark ? 'Switch to Light' : 'Switch to Dark';
        btn.setAttribute('aria-pressed', dark ? 'true' : 'false');
    }

    btn.addEventListener('click', function () {
        window.Theme.toggle();
        var t = document.documentElement.dataset.theme;
        // keep the profile menu's dark mode pill in step
        document.querySelectorAll('.theme-pill').forEach(function (p) { p.dataset.theme = t; });
    });

    // Follows any change to the theme, including the one from the other tab / the page behind.
    new MutationObserver(sync).observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });
    sync();
})();
