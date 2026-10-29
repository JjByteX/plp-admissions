(function () {
    var pw = document.getElementById('password');
    var pc = document.getElementById('password_confirm');
    var hint = document.getElementById('pw-hint');
    var msg = document.getElementById('match-msg');
    var btn = document.getElementById('reset-btn');

    function update() {
        var long = pw.value.length >= 8;
        hint.textContent = long ? 'Length looks good.' : 'At least 8 characters (' + pw.value.length + '/8).';
        hint.classList.toggle('ok', long);

        var match = pc.value.length > 0 && pw.value === pc.value;
        if (pc.value.length === 0) {
            msg.textContent = '';
            msg.classList.remove('ok', 'bad');
        } else if (match) {
            msg.textContent = 'Passwords match';
            msg.classList.add('ok');
            msg.classList.remove('bad');
        } else {
            msg.textContent = 'Passwords do not match';
            msg.classList.add('bad');
            msg.classList.remove('ok');
        }
        btn.disabled = !(long && match);
    }
    pw.addEventListener('input', update);
    pc.addEventListener('input', update);
    update();
})();
