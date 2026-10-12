@extends('layouts/contentLayoutMaster')

@section('title', 'Outreach script and settings')

@section('content')
    <div class="row mb-2">
        <div class="col-12">
            <h4 class="mb-0">Outreach</h4>
            <p class="text-caption mb-0">The texts your prospects receive. Build the sequence your way: as many messages as your sales process needs, in the order you choose.</p>
        </div>
    </div>

    @include('customer.workspaces.prospecting._nav', ['prospectingActive' => 'script'])

    @foreach((array) session('outreach_warnings', []) as $warning)
        <x-alert variant="warning" data-role="script-warning">{{ $warning }}</x-alert>
    @endforeach

    @php
        $tokens = [
            '{{agency.name}}' => 'Your agency name',
            '{{agency.website}}' => 'Your website',
            '{{agency.calendar_link}}' => 'Your calendar link',
            '{{prospect.first_name}}' => 'Prospect first name',
            '{{prospect.full_name}}' => 'Prospect name',
            '{{prospect.company}}' => 'Prospect company',
        ];
        $editorData = [
            'steps' => array_values(array_map(fn ($s) => [
                'id' => $s['id'], 'body' => $s['body'], 'advance_on' => $s['advance_on'],
                'delay_hours' => $s['delay_hours'], 'enabled' => (bool) $s['enabled'],
            ], old('sequence_steps') ? array_map(fn ($s) => $s + ['delay_hours' => null, 'enabled' => true, 'advance_on' => 'reply'], (array) old('sequence_steps')) : $sequenceSteps)),
            'faq' => array_values(array_map(fn ($e) => [
                'id' => $e['id'], 'question' => $e['question'], 'answer' => $e['answer'],
                'keywords' => is_array($e['keywords']) ? implode(', ', $e['keywords']) : (string) $e['keywords'],
                'intent' => $e['intent'], 'niche' => $e['niche'], 'campaign_ids' => $e['campaign_ids'] ?? [],
            ], $faqEntries)),
            'starter' => $starterTemplate,
            'campaigns' => $campaignOptions,
        ];
    @endphp

    <form method="post" action="{{ route('customer.workspaces.prospecting.script.update', $workspaceUid) }}" data-role="script-form" data-preview-url="{{ $previewUrl }}">
        @csrf

        <x-card title="Who you are" :padded="true" class="mb-2">
            <div class="row">
                <div class="col-md-6 mb-1">
                    <label class="form-label" for="agency_name">Agency name</label>
                    <input type="text" id="agency_name" name="agency_name" class="form-control @error('agency_name') is-invalid @enderror" value="{{ old('agency_name', $agencyName) }}">
                    @error('agency_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    <p class="text-caption mb-0">Shown to prospects wherever your text uses your agency name.</p>
                </div>
                <div class="col-md-6 mb-1">
                    <label class="form-label" for="website_url">Website</label>
                    <input type="text" id="website_url" name="website_url" class="form-control @error('website_url') is-invalid @enderror" value="{{ old('website_url', $websiteUrl) }}" placeholder="https://">
                    @error('website_url')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
            </div>
        </x-card>

        <x-card title="Message sequence" :padded="true" class="mb-2" data-role="sequence-editor">
            <p class="text-caption">Your campaign's opening message starts the conversation. These messages follow, one at a time, in this order. Each is sent when the prospect replies, or after a wait if they have not replied yet. A reply to a timed message stops its timer.</p>

            <div class="form-check mb-1">
                <input type="hidden" name="sequence_paused" value="0">
                <input class="form-check-input" type="checkbox" id="sequence_paused" name="sequence_paused" value="1" @checked(old('sequence_paused', $sequencePaused))>
                <label class="form-check-label" for="sequence_paused">Pause the sequence <span class="text-caption">(nothing is sent while paused; prospects stay where they are)</span></label>
            </div>

            <input type="hidden" name="sequence_steps_present" value="1">
            <div data-role="steps"></div>

            <div class="d-flex flex-wrap gap-1 mt-1">
                <x-button type="button" variant="outline" size="sm" data-role="add-step">Add a message</x-button>
                <x-button type="button" variant="outline" size="sm" data-role="load-starter">Use the three-message starter template</x-button>
            </div>

            <div class="mt-1">
                <span class="text-caption">Insert a detail into the message you are editing:</span>
                @foreach($tokens as $token => $label)
                    <button type="button" class="btn btn-sm btn-outline-secondary ms-25" data-merge-insert="{{ $token }}" title="{{ $label }}">{{ $label }}</button>
                @endforeach
            </div>
            <p class="text-caption mt-50 mb-0">Put your calendar link in whichever message suits your process: <code>@{{agency.calendar_link}}</code>.</p>
        </x-card>

        <x-card title="Approved answers" :padded="true" class="mb-2" data-role="faq-editor">
            <p class="text-caption">When a prospect asks a question, Outreach answers from these approved answers first, then continues the sequence. It never invents facts. Add keywords to help match how prospects phrase the question; limit an answer to one campaign or niche if it only applies there.</p>
            <input type="hidden" name="faq_entries_present" value="1">
            <div data-role="faq"></div>
            <x-button type="button" variant="outline" size="sm" data-role="add-faq" class="mt-1">Add a question and answer</x-button>

            <div class="mt-2">
                <label class="form-label" for="faq_fallback">When a question is not covered by an approved answer</label>
                <select id="faq_fallback" name="faq_fallback" class="form-select">
                    <option value="ai" @selected(old('faq_fallback', $faqFallback) === 'ai')>Let AI answer in one short sentence using only the facts on this page</option>
                    <option value="continue" @selected(old('faq_fallback', $faqFallback) === 'continue')>Skip it and continue with the next message</option>
                    <option value="handoff" @selected(old('faq_fallback', $faqFallback) === 'handoff')>Do not reply; leave it for me to answer</option>
                </select>
            </div>
        </x-card>

        <x-card title="Booking" :padded="true" class="mb-2">
            <div class="mb-1">
                <label class="form-label" for="booking_url">Calendar link</label>
                <input type="text" id="booking_url" name="booking_url" class="form-control @error('booking_url') is-invalid @enderror" value="{{ old('booking_url', $bookingUrl) }}" placeholder="https://">
                @error('booking_url')<div class="invalid-feedback">{{ $message }}</div>@enderror
                <p class="text-caption mb-0">Any booking page you already use. Prospects get it in any message that contains the calendar link detail.</p>
            </div>
            @if($bookingTypes !== [])
                <div class="mb-1 d-flex gap-2 align-items-end" data-role="booking-type-picker">
                    <div>
                        <label class="form-label" for="booking_type_choice">Use my Booking Type page</label>
                        <select id="booking_type_choice" class="form-select">
                            @foreach($bookingTypes as $type)
                                <option value="{{ $type['url'] }}">{{ $type['name'] }}</option>
                            @endforeach
                        </select>
                    </div>
                    <x-button type="button" variant="outline" size="sm" data-role="use-booking-type">Use this page</x-button>
                </div>
            @endif
            <fieldset class="mt-1">
                <legend class="form-label">How prospects book</legend>
                <div class="form-check">
                    <input class="form-check-input" type="radio" id="mode_link" checked disabled>
                    <label class="form-check-label" for="mode_link">Calendar link</label>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="radio" id="mode_conv" disabled>
                    <label class="form-check-label" for="mode_conv">Pick a time in the conversation <span class="text-caption">(coming later)</span></label>
                </div>
            </fieldset>
        </x-card>

        <x-card title="AI help" :padded="true" class="mb-2">
            <div class="form-check">
                <input type="hidden" name="ai_enabled" value="0">
                <input class="form-check-input" type="checkbox" id="ai_enabled" name="ai_enabled" value="1" @checked(old('ai_enabled', $aiEnabled))>
                <label class="form-check-label" for="ai_enabled">Allow AI to answer a question your approved answers do not cover</label>
            </div>
            <p class="text-caption mb-0">AI only writes one short sentence from the approved answers and facts on this page, then your next message follows. Prospects who reply STOP are never texted again, whatever you choose here.</p>
        </x-card>

        <x-button type="submit" variant="primary">Save script and settings</x-button>
        <a class="ms-2 text-caption" href="{{ route('customer.workspaces.prospecting.settings.show', $workspaceUid) }}">Older agent setup</a>
    </form>
@endsection

@section('page-script')
    <script>
        (function () {
            var data = @json($editorData);
            var form = document.querySelector('[data-role="script-form"]');
            if (!form) { return; }
            var previewUrl = form.getAttribute('data-preview-url');
            var csrf = form.querySelector('input[name="_token"]').value;
            var stepsEl = form.querySelector('[data-role="steps"]');
            var faqEl = form.querySelector('[data-role="faq"]');
            var lastArea = null;
            var counter = 0;

            function uid(prefix) { counter += 1; return prefix + Date.now().toString(36) + counter; }
            function el(html) { var d = document.createElement('div'); d.innerHTML = html.trim(); return d.firstChild; }
            function esc(v) { return String(v == null ? '' : v).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;'); }

            // ---- steps -------------------------------------------------------------------------
            var steps = data.steps.slice();

            function renderSteps() {
                stepsEl.innerHTML = '';
                steps.forEach(function (s, i) {
                    var n = 'sequence_steps[' + i + ']';
                    var card = el(
                        '<div class="border rounded p-1 mb-1" data-step-card="' + esc(s.id) + '">' +
                        '<input type="hidden" name="' + n + '[id]" value="' + esc(s.id) + '">' +
                        '<div class="d-flex flex-wrap justify-content-between align-items-center mb-50">' +
                        '<strong>Message ' + (i + 1) + '</strong>' +
                        '<span>' +
                        '<button type="button" class="btn btn-sm btn-outline-secondary" data-act="up"' + (i === 0 ? ' disabled' : '') + '>Move up</button> ' +
                        '<button type="button" class="btn btn-sm btn-outline-secondary" data-act="down"' + (i === steps.length - 1 ? ' disabled' : '') + '>Move down</button> ' +
                        '<button type="button" class="btn btn-sm btn-outline-secondary" data-act="dup">Duplicate</button> ' +
                        '<button type="button" class="btn btn-sm btn-outline-danger" data-act="del">Delete</button>' +
                        '</span></div>' +
                        '<textarea class="form-control" rows="3" name="' + n + '[body]" data-preview-source="' + esc(s.id) + '">' + esc(s.body) + '</textarea>' +
                        '<div class="row mt-50">' +
                        '<div class="col-md-5"><label class="form-label">Sent</label>' +
                        '<select class="form-select" name="' + n + '[advance_on]" data-advance>' +
                        '<option value="reply"' + (s.advance_on === 'reply' ? ' selected' : '') + '>When the prospect replies</option>' +
                        '<option value="time"' + (s.advance_on === 'time' ? ' selected' : '') + '>After a wait, if they have not replied</option>' +
                        '</select></div>' +
                        '<div class="col-md-4"' + (s.advance_on === 'time' ? '' : ' hidden') + ' data-delay><label class="form-label">Wait (hours)</label>' +
                        '<input type="number" min="1" max="720" class="form-control" name="' + n + '[delay_hours]" value="' + esc(s.delay_hours || 24) + '"></div>' +
                        '<div class="col-md-3 d-flex align-items-end"><div class="form-check">' +
                        '<input type="hidden" name="' + n + '[enabled]" value="0">' +
                        '<input class="form-check-input" type="checkbox" name="' + n + '[enabled]" value="1"' + (s.enabled ? ' checked' : '') + '>' +
                        '<label class="form-check-label">Enabled</label></div></div>' +
                        '</div>' +
                        '<p class="text-caption mt-50 mb-25">What a prospect will see:</p>' +
                        '<div class="border rounded p-50" data-preview-target="' + esc(s.id) + '" aria-live="polite">&nbsp;</div>' +
                        '</div>'
                    );
                    stepsEl.appendChild(card);
                });
                stepsEl.querySelectorAll('[data-preview-source]').forEach(function (area) { preview(area); });
            }

            function syncFromDom() {
                var out = [];
                stepsEl.querySelectorAll('[data-step-card]').forEach(function (card) {
                    out.push({
                        id: card.getAttribute('data-step-card'),
                        body: card.querySelector('textarea').value,
                        advance_on: card.querySelector('[data-advance]').value,
                        delay_hours: parseInt(card.querySelector('[data-delay] input').value, 10) || 24,
                        enabled: card.querySelector('input[type="checkbox"]').checked
                    });
                });
                steps = out;
            }

            stepsEl.addEventListener('click', function (e) {
                var btn = e.target.closest('[data-act]');
                if (!btn) { return; }
                syncFromDom();
                var card = btn.closest('[data-step-card]');
                var i = steps.findIndex(function (s) { return s.id === card.getAttribute('data-step-card'); });
                var act = btn.getAttribute('data-act');
                if (act === 'del') { steps.splice(i, 1); }
                if (act === 'up' && i > 0) { steps.splice(i - 1, 0, steps.splice(i, 1)[0]); }
                if (act === 'down' && i < steps.length - 1) { steps.splice(i + 1, 0, steps.splice(i, 1)[0]); }
                if (act === 'dup') { var c = JSON.parse(JSON.stringify(steps[i])); c.id = uid('s'); steps.splice(i + 1, 0, c); }
                renderSteps();
            });

            stepsEl.addEventListener('change', function (e) {
                if (e.target.matches('[data-advance]')) {
                    e.target.closest('[data-step-card]').querySelector('[data-delay]').hidden = e.target.value !== 'time';
                }
            });

            form.querySelector('[data-role="add-step"]').addEventListener('click', function () {
                syncFromDom();
                steps.push({id: uid('s'), body: '', advance_on: 'reply', delay_hours: null, enabled: true});
                renderSteps();
            });

            form.querySelector('[data-role="load-starter"]').addEventListener('click', function () {
                if (steps.length && !window.confirm('Replace your current messages with the three-message starter template?')) { return; }
                steps = data.starter.map(function (s) { return Object.assign({id: uid('s')}, s); });
                renderSteps();
            });

            // ---- approved answers --------------------------------------------------------------
            var faq = data.faq.slice();

            function renderFaq() {
                faqEl.innerHTML = '';
                faq.forEach(function (q, i) {
                    var n = 'faq_entries[' + i + ']';
                    var camp = data.campaigns.map(function (c) {
                        var on = (q.campaign_ids || []).indexOf(c.id) !== -1;
                        return '<label class="me-1"><input type="checkbox" name="' + n + '[campaign_ids][]" value="' + c.id + '"' + (on ? ' checked' : '') + '> ' + esc(c.name) + '</label>';
                    }).join('');
                    faqEl.appendChild(el(
                        '<div class="border rounded p-1 mb-1" data-faq-card="' + esc(q.id) + '">' +
                        '<input type="hidden" name="' + n + '[id]" value="' + esc(q.id) + '">' +
                        '<input type="hidden" name="' + n + '[intent]" value="' + esc(q.intent || '') + '">' +
                        '<div class="d-flex justify-content-end mb-50"><span>' +
                        '<button type="button" class="btn btn-sm btn-outline-secondary" data-act="up"' + (i === 0 ? ' disabled' : '') + '>Move up</button> ' +
                        '<button type="button" class="btn btn-sm btn-outline-secondary" data-act="down"' + (i === faq.length - 1 ? ' disabled' : '') + '>Move down</button> ' +
                        '<button type="button" class="btn btn-sm btn-outline-secondary" data-act="dup">Duplicate</button> ' +
                        '<button type="button" class="btn btn-sm btn-outline-danger" data-act="del">Delete</button></span></div>' +
                        '<label class="form-label">Question</label>' +
                        '<input type="text" class="form-control mb-50" name="' + n + '[question]" value="' + esc(q.question) + '">' +
                        '<label class="form-label">Approved answer</label>' +
                        '<textarea class="form-control mb-50" rows="2" name="' + n + '[answer]">' + esc(q.answer) + '</textarea>' +
                        '<label class="form-label">Keywords that mean this question <span class="text-caption">(comma separated, optional)</span></label>' +
                        '<input type="text" class="form-control mb-50" name="' + n + '[keywords]" value="' + esc(q.keywords) + '">' +
                        '<div class="row"><div class="col-md-6"><label class="form-label">Only for this niche <span class="text-caption">(optional)</span></label>' +
                        '<input type="text" class="form-control" name="' + n + '[niche]" value="' + esc(q.niche || '') + '"></div>' +
                        (data.campaigns.length ? '<div class="col-md-6"><label class="form-label">Only for these campaigns <span class="text-caption">(none = all)</span></label><div>' + camp + '</div></div>' : '') +
                        '</div></div>'
                    ));
                });
            }

            function syncFaq() {
                var out = [];
                faqEl.querySelectorAll('[data-faq-card]').forEach(function (card) {
                    var get = function (k) { var f = card.querySelector('[name$="[' + k + ']"]'); return f ? f.value : ''; };
                    out.push({
                        id: card.getAttribute('data-faq-card'), question: get('question'), answer: get('answer'),
                        keywords: get('keywords'), niche: get('niche'), intent: get('intent'),
                        campaign_ids: Array.prototype.map.call(card.querySelectorAll('input[type="checkbox"]:checked'), function (c) { return parseInt(c.value, 10); })
                    });
                });
                faq = out;
            }

            faqEl.addEventListener('click', function (e) {
                var btn = e.target.closest('[data-act]');
                if (!btn) { return; }
                syncFaq();
                var i = faq.findIndex(function (q) { return q.id === btn.closest('[data-faq-card]').getAttribute('data-faq-card'); });
                var act = btn.getAttribute('data-act');
                if (act === 'del') { faq.splice(i, 1); }
                if (act === 'up' && i > 0) { faq.splice(i - 1, 0, faq.splice(i, 1)[0]); }
                if (act === 'down' && i < faq.length - 1) { faq.splice(i + 1, 0, faq.splice(i, 1)[0]); }
                if (act === 'dup') { var c = JSON.parse(JSON.stringify(faq[i])); c.id = uid('q'); c.intent = ''; faq.splice(i + 1, 0, c); }
                renderFaq();
            });

            form.querySelector('[data-role="add-faq"]').addEventListener('click', function () {
                syncFaq();
                faq.push({id: uid('q'), question: '', answer: '', keywords: '', intent: '', niche: '', campaign_ids: []});
                renderFaq();
            });

            // ---- preview + inserting details -----------------------------------------------------
            var timers = {};
            function preview(area) {
                var id = area.getAttribute('data-preview-source');
                var target = form.querySelector('[data-preview-target="' + id + '"]');
                if (!target) { return; }
                fetch(previewUrl, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf},
                    body: JSON.stringify({text: area.value})
                }).then(function (r) { return r.ok ? r.json() : null; }).then(function (d) {
                    if (!d) { return; }
                    var note = (d.unknown && d.unknown.length) ? '  (not recognised, left blank: ' + d.unknown.join(', ') + ')' : '';
                    target.textContent = (d.preview || '') + note;
                }).catch(function () {});
            }

            form.addEventListener('input', function (e) {
                var area = e.target.closest('[data-preview-source]');
                if (!area) { return; }
                clearTimeout(timers[area.name]);
                timers[area.name] = setTimeout(function () { preview(area); }, 350);
            });
            form.addEventListener('focusin', function (e) {
                if (e.target.matches('textarea')) { lastArea = e.target; }
            });
            form.addEventListener('click', function (e) {
                var ins = e.target.closest('[data-merge-insert]');
                if (ins && lastArea) {
                    var t = ins.getAttribute('data-merge-insert');
                    var s = lastArea.selectionStart || lastArea.value.length;
                    lastArea.value = lastArea.value.slice(0, s) + t + lastArea.value.slice(lastArea.selectionEnd || s);
                    lastArea.dispatchEvent(new Event('input', {bubbles: true}));
                    lastArea.focus();
                }
                if (e.target.closest('[data-role="use-booking-type"]')) {
                    var choice = document.getElementById('booking_type_choice');
                    if (choice) { document.getElementById('booking_url').value = choice.value; }
                }
            });

            renderSteps();
            renderFaq();
        })();
    </script>
@endsection
