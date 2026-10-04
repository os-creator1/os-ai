@extends('layouts/contentLayoutMaster')

@section('title', $automation->exists ? 'Edit automation' : 'New automation')

@php
    $definition = (array) ($automation->definition ?? []);
    $state = [
        'trigger' => old('trigger_type', $automation->trigger_type),
        'params' => old('params', $definition['params'] ?? []),
        'conditions' => array_values(old('conditions', $definition['conditions'] ?? [])),
        'steps' => array_values(old('steps', $definition['steps'] ?? [])),
    ];
    $action = $automation->exists
        ? route('admin.platform-automations.update', $automation)
        : route('admin.platform-automations.store');
@endphp

@section('content')
    <section id="admin-platform-automation-edit">
        <div class="d-flex justify-content-between align-items-start mb-2">
            <div>
                <h3 class="mb-25">{{ $automation->exists ? $automation->name : 'New automation' }}
                    @if ($automation->exists)
                        <span class="badge badge-light-{{ $automation->status->value === 'enabled' ? 'success' : 'secondary' }} fs-6" data-role="pa-status">{{ ucfirst($automation->status->value) }}</span>
                        <span class="text-muted fs-6">v{{ $automation->version }}</span>
                    @endif
                </h3>
            </div>
            @if ($automation->exists)
                <div class="d-flex gap-1">
                    @if ($automation->status->value === 'enabled')
                        <form method="POST" action="{{ route('admin.platform-automations.disable', $automation) }}">@csrf<button class="btn btn-outline-secondary" data-role="pa-disable">Disable</button></form>
                    @else
                        <form method="POST" action="{{ route('admin.platform-automations.enable', $automation) }}">@csrf<button class="btn btn-success" data-role="pa-enable">Enable</button></form>
                    @endif
                    <form method="POST" action="{{ route('admin.platform-automations.duplicate', $automation) }}">@csrf<button class="btn btn-outline-primary" data-role="pa-duplicate">Duplicate</button></form>
                    <form method="POST" action="{{ route('admin.platform-automations.archive', $automation) }}" onsubmit="return confirm('Archive this automation? Runs already started will finish.');">@csrf<button class="btn btn-outline-danger">Archive</button></form>
                </div>
            @endif
        </div>

        @if ($errors->any())
            <div class="alert alert-danger" role="alert" data-role="pa-errors">
                <ul class="mb-0 ps-1">
                    @foreach ($errors->all() as $message)<li>{{ $message }}</li>@endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ $action }}" id="pa-form">
            @csrf
            @if ($automation->exists) @method('PUT') @endif

            <div class="card">
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6 mb-1">
                            <label class="form-label" for="pa-name">Name</label>
                            <input id="pa-name" name="name" class="form-control" maxlength="120" required value="{{ old('name', $automation->name) }}">
                        </div>
                        <div class="col-md-6 mb-1">
                            <label class="form-label" for="pa-description">Description (optional)</label>
                            <input id="pa-description" name="description" class="form-control" maxlength="500" value="{{ old('description', $automation->description) }}">
                        </div>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><h5 class="card-title mb-0">1 · When this happens</h5></div>
                <div class="card-body">
                    <select name="trigger_type" id="pa-trigger" class="form-select mb-1" required data-role="pa-trigger">
                        <option value="">Choose a trigger…</option>
                    </select>
                    <div id="pa-trigger-help" class="small text-muted mb-1"></div>
                    <div id="pa-trigger-params" class="row"></div>
                    <details class="mt-1">
                        <summary class="small text-muted">Triggers that are not available yet</summary>
                        <ul class="small text-muted mt-1 mb-0" data-role="pa-unavailable-triggers">
                            @foreach ($unavailableTriggers as $label => $reason)
                                <li><strong>{{ $label }}</strong> — {{ $reason }}</li>
                            @endforeach
                        </ul>
                    </details>
                </div>
            </div>

            <div class="card">
                <div class="card-header d-flex justify-content-between"><h5 class="card-title mb-0">2 · Only if (optional)</h5>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="pa-add-condition">Add condition</button></div>
                <div class="card-body" id="pa-conditions"><div class="text-muted small" id="pa-conditions-empty">No conditions: it runs every time the trigger fires.</div></div>
            </div>

            <div class="card">
                <div class="card-header d-flex justify-content-between"><h5 class="card-title mb-0">3 · Then do</h5>
                    <button type="button" class="btn btn-sm btn-primary" id="pa-add-step" data-role="pa-add-step">Add step</button></div>
                <div class="card-body">
                    <div id="pa-steps"></div>
                    <details class="mt-1">
                        <summary class="small text-muted">Actions that are not available yet</summary>
                        <ul class="small text-muted mt-1 mb-0">
                            @foreach ($unavailableActions as $label => $reason)
                                <li><strong>{{ $label }}</strong> — {{ $reason }}</li>
                            @endforeach
                        </ul>
                    </details>
                    <p class="small text-muted mt-1 mb-0">Merge fields you can use in text: @foreach ($mergeTokens as $t)<code>&#123;&#123;{{ $t }}&#125;&#125;</code> @endforeach</p>
                </div>
            </div>

            <div class="d-flex gap-1">
                <button type="submit" class="btn btn-primary" data-role="pa-save">Save</button>
                <a href="{{ route('admin.platform-automations.index') }}" class="btn btn-outline-secondary">Back</a>
            </div>
        </form>
    </section>
@endsection

@section('page-script')
    <script>
        (function () {
            var PA = {
                triggers: @json($triggers),
                actions: @json($actions),
                facts: @json($facts),
                operators: @json($operators),
                state: @json($state)
            };

            var triggerSel = document.getElementById('pa-trigger');
            // The <option> values are written by the server as trigger keys; rebuild them
            // from the catalog so the markup above stays simple and cannot drift.
            Array.prototype.slice.call(triggerSel.querySelectorAll('optgroup')).forEach(function (g) { g.remove(); });
            var groups = {};
            Object.keys(PA.triggers).forEach(function (k) {
                var t = PA.triggers[k]; (groups[t.group] = groups[t.group] || []).push(k);
            });
            Object.keys(groups).forEach(function (name) {
                var og = document.createElement('optgroup'); og.label = name;
                groups[name].forEach(function (k) {
                    var o = document.createElement('option'); o.value = k; o.textContent = PA.triggers[k].label; og.appendChild(o);
                });
                triggerSel.appendChild(og);
            });
            triggerSel.value = PA.state.trigger || '';

            function el(tag, attrs, text) {
                var e = document.createElement(tag);
                Object.keys(attrs || {}).forEach(function (a) { e.setAttribute(a, attrs[a]); });
                if (text) { e.textContent = text; }
                return e;
            }

            function field(name, spec, value, prefix) {
                var col = el('div', {'class': 'col-md-6 mb-1'});
                col.appendChild(el('label', {'class': 'form-label'}, spec[0]));
                var input;
                if (spec[1] === 'select') {
                    input = el('select', {'class': 'form-select', name: prefix + '[' + name + ']'});
                    Object.keys(spec[3] || {}).forEach(function (v) {
                        var o = el('option', {value: v}, spec[3][v]); if (String(value) === v) { o.selected = true; } input.appendChild(o);
                    });
                } else if (spec[1] === 'textarea') {
                    input = el('textarea', {'class': 'form-control', rows: 3, name: prefix + '[' + name + ']'}); input.value = value || '';
                } else {
                    input = el('input', {'class': 'form-control', name: prefix + '[' + name + ']', type: spec[1] === 'number' ? 'number' : 'text', min: 1}); input.value = value === undefined || value === null ? '' : value;
                }
                if (spec[2]) { input.required = true; }
                col.appendChild(input);
                if (spec[5]) { col.appendChild(el('div', {'class': 'form-text'}, spec[5])); }
                return col;
            }

            function renderTrigger() {
                var box = document.getElementById('pa-trigger-params'); box.innerHTML = '';
                var t = PA.triggers[triggerSel.value];
                document.getElementById('pa-trigger-help').textContent = t ? t.help || '' : '';
                if (!t) { return; }
                Object.keys(t.params || {}).forEach(function (k) {
                    var spec = t.params[k]; var v = (PA.state.params || {})[k]; if (v === undefined) { v = spec[4]; }
                    box.appendChild(field(k, spec, v, 'params'));
                });
            }
            triggerSel.addEventListener('change', function () { PA.state.params = {}; renderTrigger(); });
            renderTrigger();

            function renumber(container, base) {
                Array.prototype.slice.call(container.children).forEach(function (row, i) {
                    row.querySelectorAll('[name]').forEach(function (n) {
                        n.name = n.name.replace(new RegExp('^' + base + '\\[\\d+\\]'), base + '[' + i + ']');
                    });
                    var num = row.querySelector('[data-num]'); if (num) { num.textContent = i + 1; }
                });
            }

            var condBox = document.getElementById('pa-conditions');
            function addCondition(c) {
                document.getElementById('pa-conditions-empty').style.display = 'none';
                var row = el('div', {'class': 'row align-items-end mb-1', 'data-role': 'pa-condition'});
                var i = condBox.querySelectorAll('[data-role=pa-condition]').length;
                var f = el('select', {'class': 'form-select', name: 'conditions[' + i + '][fact]'});
                Object.keys(PA.facts).forEach(function (k) { var o = el('option', {value: k}, PA.facts[k].label); if (c && c.fact === k) { o.selected = true; } f.appendChild(o); });
                var op = el('select', {'class': 'form-select', name: 'conditions[' + i + '][op]'});
                Object.keys(PA.operators).forEach(function (k) { var o = el('option', {value: k}, PA.operators[k]); if (c && c.op === k) { o.selected = true; } op.appendChild(o); });
                var v = el('input', {'class': 'form-control', name: 'conditions[' + i + '][value]', placeholder: 'value (comma-separate for "is one of")'}); v.value = c ? c.value : '';
                var rm = el('button', {type: 'button', 'class': 'btn btn-sm btn-outline-danger'}, 'Remove');
                rm.addEventListener('click', function () { row.remove(); renumber(condBox, 'conditions'); if (!condBox.querySelector('[data-role=pa-condition]')) { document.getElementById('pa-conditions-empty').style.display = ''; } });
                [[f, 4], [op, 2], [v, 4], [rm, 2]].forEach(function (p) { var col = el('div', {'class': 'col-md-' + p[1]}); col.appendChild(p[0]); row.appendChild(col); });
                condBox.appendChild(row);
            }
            document.getElementById('pa-add-condition').addEventListener('click', function () { addCondition(null); });
            (PA.state.conditions || []).forEach(addCondition);

            var stepBox = document.getElementById('pa-steps');
            function addStep(step) {
                var row = el('div', {'class': 'border rounded p-1 mb-1', 'data-role': 'pa-step'});
                var head = el('div', {'class': 'd-flex align-items-center gap-1 mb-1'});
                head.appendChild(el('strong', {'data-num': ''}, ''));
                var sel = el('select', {'class': 'form-select', name: 'steps[0][action]', 'data-role': 'pa-step-action'});
                sel.appendChild(el('option', {value: ''}, 'Choose an action…'));
                var grp = {};
                Object.keys(PA.actions).forEach(function (k) { (grp[PA.actions[k].group] = grp[PA.actions[k].group] || []).push(k); });
                Object.keys(grp).forEach(function (g) {
                    var og = el('optgroup', {label: g});
                    grp[g].forEach(function (k) { var o = el('option', {value: k}, PA.actions[k].label); if (step && step.action === k) { o.selected = true; } og.appendChild(o); });
                    sel.appendChild(og);
                });
                var badge = el('span', {'class': 'badge badge-light-secondary'});
                var up = el('button', {type: 'button', 'class': 'btn btn-sm btn-outline-secondary'}, '↑');
                var down = el('button', {type: 'button', 'class': 'btn btn-sm btn-outline-secondary'}, '↓');
                var rm = el('button', {type: 'button', 'class': 'btn btn-sm btn-outline-danger'}, 'Remove');
                var paramsBox = el('div', {'class': 'row'});
                head.appendChild(sel); head.appendChild(badge); head.appendChild(up); head.appendChild(down); head.appendChild(rm);
                row.appendChild(head); row.appendChild(paramsBox);

                function renderParams(vals) {
                    paramsBox.innerHTML = ''; badge.textContent = '';
                    var a = PA.actions[sel.value]; if (!a) { return; }
                    badge.textContent = a.safety.replace('_', ' ');
                    badge.className = 'badge ' + (['account_state', 'billing', 'entitlement'].indexOf(a.safety) >= 0 ? 'badge-light-warning' : 'badge-light-secondary');
                    var idx = Array.prototype.indexOf.call(stepBox.children, row);
                    Object.keys(a.params || {}).forEach(function (k) {
                        var spec = a.params[k]; var v = vals ? vals[k] : undefined; if (v === undefined) { v = spec[4]; }
                        paramsBox.appendChild(field(k, spec, v, 'steps[' + (idx < 0 ? 0 : idx) + '][params]'));
                    });
                    if (a.help) { paramsBox.appendChild(el('div', {'class': 'col-12 small text-muted'}, a.help)); }
                }
                sel.addEventListener('change', function () { renderParams(null); renumber(stepBox, 'steps'); });
                rm.addEventListener('click', function () { row.remove(); renumber(stepBox, 'steps'); });
                up.addEventListener('click', function () { if (row.previousElementSibling) { stepBox.insertBefore(row, row.previousElementSibling); renumber(stepBox, 'steps'); } });
                down.addEventListener('click', function () { if (row.nextElementSibling) { stepBox.insertBefore(row.nextElementSibling, row); renumber(stepBox, 'steps'); } });
                stepBox.appendChild(row);
                renderParams(step ? step.params : null);
                renumber(stepBox, 'steps');
            }
            document.getElementById('pa-add-step').addEventListener('click', function () { addStep(null); });
            (PA.state.steps || []).forEach(addStep);
            if (!(PA.state.steps || []).length) { addStep(null); }
        })();
    </script>
@endsection
