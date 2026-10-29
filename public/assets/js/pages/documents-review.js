var PAGE = window.PAGE || {};
(function(){
    var _files=[], _idx=0, _scale=1, _tx=0, _ty=0;
    var _drag=false, _ds={x:0,y:0}, _touch=null;
    window._fvCurrentDocId = 0;

    window.openFileViewer = function(idx, files) {
        _files=files; _idx=idx; _scale=1; _tx=0; _ty=0;
        _render();
        document.getElementById('file-viewer-modal').style.display='flex';
        document.body.style.overflow='hidden';
        var h=document.getElementById('fv-hint');
        if(h){ h.style.opacity='1'; setTimeout(function(){ h.style.opacity='0'; },2800); }
    };

    window.closeFileViewer = function() {
        document.getElementById('file-viewer-modal').style.display='none';
        document.body.style.overflow='';
        document.getElementById('fv-transform-wrap').innerHTML='';
    };

    window.fvNavigate = function(d) {
        var n=_idx+d;
        if(n<0||n>=_files.length) return;
        _idx=n; _scale=1; _tx=0; _ty=0; _render();
    };

    window.fvZoom = function(delta) {
        _scale=Math.min(5,Math.max(0.5,_scale+delta));
        if(_scale<=1){ _tx=0; _ty=0; }
        _apply();
    };

    window.fvResetZoom = function() { _scale=1; _tx=0; _ty=0; _apply(); };

    function _render() {
        var f=_files[_idx];
        if(!f) return;
        var wrap=document.getElementById('fv-transform-wrap');
        var isPdf=f.url.toLowerCase().split('?')[0].endsWith('.pdf');
        if(isPdf){
            wrap.innerHTML='<iframe src="'+f.url+'" style="width:100%;height:100%;border:none;background:var(--white);"></iframe>';
        } else {
            wrap.innerHTML='<img src="'+f.url+'" alt="Document preview" style="max-width:100%;max-height:100%;border-radius:var(--radius);box-shadow:var(--shadow-md);display:block;pointer-events:none;user-select:none;-webkit-user-drag:none;">';
        }
        document.getElementById('fv-label').textContent=f.label;
        document.getElementById('fv-counter').textContent=(_idx+1)+' of '+_files.length;
        var p=document.getElementById('fv-prev');
        var n=document.getElementById('fv-next');
        p.disabled=(_idx===0); p.style.opacity=(_idx===0)?'0.35':'1';
        n.disabled=(_idx===_files.length-1); n.style.opacity=(_idx===_files.length-1)?'0.35':'1';
        _apply();
        _dots();
        _updateActions(f);
        var h=document.getElementById('fv-hint');
        if(h){ h.style.opacity='1'; setTimeout(function(){ h.style.opacity='0'; },2800); }
    }

    function _updateActions(f) {
        var approveForm = document.getElementById('fv-approve-form');
        var resubmitBtn = document.getElementById('fv-resubmit-btn');
        var statusMsg   = document.getElementById('fv-status-msg');
        window._fvCurrentDocId = f.doc_id || 0;

        if (f.status === 'uploaded' || f.status === 'under_review') {
            approveForm.action = PAGE.docUrl + f.doc_id;
            approveForm.style.display = '';
            resubmitBtn.style.display = '';
            statusMsg.style.display = 'none';
        } else {
            approveForm.style.display = 'none';
            resubmitBtn.style.display = 'none';
            statusMsg.style.display = '';
            if (f.status === 'approved') {
                statusMsg.textContent = 'This document has been approved.';
            } else if (f.status === 'rejected') {
                statusMsg.textContent = 'Resubmission requested for this document.';
            } else {
                statusMsg.textContent = '';
                statusMsg.style.display = 'none';
            }
        }
    }

    function _apply() {
        var w=document.getElementById('fv-transform-wrap');
        w.style.transform='translate('+_tx+'px,'+_ty+'px) scale('+_scale+')';
        document.getElementById('fv-zoom-label').textContent=Math.round(_scale*100)+'%';
        var vp=document.getElementById('fv-viewport');
        vp.dataset.zoomed=(_scale>1)?'true':'false';
    }

    function _dots() {
        var c=document.getElementById('fv-dots');
        c.innerHTML='';
        _files.forEach(function(f,i){
            var b=document.createElement('button');
            b.type='button';
            b.style.cssText='width:8px;height:8px;border-radius:50%;border:none;padding:0;cursor:pointer;flex-shrink:0;transition:background .15s,transform .15s;';
            b.style.background=(i===_idx)?'var(--accent)':'var(--border-strong)';
            b.style.transform=(i===_idx)?'scale(1.35)':'scale(1)';
            b.title=f.label;
            b.onclick=function(){ _idx=i; _scale=1; _tx=0; _ty=0; _render(); };
            c.appendChild(b);
        });
    }

    // Pan
    document.addEventListener('DOMContentLoaded',function(){
        var vp=document.getElementById('fv-viewport');
        vp.addEventListener('mousedown',function(e){
            if(_scale<=1) return;
            _drag=true; _ds={x:e.clientX-_tx,y:e.clientY-_ty};
            vp.dataset.dragging='true'; e.preventDefault();
        });
        window.addEventListener('mousemove',function(e){
            if(!_drag) return;
            _tx=e.clientX-_ds.x; _ty=e.clientY-_ds.y; _apply();
        });
        window.addEventListener('mouseup',function(){
            if(_drag){ _drag=false; document.getElementById('fv-viewport').dataset.dragging='false'; }
        });
        vp.addEventListener('touchstart',function(e){
            if(_scale<=1) return;
            var t=e.touches[0]; _touch={x:t.clientX-_tx,y:t.clientY-_ty}; e.preventDefault();
        },{passive:false});
        vp.addEventListener('touchmove',function(e){
            if(!_touch) return;
            var t=e.touches[0]; _tx=t.clientX-_touch.x; _ty=t.clientY-_touch.y; _apply(); e.preventDefault();
        },{passive:false});
        vp.addEventListener('touchend',function(){ _touch=null; });
        vp.addEventListener('wheel',function(e){
            e.preventDefault();
            var d=e.deltaY>0?-0.15:0.15;
            _scale=Math.min(5,Math.max(0.5,_scale+d));
            if(_scale<=1){ _tx=0; _ty=0; }
            _apply();
        },{passive:false});
        // Click backdrop to close
        document.getElementById('file-viewer-modal').addEventListener('click',function(e){
            if(e.target===this) closeFileViewer();
        });
    });

    // Keyboard
    document.addEventListener('keydown',function(e){
        if(document.getElementById('file-viewer-modal').style.display!=='flex') return;
        if(e.key==='Escape') closeFileViewer();
        if(e.key==='ArrowLeft') fvNavigate(-1);
        if(e.key==='ArrowRight') fvNavigate(1);
        if(e.key==='+'||e.key==='=') fvZoom(0.25);
        if(e.key==='-') fvZoom(-0.25);
        if(e.key==='0') fvResetZoom();
    });
})();

// Phase 6: Category OK / Change type (jQuery). jQuery loads after this block,
// so wait for DOMContentLoaded. The list is re-fetched after each action, so
// no full page reload.
document.addEventListener('DOMContentLoaded', function () {
    var $ = window.jQuery;
    if (!$) return;
    var actionUrl = PAGE.docUrl;
    var csrf = PAGE.csrf;

    function refresh(msg, ok) {
        $('#doc-review').load(window.location.href + ' #doc-review > *', function () {
            if (!msg) return;
            $('<div class="alert"></div>')
                .addClass(ok ? 'alert-success' : 'alert-error')
                .css('margin-bottom', 'var(--space-4)')
                .text(msg)
                .prependTo('#doc-review');
        });
    }
    function send($flag, data) {
        $flag.find('button, select').prop('disabled', true);
        $.ajax({
            url: actionUrl + $flag.data('doc'),
            method: 'POST',
            data: $.extend({ _csrf: csrf }, data),
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            dataType: 'json'
        }).done(function (r) {
            refresh(r.message, r.ok);
        }).fail(function () {
            refresh('Something went wrong. Please try again.', false);
        });
    }

    $('#doc-review').on('click', '.js-cat-ok', function () {
        send($(this).closest('.ai-flag'), { action: 'category_ok' });
    });
    $('#doc-review').on('click', '.js-change-type', function () {
        var $flag = $(this).closest('.ai-flag');
        var target = $flag.find('.js-target').val();
        if (!target) { $flag.find('.js-target').focus(); return; }
        send($flag, { action: 'change_type', target_slot: target });
    });
});

function openResubmitModal(docId) {
    document.getElementById('resubmit-form').action = PAGE.docUrl + docId;
    document.getElementById('resubmit-modal').style.display = 'flex';
}

// AJAX Approve All — fast, non-blocking
function approveAllAjax() {
    var btn = document.getElementById('approve-all-btn');
    if (!btn) return;
    btn.disabled = true;
    btn.textContent = 'Approving…';

    var fd = new FormData();
    fd.append('_csrf', PAGE.csrf);
    fd.append('action', 'approve_all');

    fetch(PAGE.applicantUrl, {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: fd
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.ok) {
            window._lastApprovedIds = data.ids || [];
            showUndoToast(data.message || 'All documents approved.');
        } else {
            ui.alert(data.message || 'Something went wrong.').then(function() { window.location.reload(); });
        }
    })
    .catch(function() { window.location.reload(); });
}

// Undo toast
function showUndoToast(msg) {
    var existing = document.getElementById('undo-toast');
    if (existing) existing.remove();

    var toast = document.createElement('div');
    toast.id = 'undo-toast';
    toast.style.cssText = 'position:fixed;bottom:24px;left:50%;transform:translateX(-50%);z-index:10000;background:var(--bg-elevated);border:1px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow-lg);padding:12px 20px;display:flex;align-items:center;gap:12px;font-size:var(--text-sm);min-width:320px;animation:slideUp .25s ease';
    toast.innerHTML = '<span style="flex:1">' + msg + '</span>'
        + '<button onclick="undoApproveAll()" class="btn btn-secondary btn-sm">Undo</button>'
        + '<button onclick="dismissToast()" class="btn btn-icon" aria-label="Dismiss">&times;</button>';
    document.body.appendChild(toast);

    window._undoToastTimer = setTimeout(function() {
        dismissToast();
        window.location.reload();
    }, 6000);
}

function dismissToast() {
    clearTimeout(window._undoToastTimer);
    var t = document.getElementById('undo-toast');
    if (t) t.remove();
}

function undoApproveAll() {
    dismissToast();
    var fd = new FormData();
    fd.append('_csrf', PAGE.csrf);
    fd.append('action', 'undo_approve_all');
    (window._lastApprovedIds || []).forEach(function(id) { fd.append('doc_ids[]', id); });

    fetch(PAGE.applicantUrl, {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: fd
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data && data.ok === false) return ui.alert(data.message || 'Could not undo.').then(function() { window.location.reload(); });
        window.location.reload();
    })
    .catch(function() { window.location.reload(); });
}
