// Show-password checkbox for the Change Password forms (staff, student, admin).
// A .js-show-password checkbox reveals or hides every password field in its form.
document.querySelectorAll('.js-show-password').forEach(function (box) {
    box.addEventListener('change', function () {
        var form = box.closest('form');
        if (!form) return;
        form.querySelectorAll('input[name$="password"]').forEach(function (input) {
            if (input.name === 'action') return;
            input.type = box.checked ? 'text' : 'password';
        });
    });
});
