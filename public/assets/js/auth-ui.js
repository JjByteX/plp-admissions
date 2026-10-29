setAccentColor(document.body.getAttribute('data-accent') || '');

// Auth UI (jQuery): show-password toggle, confirm-password check, loading label.
jQuery(function ($) {
    var eye = '<i class="ph-bold ph-eye" style="font-size:var(--text-xl)" aria-hidden="true"></i>';

    // One show/hide password toggle for every auth page.
    $('input[type="password"]').each(function () {
        var $in = $(this);
        if ($in.parent().hasClass('auth-pw')) return;
        var $wrap = $('<div class="auth-pw"></div>');
        $in.wrap($wrap);
        $('<button type="button" class="auth-pw-eye" aria-label="Show password"></button>')
            .html(eye)
            .insertAfter($in)
            .on('click', function () {
                var show = $in.attr('type') === 'password';
                $in.attr('type', show ? 'text' : 'password');
                $(this).toggleClass('on', show).attr('aria-label', show ? 'Hide password' : 'Show password');
                $(this).find('i').toggleClass('ph-eye', !show).toggleClass('ph-eye-slash', show);
            });
    });

    // Confirm password must match (client hint only; server still validates).
    var $pw = $('#password'), $cf = $('#password_confirm');
    if ($pw.length && $cf.length) {
        $cf.on('input blur', function () {
            var bad = $cf.val() !== '' && $cf.val() !== $pw.val();
            $cf.toggleClass('error', bad);
        });
    }

    // Live email check on blur (shows the red message before submit).
    var emailRe = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    $('input[type="email"]').on('blur input', function (ev) {
        var $in = $(this), v = $.trim($in.val());
        if (ev.type === 'input' && !$in.hasClass('error')) return;
        var bad = v !== '' && !emailRe.test(v);
        $in.siblings('.form-error').remove();
        $in.toggleClass('error', bad);
        if (bad) $('<span class="form-error">Enter a valid email address.</span>').insertAfter($in);
    });

    // Loading label on submit (app.js already disables the button).
    $('form[data-once]').on('submit', function () {
        var $b = $(this).find('[type="submit"]').first();
        if ($b.length && !$b.data('label')) $b.data('label', $b.text()).attr('aria-busy', 'true');
    });
});
