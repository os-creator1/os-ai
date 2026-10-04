{{--
    Wizard screen behaviour: repeatable lists (add / remove / re-order),
    immediate image upload with progress, gallery management and the small
    "generate a description" helper. Plain, dependency-free script — the
    server remains the authority for every value (it re-validates and
    re-derives everything it is sent).
--}}
<script>
(function () {
    'use strict';

    var screen = document.querySelector('[data-wizard-screen]');
    if (!screen) { return; }

    var csrfMeta = document.querySelector('meta[name="csrf-token"]');
    var csrf = csrfMeta ? csrfMeta.getAttribute('content') : '';
    var ALLOWED_TYPES = ['image/png', 'image/jpeg', 'image/webp'];
    var MAX_BYTES = 8 * 1024 * 1024;
    var pendingUploads = 0;

    // ---------------------------------------------------------------- helpers
    function show(el, visible) { if (el) { el.classList.toggle('d-none', !visible); } }
    function holderOf(container) { return container.querySelector(':scope > [data-repeatable-rows]'); }
    function directRows(container) {
        var holder = holderOf(container);
        return holder ? Array.prototype.filter.call(holder.children, function (c) { return c.hasAttribute('data-row'); }) : [];
    }
    function containerOf(row) { return row.parentElement.closest('[data-repeatable]'); }
    function answerForm() { return screen.querySelector('form[id^="answer-form-"]'); }

    function checkFile(file) {
        if (ALLOWED_TYPES.indexOf(file.type) === -1) { return 'Choose a PNG, JPEG or WEBP image.'; }
        if (file.size > MAX_BYTES) { return 'That image is larger than 8 MB.'; }
        return null;
    }

    function request(method, url, body, onProgress, done) {
        var xhr = new XMLHttpRequest();
        xhr.open(method, url);
        xhr.setRequestHeader('X-CSRF-TOKEN', csrf);
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        if (onProgress && xhr.upload) {
            xhr.upload.onprogress = function (e) { if (e.lengthComputable) { onProgress(Math.round(e.loaded / e.total * 100)); } };
        }
        xhr.onload = function () {
            var data = null;
            try { data = JSON.parse(xhr.responseText); } catch (e) { data = null; }
            done(xhr.status, data);
        };
        xhr.onerror = function () { done(0, null); };
        xhr.send(body);
    }

    function errorText(status, data) {
        if (data && data.message) { return data.message; }
        if (status === 413) { return 'That image is too large to upload.'; }
        if (status === 419) { return 'Your session expired — reload the page and try again.'; }
        return 'The upload failed. Please try again.';
    }

    // ------------------------------------------------------ repeatable lists
    function nameNested(nested, outerIndex) {
        var template = nested.getAttribute('data-name-template') || '';
        var holder = holderOf(nested);
        if (!holder) { return; }
        holder.querySelectorAll('input[type="text"]').forEach(function (input) {
            input.name = template.replace('{i}', String(outerIndex));
        });
    }

    function outerIndexOf(nested) {
        var outerRow = nested.parentElement.closest('[data-row]');
        if (!outerRow) { return 0; }
        return directRows(containerOf(outerRow)).indexOf(outerRow);
    }

    function updateMoveButtons(container) {
        var rows = directRows(container);
        rows.forEach(function (row, i) {
            var up = row.querySelector(':scope [data-move-row="up"]');
            var down = row.querySelector(':scope [data-move-row="down"]');
            // Only this row's own controls (not a nested list's).
            [up, down].forEach(function (btn, which) {
                if (btn && btn.closest('[data-row]') === row) {
                    btn.disabled = which === 0 ? i === 0 : i === rows.length - 1;
                }
            });
        });
    }

    function reindex(container) {
        if (!container.hasAttribute('data-nested')) {
            directRows(container).forEach(function (row, i) {
                row.querySelectorAll('input[name], textarea[name], select[name]').forEach(function (el) {
                    el.name = el.name.replace(/\[(\d+|__INDEX__)\]/, '[' + i + ']');
                });
                row.querySelectorAll('[data-nested]').forEach(function (nested) { nameNested(nested, i); });
            });
        }
        updateMoveButtons(container);
    }

    function addRow(container) {
        var max = parseInt(container.getAttribute('data-max-rows') || '0', 10);
        var rows = directRows(container);
        if (max && rows.length >= max) { return; }

        var template = container.querySelector(':scope > template[data-row-template]');
        if (!template) { return; }

        var wrap = document.createElement('div');
        wrap.innerHTML = template.innerHTML.replace(/__INDEX__/g, String(rows.length)).trim();
        var row = wrap.firstElementChild;
        holderOf(container).appendChild(row);

        if (container.hasAttribute('data-nested')) { nameNested(container, outerIndexOf(container)); }
        reindex(container);

        var first = row.querySelector('input[type="text"], textarea');
        if (first) { first.focus(); }
    }

    function clearRow(row) {
        row.querySelectorAll('input[type="text"], input[type="number"], textarea').forEach(function (el) { el.value = ''; });
        row.querySelectorAll('select').forEach(function (el) { el.selectedIndex = 0; });
        row.querySelectorAll('[data-image-picker]').forEach(function (box) { setPickerImage(box, '', ''); });
    }

    function removeRow(row) {
        var container = containerOf(row);
        var min = parseInt(container.getAttribute('data-min-rows') || '1', 10);
        if (directRows(container).length <= min) {
            // Keep one blank row rather than an empty list.
            clearRow(row);
            return;
        }
        row.querySelectorAll('[data-image-picker]').forEach(function (box) { discardPickerFile(box); });
        row.remove();
        reindex(container);
    }

    function moveRow(row, direction) {
        var sibling = direction === 'up' ? row.previousElementSibling : row.nextElementSibling;
        while (sibling && !sibling.hasAttribute('data-row')) {
            sibling = direction === 'up' ? sibling.previousElementSibling : sibling.nextElementSibling;
        }
        if (!sibling) { return; }
        if (direction === 'up') { row.parentElement.insertBefore(row, sibling); } else { row.parentElement.insertBefore(sibling, row); }
        reindex(containerOf(row));
    }

    screen.querySelectorAll('[data-repeatable]').forEach(function (container) {
        if (container.hasAttribute('data-nested')) { nameNested(container, outerIndexOf(container)); }
        reindex(container);
    });

    // ------------------------------------------------ immediate image picker
    function setPickerImage(box, path, url) {
        var hidden = box.querySelector('[data-image-path]');
        var preview = box.querySelector('[data-image-preview]');
        hidden.value = path;
        if (path) { preview.src = url || preview.src; }
        show(preview, !!path);
        show(box.querySelector('[data-image-add]'), !path);
        show(box.querySelector('[data-image-replace]'), !!path);
        show(box.querySelector('[data-image-remove]'), !!path);
    }

    function discardPickerFile(box) {
        var hidden = box.querySelector('[data-image-path]');
        if (hidden && hidden.value && box.getAttribute('data-remove-url')) {
            var body = new FormData();
            body.append('path', hidden.value);
            request('POST', box.getAttribute('data-remove-url'), body, null, function () {});
        }
    }

    function uploadPickerFile(box, file) {
        var error = box.querySelector('[data-upload-error]');
        var bar = box.querySelector('[data-upload-progress]');
        var problem = checkFile(file);
        show(error, false);
        if (problem) { error.textContent = problem; show(error, true); return; }

        var row = box.closest('[data-row]');
        var body = new FormData();
        body.append('photo', file);
        var name = row ? row.querySelector('[data-item-name]') : null;
        var category = row ? row.querySelector('[data-item-category]') : null;
        if (name) { body.append('name', name.value); }
        if (category) { body.append('category', category.value); }

        var previousPath = box.querySelector('[data-image-path]').value;
        pendingUploads++;
        show(bar.parentElement, true);
        bar.style.width = '0%';

        request('POST', box.getAttribute('data-upload-url'), body, function (pct) { bar.style.width = pct + '%'; }, function (status, data) {
            pendingUploads--;
            show(bar.parentElement, false);

            if (status === 200 && data && data.path) {
                setPickerImage(box, data.path, data.url);
                var alt = row ? row.querySelector('[data-image-alt]') : null;
                if (alt && data.alt_suggestion) { alt.placeholder = data.alt_suggestion; }
                if (previousPath && previousPath !== data.path) {
                    var cleanup = new FormData();
                    cleanup.append('path', previousPath);
                    request('POST', box.getAttribute('data-remove-url'), cleanup, null, function () {});
                }
                return;
            }

            error.textContent = errorText(status, data);
            show(error, true);
        });
    }

    // ------------------------------------------------------------- gallery
    function gallerySetCount(gallery) {
        var n = gallery.querySelectorAll('[data-gallery-card]').length;
        var el = gallery.querySelector('[data-gallery-count]');
        if (el) { el.textContent = String(n); }
    }

    function galleryUrl(gallery, key, uid) { return gallery.getAttribute('data-' + key + '-url').replace('__UID__', encodeURIComponent(uid)); }

    function galleryStatus(card, text) {
        var el = card.querySelector('[data-gallery-status]');
        if (!el) { return; }
        el.textContent = text;
        show(el, !!text);
    }

    function galleryAddCard(gallery, asset) {
        var template = gallery.querySelector('template[data-gallery-card-template]');
        var wrap = document.createElement('div');
        wrap.innerHTML = template.innerHTML.replace(/__UID__/g, asset.uid).replace(/__URL__/g, asset.url).trim();
        var card = wrap.firstElementChild;
        card.querySelector('[data-gallery-image]').alt = asset.alt_text || '';
        card.querySelector('[data-gallery-field="title"]').value = asset.title || '';
        card.querySelector('[data-gallery-field="category_tag"]').value = asset.category_tag || '';
        gallery.querySelector('[data-gallery-list]').appendChild(card);
        gallerySetCount(gallery);
    }

    function galleryUploadFiles(gallery, files) {
        var queue = Array.prototype.slice.call(files);
        var error = gallery.querySelector('[data-upload-error]');
        var bar = gallery.querySelector('[data-upload-progress]');
        show(error, false);

        (function next() {
            var file = queue.shift();
            if (!file) { show(bar.parentElement, false); return; }

            var problem = checkFile(file);
            if (problem) { error.textContent = file.name + ': ' + problem; show(error, true); next(); return; }

            var body = new FormData();
            body.append('photos[]', file);
            pendingUploads++;
            show(bar.parentElement, true);
            bar.style.width = '0%';

            request('POST', gallery.getAttribute('data-upload-url'), body, function (pct) { bar.style.width = pct + '%'; }, function (status, data) {
                pendingUploads--;
                if (status === 200 && data && data.assets) {
                    data.assets.forEach(function (asset) { galleryAddCard(gallery, asset); });
                } else {
                    error.textContent = file.name + ': ' + errorText(status, data);
                    show(error, true);
                }
                next();
            });
        })();
    }

    function gallerySaveField(gallery, card, field) {
        var body = new FormData();
        body.append(field.getAttribute('data-gallery-field'), field.value);
        galleryStatus(card, 'Saving…');
        request('POST', galleryUrl(gallery, 'update', card.getAttribute('data-uid')), body, null, function (status, data) {
            galleryStatus(card, status === 200 ? 'Saved' : errorText(status, data));
            if (status === 200 && data && data.asset) { card.querySelector('[data-gallery-image]').alt = data.asset.alt_text || ''; }
        });
    }

    function galleryAction(gallery, card, action) {
        var uid = card.getAttribute('data-uid');
        var body = new FormData();

        if (action === 'cover') {
            body.append('is_cover', '1');
            request('POST', galleryUrl(gallery, 'update', uid), body, null, function (status, data) {
                if (status !== 200) { galleryStatus(card, errorText(status, data)); return; }
                gallery.querySelectorAll('[data-gallery-action="cover"]').forEach(function (btn) {
                    btn.className = 'btn btn-sm btn-outline-primary';
                    btn.textContent = 'Make cover';
                });
                var self = card.querySelector('[data-gallery-action="cover"]');
                self.className = 'btn btn-sm btn-primary';
                self.textContent = 'Cover photo';
            });
        } else if (action === 'up' || action === 'down') {
            body.append('direction', action);
            request('POST', galleryUrl(gallery, 'move', uid), body, null, function (status, data) {
                if (status !== 200) { galleryStatus(card, errorText(status, data)); return; }
                var sibling = action === 'up' ? card.previousElementSibling : card.nextElementSibling;
                if (!sibling) { return; }
                if (action === 'up') { card.parentElement.insertBefore(card, sibling); } else { card.parentElement.insertBefore(sibling, card); }
            });
        } else if (action === 'remove') {
            body.append('_method', 'DELETE');
            request('POST', galleryUrl(gallery, 'remove', uid), body, null, function (status, data) {
                if (status !== 200) { galleryStatus(card, errorText(status, data)); return; }
                card.remove();
                gallerySetCount(gallery);
            });
        }
    }

    // ------------------------------------------------ custom-section images
    function updateRevision(data) {
        var form = answerForm();
        var field = form ? form.querySelector('input[name="answers_revision"]') : null;
        if (field && data && data.answers_revision) { field.value = data.answers_revision; }
    }

    function customImageAdd(box, file) {
        var error = box.querySelector('[data-upload-error]');
        var bar = box.querySelector('[data-upload-progress]');
        var problem = checkFile(file);
        show(error, false);
        if (problem) { error.textContent = problem; show(error, true); return; }

        var max = parseInt(box.getAttribute('data-max') || '6', 10);
        if (box.querySelectorAll('[data-custom-image]').length >= max) { error.textContent = 'You can add up to ' + max + ' images.'; show(error, true); return; }

        var body = new FormData();
        body.append('photo', file);
        pendingUploads++;
        show(bar.parentElement, true);
        bar.style.width = '0%';

        request('POST', box.getAttribute('data-upload-url'), body, function (pct) { bar.style.width = pct + '%'; }, function (status, data) {
            pendingUploads--;
            show(bar.parentElement, false);
            if (status !== 200 || !data || !data.uid) { error.textContent = errorText(status, data); show(error, true); return; }

            var item = document.createElement('div');
            item.className = 'text-center';
            item.setAttribute('data-custom-image', '');
            item.setAttribute('data-uid', data.uid);
            var img = document.createElement('img');
            img.src = data.url; img.alt = data.alt_text || '';
            img.className = 'rounded d-block mb-1';
            img.style.cssText = 'width:80px;height:80px;object-fit:cover;';
            var remove = document.createElement('button');
            remove.type = 'button'; remove.className = 'btn btn-sm btn-outline-danger';
            remove.setAttribute('data-custom-image-remove', ''); remove.textContent = 'Remove';
            item.appendChild(img); item.appendChild(remove);
            box.querySelector('[data-custom-images-list]').appendChild(item);

            var hidden = document.createElement('input');
            hidden.type = 'hidden'; hidden.name = box.getAttribute('data-field-name'); hidden.value = data.uid;
            hidden.setAttribute('data-custom-image-field', '');
            var row = screen.querySelector('[data-row]');
            (row || answerForm()).appendChild(hidden);
            updateRevision(data);
        });
    }

    function customImageRemove(box, item) {
        var uid = item.getAttribute('data-uid');
        var body = new FormData();
        body.append('_method', 'DELETE');
        request('POST', box.getAttribute('data-remove-url').replace('__UID__', encodeURIComponent(uid)), body, null, function (status, data) {
            if (status !== 200) { return; }
            item.remove();
            screen.querySelectorAll('[data-custom-image-field]').forEach(function (field) { if (field.value === uid) { field.remove(); } });
            updateRevision(data);
        });
    }

    // ----------------------------------------------- AI service description
    function aiDescribe(button) {
        var row = button.closest('[data-row]');
        var name = row.querySelector('[data-item-name]');
        var area = row.querySelector('[data-item-description]');
        var status = row.querySelector('[data-ai-status]');
        function say(text) { if (status) { status.textContent = text; show(status, !!text); } }

        if (!name || name.value.trim() === '') { say('Enter a name first.'); return; }

        var last = area.getAttribute('data-ai-last');
        if (area.value.trim() !== '' && area.value !== last && !window.confirm('Replace the description you wrote with a new AI suggestion?')) { return; }

        button.disabled = true;
        say('Writing a description…');

        fetch(button.getAttribute('data-url'), {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({ name: name.value.trim() })
        }).then(function (response) {
            return response.json().then(function (data) { return { ok: response.ok, data: data }; });
        }).then(function (result) {
            if (result.ok && result.data && result.data.description) {
                area.value = result.data.description;
                area.setAttribute('data-ai-last', result.data.description);
                say('Done — edit it however you like.');
            } else {
                say((result.data && result.data.message) || 'AI descriptions are not available right now.');
            }
        }).catch(function () {
            say('AI descriptions are not available right now.');
        }).then(function () { button.disabled = false; });
    }

    // ----------------------------------------------------------- wiring
    screen.addEventListener('click', function (event) {
        var target = event.target;
        var el;

        if ((el = target.closest('[data-add-row]'))) { event.preventDefault(); addRow(el.closest('[data-repeatable]')); return; }
        if ((el = target.closest('[data-remove-row]'))) { event.preventDefault(); removeRow(el.closest('[data-row]')); return; }
        if ((el = target.closest('[data-move-row]'))) { event.preventDefault(); moveRow(el.closest('[data-row]'), el.getAttribute('data-move-row')); return; }
        if ((el = target.closest('[data-ai-describe]'))) { event.preventDefault(); aiDescribe(el); return; }
        if ((el = target.closest('[data-image-replace]'))) { event.preventDefault(); el.closest('[data-image-picker]').querySelector('[data-image-input]').click(); return; }
        if ((el = target.closest('[data-image-remove]'))) {
            event.preventDefault();
            var box = el.closest('[data-image-picker]');
            discardPickerFile(box);
            setPickerImage(box, '', '');
            return;
        }
        if ((el = target.closest('[data-gallery-action]'))) {
            event.preventDefault();
            galleryAction(el.closest('[data-gallery]'), el.closest('[data-gallery-card]'), el.getAttribute('data-gallery-action'));
            return;
        }
        if ((el = target.closest('[data-custom-image-remove]'))) {
            event.preventDefault();
            customImageRemove(el.closest('[data-custom-images]'), el.closest('[data-custom-image]'));
        }
    });

    screen.addEventListener('change', function (event) {
        var target = event.target;
        var box;

        if (target.matches('[data-image-input]')) {
            box = target.closest('[data-image-picker]');
            if (target.files && target.files[0]) { uploadPickerFile(box, target.files[0]); }
            target.value = '';
        } else if (target.matches('[data-gallery-input]')) {
            if (target.files && target.files.length) { galleryUploadFiles(target.closest('[data-gallery]'), target.files); }
            target.value = '';
        } else if (target.matches('[data-custom-image-input]')) {
            if (target.files && target.files[0]) { customImageAdd(target.closest('[data-custom-images]'), target.files[0]); }
            target.value = '';
        } else if (target.matches('[data-gallery-field]')) {
            gallerySaveField(target.closest('[data-gallery]'), target.closest('[data-gallery-card]'), target);
        }
    });

    var form = answerForm();
    if (form) {
        form.addEventListener('submit', function (event) {
            if (pendingUploads > 0) {
                event.preventDefault();
                window.alert('Please wait for your images to finish uploading.');
            }
        });
    }

    screen.querySelectorAll('[data-image-picker]').forEach(function (box) {
        var hidden = box.querySelector('[data-image-path]');
        setPickerImage(box, hidden.value, '');
    });
})();
</script>
