(function () {
    // Resend countdown (only when a wait is running). The seconds left come
    // from data-resend-in on the button.
    var btn   = document.getElementById('resend-btn');
    var label = document.getElementById('resend-label');
    if (!btn || !label) return;
    var left  = parseInt(btn.getAttribute('data-resend-in') || '0', 10);
    if (left <= 0) return;
    btn.disabled = true;
    var t = setInterval(function () {
        left -= 1;
        if (left <= 0) {
            clearInterval(t);
            btn.disabled = false;
            label.textContent = 'Resend code';
        } else {
            label.textContent = 'Resend in ' + left + 's';
        }
    }, 1000);
})();
