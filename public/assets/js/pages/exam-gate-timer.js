(function() {
    const el = document.getElementById('gate-timer');
    if (!el) return;
    let s = parseInt(el.getAttribute('data-secs') || '0', 10);
    const iv = setInterval(() => {
        if (!el) { clearInterval(iv); return; }
        s--;
        el.textContent = s;
        if (s <= 0) {
            clearInterval(iv);
            location.reload(); // refresh to show expired state
        }
    }, 1000);
})();
