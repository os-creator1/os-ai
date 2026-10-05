/*
 * Forms visual builder (browser side).
 *
 * The page hands this script ONE JSON document (#fb-state): the form as the
 * server stores it (name, intro, style, ordered pages, flat ordered elements,
 * each element carrying its page key), the toolbox, the Business's canonical
 * Custom Fields and the mapping-compatibility table. Nothing here invents a
 * field type or a rule: a dropped toolbox item becomes an element with exactly the
 * properties the server described, and the server (FormDefinitionNormalizer)
 * still decides what is valid.
 *
 * Moves and edits update the DOM at once (optimistic), then a debounced,
 * serialized autosave posts the whole document with the version it was editing.
 * The server refuses a save on top of any other version (409) so a second tab or a
 * teammate can never be silently overwritten; a save that changes nothing writes
 * no version. No full page reload happens after a move or an edit.
 *
 * Keyboard: toolbox buttons add; a focused element moves with Alt+Up / Alt+Down
 * and is removed with Delete; every control is a real button or input.
 */
(function () {
    'use strict';

    var stateEl = document.getElementById('fb-state');
    var configEl = document.getElementById('fb-config');
    if (!stateEl || !configEl) { return; }

    var S = JSON.parse(stateEl.textContent);
    var C = JSON.parse(configEl.textContent);
    var doc = S.doc;
    if (Array.isArray(doc.design) || !doc.design) { doc.design = {}; }

    var $ = function (id) { return document.getElementById(id); };
    var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';

    var ACCENTS = ['#2563eb', '#7c3aed', '#db2777', '#dc2626', '#ea580c', '#16a34a', '#0f766e', '#111827'];
    var RADII = { none: '0px', sm: '4px', md: '8px', lg: '16px' };
    var WIDTHS = { narrow: '30rem', medium: '40rem', wide: '52rem' };
    var GLYPH = {
        user: 'Aa', mail: '@', phone: '☎', type: 'T', 'align-left': '¶', hash: '#', 'dollar-sign': '$', calendar: '▦',
        clock: '◷', 'chevron-down': '▾', 'check-square': '☑', check: '✓', circle: '◉', 'toggle-left': '⇄',
        heading: 'H', 'file-text': '¶', minus: '—', 'move-vertical': '↕', send: '➤', shield: '⛨', database: '⛁'
    };
    var UNIQUE_TYPES = ['phone', 'consent_transactional', 'consent_marketing'];
    var HAS_OPTIONS = ['select', 'multi_select', 'radio'];
    var CONTENT = ['heading', 'paragraph', 'divider', 'spacer'];
    var CONSENT = ['consent_transactional', 'consent_marketing'];
    var PLACEHOLDER_TYPES = ['text', 'textarea', 'email', 'phone', 'number', 'currency', 'select'];
    var DEFAULT_TYPES = ['text', 'textarea', 'select', 'radio', 'number', 'currency'];
    var TYPE_NAME = {
        text: 'Short text', textarea: 'Long text', email: 'Email', phone: 'Phone', select: 'Dropdown', checkbox: 'Checkbox', date: 'Date',
        number: 'Number', currency: 'Currency', datetime: 'Date & time', multi_select: 'Multi-select', radio: 'Radio buttons', yes_no: 'Yes / No',
        consent_transactional: 'Transactional consent', consent_marketing: 'Marketing consent', heading: 'Heading', paragraph: 'Paragraph',
        divider: 'Divider', spacer: 'Spacer'
    };

    var sel = null;            // {kind:'field', key} | {kind:'submit'} | null
    var drag = null;           // {kind:'tool', item} | {kind:'field', key}
    var dirty = false;
    var saving = false;
    var again = false;
    var stale = false;
    var version = S.version;
    var baseHash = S.hash || null;
    var timer = null;
    var seq = 0;

    // ------------------------------------------------------------------ utils
    function h(tag, attrs, kids) {
        var n = document.createElement(tag);
        if (attrs) {
            Object.keys(attrs).forEach(function (k) {
                var v = attrs[k];
                if (v === null || v === undefined || v === false) { return; }
                if (k === 'class') { n.className = v; }
                else if (k === 'text') { n.textContent = v; }
                else if (k.slice(0, 2) === 'on') { n.addEventListener(k.slice(2), v); }
                else if (v === true) { n.setAttribute(k, ''); }
                else { n.setAttribute(k, v); }
            });
        }
        (kids || []).forEach(function (c) { if (c) { n.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); } });
        return n;
    }
    function clear(n) { while (n.firstChild) { n.removeChild(n.firstChild); } }
    function clone(o) { return JSON.parse(JSON.stringify(o)); }
    function byKey(key) { for (var i = 0; i < doc.fields.length; i++) { if (doc.fields[i].key === key) { return doc.fields[i]; } } return null; }
    function isContent(f) { return CONTENT.indexOf(f.type) > -1; }
    function isConsent(f) { return CONSENT.indexOf(f.type) > -1; }
    function pageKeys() { return doc.pages.map(function (p) { return p.key; }); }
    function fieldsOn(pageKey) { return doc.fields.filter(function (f) { return f.page === pageKey; }); }
    function usedKeys() { var o = {}; doc.fields.forEach(function (f) { o[f.key] = 1; }); return o; }
    var RESERVED = ['form_hp', 'operation_token', 'location_uid', 'page'];
    // A readable, stable key from the element's starting label ("date", "short_text").
    // It never changes afterwards, however the element is relabelled.
    function newKey(base) {
        var used = usedKeys();
        var slug = String(base || '').toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '').slice(0, 24).replace(/_+$/, '');
        if (slug && !/^[a-z]/.test(slug)) { slug = 'f_' + slug; }
        if (slug) {
            var k = slug, n = 2;
            while (used[k] || RESERVED.indexOf(k) > -1) { k = slug + '_' + n++; }
            return k;
        }
        var r;
        do { r = 'f' + Math.random().toString(36).slice(2, 8); } while (used[r]);
        return r;
    }
    function newPageKey() {
        var have = pageKeys();
        for (var n = 1; ; n++) { if (have.indexOf('page_' + n) < 0) { return 'page_' + n; } }
    }
    function mappedUids(exceptKey) {
        var o = {};
        doc.fields.forEach(function (f) { if (f.custom_field_uid && f.key !== exceptKey) { o[f.custom_field_uid] = 1; } });
        return o;
    }
    function isUniqueField(f) {
        return UNIQUE_TYPES.indexOf(f.type) > -1 || f.contact_name || f.contact_part || !!f.custom_field_uid;
    }
    function firstEmailKey() {
        for (var i = 0; i < doc.fields.length; i++) { if (doc.fields[i].type === 'email') { return doc.fields[i].key; } }
        return null;
    }
    function builtinOf(f) {
        if (f.contact_name) { return 'full_name'; }
        if (f.contact_part) { return f.contact_part; }
        return '';
    }

    // ------------------------------------------------------------- save state
    var saveEl = $('fb-save');
    var bannerEl = $('fb-banner');
    function setSave(state, text) {
        if (!saveEl) { return; }
        saveEl.setAttribute('data-state', state);
        saveEl.textContent = text;
    }
    function banner(msg, opts) {
        opts = opts || {};
        $('fb-banner-text').textContent = msg || '';
        $('fb-reload').hidden = !opts.reload;
        bannerEl.classList.toggle('is-on', !!msg);
    }
    var flashTimer = null;
    function flash(msg) {
        banner(msg);
        clearTimeout(flashTimer);
        flashTimer = setTimeout(function () { if (!stale && saveEl.getAttribute('data-state') !== 'failed') { banner(''); } }, 4500);
    }

    function payload() {
        var fields = doc.fields.map(function (f) {
            var o = {
                key: f.key, label: f.label || '', type: f.type, page: f.page,
                required: !!f.required && !isContent(f),
                options: HAS_OPTIONS.indexOf(f.type) > -1 ? (f.options || []) : [],
                contact_name: !!f.contact_name
            };
            if (f.contact_part) { o.contact_part = f.contact_part; }
            if (f.custom_field_uid) { o.custom_field_uid = f.custom_field_uid; }
            if (f.placeholder) { o.placeholder = f.placeholder; }
            if (f.help) { o.help = f.help; }
            if (f.width === 'half') { o.width = 'half'; }
            if (f['default']) { o['default'] = f['default']; }
            return o;
        });
        var design = {};
        Object.keys(doc.design || {}).forEach(function (k) { if (doc.design[k]) { design[k] = doc.design[k]; } });
        return {
            name: doc.name,
            intro: doc.intro || '',
            submit_label: doc.submit_label || '',
            success_message: doc.success_message || '',
            design: design,
            create_opportunity: !!doc.create_opportunity,
            opportunity_pipeline_id: doc.opportunity_pipeline_id || null,
            pages: doc.pages.map(function (p, i) { return { key: p.key, title: p.title || '', position: i + 1 }; }),
            fields: fields
        };
    }

    function post(url, body) {
        return fetch(url, {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json, text/html', 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify(body)
        });
    }

    function touch() {
        if (stale) { return; }
        dirty = true;
        setSave('saving', 'Saving…');
        clearTimeout(timer);
        timer = setTimeout(save, 1500);
        if (previewOpen) { schedulePreview(); }
    }

    function save() {
        clearTimeout(timer);
        if (stale || !dirty) { return Promise.resolve(); }
        if (saving) { again = true; return Promise.resolve(); }
        saving = true; dirty = false;
        var mine = ++seq;
        var body = payload();
        body.base_version = version;
        body.base_hash = baseHash;
        setSave('saving', 'Saving…');
        return post(C.saveUrl, body).then(function (res) {
            return res.json().catch(function () { return {}; }).then(function (data) { return { res: res, data: data }; });
        }).then(function (r) {
            if (mine !== seq) { return; }          // a newer save superseded this answer
            if (r.res.status === 200 && r.data.status === 'saved') {
                version = r.data.version;
                baseHash = r.data.hash || baseHash;
                banner('');
                setSave(dirty ? 'saving' : 'saved', dirty ? 'Saving…' : 'Saved');
            } else if (r.res.status === 409) {
                stale = true; dirty = false;
                setSave('conflict', 'Conflict — stale tab');
                banner(r.data.message || 'This form was changed somewhere else. Reload to continue from the latest version.', { reload: true });
            } else {
                dirty = true;
                var msg = r.data.message || (r.data.errors ? Object.keys(r.data.errors).map(function (k) { return r.data.errors[k][0]; })[0] : '') || 'The form could not be saved.';
                setSave('failed', 'Save failed');
                banner(msg);
            }
        }).catch(function () {
            dirty = true;
            setSave('failed', 'Save failed');
            banner('You appear to be offline. Your changes will be saved when you edit again.');
        }).then(function () {
            saving = false;
            if (again && !stale) { again = false; dirty = true; return save(); }
            again = false;
        });
    }
    function flush() { clearTimeout(timer); return dirty ? save() : Promise.resolve(); }

    window.addEventListener('beforeunload', function (e) {
        if (dirty || saving) { e.preventDefault(); e.returnValue = ''; }
    });

    // ----------------------------------------------------------------- model
    function canAdd(el) {
        var t = el.type;
        var i;
        if (UNIQUE_TYPES.indexOf(t) > -1) {
            for (i = 0; i < doc.fields.length; i++) { if (doc.fields[i].type === t) { return 'A form can have only one ' + (TYPE_NAME[t] || t).toLowerCase() + ' element.'; } }
        }
        if (el.contact_name) {
            for (i = 0; i < doc.fields.length; i++) {
                if (doc.fields[i].contact_name) { return 'A form can have only one full-name element.'; }
                if (doc.fields[i].contact_part) { return 'Use either one full name or separate first and last names, not both.'; }
            }
        }
        if (el.contact_part) {
            for (i = 0; i < doc.fields.length; i++) {
                if (doc.fields[i].contact_name) { return 'Use either one full name or separate first and last names, not both.'; }
                if (doc.fields[i].contact_part === el.contact_part) { return 'A form can have only one ' + (el.contact_part === 'first_name' ? 'first' : 'last') + '-name element.'; }
            }
        }
        if (el.custom_field_uid && mappedUids()[el.custom_field_uid]) { return 'That contact field is already on this form.'; }
        return null;
    }
    function keyBaseFor(el) {
        if (el.contact_name) { return 'full_name'; }
        if (el.contact_part) { return el.contact_part; }
        if (el.type === 'email' || el.type === 'phone') { return el.type; }
        if (CONSENT.indexOf(el.type) > -1) { return el.type; }
        return null;
    }

    function insertField(el, pageKey, index) {
        var f = clone(el);
        f.key = newKey(keyBaseFor(el) || (isConsent(el) || isContent(el) || !el.label ? el.type : el.label));
        f.page = pageKey;
        f.options = f.options || [];
        var onPage = fieldsOn(pageKey);
        var at;
        if (index >= onPage.length) {
            var last = onPage.length ? onPage[onPage.length - 1] : null;
            at = last ? doc.fields.indexOf(last) + 1 : doc.fields.length;
        } else {
            at = doc.fields.indexOf(onPage[index]);
        }
        doc.fields.splice(at, 0, f);
        return f;
    }
    function moveField(key, pageKey, index) {
        var f = byKey(key);
        if (!f) { return; }
        var peers = fieldsOn(pageKey).filter(function (x) { return x.key !== key; });
        doc.fields.splice(doc.fields.indexOf(f), 1);
        f.page = pageKey;
        var at;
        if (index >= peers.length) {
            at = peers.length ? doc.fields.indexOf(peers[peers.length - 1]) + 1 : doc.fields.length;
        } else {
            at = doc.fields.indexOf(peers[index]);
        }
        doc.fields.splice(at, 0, f);
    }
    function nudge(key, delta) {
        var f = byKey(key);
        var peers = fieldsOn(f.page);
        var i = peers.indexOf(f);
        var j = i + delta;
        if (j >= 0 && j < peers.length) { moveField(key, f.page, j); return true; }
        // at a page edge: continue onto the neighbouring page
        var p = pageKeys().indexOf(f.page);
        var np = p + delta;
        if (np >= 0 && np < doc.pages.length) {
            var target = doc.pages[np].key;
            moveField(key, target, delta < 0 ? fieldsOn(target).length : 0);
            return true;
        }
        return false;
    }
    function removeField(key) {
        var f = byKey(key);
        if (!f) { return; }
        doc.fields.splice(doc.fields.indexOf(f), 1);
        if (sel && sel.key === key) { sel = null; }
    }
    function duplicateField(key) {
        var f = byKey(key);
        if (!f || isUniqueField(f)) { return; }
        var c = clone(f);
        c.key = newKey(f.type + '_copy');
        doc.fields.splice(doc.fields.indexOf(f) + 1, 0, c);
        sel = { kind: 'field', key: c.key };
    }

    function pick(item) {
        // Click-to-add: after the selected element, else at the end of the last page.
        if (item.special === 'submit') { sel = { kind: 'submit' }; renderAll(); openDrawer('inspector'); return; }
        var why = canAdd(item.element);
        if (why) { flash(why); return; }
        var page, index;
        var cur = sel && sel.kind === 'field' ? byKey(sel.key) : null;
        if (cur) { page = cur.page; index = fieldsOn(page).indexOf(cur) + 1; }
        else { page = doc.pages[doc.pages.length - 1].key; index = fieldsOn(page).length; }
        var f = insertField(item.element, page, index);
        sel = { kind: 'field', key: f.key };
        renderAll(); touch(); scrollTo(f.key);
        closeDrawers();
    }
    function scrollTo(key) {
        var n = document.querySelector('.fb-el[data-key="' + key + '"]');
        if (n && n.scrollIntoView) { n.scrollIntoView({ block: 'nearest' }); }
    }

    // -------------------------------------------------------------- toolbox
    var toolboxEl = $('fb-toolbox');
    function renderToolbox() {
        clear(toolboxEl);
        var usedUid = mappedUids();
        S.toolbox.forEach(function (group) {
            var items = h('div', { class: 'fb-items' });
            if (group.id === 'custom' && !group.items.length) {
                toolboxEl.appendChild(h('div', { class: 'fb-group' }, [h('h6', { text: group.label }), h('p', { class: 'fb-empty-note', text: 'Your Business has no custom fields yet. Add them in Settings → Custom fields.' })]));
                return;
            }
            group.items.forEach(function (item) {
                var used = item.custom_field_uid && usedUid[item.custom_field_uid];
                var btn = h('button', {
                    type: 'button', class: 'fb-item', draggable: used ? null : 'true', disabled: used ? true : null,
                    title: used ? 'Already on this form' : (item.token ? 'Saves to ' + item.token : item.label),
                    'data-item': item.id,
                    onclick: function () { if (!used) { pick(item); } },
                    ondragstart: function (e) {
                        if (item.special) { e.preventDefault(); return; }
                        drag = { kind: 'tool', item: item };
                        e.dataTransfer.effectAllowed = 'copy';
                        try { e.dataTransfer.setData('text/plain', 'tool:' + item.id); } catch (x) { /* old browsers */ }
                    },
                    ondragend: function () { drag = null; clearDropLine(); }
                }, [h('span', { class: 'fb-ico', text: GLYPH[item.icon] || '•' }), h('span', { class: 'fb-lbl', text: item.label })]);
                items.appendChild(btn);
            });
            toolboxEl.appendChild(h('div', { class: 'fb-group', 'data-group': group.id }, [h('h6', { text: group.label }), items]));
        });
    }

    // --------------------------------------------------------------- canvas
    var canvasEl = $('fb-canvas');
    var dropLine = null;
    function clearDropLine() { if (dropLine && dropLine.parentNode) { dropLine.parentNode.removeChild(dropLine); } dropLine = null; }

    function control(f) {
        var req = f.required ? h('span', { class: 'pf-req', text: ' *' }) : null;
        var label = function (text) { return h('span', { class: 'pf-label' }, [text, req]); };
        var ph = f.placeholder || '';
        var t = f.type;
        if (t === 'heading') { return h('h2', { class: 'pf-heading', text: f.label || 'Heading' }); }
        if (t === 'paragraph') { return h('p', { class: 'pf-paragraph', text: f.label || '' }); }
        if (t === 'divider') { return h('hr', { class: 'pf-divider' }); }
        if (t === 'spacer') { return h('div', { class: 'pf-spacer' }); }
        if (t === 'checkbox' || isConsent(f)) {
            return h('label', { class: 'pf-choice' + (isConsent(f) ? ' pf-consent' : '') }, [h('input', { type: 'checkbox', tabindex: '-1', disabled: true }), h('span', null, [f.label || '', req])]);
        }
        if (t === 'radio' || t === 'yes_no') {
            var opts = t === 'yes_no' ? ['Yes', 'No'] : (f.options || []);
            return h('div', null, [label(f.label), h('div', null, opts.map(function (o) { return h('label', { class: 'pf-choice' }, [h('input', { type: 'radio', tabindex: '-1', disabled: true }), h('span', { text: o })]); }))]);
        }
        if (t === 'multi_select') {
            return h('div', null, [label(f.label), h('div', null, (f.options || []).map(function (o) { return h('label', { class: 'pf-choice' }, [h('input', { type: 'checkbox', tabindex: '-1', disabled: true }), h('span', { text: o })]); }))]);
        }
        var input;
        if (t === 'textarea') { input = h('textarea', { class: 'pf-textarea', placeholder: ph, tabindex: '-1', readonly: true }); }
        else if (t === 'select') { input = h('select', { class: 'pf-select', tabindex: '-1', disabled: true }, [h('option', { text: ph || 'Choose…' })]); }
        else {
            var type = { email: 'email', phone: 'tel', date: 'date', datetime: 'datetime-local', number: 'number', currency: 'number' }[t] || 'text';
            input = h('input', { class: 'pf-input', type: type, placeholder: ph, tabindex: '-1', readonly: true });
            if (f['default'] && DEFAULT_TYPES.indexOf(t) > -1) { input.value = f['default']; }
        }
        var kids = [h('span', { class: 'pf-label' }, [f.label || '', req]), input];
        if (f.help) { kids.push(h('small', { class: 'pf-help', text: f.help })); }
        return h('div', null, kids);
    }

    function mappedBadge(f) {
        if (!f.custom_field_uid) { return null; }
        var def = (S.customFields || {})[f.custom_field_uid];
        if (!def) { return h('span', { class: 'fb-mapped is-archived', text: '→ unknown contact field' }); }
        return h('span', { class: 'fb-mapped' + (def.archived ? ' is-archived' : ''), text: '→ ' + def.token + (def.archived ? ' (archived)' : '') });
    }

    function elementNode(f) {
        var item = h('div', { class: 'pf-item' + (f.width === 'half' ? ' pf-half' : '') }, [control(f)]);
        var badge = mappedBadge(f);
        if (badge) { item.appendChild(h('div', null, [badge])); }
        var tools = h('div', { class: 'fb-el-tools' }, [
            h('button', { type: 'button', class: 'fb-icon-btn', title: 'Move up (Alt+↑)', 'aria-label': 'Move up', text: '↑', onclick: function (e) { e.stopPropagation(); if (nudge(f.key, -1)) { renderAll(); touch(); scrollTo(f.key); } } }),
            h('button', { type: 'button', class: 'fb-icon-btn', title: 'Move down (Alt+↓)', 'aria-label': 'Move down', text: '↓', onclick: function (e) { e.stopPropagation(); if (nudge(f.key, 1)) { renderAll(); touch(); scrollTo(f.key); } } }),
            h('button', { type: 'button', class: 'fb-icon-btn', title: isUniqueField(f) ? 'This element can only appear once' : 'Duplicate', 'aria-label': 'Duplicate', text: '⧉', disabled: isUniqueField(f) ? true : null, onclick: function (e) { e.stopPropagation(); duplicateField(f.key); renderAll(); touch(); } }),
            h('button', { type: 'button', class: 'fb-icon-btn is-danger', title: 'Remove (Delete)', 'aria-label': 'Remove', text: '✕', onclick: function (e) { e.stopPropagation(); removeField(f.key); renderAll(); touch(); } })
        ]);
        var node = h('div', {
            class: 'fb-el' + (f.width === 'half' ? ' is-half' : '') + (sel && sel.key === f.key ? ' is-selected' : ''),
            tabindex: '0', draggable: 'true', 'data-key': f.key, 'data-type': f.type, role: 'button',
            'aria-label': (TYPE_NAME[f.type] || f.type) + ': ' + (f.label || ''),
            onclick: function () { sel = { kind: 'field', key: f.key }; renderAll(); openDrawer('inspector'); },
            onkeydown: function (e) {
                if (e.target !== node) { return; }
                if (e.altKey && (e.key === 'ArrowUp' || e.key === 'ArrowDown')) { e.preventDefault(); if (nudge(f.key, e.key === 'ArrowUp' ? -1 : 1)) { renderAll(); touch(); focusEl(f.key); } }
                else if (e.key === 'Delete' || e.key === 'Backspace') { e.preventDefault(); removeField(f.key); renderAll(); touch(); }
                else if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); sel = { kind: 'field', key: f.key }; renderAll(); focusEl(f.key); openDrawer('inspector'); }
            },
            ondragstart: function (e) {
                drag = { kind: 'field', key: f.key };
                e.dataTransfer.effectAllowed = 'move';
                try { e.dataTransfer.setData('text/plain', 'field:' + f.key); } catch (x) { /* old browsers */ }
                setTimeout(function () { node.classList.add('is-dragging'); }, 0);
            },
            ondragend: function () { drag = null; node.classList.remove('is-dragging'); clearDropLine(); }
        }, [h('span', { class: 'fb-el-tag', text: TYPE_NAME[f.type] || f.type }), tools, item]);
        return node;
    }
    function focusEl(key) { var n = document.querySelector('.fb-el[data-key="' + key + '"]'); if (n) { n.focus(); } }

    function dropIndex(zone, e) {
        var els = [].slice.call(zone.querySelectorAll(':scope > .fb-el:not(.is-dragging)'));
        for (var i = 0; i < els.length; i++) {
            var r = els[i].getBoundingClientRect();
            if (els[i].classList.contains('is-half')) {
                if (e.clientY < r.top || (e.clientY <= r.bottom && e.clientX < r.left + r.width / 2)) { return { i: i, before: els[i] }; }
            } else if (e.clientY < r.top + r.height / 2) { return { i: i, before: els[i] }; }
        }
        return { i: els.length, before: null };
    }
    function bindZone(zone, pageKey) {
        zone.addEventListener('dragover', function (e) {
            if (!drag) { return; }
            e.preventDefault();
            e.dataTransfer.dropEffect = drag.kind === 'tool' ? 'copy' : 'move';
            var d = dropIndex(zone, e);
            if (!dropLine) { dropLine = h('div', { class: 'fb-drop-line' }); }
            if (d.before) { zone.insertBefore(dropLine, d.before); } else { zone.appendChild(dropLine); }
            zone.classList.remove('is-empty');
        });
        zone.addEventListener('dragleave', function (e) {
            if (!zone.contains(e.relatedTarget)) { clearDropLine(); if (!fieldsOn(pageKey).length) { zone.classList.add('is-empty'); } }
        });
        zone.addEventListener('drop', function (e) {
            if (!drag) { return; }
            e.preventDefault();
            var d = dropIndex(zone, e);
            var d0 = drag; drag = null; clearDropLine();
            if (d0.kind === 'tool') {
                var why = canAdd(d0.item.element);
                if (why) { flash(why); renderAll(); return; }
                var f = insertField(d0.item.element, pageKey, d.i);
                sel = { kind: 'field', key: f.key };
            } else {
                // d.i counts the zone without the dragged element, which is what moveField expects.
                moveField(d0.key, pageKey, d.i);
                sel = { kind: 'field', key: d0.key };
            }
            renderAll(); touch();
        });
    }

    function applyCanvasTheme() {
        var d = doc.design || {};
        canvasEl.style.setProperty('--pf-accent', d.accent || '#2563eb');
        canvasEl.style.setProperty('--pf-radius', RADII[d.radius || 'md']);
        canvasEl.style.setProperty('--pf-width', WIDTHS[d.width || 'medium']);
        canvasEl.style.background = d.background || '';
    }

    function renderCanvas() {
        applyCanvasTheme();
        var top = canvasEl.scrollTop;
        clear(canvasEl);
        var multi = doc.pages.length > 1;
        doc.pages.forEach(function (page, pi) {
            var isLast = pi === doc.pages.length - 1;
            var zone = h('div', { class: 'fb-zone' + (fieldsOn(page.key).length ? '' : ' is-empty'), 'data-page': page.key, 'data-role': 'forms-canvas-page' });
            fieldsOn(page.key).forEach(function (f) { zone.appendChild(elementNode(f)); });
            bindZone(zone, page.key);

            var card = h('div', { class: 'pf-card' });
            if (pi === 0) {
                card.appendChild(h('h1', { class: 'pf-title', text: doc.name || 'Untitled form' }));
                if (doc.intro) { card.appendChild(h('p', { class: 'pf-intro', text: doc.intro })); }
            }
            card.appendChild(zone);
            var align = (doc.design || {}).button_align || 'left';
            var btn = h('button', { type: 'button', class: 'pf-btn', tabindex: '-1', text: isLast ? (doc.submit_label || 'Send') : 'Next' });
            var actions = h('div', { class: 'pf-actions pf-align-' + align }, [btn]);
            if (isLast) {
                card.appendChild(h('div', {
                    class: 'fb-submit-el' + (sel && sel.kind === 'submit' ? ' is-selected' : ''), tabindex: '0', role: 'button', 'aria-label': 'Submit button', 'data-role': 'forms-canvas-submit',
                    onclick: function () { sel = { kind: 'submit' }; renderAll(); openDrawer('inspector'); },
                    onkeydown: function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); sel = { kind: 'submit' }; renderAll(); openDrawer('inspector'); } }
                }, [actions]));
            } else {
                card.appendChild(actions);
            }

            var wrap = h('section', { class: 'fb-page', 'data-page': page.key });
            if (multi) {
                var title = h('input', {
                    class: 'fb-page-title', type: 'text', value: page.title || '', placeholder: 'Page ' + (pi + 1), maxlength: '120', 'aria-label': 'Page ' + (pi + 1) + ' title',
                    oninput: function () { page.title = title.value; touch(); }
                });
                var canRemove = fieldsOn(page.key).length === 0;
                wrap.appendChild(h('div', { class: 'fb-page-head' }, [
                    h('span', { text: 'Page ' + (pi + 1) + ' of ' + doc.pages.length }),
                    title,
                    h('button', { type: 'button', class: 'fb-icon-btn', title: 'Move page up', 'aria-label': 'Move page up', text: '↑', disabled: pi === 0 ? true : null, onclick: function () { movePage(pi, -1); } }),
                    h('button', { type: 'button', class: 'fb-icon-btn', title: 'Move page down', 'aria-label': 'Move page down', text: '↓', disabled: isLast ? true : null, onclick: function () { movePage(pi, 1); } }),
                    h('button', { type: 'button', class: 'fb-icon-btn is-danger', title: canRemove ? 'Remove page' : 'Move or remove its elements first', 'aria-label': 'Remove page', text: '✕', disabled: canRemove ? null : true, onclick: function () { removePage(pi); } })
                ]));
            }
            wrap.appendChild(card);
            canvasEl.appendChild(wrap);
        });
        canvasEl.appendChild(h('button', { type: 'button', class: 'fb-add-page', 'data-role': 'forms-add-page', text: '+ Add page', onclick: addPage }));
        canvasEl.scrollTop = top;
    }

    function addPage() {
        if (doc.pages.length >= C.limits.pages) { flash('A form can have at most ' + C.limits.pages + ' pages.'); return; }
        doc.pages.push({ key: newPageKey(), title: '' });
        renderAll(); touch();
    }
    function movePage(i, delta) {
        var j = i + delta;
        if (j < 0 || j >= doc.pages.length) { return; }
        var p = doc.pages.splice(i, 1)[0];
        doc.pages.splice(j, 0, p);
        renderAll(); touch();
    }
    function removePage(i) {
        if (fieldsOn(doc.pages[i].key).length) { return; }
        doc.pages.splice(i, 1);
        renderAll(); touch();
    }

    // ------------------------------------------------------------ inspector
    var inspEl = $('fb-inspector');
    function field(labelText, control, hint) {
        return h('div', { class: 'fb-field' }, [h('label', { text: labelText }), control, hint ? h('small', { text: hint }) : null]);
    }
    function textInput(f, prop, opts) {
        opts = opts || {};
        var el = opts.area ? h('textarea', { class: 'form-control', rows: opts.rows || 3 }) : h('input', { class: 'form-control', type: 'text' });
        el.value = f[prop] || '';
        if (opts.max) { el.maxLength = opts.max; }
        el.addEventListener('input', function () { f[prop] = el.value; renderCanvas(); touch(); if (opts.after) { opts.after(); } });
        return el;
    }
    function seg(options, current, onPick) {
        var box = h('div', { class: 'fb-seg' });
        options.forEach(function (o) {
            box.appendChild(h('button', { type: 'button', class: current === o[0] ? 'is-on' : '', text: o[1], onclick: function () { onPick(o[0]); } }));
        });
        return box;
    }

    function optionsEditor(f) {
        var wrap = h('div');
        function draw() {
            clear(wrap);
            (f.options || []).forEach(function (o, i) {
                var inp = h('input', { class: 'form-control form-control-sm', type: 'text', value: o, maxlength: '80', 'aria-label': 'Option ' + (i + 1) });
                inp.addEventListener('input', function () { f.options[i] = inp.value; renderCanvas(); touch(); });
                wrap.appendChild(h('div', { class: 'fb-opt' }, [
                    inp,
                    h('button', { type: 'button', class: 'fb-icon-btn', text: '↑', title: 'Move up', 'aria-label': 'Move option up', disabled: i === 0 ? true : null, onclick: function () { var x = f.options.splice(i, 1)[0]; f.options.splice(i - 1, 0, x); draw(); renderCanvas(); touch(); } }),
                    h('button', { type: 'button', class: 'fb-icon-btn', text: '↓', title: 'Move down', 'aria-label': 'Move option down', disabled: i === f.options.length - 1 ? true : null, onclick: function () { var x = f.options.splice(i, 1)[0]; f.options.splice(i + 1, 0, x); draw(); renderCanvas(); touch(); } }),
                    h('button', { type: 'button', class: 'fb-icon-btn is-danger', text: '✕', title: 'Remove option', 'aria-label': 'Remove option', onclick: function () { f.options.splice(i, 1); draw(); renderCanvas(); touch(); } })
                ]));
            });
            wrap.appendChild(h('button', {
                type: 'button', class: 'btn btn-sm btn-outline-primary', 'data-role': 'forms-add-option', text: '+ Add option',
                onclick: function () { f.options.push('Option ' + (f.options.length + 1)); draw(); renderCanvas(); touch(); var ins = wrap.querySelectorAll('.fb-opt input'); if (ins.length) { ins[ins.length - 1].focus(); ins[ins.length - 1].select(); } }
            }));
        }
        draw();
        return wrap;
    }

    function compatibleDefs(f) {
        var allowed = (S.compat || {})[f.type] || [];
        var taken = mappedUids(f.key);
        var out = [];
        Object.keys(S.customFields || {}).forEach(function (uid) {
            var d = S.customFields[uid];
            if (allowed.indexOf(d.type) < 0) { return; }
            if (d.archived && f.custom_field_uid !== uid) { return; }   // archived: never newly insertable
            out.push({ uid: uid, def: d, taken: !!taken[uid] });
        });
        return out;
    }

    function mappingEditor(f) {
        var box = h('div');
        if (f.type === 'email' || f.type === 'phone') {
            var isFirstEmail = f.type !== 'email' || firstEmailKey() === f.key;
            box.appendChild(field('Save answer to', h('input', { class: 'form-control', value: f.type === 'email' ? 'Contact → Email' : 'Contact → Phone', readonly: true }),
                f.type === 'phone' ? 'The phone number is how the person is recognized.' : (isFirstEmail ? 'Saved on the contact when one is created.' : 'Only the first email element is used for the contact.')));
            return box;
        }
        if (isConsent(f)) {
            box.appendChild(h('div', { class: 'fb-note', text: 'Consent is recorded with the response only. It is never pre-checked and never changes a contact on its own.' }));
            return box;
        }
        var s = h('select', { class: 'form-control', 'data-role': 'forms-save-to', 'aria-label': 'Save answer to' });
        s.appendChild(h('option', { value: '', text: 'Don’t save to a contact field' }));
        if (f.type === 'text') {
            var og = h('optgroup', { label: 'Contact' });
            [['builtin:full_name', 'Full name'], ['builtin:first_name', 'First name'], ['builtin:last_name', 'Last name']].forEach(function (o) { og.appendChild(h('option', { value: o[0], text: o[1] })); });
            s.appendChild(og);
        }
        var defs = compatibleDefs(f);
        if (defs.length) {
            var cg = h('optgroup', { label: 'Custom fields' });
            defs.forEach(function (d) { cg.appendChild(h('option', { value: 'cf:' + d.uid, disabled: d.taken ? true : null, text: d.def.label + (d.def.archived ? ' — archived' : '') + (d.taken ? ' (already used)' : '') })); });
            s.appendChild(cg);
        }
        var cur = f.custom_field_uid ? 'cf:' + f.custom_field_uid : (builtinOf(f) ? 'builtin:' + builtinOf(f) : '');
        // A mapping to a field that is no longer in the compatible list (e.g. archived) must still be visible.
        if (f.custom_field_uid && !defs.some(function (d) { return d.uid === f.custom_field_uid; })) {
            var d0 = (S.customFields || {})[f.custom_field_uid];
            s.appendChild(h('option', { value: cur, text: (d0 ? d0.label : 'Unknown contact field') + ' — ' + (d0 && d0.archived ? 'archived' : 'unavailable') }));
        }
        s.value = cur;
        s.addEventListener('change', function () {
            var v = s.value;
            delete f.custom_field_uid; delete f.contact_part; f.contact_name = false;
            if (v.indexOf('cf:') === 0) { f.custom_field_uid = v.slice(3); }
            else if (v === 'builtin:full_name') { var w = canAddBuiltin(f, 'full'); if (w) { flash(w); s.value = cur; return; } f.contact_name = true; }
            else if (v === 'builtin:first_name' || v === 'builtin:last_name') { var w2 = canAddBuiltin(f, v.slice(8)); if (w2) { flash(w2); s.value = cur; return; } f.contact_part = v.slice(8); }
            renderAll(); touch();
        });
        box.appendChild(field('Save answer to', s, 'Choose the contact field this answer updates. It is never guessed from the label.'));
        if (f.custom_field_uid) {
            var d = (S.customFields || {})[f.custom_field_uid];
            if (d) {
                box.appendChild(h('div', { class: 'fb-note' + (d.archived ? ' is-warn' : '') }, [
                    d.archived ? 'This contact field is archived: answers are kept on the response but no longer saved to the contact. ' : 'Merge field: ',
                    h('span', { class: 'fb-token', text: d.token })
                ]));
            }
        }
        return box;
    }
    function canAddBuiltin(f, which) {
        for (var i = 0; i < doc.fields.length; i++) {
            var o = doc.fields[i];
            if (o === f) { continue; }
            if (which === 'full' && (o.contact_name || o.contact_part)) { return o.contact_name ? 'A form can have only one full-name element.' : 'Use either one full name or separate first and last names, not both.'; }
            if (which !== 'full' && o.contact_name) { return 'Use either one full name or separate first and last names, not both.'; }
            if (which !== 'full' && o.contact_part === which) { return 'A form can have only one ' + (which === 'first_name' ? 'first' : 'last') + '-name element.'; }
        }
        return null;
    }

    function renderInspector() {
        clear(inspEl);
        if (!sel) {
            inspEl.appendChild(h('h5', { text: 'Settings' }));
            inspEl.appendChild(h('p', { class: 'fb-sub', text: 'Select an element on the form to edit it.' }));
            inspEl.appendChild(h('div', { class: 'fb-note', text: 'Add elements by dragging them from the left onto the form, or click one to add it. Reorder by dragging, or use Alt + arrow keys.' }));
            return;
        }
        if (sel.kind === 'submit') {
            inspEl.appendChild(h('h5', { text: 'Submit button' }));
            inspEl.appendChild(h('p', { class: 'fb-sub', text: 'The last page of the form ends with this button.' }));
            var bl = h('input', { class: 'form-control', type: 'text', maxlength: '40', value: doc.submit_label || '', 'data-role': 'forms-button-label' });
            bl.addEventListener('input', function () { doc.submit_label = bl.value; renderCanvas(); touch(); syncSettings(); });
            inspEl.appendChild(field('Button label', bl));
            inspEl.appendChild(field('Alignment', seg([['left', 'Left'], ['center', 'Center'], ['right', 'Right'], ['full', 'Full width']], (doc.design || {}).button_align || 'left', function (v) { setDesign('button_align', v); renderInspector(); })));
            return;
        }
        var f = byKey(sel.key);
        if (!f) { sel = null; renderInspector(); return; }
        var t = f.type;
        inspEl.appendChild(h('h5', { text: TYPE_NAME[t] || t }));
        inspEl.appendChild(h('p', { class: 'fb-sub', text: isContent(f) ? 'Content only — collects no answer.' : (isConsent(f) ? 'Records an explicit agreement.' : 'Collects an answer.') }));

        if (t === 'divider' || t === 'spacer') {
            inspEl.appendChild(h('div', { class: 'fb-note', text: 'Nothing to configure.' }));
            return;
        }
        if (t === 'heading') { inspEl.appendChild(field('Heading text', textInput(f, 'label', { max: 120 }))); return; }
        if (t === 'paragraph') { inspEl.appendChild(field('Text', textInput(f, 'label', { area: true, rows: 5, max: 1000 }))); return; }

        if (isConsent(f)) {
            inspEl.appendChild(field('Consent text', textInput(f, 'label', { area: true, rows: 4, max: 500 }), 'Shown next to the checkbox exactly as written. It is never pre-checked.'));
            inspEl.appendChild(h('div', { class: 'fb-note', text: 'You are responsible for wording that meets the rules that apply to you. Recording consent here does not make a form compliant by itself.' }));
        } else {
            inspEl.appendChild(field('Label', textInput(f, 'label', { max: 120 })));
            if (PLACEHOLDER_TYPES.indexOf(t) > -1) { inspEl.appendChild(field('Placeholder', textInput(f, 'placeholder', { max: 120 }))); }
            inspEl.appendChild(field('Help text', textInput(f, 'help', { max: 300 })));
        }

        if (HAS_OPTIONS.indexOf(t) > -1) { inspEl.appendChild(field('Options', optionsEditor(f), 'At least two. The visitor can only choose from these.')); }

        var req = h('input', { type: 'checkbox', class: 'custom-control-input', id: 'fb-req', checked: f.required ? true : null });
        req.addEventListener('change', function () { f.required = req.checked; renderCanvas(); touch(); });
        inspEl.appendChild(h('div', { class: 'fb-field' }, [h('div', { class: 'custom-control custom-checkbox' }, [req, h('label', { class: 'custom-control-label', for: 'fb-req', text: 'Required' })])]));

        if (!isConsent(f)) {
            inspEl.appendChild(field('Width', seg([['full', 'Full'], ['half', 'Half']], f.width === 'half' ? 'half' : 'full', function (v) { f.width = v === 'half' ? 'half' : ''; renderAll(); touch(); })));
        }
        if (DEFAULT_TYPES.indexOf(t) > -1) {
            if (HAS_OPTIONS.indexOf(t) > -1) {
                var ds = h('select', { class: 'form-control' }, [h('option', { value: '', text: 'No default' })].concat((f.options || []).filter(Boolean).map(function (o) { return h('option', { value: o, text: o }); })));
                ds.value = f['default'] || '';
                ds.addEventListener('change', function () { f['default'] = ds.value; renderCanvas(); touch(); });
                inspEl.appendChild(field('Default value', ds));
            } else {
                inspEl.appendChild(field('Default value', textInput(f, 'default', { max: 200 })));
            }
        }
        inspEl.appendChild(h('hr'));
        inspEl.appendChild(mappingEditor(f));

        var rm = h('button', { type: 'button', class: 'btn btn-sm btn-outline-danger', text: 'Remove element', onclick: function () { removeField(f.key); renderAll(); touch(); } });
        inspEl.appendChild(h('div', { class: 'mt-1' }, [rm]));
    }

    // ------------------------------------------------------------- settings
    function setDesign(k, v) {
        if (!doc.design) { doc.design = {}; }
        if (v) { doc.design[k] = v; } else { delete doc.design[k]; }
        renderCanvas(); syncSettings(); touch();
    }
    function syncSettings() {
        [].forEach.call(document.querySelectorAll('[data-bind]'), function (inp) {
            var k = inp.getAttribute('data-bind');
            if (inp.type === 'checkbox') { inp.checked = !!doc[k]; }
            else if (document.activeElement !== inp) { inp.value = doc[k] === null || doc[k] === undefined ? '' : doc[k]; }
        });
        var top = $('fb-name'); if (top && document.activeElement !== top) { top.value = doc.name; }
        [].forEach.call(document.querySelectorAll('[data-design-seg]'), function (box) {
            var k = box.getAttribute('data-design-seg');
            [].forEach.call(box.querySelectorAll('button'), function (b) { b.classList.toggle('is-on', (doc.design || {})[k] === b.getAttribute('data-v') || (!(doc.design || {})[k] && b.getAttribute('data-v') === { button_align: 'left', radius: 'md', width: 'medium' }[k])); });
        });
        var sw = $('fb-swatches'); if (sw) { [].forEach.call(sw.querySelectorAll('.fb-swatch'), function (b) { b.classList.toggle('is-on', ((doc.design || {}).accent || '#2563eb') === b.getAttribute('data-c')); }); }
        var bg = $('fb-s-bg'); if (bg) { bg.value = (doc.design || {}).background || '#f3f5f9'; }
    }
    function initSettings() {
        var pl = $('fb-s-pipeline');
        pl.appendChild(h('option', { value: '', text: 'First active pipeline' }));
        (S.pipelines || []).forEach(function (p) { pl.appendChild(h('option', { value: String(p.id), text: p.name })); });
        [].forEach.call(document.querySelectorAll('[data-bind]'), function (inp) {
            var k = inp.getAttribute('data-bind');
            inp.addEventListener(inp.type === 'checkbox' || inp.tagName === 'SELECT' ? 'change' : 'input', function () {
                if (inp.type === 'checkbox') { doc[k] = inp.checked; }
                else if (k === 'opportunity_pipeline_id') { doc[k] = inp.value ? parseInt(inp.value, 10) : null; }
                else { doc[k] = inp.value; }
                renderCanvas(); syncSettings(); touch();
            });
        });
        var top = $('fb-name');
        top.addEventListener('input', function () { doc.name = top.value; renderCanvas(); syncSettings(); touch(); });
        var sw = $('fb-swatches');
        ACCENTS.forEach(function (c) {
            sw.appendChild(h('button', { type: 'button', class: 'fb-swatch', style: 'background:' + c, 'data-c': c, title: c, 'aria-label': 'Accent ' + c, onclick: function () { setDesign('accent', c === '#2563eb' ? '' : c); } }));
        });
        var custom = h('input', { type: 'color', value: (doc.design || {}).accent || '#2563eb', 'aria-label': 'Custom accent color' });
        custom.addEventListener('input', function () { setDesign('accent', custom.value); });
        sw.appendChild(custom);
        [].forEach.call(document.querySelectorAll('[data-design-seg]'), function (box) {
            var k = box.getAttribute('data-design-seg');
            [].forEach.call(box.querySelectorAll('button'), function (b) { b.addEventListener('click', function () { setDesign(k, b.getAttribute('data-v')); }); });
        });
        $('fb-s-bg').addEventListener('input', function () { setDesign('background', $('fb-s-bg').value); });
        $('fb-s-bg-reset').addEventListener('click', function () { setDesign('background', ''); });
        [].forEach.call(document.querySelectorAll('form.fb-nav-form'), function (f) {
            f.addEventListener('submit', function (e) {
                if (!dirty && !saving) { return; }
                e.preventDefault();
                (function wait() { flush().then(function () { if (saving) { setTimeout(wait, 150); } else if (stale) { /* stay: stale tab */ } else { f.submit(); } }); })();
            });
        });
    }

    // ----------------------------------------------------------------- tabs
    function showTab(name) {
        [].forEach.call(document.querySelectorAll('[data-fb-pane]'), function (p) { p.hidden = p.getAttribute('data-fb-pane') !== name; });
        [].forEach.call(document.querySelectorAll('[data-fb-tab]'), function (b) { b.classList.toggle('is-active', b.getAttribute('data-fb-tab') === name); });
        try { history.replaceState(null, '', name === 'edit' ? location.pathname + location.search : '#' + name); } catch (x) { /* ignore */ }
        if (name === 'settings') { syncSettings(); }
    }
    [].forEach.call(document.querySelectorAll('[data-fb-tab]'), function (b) { b.addEventListener('click', function () { showTab(b.getAttribute('data-fb-tab')); }); });

    // --------------------------------------------------------------- drawers
    function openDrawer(which) {
        if (window.innerWidth > 1100) { return; }
        closeDrawers();
        (which === 'toolbox' ? toolboxEl : inspEl).classList.add('is-open');
    }
    function closeDrawers() { toolboxEl.classList.remove('is-open'); inspEl.classList.remove('is-open'); }
    $('fb-toggle-toolbox').addEventListener('click', function () { toolboxEl.classList.contains('is-open') ? closeDrawers() : openDrawer('toolbox'); });
    $('fb-toggle-inspector').addEventListener('click', function () { inspEl.classList.contains('is-open') ? closeDrawers() : openDrawer('inspector'); });
    canvasEl.addEventListener('click', function (e) { if (e.target === canvasEl) { sel = null; renderAll(); closeDrawers(); } });

    // ---------------------------------------------------------------- modals
    function modal(id, on) { $(id).classList.toggle('is-on', on); }
    [].forEach.call(document.querySelectorAll('[data-fb-close]'), function (b) {
        b.addEventListener('click', function () { var m = b.closest('.fb-modal'); m.classList.remove('is-on'); if (m.id === 'fb-preview-modal') { previewOpen = false; } });
    });
    [].forEach.call(document.querySelectorAll('.fb-modal'), function (m) {
        m.addEventListener('click', function (e) { if (e.target === m) { m.classList.remove('is-on'); if (m.id === 'fb-preview-modal') { previewOpen = false; } } });
    });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { [].forEach.call(document.querySelectorAll('.fb-modal.is-on'), function (m) { m.classList.remove('is-on'); }); previewOpen = false; } });

    var previewOpen = false;
    var previewTimer = null;
    var previewSeq = 0;
    function schedulePreview() { clearTimeout(previewTimer); previewTimer = setTimeout(loadPreview, 400); }
    function loadPreview() {
        var mine = ++previewSeq;
        var msg = $('fb-preview-msg');
        post(C.previewUrl, payload()).then(function (res) {
            return res.text().then(function (text) { return { res: res, text: text }; });
        }).then(function (r) {
            if (mine !== previewSeq) { return; }
            if (r.res.ok) { msg.hidden = true; $('fb-preview-frame').srcdoc = r.text; $('fb-preview-frame').hidden = false; return; }
            var m = 'The preview could not be built.';
            try { m = JSON.parse(r.text).message || m; } catch (x) { /* keep default */ }
            msg.hidden = false; msg.textContent = m;
        }).catch(function () { msg.hidden = false; msg.textContent = 'The preview could not be loaded.'; });
    }
    $('fb-preview-btn').addEventListener('click', function () { previewOpen = true; modal('fb-preview-modal', true); loadPreview(); });
    [].forEach.call($('fb-preview-device').querySelectorAll('button'), function (b) {
        b.addEventListener('click', function () {
            [].forEach.call($('fb-preview-device').querySelectorAll('button'), function (x) { x.classList.toggle('is-on', x === b); });
            $('fb-frame-wrap').classList.toggle('is-mobile', b.getAttribute('data-d') === 'mobile');
        });
    });
    var locSel = $('fb-integrate-location');
    if (locSel) {
        locSel.addEventListener('change', function () {
            [].forEach.call(document.querySelectorAll('.fb-integrate-loc'), function (n) { n.hidden = n.getAttribute('data-location') !== locSel.value; });
        });
    }
    function openIntegrate() { flush(); modal('fb-integrate-modal', true); }
    $('fb-integrate-btn').addEventListener('click', openIntegrate);
    [].forEach.call(document.querySelectorAll('[data-copy]'), function (b) { b.addEventListener('click', function () { copy(b.getAttribute('data-copy'), b); }); });
    [].forEach.call(document.querySelectorAll('[data-copy-prev]'), function (b) { b.addEventListener('click', function () { copy(b.previousElementSibling.value, b); }); });
    function copy(text, btn) {
        var done = function () { var t = btn.textContent; btn.textContent = 'Copied'; setTimeout(function () { btn.textContent = t; }, 1400); };
        if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(text).then(done, done); return; }
        var ta = h('textarea', { style: 'position:fixed;opacity:0' }); ta.value = text; document.body.appendChild(ta); ta.select();
        try { document.execCommand('copy'); } catch (x) { /* ignore */ } document.body.removeChild(ta); done();
    }

    // ------------------------------------------------------------------ boot
    function renderAll() { renderToolbox(); renderCanvas(); renderInspector(); }
    initSettings();
    renderAll();
    syncSettings();
    var hash = (location.hash || '').replace('#', '');
    if (hash === 'settings') { showTab('settings'); }
    if (hash === 'integrate') { openIntegrate(); }
    window.FormBuilder = { doc: function () { return doc; }, payload: payload, flush: flush, version: function () { return version; }, state: function () { return { dirty: dirty, saving: saving, stale: stale }; } };
})();
