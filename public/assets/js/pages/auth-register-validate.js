// jQuery loads after page content, so wait for DOMContentLoaded.
document.addEventListener('DOMContentLoaded', function () {
    var $ = window.jQuery;
    if (!$) return;
    // Show only the ticks that fit the applicant type; clear hidden ones.
    function syncFlags() {
        var type = $('#applicant_type').val();
        $('#doc-flags').toggle(!!type);
        $('#doc-flags [data-types]').each(function () {
            var show = String($(this).data('types')).split(' ').indexOf(type) !== -1;
            $(this).toggle(show);
            if (!show) $(this).find('input').prop('checked', false);
        });
    }
    $('#applicant_type').on('change', syncFlags);
    syncFlags();

    // Live password checks (same rules as the server): 8+ chars, and both match.
    // The layout wraps each password input in .auth-pw (show/hide button), so the
    // message goes after that wrapper.
    function pwMsg($in, msg) {
        var $box = $in.closest('.auth-pw');
        if (!$box.length) $box = $in;
        $box.siblings('.form-error').remove();
        $in.toggleClass('error', !!msg);
        if (msg) $('<span class="form-error"></span>').text(msg).insertAfter($box);
    }
    function checkPw() { pwMsg($('#password'), $('#password').val().length < 8 ? 'Password must be at least 8 characters.' : ''); }
    function checkConfirm() { pwMsg($('#password_confirm'), $('#password_confirm').val() !== $('#password').val() ? 'Passwords do not match.' : ''); }
    $('#password').on('blur', function () {
        checkPw();
        if ($('#password_confirm').val() !== '') checkConfirm();
    }).on('input', function () {
        if ($(this).hasClass('error')) checkPw();
        if ($('#password_confirm').val() !== '') checkConfirm();
    });
    $('#password_confirm').on('blur', checkConfirm).on('input', function () {
        if ($(this).hasClass('error')) checkConfirm();
    });

    // Live phone check: must be 11 digits.
    $('#phone').on('blur input', function (ev) {
        var $in = $(this), v = $in.val();
        if (ev.type === 'input' && !$in.hasClass('error')) return; // wait for blur first
        var bad = !/^\d{11}$/.test(v);
        $in.siblings('.form-error').remove();
        $in.toggleClass('error', bad);
        if (bad) $('<span class="form-error">Enter a valid 11-digit mobile number (e.g. 09123456789).</span>').insertAfter($in);
    });
});
