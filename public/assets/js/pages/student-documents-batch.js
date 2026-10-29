// Phase 5: Upload many files. One file per request (action=classify), sequential queue.
// ponytail: queue lives in memory only (a page reload drops unfinished rows); upgrade path is none needed, saved files stay in the slot list.
document.addEventListener('DOMContentLoaded', function () {
    var $ = window.jQuery;
    if (!$ || !$('#batch-box').length) return;

    var URL_  = window.PAGE.uploadUrl;
    var MANUAL_URL = window.PAGE.manualUrl;
    var CSRF  = $('#flags-form [name=_csrf], #upload-form [name=_csrf]').first().val() || window.PAGE.csrf;
    var MAX   = 4 * 1024 * 1024;
    var BODY  = 4.3 * 1024 * 1024;   // Vercel rejects request bodies over 4.5 MB; the PDF and its preview travel together
    var AI_PX = 1024;                // longest side of the picture the AI reads (keep in step with llama-server --image-max-tokens)
    var PDFJS = window.PAGE.pdfJs, PDFJS_WORKER = window.PAGE.pdfWorker;
    var OK    = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];
    var rows  = {}, queue = [], busy = false, seq = 0, lastCats = null, replaceId = null;

    function badge(cls, text) { return $('<span class="badge">').addClass(cls).text(text); }
    function bytesOk(b) { return b.size <= MAX; }

    // 5.3: shrink to AI_PX JPEG. Falls back to the original file if the browser cannot decode it.
    function shrink(file) {
        var d = $.Deferred();
        if (file.type === 'application/pdf') return d.resolve(file).promise();
        var img = new Image(), src = URL.createObjectURL(file);
        img.onload = function () {
            var k = Math.min(1, AI_PX / Math.max(img.width, img.height));
            var c = document.createElement('canvas');
            c.width = Math.round(img.width * k); c.height = Math.round(img.height * k);
            var x = c.getContext('2d');
            x.fillStyle = '#fff'; x.fillRect(0, 0, c.width, c.height);
            x.drawImage(img, 0, 0, c.width, c.height);
            URL.revokeObjectURL(src);
            c.toBlob(function (b) { d.resolve(b || file); }, 'image/jpeg', 0.85);
        };
        img.onerror = function () { URL.revokeObjectURL(src); d.resolve(file); };
        img.src = src;
        return d.promise();
    }

    // PDF first page -> JPEG for the AI. pdf.js is loaded only when a PDF is picked.
    // Any failure (script blocked, locked or broken PDF) just means no preview: the applicant picks the type.
    function loadPdfJs() {
        return PLP.loadPdfJs({ src: PDFJS, worker: PDFJS_WORKER });
    }

    function renderPdf(file) {
        return loadPdfJs().then(function (lib) {
            return file.arrayBuffer().then(function (buf) {
                return lib.getDocument({ data: buf, isEvalSupported: false }).promise;
            });
        }).then(function (pdf) {
            return pdf.getPage(1);
        }).then(function (page) {
            var v1 = page.getViewport({ scale: 1 });
            var vp = page.getViewport({ scale: AI_PX / Math.max(v1.width, v1.height) });
            var c = document.createElement('canvas');
            c.width = Math.round(vp.width); c.height = Math.round(vp.height);
            var x = c.getContext('2d');
            x.fillStyle = '#fff'; x.fillRect(0, 0, c.width, c.height);
            return page.render({ canvasContext: x, viewport: vp }).promise.then(function () {
                return new Promise(function (resolve, reject) {
                    c.toBlob(function (b) { b ? resolve(b) : reject(new Error('no preview')); }, 'image/jpeg', 0.85);
                });
            });
        });
    }

    // Sets r.preview (a JPEG blob, or null). Runs once per file.
    function previewFor(r) {
        var d = $.Deferred();
        if (!r.blob || r.blob.type !== 'application/pdf' || r.preview !== undefined) return d.resolve().promise();
        busyState(r, 'Reading PDF…');
        renderPdf(r.blob).then(function (b) {
            r.preview = (r.blob.size + b.size <= BODY) ? b : null;
            d.resolve();
        }, function () { r.preview = null; d.resolve(); });
        return d.promise();
    }

    function newRow(file) {
        var id = ++seq;
        var $el = $('<div class="batch-row">').attr('data-id', id).append(
            $('<div class="batch-head">').append($('<span class="batch-name">').text(file.name), $('<span class="batch-state">')),
            $('<div class="batch-msg">'), $('<div class="batch-bar" style="display:none"><span></span></div>'), $('<div class="batch-actions">')
        );
        $('#batch-rows').append($el);
        rows[id] = { id: id, $el: $el, file: file, blob: null, preview: undefined, tries: 0, guess: '' };
        return rows[id];
    }

    function show(r, cls, label, msg) {
        r.$el.find('.batch-state').empty().append(badge(cls, label));
        r.$el.find('.batch-msg').text(msg || '');
        r.$el.find('.batch-actions').empty();
        r.$el.find('.batch-tip').remove();
    }

    function busyState(r, label) {
        show(r, 'badge-info', label, '');
        r.$el.find('.batch-bar').show().find('span').css('width', '0');
    }

    function tip(r) {
        // 5.10: after 2 failed tries, point to one by one upload.
        if (r.tries >= 2) r.$el.append($('<div class="batch-tip">').text('Still not working? ').append($('<a class="batch-link">').attr('href', MANUAL_URL).text('Upload your documents manually.')));
    }

    function actionBtn(text, fn, primary) {
        return $('<button type="button" class="btn btn-sm">').addClass(primary ? 'btn-primary' : 'btn-ghost').text(text).on('click', fn);
    }

    function dropdown(r, cats, pre) {
        var $sel = $('<select class="form-select batch-select">').append($('<option value="">').text('Choose document type…'));
        $.each(cats, function (_, c) {
            var $o = $('<option>').val(c.category).text(c.label + (c.note ? ' - ' + c.note : ''));
            if (!c.available) $o.prop('disabled', true);
            $sel.append($o);
        });
        var $go = actionBtn('Use this type', function () { pick(r, $sel.val()); }, true).prop('disabled', true);
        $sel.on('change', function () { $go.prop('disabled', !$sel.val()); });
        // The AI's guess is already picked, so confirming it is one tap.
        var g = $.grep(cats, function (c) { return c.category === pre && c.available; })[0];
        if (g) { $sel.val(g.category); $go.prop('disabled', false); }
        return [$sel, $go];
    }

    function offer(r, kind, msg, cats) {
        // kind: 'uncertain' (Not sure) | 'failed' (Unreadable) | 'error'
        cats = cats && cats.length ? cats : lastCats;
        var cls = kind === 'uncertain' ? 'badge-warning' : 'badge-error';
        var label = kind === 'uncertain' ? 'Not sure' : (kind === 'failed' ? 'Unreadable' : 'Error');
        show(r, cls, label, msg);
        var $a = r.$el.find('.batch-actions');
        if (kind === 'uncertain') $a.append(actionBtn('Replace file', function () { replaceId = r.id; $('#batch-replace').removeAttr('capture').val('').trigger('click'); }));
        else if (kind === 'failed') $a.append(actionBtn('Retake', function () { replaceId = r.id; $('#batch-replace').attr('capture', 'environment').val('').trigger('click'); }));
        else $a.append(actionBtn('Try again', function () { r.tries = 0; enqueue(r); }));
        if (cats && cats.length) $a.prepend.apply($a, dropdown(r, cats, kind === 'uncertain' ? r.guessCat : ''));
        $a.append(actionBtn('Remove', function () { r.$el.remove(); delete rows[r.id]; }));
        tip(r);
    }

    function done(r, label, cls, msg) {
        show(r, cls, label, msg);
        r.blob = null; r.file = null;
        r.$el.find('.batch-actions').append(actionBtn('Dismiss', function () { r.$el.remove(); delete rows[r.id]; }));
    }

    // A sorted file now shows as Uploaded in the document list, so its result row goes away once the list has refreshed.
    // If the refresh fails, keep the row so the applicant still sees what happened.
    function sorted(r, msg) {
        r.blob = null; r.file = null;
        window.refreshSlotList().done(function () { r.$el.remove(); delete rows[r.id]; })
            .fail(function () { done(r, 'Sorted', 'badge-success', msg); });
    }

    // jQuery calls fail() for a timeout, a 5xx and a connection drop alike; tell them apart for the applicant.
    function failMessage(xhr, status) {
        if (status === 'timeout' || (xhr && [502, 503, 504].indexOf(xhr.status) >= 0)) return 'This took too long. Please try again.';
        if (xhr && xhr.status > 0) return 'Something went wrong on the server. Please try again.';
        return 'Could not reach the server. Check your connection and try again.';
    }

    function send(r, data) {
        return $.ajax({
            url: URL_, method: 'POST', data: data, processData: false, contentType: false,
            dataType: 'json', timeout: 70000, headers: { 'X-Requested-With': 'XMLHttpRequest' },
            xhr: function () {
                var x = $.ajaxSettings.xhr();
                if (x.upload) x.upload.onprogress = function (e) {
                    if (!e.lengthComputable) return;
                    var p = Math.round(e.loaded / e.total * 100);
                    r.$el.find('.batch-bar span').css('width', p + '%');
                    if (p >= 100) r.$el.find('.batch-state .badge').text('Checking…');
                };
                return x;
            }
        });
    }

    function classify(r) {
        var d = $.Deferred();
        busyState(r, 'Uploading…');
        var fd = new FormData();
        fd.append('_csrf', CSRF); fd.append('action', 'classify');
        fd.append('doc_file', r.blob, r.blob.name || r.file.name);
        if (r.preview) fd.append('ai_preview', r.preview, 'preview.jpg');
        send(r, fd).done(function (res) {
            r.$el.find('.batch-bar').hide();
            if (!res || res.ok === false) { r.tries++; offer(r, 'error', (res && res.message) || 'Something went wrong. Please try again.'); }
            else if (res.categories && res.categories.length) lastCats = res.categories;
            if (res && res.ok !== false) {
                r.guess = res.guess || '';
                r.guessCat = res.guess_category || '';
                if (res.status === 'passed') sorted(r, res.message || 'Saved.');
                else if (res.status === 'blocked') {
                    done(r, 'Blocked', 'badge-error', res.message);
                    if (res.can_replace) r.$el.append($('<div class="batch-tip">').text('To replace a saved file, ').append($('<a class="batch-link">').attr('href', MANUAL_URL).text('upload your documents manually.')));
                    window.refreshSlotList && window.refreshSlotList();
                }
                else if (res.status === 'failed') { r.tries++; offer(r, 'failed', res.message, res.categories); }
                else { r.tries++; offer(r, 'uncertain', res.message, res.categories); }
            }
        }).fail(function (xhr, status) {
            r.$el.find('.batch-bar').hide(); r.tries++;
            offer(r, 'error', failMessage(xhr, status));
        }).always(function () { d.resolve(); });
        return d.promise();
    }

    // 5.9: send the kept file to the existing upload action with the chosen category.
    function pick(r, cat) {
        if (!cat || !r.blob) return;
        busyState(r, 'Saving…');
        var fd = new FormData();
        fd.append('_csrf', CSRF); fd.append('doc_slug', cat); fd.append('picked', '1'); fd.append('ai_guess', r.guess || '');
        fd.append('doc_file', r.blob, r.blob.name || r.file.name);
        send(r, fd).done(function (res) {
            r.$el.find('.batch-bar').hide();
            if (res && res.ok) sorted(r, res.message || 'Saved.');
            else offer(r, 'error', (res && res.message) || 'Could not save this file.', lastCats);
        }).fail(function (xhr, status) {
            r.$el.find('.batch-bar').hide();
            offer(r, 'error', failMessage(xhr, status), lastCats);
        });
    }

    function enqueue(r) {
        show(r, 'badge-pending', 'Waiting', '');
        queue.push(r); run();
    }

    function run() {
        if (busy || !queue.length) return;
        busy = true;
        var r = queue.shift();
        if (!rows[r.id]) { busy = false; return run(); }
        (r.blob ? $.Deferred().resolve(r.blob).promise() : shrink(r.file)).then(function (b) {
            if (!(b instanceof Blob)) b = r.file;
            if (!b.name) b.name = r.file.name.replace(/\.[^.]+$/, '') + (b.type === 'image/jpeg' ? '.jpg' : '');
            if (!bytesOk(b)) { offer(r, 'error', 'This file is over the 4 MB limit.'); return; }
            r.blob = b;
            return previewFor(r).then(function () { return classify(r); });
        }).always(function () { busy = false; run(); });
    }

    function addFiles(list) {
        $.each(list, function (_, f) {
            var r = newRow(f);
            if (OK.indexOf(f.type) < 0) { show(r, 'badge-error', 'Error', 'Only PDF, JPG, PNG, and WEBP files are accepted.'); r.$el.find('.batch-actions').append(actionBtn('Dismiss', function () { r.$el.remove(); delete rows[r.id]; })); return; }
            enqueue(r);
        });
    }

    // Replace file / Retake: swap the kept file and classify the same row again.
    $('#batch-replace').on('change', function () {
        var f = this.files[0], r = rows[replaceId];
        if (!f || !r) return;
        if (OK.indexOf(f.type) < 0) { offer(r, 'error', 'Only PDF, JPG, PNG, and WEBP files are accepted.'); return; }
        r.file = f; r.blob = null; r.preview = undefined; r.$el.find('.batch-name').text(f.name);
        enqueue(r);
    });

    var $drop = $('#batch-drop');
    $drop.on('click', function () { $('#batch-input').val('').trigger('click'); });
    $('#batch-camera-btn').on('click', function (e) { e.stopPropagation(); $('#batch-camera').val('').trigger('click'); });
    $('#batch-input, #batch-camera').on('change', function () { addFiles(this.files); });
    $drop.on('dragover dragenter', function (e) { e.preventDefault(); $drop.addClass('drag-over'); });
    $drop.on('dragleave drop', function (e) { e.preventDefault(); $drop.removeClass('drag-over'); });
    $drop.on('drop', function (e) { addFiles(e.originalEvent.dataTransfer.files); });
});
