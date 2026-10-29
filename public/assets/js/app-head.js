// Apply saved theme before render to prevent flash
(function(){
    const t = localStorage.getItem('plp_theme') || 'light';
    document.documentElement.dataset.theme = t;
    let fs = null; try { fs = localStorage.getItem('plp_font_size'); } catch (e) {}
    document.documentElement.dataset.fontSize = ['small','medium','large','xlarge'].indexOf(fs) > -1 ? fs : 'medium';
    document.addEventListener('DOMContentLoaded', function() {
        const pill = document.querySelector('.theme-pill');
        if (pill) pill.dataset.theme = t;
    });
})();
