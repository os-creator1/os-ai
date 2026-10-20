@extends('layouts/contentLayoutMaster')

@section('title', 'Demo Form')

@section('content')
    <section id="admin-demo-form">
        @include('admin.partials.flash')

        <p class="text-muted">
            The questions on the public <a href="{{ route('demo.booking.show') }}" target="_blank" rel="noopener">Book a demo</a> page.
            Changes are saved as a draft and go live only when you publish. Name, email and business name are needed to book
            and create the Contact, so they can be relabelled but not removed. Answers are saved with each booking and shown on the appointment.
        </p>

        <div class="row">
            <div class="col-12 col-xl-7">
                <form method="POST" action="{{ route('admin.platform-owner.demo-form.save') }}" id="demo-form-editor">
                    @csrf
                    <input type="hidden" name="fields" id="demo-fields-json">
                    <div id="demo-fields"></div>

                    <div class="card mb-2">
                        <div class="card-body d-flex flex-wrap align-items-center" style="gap:.5rem">
                            <button type="button" class="btn btn-outline-secondary" id="demo-add">Add a field</button>
                            <span class="flex-grow-1"></span>
                            <button type="submit" class="btn btn-outline-secondary">Save draft</button>
                            <button type="submit" class="btn btn-primary" formaction="{{ route('admin.platform-owner.demo-form.publish') }}"
                                    onclick="return confirm('Publish this form? It goes live on /book-demo immediately.');">Publish</button>
                        </div>
                    </div>
                </form>
                @if ($hasDraft)
                    <form method="POST" action="{{ route('admin.platform-owner.demo-form.discard') }}" onsubmit="return confirm('Discard the draft and go back to the live form?');" class="mb-2">
                        @csrf
                        <button type="submit" class="btn btn-sm btn-flat-secondary">Discard draft</button>
                        <span class="text-muted ms-1">You have unpublished changes.</span>
                    </form>
                @endif
            </div>
            <div class="col-12 col-xl-5">
                <div class="card" style="position:sticky;top:1rem">
                    <div class="card-header"><h4 class="card-title">Preview</h4></div>
                    <div class="card-body p-0">
                        <p class="text-muted px-2 pt-1 mb-0">The public form, as saved in the draft. Save to refresh.</p>
                        <iframe src="{{ route('admin.platform-owner.demo-form.preview') }}" title="Demo form preview" style="width:100%;height:720px;border:0"></iframe>
                    </div>
                </div>
            </div>
        </div>

        <script>
        (function () {
            var fields = @json(array_values($fields));
            var types = @json($types);
            var CHOICE = ['dropdown', 'checkboxes', 'radio'];
            var LOCKED = ['first_name', 'email', 'business_name'];
            var root = document.getElementById('demo-fields');
            var out = document.getElementById('demo-fields-json');

            function el(tag, attrs, children) {
                var n = document.createElement(tag);
                Object.keys(attrs || {}).forEach(function (k) {
                    if (k === 'text') { n.textContent = attrs[k]; } else if (k === 'class') { n.className = attrs[k]; } else { n.setAttribute(k, attrs[k]); }
                });
                (children || []).forEach(function (c) { n.appendChild(c); });
                return n;
            }
            function input(label, value, onChange, extra) {
                var i = el('input', Object.assign({ type: 'text', class: 'form-control', maxlength: '200' }, extra || {}));
                i.value = value || '';
                i.addEventListener('input', function () { onChange(i.value); });
                return el('div', { class: 'mb-1' }, [el('label', { class: 'form-label', text: label }), i]);
            }
            function check(label, checked, onChange, disabled) {
                var i = el('input', { type: 'checkbox', class: 'form-check-input' });
                i.checked = !!checked; i.disabled = !!disabled;
                i.addEventListener('change', function () { onChange(i.checked); });
                return el('div', { class: 'form-check form-check-inline' }, [i, el('label', { class: 'form-check-label', text: label })]);
            }
            function move(i, d) {
                var j = i + d;
                if (j < 0 || j >= fields.length) { return; }
                var t = fields[i]; fields[i] = fields[j]; fields[j] = t;
                render();
            }
            function optionsFromText(text, old) {
                var lines = text.split('\n').map(function (l) { return l.trim(); }).filter(Boolean);
                var byLabel = {}; old.forEach(function (o) { byLabel[o.label] = o; });
                var used = {}; lines.forEach(function (l) { if (byLabel[l]) { used[l] = true; } });
                var spare = old.filter(function (o) { return lines.indexOf(o.label) === -1; });
                return lines.map(function (l) {
                    if (byLabel[l]) { return byLabel[l]; }
                    var reuse = spare.shift();           // a renamed option keeps its stable value
                    return { value: reuse ? reuse.value : '', label: l };
                });
            }

            function card(f, i) {
                var system = !!f.system, locked = LOCKED.indexOf(f.key) !== -1;
                var head = el('div', { class: 'd-flex align-items-center', style: 'gap:.5rem' }, [
                    el('strong', { class: 'flex-grow-1', text: f.label || '(untitled)' }),
                    el('span', { class: 'badge bg-light text-dark', text: types[f.type] || f.type }),
                    system ? el('span', { class: 'badge bg-light-secondary', text: 'Required for booking' }) : el('span')
                ]);
                [['↑', -1], ['↓', 1]].forEach(function (b) {
                    var btn = el('button', { type: 'button', class: 'btn btn-sm btn-flat-secondary', 'aria-label': b[1] < 0 ? 'Move up' : 'Move down', text: b[0] });
                    btn.addEventListener('click', function () { move(i, b[1]); });
                    head.appendChild(btn);
                });
                var del = el('button', { type: 'button', class: 'btn btn-sm btn-flat-danger', text: 'Remove' });
                if (system) { del.disabled = true; del.title = 'This field is needed to book and cannot be removed.'; }
                del.addEventListener('click', function () { if (confirm('Remove "' + f.label + '"? Answers already collected stay on past appointments.')) { fields.splice(i, 1); render(); } });
                head.appendChild(del);

                var body = el('div', { class: 'mt-1' });
                body.appendChild(input('Label', f.label, function (v) { f.label = v; head.firstChild.textContent = v || '(untitled)'; }, { maxlength: '120' }));
                if (!system) {
                    var sel = el('select', { class: 'form-select' });
                    Object.keys(types).forEach(function (k) { var o = el('option', { value: k, text: types[k] }); if (k === f.type) { o.selected = true; } sel.appendChild(o); });
                    sel.addEventListener('change', function () { f.type = sel.value; if (CHOICE.indexOf(f.type) !== -1 && !(f.options || []).length) { f.options = [{ value: '', label: 'Option 1' }]; } render(); });
                    body.appendChild(el('div', { class: 'mb-1' }, [el('label', { class: 'form-label', text: 'Field type' }), sel]));
                }
                if (f.type !== 'checkboxes' && f.type !== 'radio') {
                    body.appendChild(input('Placeholder', f.placeholder, function (v) { f.placeholder = v; }, { maxlength: '120' }));
                }
                body.appendChild(input('Helper text', f.help, function (v) { f.help = v; }, { maxlength: '200' }));
                if (CHOICE.indexOf(f.type) !== -1) {
                    var ta = el('textarea', { class: 'form-control', rows: '5' });
                    ta.value = (f.options || []).map(function (o) { return o.label; }).join('\n');
                    ta.addEventListener('input', function () { f.options = optionsFromText(ta.value, f.options || []); });
                    body.appendChild(el('div', { class: 'mb-1' }, [el('label', { class: 'form-label', text: 'Options (one per line)' }), ta]));
                }
                var flags = el('div', {});
                flags.appendChild(check('Required', f.required || locked, function (v) { f.required = v; }, locked));
                flags.appendChild(check('Shown', f.visible !== false || locked, function (v) { f.visible = v; }, locked));
                if (['long_text', 'checkboxes', 'radio'].indexOf(f.type) === -1) {
                    flags.appendChild(check('Half width', f.half, function (v) { f.half = v; }));
                }
                flags.appendChild(check('Show “(optional)”', f.show_optional, function (v) { f.show_optional = v; }));
                body.appendChild(flags);

                var wrap = el('div', { class: 'card mb-1' }, [el('div', { class: 'card-body' }, [head, body])]);
                if (f.visible === false) { wrap.style.opacity = '.7'; }
                return wrap;
            }

            function render() {
                root.innerHTML = '';
                fields.forEach(function (f, i) { root.appendChild(card(f, i)); });
            }

            document.getElementById('demo-add').addEventListener('click', function () {
                var rand = Math.random().toString(16).slice(2, 10);
                fields.push({ key: 'custom_' + rand, type: 'short_text', label: 'New question', placeholder: '', help: '', required: false, visible: true, half: false, show_optional: true, options: [], system: false });
                render();
                var cards = root.querySelectorAll('.card'); cards[cards.length - 1].scrollIntoView({ block: 'center' });
            });
            document.getElementById('demo-form-editor').addEventListener('submit', function () { out.value = JSON.stringify(fields); });
            render();
        })();
        </script>
    </section>
@endsection
