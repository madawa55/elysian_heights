/*
 * Gallery uploader: choose or drop photos (repeatedly — selections add up), preview them,
 * remove any before uploading, then upload one file per request with a progress bar.
 * One file per request keeps each request well under PHP's post_max_size.
 */
(function () {
    var form = document.getElementById('upload-form');
    if (!form || !window.FormData || !window.XMLHttpRequest) return; // plain form still works

    var MAX_BYTES = 8 * 1024 * 1024;
    var TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    var input = form.querySelector('input[type=file]');
    var dropzone = document.getElementById('dropzone');
    var queueEl = document.getElementById('upload-queue');
    var summary = document.getElementById('upload-summary');
    var uploadBtn = document.getElementById('upload-btn');
    var clearBtn = document.getElementById('upload-clear');
    var progress = document.getElementById('upload-progress');
    var bar = progress.firstElementChild;
    var errorsEl = document.getElementById('upload-errors');

    var queue = [];   // {file, url, el, error}
    var busy = false;

    input.required = false; // the queue, not the input, holds the files now

    function sizeText(bytes) {
        return bytes > 1048576 ? (bytes / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(bytes / 1024)) + ' KB';
    }

    function addFiles(list) {
        if (busy) return;
        Array.prototype.forEach.call(list, function (file) {
            var duplicate = queue.some(function (q) {
                return q.file.name === file.name && q.file.size === file.size && q.file.lastModified === file.lastModified;
            });
            if (duplicate) return;

            var item = { file: file, url: null, error: '' };
            if (TYPES.indexOf(file.type) === -1) item.error = 'Not a JPG, PNG, WebP or GIF';
            else if (file.size > MAX_BYTES) item.error = 'Larger than 8 MB';
            else item.url = URL.createObjectURL(file);
            queue.push(item);
        });
        render();
    }

    function remove(item) {
        if (busy) return;
        if (item.url) URL.revokeObjectURL(item.url);
        queue.splice(queue.indexOf(item), 1);
        render();
    }

    function render() {
        queueEl.innerHTML = '';
        queue.forEach(function (item) {
            var el = document.createElement('div');
            el.className = 'q-item' + (item.error ? ' q-bad' : '');
            el.innerHTML = (item.url ? '<img alt="">' : '<div class="q-noimg">!</div>') +
                '<div class="q-info"><span class="q-name"></span><small></small></div>' +
                '<span class="q-state"></span>' +
                '<button type="button" class="q-remove" aria-label="Remove">&times;</button>';
            if (item.url) el.querySelector('img').src = item.url;
            el.querySelector('.q-name').textContent = item.file.name;
            el.querySelector('small').textContent = item.error || sizeText(item.file.size);
            el.querySelector('.q-remove').addEventListener('click', function () { remove(item); });
            item.el = el;
            queueEl.appendChild(el);
        });

        var good = queue.filter(function (q) { return !q.error; });
        var bytes = good.reduce(function (n, q) { return n + q.file.size; }, 0);
        queueEl.hidden = !queue.length;
        clearBtn.hidden = !queue.length;
        dropzone.classList.toggle('has-files', queue.length > 0);
        summary.textContent = good.length
            ? good.length + ' photo' + (good.length > 1 ? 's' : '') + ' ready (' + sizeText(bytes) + ')' +
              (good.length < queue.length ? ' · ' + (queue.length - good.length) + ' can’t be uploaded' : '')
            : (queue.length ? 'None of the selected files can be uploaded.' : '');
        uploadBtn.textContent = good.length ? 'Upload ' + good.length + ' photo' + (good.length > 1 ? 's' : '') : 'Upload';
    }

    function setState(item, cls, text) {
        item.el.classList.remove('q-uploading', 'q-done', 'q-failed');
        if (cls) item.el.classList.add(cls);
        item.el.querySelector('.q-state').textContent = text;
    }

    function uploadOne(item, onProgress) {
        return new Promise(function (resolve) {
            var data = new FormData();
            data.append('csrf_token', form.elements.csrf_token.value);
            data.append('action', 'upload');
            data.append('caption', form.elements.caption.value);
            data.append('images[]', item.file, item.file.name);

            var xhr = new XMLHttpRequest();
            xhr.open('POST', form.getAttribute('action') || window.location.pathname);
            xhr.setRequestHeader('X-Requested-With', 'fetch');
            xhr.upload.onprogress = function (e) { if (e.lengthComputable) onProgress(e.loaded); };
            xhr.onload = function () {
                var res;
                try { res = JSON.parse(xhr.responseText); } catch (e) { res = { ok: false, message: 'Server error (' + xhr.status + ')' }; }
                resolve(res);
            };
            xhr.onerror = function () { resolve({ ok: false, message: 'Network error' }); };
            xhr.send(data);
        });
    }

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        var todo = queue.filter(function (q) { return !q.error; });
        if (busy) return;
        if (!todo.length) {
            summary.textContent = 'Choose at least one photo first.';
            return;
        }

        busy = true;
        uploadBtn.disabled = clearBtn.disabled = true;
        input.disabled = true;
        dropzone.classList.add('is-busy');
        progress.hidden = false;
        errorsEl.hidden = true;
        errorsEl.innerHTML = '';

        var total = todo.reduce(function (n, q) { return n + q.file.size; }, 0);
        var doneBytes = 0, added = 0, failed = [];

        todo.reduce(function (chain, item, i) {
            return chain.then(function () {
                setState(item, 'q-uploading', 'Uploading…');
                summary.textContent = 'Uploading ' + (i + 1) + ' of ' + todo.length + '…';
                return uploadOne(item, function (loaded) {
                    bar.style.width = Math.min(100, (doneBytes + loaded) / total * 100) + '%';
                }).then(function (res) {
                    doneBytes += item.file.size;
                    bar.style.width = (doneBytes / total * 100) + '%';
                    if (res.ok) {
                        added++;
                        setState(item, 'q-done', '✓ Uploaded');
                    } else {
                        failed.push(item.file.name + ': ' + res.message);
                        setState(item, 'q-failed', 'Failed');
                    }
                });
            });
        }, Promise.resolve()).then(function () {
            if (!failed.length) {
                window.location.href = 'gallery.php?added=' + added;
                return;
            }
            busy = false;
            input.disabled = false;
            uploadBtn.disabled = clearBtn.disabled = false;
            dropzone.classList.remove('is-busy');
            summary.textContent = added + ' uploaded, ' + failed.length + ' failed.';
            failed.forEach(function (msg) {
                var li = document.createElement('li');
                li.textContent = msg;
                errorsEl.appendChild(li);
            });
            errorsEl.hidden = false;
            // Keep only the failed files in the queue so they can be retried.
            queue = queue.filter(function (q) { return q.el.classList.contains('q-failed'); });
            render();
            if (added) {
                var li = document.createElement('li');
                li.innerHTML = '<a href="gallery.php?added=' + added + '">Refresh the page</a> to see the photos that were uploaded.';
                errorsEl.appendChild(li);
            }
        });
    });

    input.addEventListener('change', function () {
        addFiles(input.files);
        input.value = ''; // allow choosing the same file again later, and keep adding
    });

    clearBtn.addEventListener('click', function () {
        queue.forEach(function (q) { if (q.url) URL.revokeObjectURL(q.url); });
        queue = [];
        render();
    });

    ['dragenter', 'dragover'].forEach(function (type) {
        dropzone.addEventListener(type, function (e) {
            e.preventDefault();
            if (!busy) dropzone.classList.add('is-over');
        });
    });
    ['dragleave', 'dragend'].forEach(function (type) {
        dropzone.addEventListener(type, function (e) {
            if (!dropzone.contains(e.relatedTarget)) dropzone.classList.remove('is-over');
        });
    });
    dropzone.addEventListener('drop', function (e) {
        e.preventDefault(); // stop the browser replacing the input's files or opening the image
        dropzone.classList.remove('is-over');
        if (e.dataTransfer && e.dataTransfer.files.length) addFiles(e.dataTransfer.files);
    });
    // Dropping just outside the box shouldn't navigate away from the page.
    window.addEventListener('dragover', function (e) { e.preventDefault(); });
    window.addEventListener('drop', function (e) { e.preventDefault(); });
})();
