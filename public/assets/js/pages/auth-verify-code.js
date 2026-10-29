(function () {
    // Auto-submit when the user pastes/types a 6-digit code.
    const input = document.getElementById('code');
    if (input) {
        input.addEventListener('input', function () {
            const v = (input.value || '').replace(/\D/g, '');
            if (v.length === 6) input.form.submit();
        });
    }
})();
