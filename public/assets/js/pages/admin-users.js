const PAGE = window.PAGE || {};
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
    const roleSel = document.getElementById('role-select');
    const deptSel = document.getElementById('dept-select');
    const deptHint = document.getElementById('dept-hint');
    if (!roleSel || !deptSel) return;
    function sync() {
        const isDean = roleSel.value === PAGE.roles.dean;
        const isProctor = roleSel.value === PAGE.roles.proctor;
        const isStaff = roleSel.value === PAGE.roles.staff;
        const needsDept = isDean || isProctor || isStaff;
        const isAdmin = roleSel.value === PAGE.roles.admin;
        const isSSO = roleSel.value === PAGE.roles.sso;
        const hideDept = isAdmin || isSSO;
        const deptRow = deptSel.closest('div');
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
