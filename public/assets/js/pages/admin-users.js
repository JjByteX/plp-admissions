var PAGE = window.PAGE || {};
function openResetModal(uid, name) {
    document.getElementById('reset-uid').value = uid;
    document.getElementById('reset-name').textContent = 'Resetting password for: ' + name;
    document.getElementById('reset-pw-modal').style.display = 'flex';
}
[document.getElementById('create-user-modal'), document.getElementById('reset-pw-modal')].forEach(m => {
    m.addEventListener('click', function(e){ if(e.target===this) this.style.display='none'; });
});

// Make department required when role is Dean, optional otherwise.
(function(){
    var roleSel = document.getElementById('role-select');
    var deptSel = document.getElementById('dept-select');
    var deptHint = document.getElementById('dept-hint');
    if (!roleSel || !deptSel) return;
    function sync() {
        var isDean = roleSel.value === PAGE.roles.dean;
        var isProctor = roleSel.value === PAGE.roles.proctor;
        var isStaff = roleSel.value === PAGE.roles.staff;
        var needsDept = isDean || isProctor || isStaff;
        var isAdmin = roleSel.value === PAGE.roles.admin;
        var isSSO = roleSel.value === PAGE.roles.sso;
        var hideDept = isAdmin || isSSO;
        var deptRow = deptSel.closest('div');
        if (hideDept) {
            deptRow.style.display = 'none';
            deptSel.required = false;
            deptSel.value = '';
        } else {
            deptRow.style.display = '';
            deptSel.required = needsDept;
        }
        if (deptHint && !hideDept) {
            deptHint.textContent = needsDept
                ? ' - required'
                : '';
        }
    }
    roleSel.addEventListener('change', sync);
    sync();
})();
