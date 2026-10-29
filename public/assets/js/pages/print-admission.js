$('#printBtn').on('click', function () { window.print(); });
// Drop the logo if it fails to load (also when it already failed before this ran).
$('.pr-logo').on('error', function () { $(this).remove(); }).each(function () {
    if (this.complete && !this.naturalWidth) { $(this).remove(); }
});
