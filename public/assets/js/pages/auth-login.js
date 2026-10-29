(function () {
    // Carry the Remember me tick through the Google redirect
    var g = document.getElementById('auth-google'), rem = document.getElementById('remember');
    if (g && rem) {
        g.addEventListener('click', function () {
            g.href = g.href.split('?')[0] + (rem.checked ? '?remember=1' : '');
        });
    }
})();
