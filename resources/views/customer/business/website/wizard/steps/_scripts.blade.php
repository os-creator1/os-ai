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

    // `root` is the persistent shell; `screen` is the CURRENT screen inside it and is
    // re-resolved after every in-page navigation (see swapScreen()).
    var root = document.querySelector('[data-wizard-root]');
    if (!root) { return; }
    var screen = root.querySelector('[data-wizard-screen]');

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
                wizard.markDirty(0);
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
        if (field && data && data.answers_revision) {
            field.value = data.answers_revision;
            // This tab's own media endpoint bumped the revision; a draft already in flight was sent
            // with the previous one and is retried against the new revision rather than reported as a conflict.
            wizard.noteOwnRevisionBump();
        }
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

    // ------------------------------------------------------------- logo
    function logoUpload(box, file) {
        var error = box.querySelector('[data-upload-error]');
        var bar = box.querySelector('[data-upload-progress]');
        var problem = checkFile(file);
        show(error, false);
        if (problem) { error.textContent = problem; show(error, true); return; }
        var body = new FormData();
        body.append('logo', file);
        pendingUploads++;
        show(bar.parentElement, true);
        bar.style.width = '0%';
        request('POST', box.getAttribute('data-upload-url'), body, function (pct) { bar.style.width = pct + '%'; }, function (status, data) {
            pendingUploads--;
            show(bar.parentElement, false);
            if (status === 200 && data && data.url) {
                var preview = box.querySelector('[data-logo-preview]');
                preview.src = data.url; preview.alt = data.alt_text || 'Your logo';
                show(preview, true);
                show(box.querySelector('[data-logo-remove]'), true);
                var addLabel = box.querySelector('[data-logo-add]');
                addLabel.firstChild.textContent = 'Replace logo';
                return;
            }
            error.textContent = errorText(status, data); show(error, true);
        });
    }

    function logoRemove(box) {
        var body = new FormData();
        body.append('_method', 'DELETE');
        request('POST', box.getAttribute('data-remove-url'), body, null, function (status) {
            if (status !== 200) { return; }
            show(box.querySelector('[data-logo-preview]'), false);
            show(box.querySelector('[data-logo-remove]'), false);
            box.querySelector('[data-logo-add]').firstChild.textContent = '+ Upload logo';
        });
    }

    // ------------------------------------------- nearby service-area suggestions
    function areaValues(container) {
        return directRows(container).map(function (row) { var i = row.querySelector('input[type="text"]'); return i ? i.value.trim() : ''; }).filter(Boolean);
    }

    function areaSay(list, text) {
        var el = list.parentElement.querySelector('[data-area-status]');
        if (el) { el.textContent = text; show(el, !!text); }
    }

    function areaFetch(list, exclude, done) {
        var params = new URLSearchParams();
        params.set('city', list.getAttribute('data-base-city') || '');
        if (list.getAttribute('data-base-region')) { params.set('region', list.getAttribute('data-base-region')); }
        params.set('country', list.getAttribute('data-base-country') || '');
        params.set('count', '10');
        exclude.forEach(function (name) { params.append('exclude[]', name); });
        fetch(list.getAttribute('data-suggest-url') + '?' + params.toString(), { credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, data: d }; }); })
            .then(function (res) { done(res.ok && res.data ? res.data : null); })
            .catch(function () { done(null); });
    }

    function areaSetRows(container, labels) {
        directRows(container).slice(1).forEach(function (row) { row.remove(); });
        var first = directRows(container)[0];
        if (first) { first.querySelector('input[type="text"]').value = labels[0] || ''; }
        labels.slice(1).forEach(function (label) {
            addRow(container);
            var rows = directRows(container);
            rows[rows.length - 1].querySelector('input[type="text"]').value = label;
        });
        reindex(container);
    }

    function areaSuggestMore(button) {
        var list = screen.querySelector('[data-area-list]');
        var container = screen.querySelector('[data-repeatable]');
        if (!list || !container) { return; }
        button.disabled = true;
        areaSay(list, 'Looking for nearby communities\u2026');
        areaFetch(list, areaValues(container), function (data) {
            button.disabled = false;
            if (!data) { areaSay(list, 'Couldn\u2019t load suggestions right now. Try again in a moment.'); return; }
            var fresh = (data.suggestions || []).map(function (s) { return s.label; });
            if (!data.resolved || fresh.length === 0) { areaSay(list, 'No more nearby communities to suggest.'); return; }
            // Fill blank rows first, then add new ones.
            var blanks = directRows(container).filter(function (row) { return row.querySelector('input[type="text"]').value.trim() === ''; });
            fresh.forEach(function (label) {
                var row = blanks.shift();
                if (!row) { addRow(container); var rows = directRows(container); row = rows[rows.length - 1]; }
                row.querySelector('input[type="text"]').value = label;
            });
            reindex(container);
            areaSay(list, 'Added ' + fresh.length + ' suggestion' + (fresh.length === 1 ? '' : 's') + ' \u2014 review them below.');
            wizard.markDirty(0);
        });
    }

    function areaRefresh(button) {
        var list = screen.querySelector('[data-area-list]');
        var container = screen.querySelector('[data-repeatable]');
        if (!list || !container) { return; }
        if (!window.confirm('Replace your current service areas with fresh suggestions near your new base location? Your edits to the current list will be lost.')) { return; }
        button.disabled = true;
        areaFetch(list, [], function (data) {
            button.disabled = false;
            var fresh = data && data.resolved ? (data.suggestions || []).map(function (s) { return s.label; }) : [];
            if (fresh.length === 0) { areaSay(list, 'We couldn\u2019t find nearby communities for that location \u2014 your list is unchanged.'); return; }
            areaSetRows(container, fresh);
            var note = list.querySelector('[data-area-note="base-changed"]');
            if (note) { note.remove(); }
            wizard.markDirty(0);
        });
    }

    // ----------------------------------------------------------- wiring
    root.addEventListener('click', function (event) {
        var target = event.target;
        var el;

        if ((el = target.closest('[data-faq-suggestion]'))) {
            // Niche suggestion: fill the first empty question row, else add one — the owner writes the answer.
            event.preventDefault();
            var faqContainer = el.closest('[data-testid="faq-suggestions"]').nextElementSibling;
            var emptyQuestion = null;
            directRows(faqContainer).some(function (row) {
                var q = row.querySelector('input[name$="[question]"]');
                if (q && q.value.trim() === '') { emptyQuestion = q; return true; }
                return false;
            });
            if (!emptyQuestion) {
                addRow(faqContainer);
                var rows = directRows(faqContainer);
                emptyQuestion = rows[rows.length - 1].querySelector('input[name$="[question]"]');
            }
            if (emptyQuestion) {
                emptyQuestion.value = el.getAttribute('data-faq-suggestion');
                var answer = emptyQuestion.closest('[data-row]').querySelector('textarea, input[name$="[answer]"]');
                if (answer) { answer.focus(); }
            }
            wizard.markDirty(0);
            return;
        }
        if ((el = target.closest('[data-logo-remove]'))) { event.preventDefault(); logoRemove(el.closest('[data-logo-box]')); return; }
        if ((el = target.closest('[data-area-suggest-more]'))) { event.preventDefault(); areaSuggestMore(el); return; }
        if ((el = target.closest('[data-area-refresh]'))) { event.preventDefault(); areaRefresh(el); return; }
        if ((el = target.closest('[data-add-row]'))) { event.preventDefault(); addRow(el.closest('[data-repeatable]')); wizard.markDirty(0); return; }
        if ((el = target.closest('[data-remove-row]'))) { event.preventDefault(); removeRow(el.closest('[data-row]')); wizard.markDirty(0); return; }
        if ((el = target.closest('[data-move-row]'))) { event.preventDefault(); moveRow(el.closest('[data-row]'), el.getAttribute('data-move-row')); wizard.markDirty(0); return; }
        if ((el = target.closest('[data-ai-describe]'))) { event.preventDefault(); aiDescribe(el); return; }
        if ((el = target.closest('[data-image-replace]'))) { event.preventDefault(); el.closest('[data-image-picker]').querySelector('[data-image-input]').click(); return; }
        if ((el = target.closest('[data-image-remove]'))) {
            event.preventDefault();
            var box = el.closest('[data-image-picker]');
            discardPickerFile(box);
            setPickerImage(box, '', '');
            wizard.markDirty(0);
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

    root.addEventListener('change', function (event) {
        var target = event.target;
        var box;

        if (target.matches('[data-logo-input]')) {
            if (target.files && target.files[0]) { logoUpload(target.closest('[data-logo-box]'), target.files[0]); }
            target.value = '';
        } else if (target.matches('[data-image-input]')) {
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

    // ------------------------------------------------- screen (re)initialisation
    function initScreen() {
        screen = root.querySelector('[data-wizard-screen]');
        if (!screen) { return; }

        screen.querySelectorAll('[data-repeatable]').forEach(function (container) {
            if (container.hasAttribute('data-nested')) { nameNested(container, outerIndexOf(container)); }
            reindex(container);
        });

        screen.querySelectorAll('[data-image-picker]').forEach(function (box) {
            var hidden = box.querySelector('[data-image-path]');
            setPickerImage(box, hidden.value, '');
        });
    }

    // =====================================================================
    // Wizard shell: in-page navigation + durable autosave.
    //
    // Everything here is progressive enhancement over ordinary forms — with
    // no script, Continue/Back still POST and redirect. With it:
    //  * edits autosave (debounced) to the server as drafts;
    //  * saves are strictly SERIALISED (one in flight) and carry the
    //    server's answers_revision, so a slower, earlier request can never
    //    overwrite a newer one — the server also refuses a stale revision;
    //  * Continue / Back / Save & exit first flush pending edits and only
    //    navigate once the server confirmed them. A failed save never
    //    navigates away, keeps what the owner typed and offers a retry;
    //  * moving between screens swaps just the screen element and updates
    //    the URL with the History API — no document reload.
    // =====================================================================
    var wizard = (function () {
        var DEBOUNCE_MS = 800;
        var state = { dirty: false, inflight: null, timer: null, lastResult: { ok: true }, navigating: false, ownBumps: 0 };
        // The screens visited through this page load, in order, and where we are in them: the in-app Back button
        // steps back through browser history when it can (so the browser's Forward button then works) instead of
        // stacking a duplicate entry.
        var trail = [window.location.href];
        var trailAt = 0;

        function statusEl() { return root.querySelector('[data-save-status]'); }
        function errorEl() { return root.querySelector('[data-wizard-error]'); }
        function revisionInput() { var f = answerForm(); return f ? f.querySelector('input[name="answers_revision"]') : null; }

        function setStatus(kind, extra) {
            var el = statusEl();
            if (!el) { return; }
            el.textContent = '';
            el.removeAttribute('data-state');
            el.setAttribute('data-state', kind);
            if (kind === 'saving') { el.textContent = 'Saving\u2026'; }
            else if (kind === 'saved') { el.textContent = 'Saved'; }
            else if (kind === 'pending') { el.textContent = 'Unsaved changes'; }
            else if (kind === 'invalid') { el.textContent = 'Finish this field to save'; }
            else if (kind === 'error') {
                el.appendChild(document.createTextNode('Couldn\u2019t save \u2014 '));
                var retry = document.createElement('button');
                retry.type = 'button';
                retry.className = 'btn btn-link p-0 align-baseline';
                retry.setAttribute('data-save-retry', '');
                retry.textContent = 'Retry';
                el.appendChild(retry);
                if (extra) { el.appendChild(document.createTextNode(' (' + extra + ')')); }
            }
        }

        function showError(message) {
            var el = errorEl();
            if (!el) { return; }
            el.textContent = message;
            el.classList.remove('d-none');
            el.focus();
        }

        function clearError() { var el = errorEl(); if (el) { el.classList.add('d-none'); el.textContent = ''; } }

        function csrfHeaders() {
            return { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
        }

        function postJson(url, formData) {
            return fetch(url, { method: 'POST', credentials: 'same-origin', headers: csrfHeaders(), body: formData }).then(function (response) {
                return response.json().catch(function () { return null; }).then(function (data) {
                    return { status: response.status, data: data };
                });
            });
        }

        function adoptRevision(revision) {
            var input = revisionInput();
            var next = parseInt(revision, 10);
            // Monotonic: a late response can never move the revision backwards.
            if (input && next && next > parseInt(input.value || '0', 10)) { input.value = String(next); }
        }

        function markDirty(delay) {
            var host = screen;
            if (!host || state.navigating) { return; }
            state.dirty = true;
            clearTimeout(state.timer);
            if (host.getAttribute('data-autosave') === 'off') { setStatus('pending'); return; }
            setStatus('pending');
            state.timer = setTimeout(function () { flush(false); }, delay === undefined ? DEBOUNCE_MS : delay);
        }

        // Saves the current screen's draft. Resolves { ok, kind? }. Never rejects.
        function flush(explicit) {
            clearTimeout(state.timer);

            if (state.inflight) {
                // Strictly one save at a time; whatever changed meanwhile is saved by the next pass.
                return state.inflight.then(function () { return flush(explicit); });
            }
            if (!state.dirty) { return Promise.resolve(state.lastResult); }
            if (pendingUploads > 0) { return waitForUploads().then(function () { return flush(explicit); }); }

            var host = screen;
            var form = answerForm();
            if (!host || !form) { return Promise.resolve({ ok: true }); }

            var body = new FormData(form);
            if (revisionInput()) { body.set('answers_revision', revisionInput().value); }
            if (explicit) { body.set('flush', '1'); }
            state.dirty = false;
            state.ownBumps = 0;
            setStatus('saving');

            state.inflight = postJson(host.getAttribute('data-draft-url'), body).then(function (res) {
                var data = res.data || {};
                if (res.status === 200 && data.ok) {
                    adoptRevision(data.revision);
                    state.lastResult = { ok: true };
                    if (!state.dirty) { setStatus('saved'); }
                    return state.lastResult;
                }
                state.dirty = true;
                if (res.status === 409 && data.kind === 'conflict' && state.ownBumps > 0 && data.revision) {
                    // Our own upload/remove bumped the revision while this draft was in flight: not a
                    // cross-tab conflict. Adopt the server's revision and save again from the live form.
                    state.ownBumps = 0;
                    adoptRevision(data.revision);
                    state.inflight = null;
                    return flush(explicit);
                }
                if (res.status === 409 && data.kind === 'conflict') {
                    setStatus('error', 'this was changed in another tab');
                    showError('This setup was changed in another tab or device. Reload the page to see the latest answers \u2014 your unsaved text on this screen is still here until you do.');
                    state.lastResult = { ok: false, kind: 'conflict' };
                } else if (res.status === 422) {
                    setStatus('invalid');
                    state.lastResult = { ok: false, kind: 'invalid', message: data.message };
                } else if (res.status === 419) {
                    setStatus('error', 'session expired \u2014 reload');
                    state.lastResult = { ok: false, kind: 'auth' };
                } else {
                    setStatus('error');
                    state.lastResult = { ok: false, kind: 'server', message: data.message };
                }
                return state.lastResult;
            }).catch(function () {
                // Offline / connection lost: nothing was saved, so nothing is claimed as saved.
                state.dirty = true;
                setStatus('error', 'you appear to be offline');
                state.lastResult = { ok: false, kind: 'network' };
                return state.lastResult;
            }).then(function (result) {
                state.inflight = null;
                return result;
            });

            return state.inflight;
        }

        function waitForUploads() {
            return new Promise(function (resolve) {
                (function check() { if (pendingUploads > 0) { setTimeout(check, 150); } else { resolve(); } })();
            });
        }

        // ---- navigation -------------------------------------------------
        function isWizardUrl(url) {
            try { var u = new URL(url, window.location.href); return u.origin === window.location.origin && /\/website\/build\//.test(u.pathname) && !/\/review$/.test(u.pathname); } catch (e) { return false; }
        }

        function swapScreen(html, finalUrl, push) {
            var doc = new DOMParser().parseFromString(html, 'text/html');
            var incoming = doc.querySelector('[data-wizard-screen]');
            if (!incoming) { return false; }
            var current = root.querySelector('[data-wizard-screen]');
            current.replaceWith(document.importNode(incoming, true));
            if (doc.title) { document.title = doc.title; }
            // The page header (outside the swapped screen) names the screen too.
            var headerNow = document.querySelector('.content-header-title');
            var headerNext = doc.querySelector('.content-header-title');
            if (headerNow && headerNext) { headerNow.textContent = headerNext.textContent; }
            if (push) {
                window.history.pushState({ wizard: true }, '', finalUrl);
                trail = trail.slice(0, trailAt + 1);
                trail.push(finalUrl);
                trailAt = trail.length - 1;
            }
            state.dirty = false;
            state.lastResult = { ok: true };
            state.navigating = false;
            initScreen();
            window.scrollTo(0, 0);
            var heading = screen.querySelector('[data-wizard-heading]') || screen.querySelector('h4, h5');
            if (heading) { heading.setAttribute('tabindex', '-1'); heading.focus({ preventScroll: true }); }
            return true;
        }

        function navigate(url, push) {
            if (!isWizardUrl(url)) { window.location.assign(url); return Promise.resolve(); }
            state.navigating = true;
            root.setAttribute('aria-busy', 'true');
            return fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'text/html', 'X-Requested-With': 'XMLHttpRequest' } }).then(function (response) {
                if (!response.ok && response.status !== 404) { throw new Error('http ' + response.status); }
                var finalUrl = response.url || url;
                if (!isWizardUrl(finalUrl)) { window.location.assign(finalUrl); return null; }
                return response.text().then(function (html) {
                    if (!swapScreen(html, finalUrl, push)) { window.location.assign(finalUrl); }
                });
            }).catch(function () {
                // Could not fetch the next screen in place: fall back to a normal navigation (state is saved).
                window.location.assign(url);
            }).then(function () {
                state.navigating = false;
                root.removeAttribute('aria-busy');
            });
        }

        function leaveAfterSave(go, opts) {
            opts = opts || {};
            clearError();
            return flush(true).then(function (result) {
                if (result.ok) { return go(); }
                if (result.kind === 'invalid' && opts.allowDiscard) {
                    if (window.confirm('Part of this screen can\u2019t be saved yet (' + (result.message || 'it is incomplete') + '). Go back without saving that part?')) {
                        state.dirty = false;
                        return go();
                    }
                    return null;
                }
                showError(result.kind === 'invalid'
                    ? (result.message || 'Please finish this field first.')
                    : 'Your changes could not be saved, so we haven\u2019t moved on. Check your connection and use Retry \u2014 nothing you typed has been lost.');
                return null;
            });
        }

        function submitContinue(submitter) {
            var form = answerForm();
            var host = screen;
            clearError();
            return flush(false).then(function (pre) {
                // Wait for any in-flight draft so the revision we send is the server's latest.
                if (!pre.ok && pre.kind !== 'invalid') {
                    showError('Your changes could not be saved yet, so we haven\u2019t moved on. Use Retry \u2014 nothing you typed has been lost.');
                    return null;
                }
                var body = new FormData(form);
                if (revisionInput()) { body.set('answers_revision', revisionInput().value); }
                if (submitter && submitter.name) { body.set(submitter.name, submitter.value); }
                setStatus('saving');
                return postJson(form.getAttribute('action'), body).then(function (res) {
                    var data = res.data || {};
                    if (res.status === 200 && data.ok) {
                        adoptRevision(data.revision);
                        state.dirty = false;
                        setStatus('saved');
                        return data.full_page ? window.location.assign(data.next_url) : navigate(data.next_url, true);
                    }
                    state.dirty = true;
                    if (res.status === 422) { setStatus('invalid'); showError(data.message || 'Please check your answer.'); }
                    else if (res.status === 409) { setStatus('error', 'changed elsewhere'); showError(data.message || 'This setup changed in another tab. Reload to continue.'); }
                    else { setStatus('error'); showError('Something went wrong saving this screen. Please try again.'); }
                    return null;
                });
            }).catch(function () {
                state.dirty = true;
                setStatus('error', 'you appear to be offline');
                showError('Your connection dropped, so nothing was saved and we haven\u2019t moved on. Reconnect and press Continue again \u2014 what you typed is still here.');
            });
        }

        function submitBack(form) {
            return leaveAfterSave(function () {
                return postJson(form.getAttribute('action'), new FormData()).then(function (res) {
                    var data = res.data || {};
                    if (res.status === 200 && data.ok) {
                        if (data.full_page) { return window.location.assign(data.url); }
                        // The previous screen is the previous history entry: go there the way the browser's own Back does.
                        if (trailAt > 0 && trail[trailAt - 1] === data.url) { window.history.back(); return null; }
                        return navigate(data.url, true);
                    }
                    showError(data.message || 'Could not go back right now. Please try again.');
                    return null;
                });
            }, { allowDiscard: true }).catch(function () {
                showError('Your connection dropped, so we haven\u2019t moved. Try again in a moment.');
            });
        }

        function exitWizard() {
            leaveAfterSave(function () { window.location.assign(screen.getAttribute('data-exit-url')); });
        }

        // ---- wiring (delegated on the persistent root; survives swaps) ----
        root.addEventListener('input', function (event) {
            if (event.target.closest && event.target.closest('form[id^="answer-form-"]') && !event.target.matches('[data-gallery-field]')) { markDirty(); }
        });
        root.addEventListener('change', function (event) {
            if (event.target.closest && event.target.closest('form[id^="answer-form-"]') && !event.target.matches('[data-gallery-input], [data-image-input], [data-custom-image-input], [data-gallery-field]')) { markDirty(0); }
        });

        root.addEventListener('submit', function (event) {
            var form = event.target;
            if (form.matches && form.matches('form[id^="answer-form-"]')) {
                event.preventDefault();
                if (pendingUploads > 0) { showError('Please wait for your images to finish uploading.'); return; }
                // A formaction override (e.g. "Improve with AI") is a normal submission, not a Continue.
                if (event.submitter && event.submitter.hasAttribute('formaction')) { form.submit(); return; }
                submitContinue(event.submitter);
            } else if (form.matches && form.matches('form[data-wizard-back]')) {
                event.preventDefault();
                submitBack(form);
            }
        }, true);

        root.addEventListener('click', function (event) {
            var el;
            if ((el = event.target.closest('[data-save-retry]'))) { event.preventDefault(); clearError(); flush(true); return; }
            if ((el = event.target.closest('[data-wizard-exit]'))) { event.preventDefault(); exitWizard(); }
        });

        window.addEventListener('popstate', function () {
            if (!isWizardUrl(window.location.href)) { return; }
            var known = trail.lastIndexOf(window.location.href);
            if (known === -1) { trail = [window.location.href]; trailAt = 0; } else { trailAt = known; }
            flush(true).then(function () {
                return navigate(window.location.href, false).then(function () {
                    // Browser Back/Forward changes screens without a save: record the resume position.
                    var host = screen;
                    if (host && host.getAttribute('data-visit-url')) { postJson(host.getAttribute('data-visit-url'), new FormData()); }
                });
            });
        });

        window.addEventListener('beforeunload', function (event) {
            if (state.dirty || state.inflight) { event.preventDefault(); event.returnValue = ''; }
        });

        // Last-chance save when the tab is closed or hidden with unsaved edits.
        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState !== 'hidden' || !state.dirty || state.inflight || !screen) { return; }
            var form = answerForm();
            if (!form || !navigator.sendBeacon || pendingUploads > 0) { return; }
            var body = new FormData(form);
            if (revisionInput()) { body.set('answers_revision', revisionInput().value); }
            body.set('flush', screen.getAttribute('data-autosave') === 'off' ? '1' : '0');
            body.set('_token', csrf);
            if (navigator.sendBeacon(screen.getAttribute('data-draft-url'), body)) { state.dirty = false; }
        });

        window.history.replaceState({ wizard: true }, '', window.location.href);

        return { markDirty: markDirty, flush: flush, noteOwnRevisionBump: function () { state.ownBumps++; } };
    })();

    initScreen();
})();
</script>
