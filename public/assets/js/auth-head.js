(function(){
    // Auth pages are always light (no theme switch here).
    window.PLP_FORCE_LIGHT = true;
    document.documentElement.dataset.theme = 'light';
    var fs = null; try { fs = localStorage.getItem('plp_font_size'); } catch (e) {}
    document.documentElement.dataset.fontSize = ['small','medium','large','xlarge'].indexOf(fs) > -1 ? fs : 'medium';
})();

