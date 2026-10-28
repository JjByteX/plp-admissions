function setSettingsSex(val) {
    document.getElementById('settings_sex_value').value = val;
    document.querySelectorAll('.sex-btn-s').forEach(function(btn) {
        btn.classList.toggle('active', btn.textContent.trim() === (val === 'M' ? 'Male' : 'Female'));
    });
}
