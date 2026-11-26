@extends('layouts/contentLayoutMaster')

@section('title', 'Outreach script and settings')
@section('page-heading', 'own')

@section('content')
<div class="og page">
    @include('customer.workspaces.prospecting._nav', ['prospectingActive' => 'script', 'ogInside' => true])

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

    @foreach((array) session('outreach_warnings', []) as $warning)
        <div class="alertbar" role="alert" data-role="script-warning"><span>{{ $warning }}</span></div>
    @endforeach

    @if($errors->any())
        <div class="errsum" role="alert" data-role="script-errors">
            <b>Fix {{ $errors->count() }} {{ \Illuminate\Support\Str::plural('thing', $errors->count()) }} before saving</b>
            <ul>@foreach(collect($errors->all())->unique()->take(8) as $message)<li>{{ $message }}</li>@endforeach</ul>
        </div>
    @endif

    <form method="post" action="{{ route('customer.workspaces.prospecting.script.update', $workspaceUid) }}" data-role="script-form" data-preview-url="{{ $previewUrl }}" novalidate>
        @csrf
        <input type="hidden" name="faq_snapshot" value="{{ hash('sha256', serialize($faqEntries)) }}">
        <div class="sc">
            <aside class="sc-side" aria-label="Script sections">
                <a href="#sc-who" class="on" data-spy><span class="n">1</span>Who you are</a>
                <a href="#sc-messages" data-spy><span class="n">2</span>Messages</a>
                <a href="#sc-answers" data-spy><span class="n">3</span>Approved answers</a>
                <a href="#sc-booking" data-spy><span class="n">4</span>Booking</a>
                <a href="#sc-timing" data-spy><span class="n">5</span>Reply timing</a>
                <div class="seq">
                    <input type="hidden" name="sequence_paused" id="sequence_paused" value="{{ old('sequence_paused', $sequencePaused) ? 1 : 0 }}">
                    <label for="sequence_on"><input type="checkbox" class="swi" id="sequence_on" @checked(! old('sequence_paused', $sequencePaused)) aria-describedby="seq-help"><span id="seq-state">{{ old('sequence_paused', $sequencePaused) ? 'Sequence paused' : 'Sequence on' }}</span></label>
                    <span class="mut" id="seq-help">STOP is always respected. Nothing is sent while paused; prospects stay where they are.</span>
                </div>
            </aside>

            <div class="sc-main">
                <p class="mut">Which number these texts come from is set in <a class="link" href="{{ route('customer.workspaces.prospecting.phone-messaging', $workspaceUid) }}">Phone &amp; texting</a>.</p>

                <section class="sec" id="sc-who" aria-labelledby="h-who">
                    <div class="sec-h"><span class="num">1</span><div><b id="h-who">Who you are</b><span class="d">Good wherever a message says your agency.</span></div></div>
                    <div class="split" style="flex-wrap:wrap">
                        <div class="fl" style="flex:1 1 240px">
                            <label for="agency_name">Agency name</label>
                            <input type="text" id="agency_name" name="agency_name" class="in @error('agency_name') bad @enderror" value="{{ old('agency_name', $agencyName) }}">
                            @error('agency_name')<span class="err" role="alert">{{ $message }}</span>@enderror
                            <span class="hint">Shown to prospects wherever your text uses your agency name.</span>
                        </div>
                        <div class="fl" style="flex:1 1 240px">
                            <label for="website_url">Website</label>
                            <input type="text" id="website_url" name="website_url" class="in @error('website_url') bad @enderror" value="{{ old('website_url', $websiteUrl) }}" placeholder="https://">
                            @error('website_url')<span class="err" role="alert">{{ $message }}</span>@enderror
                        </div>
                    </div>
                </section>

                <section class="sec" id="sc-messages" data-role="sequence-editor" aria-labelledby="h-msg">
                    <div class="sec-h"><span class="num">2</span><div><b id="h-msg">Messages</b><span class="d">Your campaign's opening message starts the conversation. These follow one at a time, in order — each when they reply, or after a wait. A reply stops the timer.</span></div></div>
                    <input type="hidden" name="sequence_steps_present" value="1">
                    <div data-role="steps"></div>
                    <div class="addrow">
                        <button type="button" class="btn out sm" data-role="add-step"><svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>Add message</button>
                        <button type="button" class="btn out sm" data-role="load-starter">Use starter template</button>
                    </div>
                    <div class="toks" role="group" aria-label="Insert a detail into the message you are editing">
                        <span>Insert into the message you're editing:</span>
                        @foreach($tokens as $token => $label)
                            <button type="button" class="tok" data-merge-insert="{{ $token }}" title="{{ $token }}">{{ $label }}</button>
                        @endforeach
                    </div>
                    <span class="hint">Put your calendar link in whichever message suits your process: <code>@{{agency.calendar_link}}</code>.</span>
                </section>

                <section class="sec" id="sc-answers" data-role="faq-editor" aria-labelledby="h-faq">
                    <div class="sec-h"><span class="num">3</span><div><b id="h-faq">Approved answers</b><span class="d">When a prospect asks something, we answer from these first — never invented facts. Add keywords to help match how they phrase it; limit an answer to one campaign or niche if it only applies there.</span></div></div>
                    <input type="hidden" name="faq_entries_present" value="1">
                    <div data-role="faq" style="display:flex;flex-direction:column;gap:10px"></div>
                    <div class="addrow"><button type="button" class="btn out sm" data-role="add-faq"><svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>Add question &amp; answer</button></div>

                    <div class="fallback" data-role="faq-fallback">
                        <div class="tx"><b>Question not covered?</b><span>What happens when a question is not covered by an approved answer.</span></div>
                        <div class="fl" style="flex:1 1 280px">
                            <label class="sr" for="faq_fallback">When a question is not covered by an approved answer</label>
                            <select id="faq_fallback" name="faq_fallback" class="in">
                                <option value="ai" @selected(old('faq_fallback', $faqFallback) === 'ai')>Let AI match the question to one of these approved answers (it never writes its own), otherwise continue</option>
                                <option value="continue" @selected(old('faq_fallback', $faqFallback) === 'continue')>Skip it and continue with the next message</option>
                                <option value="handoff" @selected(old('faq_fallback', $faqFallback) === 'handoff')>Do not reply; leave it for me to answer</option>
                            </select>
                        </div>
                        <label style="display:flex;align-items:center;gap:10px;margin:0;font-size:13.5px;font-weight:600;flex:1 1 240px">
                            <input type="hidden" name="ai_enabled" value="0">
                            <input type="checkbox" class="swi" id="ai_enabled" name="ai_enabled" value="1" @checked(old('ai_enabled', $aiEnabled))>
                            <span>Allow AI to answer a question your approved answers do not cover<span class="mut" style="display:block;font-weight:400">One short sentence from the facts on this page, then your next message follows. STOP is always respected.</span></span>
                        </label>
                    </div>
                </section>

                <section class="sec" id="sc-booking" aria-labelledby="h-book">
                    <div class="sec-h"><span class="num">4</span><div><b id="h-book">Booking</b><span class="d">How prospects book a call.</span></div></div>
                    <div class="bk">
                        <div class="sel1">
                            <div class="ttl"><span class="rd" aria-hidden="true"></span><b>Send a calendar link</b></div>
                            <input type="text" id="booking_url" name="booking_url" class="in @error('booking_url') bad @enderror" value="{{ old('booking_url', $bookingUrl) }}" placeholder="https://" aria-label="Calendar link" style="font-size:13.5px">
                            @error('booking_url')<span class="err" role="alert">{{ $message }}</span>@enderror
                            <span class="mut">Any booking page you already use. Prospects get it in any message that contains the calendar link detail.</span>
                            @if($bookingTypes !== [])
                                <div data-role="booking-type-picker" style="display:flex;flex-wrap:wrap;gap:8px;align-items:center">
                                    <label for="booking_type_choice" class="mut" style="margin:0">Or use your booking type:</label>
                                    <select id="booking_type_choice" class="in" style="height:38px;width:auto;flex:1 1 160px;font-size:13.5px">
                                        @foreach($bookingTypes as $type)<option value="{{ $type['url'] }}">{{ $type['name'] }}</option>@endforeach
                                    </select>
                                    <button type="button" class="btn out sm" data-role="use-booking-type">Use this page</button>
                                </div>
                            @endif
                        </div>
                        <div class="dis" aria-disabled="true">
                            <div class="ttl"><span class="rd o" aria-hidden="true"></span><b>Pick a time in the chat</b><span class="pl n">Coming soon</span></div>
                            <span class="mut">The AI offers times and books it for them — coming later.</span>
                        </div>
                    </div>
                </section>

                <section class="sec" id="sc-timing" data-role="reply-timing" aria-labelledby="h-timing">
                    <div class="sec-h"><span class="num">5</span><div><b id="h-timing">Reply timing</b><span class="d">Replies wait a random moment inside these windows so it feels human. Use 0 for an immediate reply. STOP is always handled immediately, and timed follow-ups keep their own waits.</span></div></div>
                    <div class="rt">
                        @foreach(\App\Library\AgencyOutreach\OutreachReplyTiming::LABELS as $category => $label)
                            <div data-reply-delay="{{ $category }}">
                                <label class="lbl" for="reply_delay_{{ $category }}_min">{{ $label }}</label>
                                <div class="pair">
                                    <input type="number" min="0" max="{{ \App\Library\AgencyOutreach\OutreachReplyTiming::MAX_MINUTES }}" id="reply_delay_{{ $category }}_min" name="reply_delay[{{ $category }}][min]" value="{{ old('reply_delay.'.$category.'.min', $replyDelay[$category]['min']) }}" aria-label="{{ $label }}: shortest wait in minutes">
                                    <span class="mut">–</span>
                                    <input type="number" min="0" max="{{ \App\Library\AgencyOutreach\OutreachReplyTiming::MAX_MINUTES }}" id="reply_delay_{{ $category }}_max" name="reply_delay[{{ $category }}][max]" value="{{ old('reply_delay.'.$category.'.max', $replyDelay[$category]['max']) }}" aria-label="{{ $label }}: longest wait in minutes">
                                    <small>min</small>
                                </div>
                                @error('reply_delay.'.$category.'.min')<span class="err" role="alert">{{ $message }}</span>@enderror
                                @error('reply_delay.'.$category.'.max')<span class="err" role="alert">{{ $message }}</span>@enderror
                            </div>
                        @endforeach
                    </div>
                </section>

                <section class="sec" id="sc-opener-cap" data-role="opener-daily-cap" aria-labelledby="h-opener-cap">
                    <div class="sec-h"><span class="num">6</span><div><b id="h-opener-cap">New conversations per 24 hours</b><span class="d">Maximum new conversations started by your sending number in any rolling 24-hour period. Applies across all campaigns, and is shared by all your own sending numbers. Extra first messages wait automatically. Replies and follow-ups are not limited by this setting.</span></div></div>
                    <div>
                        <label class="lbl" for="opener_daily_cap">New conversations per 24 hours</label>
                        <input type="number" inputmode="numeric" min="1" max="500" step="1" id="opener_daily_cap" name="opener_daily_cap" value="{{ old('opener_daily_cap', $openerDailyCap) }}" aria-describedby="opener-cap-help">
                        <small id="opener-cap-help" class="mut">Choose 1 to 500. The default is 50. A higher limit does not guarantee deliverability or compliance.</small>
                        @error('opener_daily_cap')<span class="err" role="alert">{{ $message }}</span>@enderror
                    </div>
                </section>

                <div class="savebar" id="savebar" data-role="savebar">
                    <span class="dot" aria-hidden="true"></span>
                    <span class="st" role="status" aria-live="polite" id="savebar-text">All changes saved</span>
                    <a href="{{ route('customer.workspaces.prospecting.settings.show', $workspaceUid) }}">Older agent setup</a>
                    <button type="submit" class="btn pri">Save script</button>
                </div>
            </div>
        </div>
    </form>

    <section class="sec" id="sc-senders" data-role="sending-numbers" aria-labelledby="h-senders">
        <div class="sec-h"><span class="num">7</span><div><b id="h-senders">Sending numbers</b><span class="d">Which of your own numbers each campaign starts new conversations from. New prospects are spread evenly across the selected numbers. A prospect keeps the number their conversation started on — changing this never moves them.</span></div></div>

        @if($senders['campaign'] === null)
            <p class="mut" data-role="senders-empty">Create an Outreach campaign first; its sending numbers are chosen here.</p>
        @else
            @if(count($senders['campaigns']) > 1)
                <form method="get" action="{{ route('customer.workspaces.prospecting.script.show', $workspaceUid) }}#sc-senders" data-role="senders-campaign-picker">
                    <label class="lbl" for="senders_campaign">Campaign</label>
                    <select id="senders_campaign" name="senders_campaign" class="in" style="width:auto" onchange="this.form.submit()">
                        @foreach($senders['campaigns'] as $option)
                            <option value="{{ $option['uid'] }}" @selected($option['uid'] === $senders['campaign']['uid'])>{{ $option['name'] }}</option>
                        @endforeach
                    </select>
                    <noscript><button type="submit" class="btn">Show</button></noscript>
                </form>
            @endif

            <form method="post" action="{{ route('customer.workspaces.prospecting.campaigns.senders', [$workspaceUid, $senders['campaign']['uid']]) }}" data-role="senders-form">
                @csrf
                @unless($senders['campaign']['configured'])
                    <p class="mut" data-role="senders-default-note">“{{ $senders['campaign']['name'] }}” sends from your default sender until you save a selection here.</p>
                @endunless

                @if(collect($senders['numbers'])->isEmpty())
                    <p class="mut">You have no Telnyx numbers of your own yet. Connect them in <a class="link" href="{{ route('customer.workspaces.prospecting.phone-messaging', $workspaceUid) }}">Phone &amp; texting</a>.</p>
                @else
                    <table class="table" data-role="senders-table">
                        <thead><tr><th scope="col">Use</th><th scope="col">Number</th><th scope="col">Status</th><th scope="col">Prospects on it</th></tr></thead>
                        <tbody>
                        @foreach($senders['numbers'] as $n)
                            <tr data-role="sender-row" data-number="{{ $n['number'] }}">
                                <td><input type="checkbox" name="channels[]" value="{{ $n['uid'] }}" id="sender_{{ $n['uid'] }}" aria-label="Use {{ $n['number'] }}" @checked($n['selected']) @disabled(! $n['in_pool'])></td>
                                <td><label for="sender_{{ $n['uid'] }}">{{ $n['number'] }}</label></td>
                                <td data-role="sender-status">
                                    @if($n['available'])
                                        Available
                                    @else
                                        Unavailable — {{ $n['reason'] }}
                                    @endif
                                    @if($n['held'] > 0)
                                        <br><span class="err" role="status">{{ $n['held'] }} {{ \Illuminate\Support\Str::plural('message', $n['held']) }} waiting until this number can send again.</span>
                                    @endif
                                </td>
                                <td>{{ $n['assigned'] }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>

                    @if($senders['cap'] !== null && $senders['distribution'] !== [])
                        <p class="mut" data-role="senders-distribution">Estimated split of your {{ $senders['cap'] }} new conversations per 24 hours (shared by all numbers, never multiplied):
                            @foreach($senders['distribution'] as $share){{ $share['number'] }}: about {{ $share['share'] }}@if(! $loop->last), @endif @endforeach.
                        </p>
                    @endif

                    <div style="display:flex;gap:8px;flex-wrap:wrap">
                        <button type="submit" class="btn pri">Save sending numbers</button>
                        <button type="submit" class="btn" name="select_all" value="1">Use all available numbers</button>
                    </div>
                @endif
            </form>
        @endif
    </section>
</div>
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

            var ICON = {
                up: '<svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 19V5M6 11l6-6 6 6"/></svg>',
                down: '<svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M6 13l6 6 6-6"/></svg>',
                dup: '<svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><rect x="8" y="8" width="12" height="12" rx="2"/><path d="M4 16V6a2 2 0 0 1 2-2h10"/></svg>',
                del: '<svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 7h14M10 7V4h4v3M7 7l1 13h8l1-13"/></svg>'
            };
            function iconBtn(act, label, disabled) {
                return '<button type="button" class="ib sm2" data-act="' + act + '" title="' + label + '" aria-label="' + label + '"' + (disabled ? ' disabled' : '') + '>' + ICON[act] + '</button>';
            }

            function renderSteps() {
                stepsEl.innerHTML = '';
                steps.forEach(function (s, i) {
                    var n = 'sequence_steps[' + i + ']';
                    var pill = i === 0 ? '<span class="pl n">First text</span>'
                        : (s.advance_on === 'time' ? '<span class="pl a">After ' + esc(s.delay_hours || 24) + 'h wait</span>' : '<span class="pl b">After reply</span>');
                    var card = el(
                        '<div class="stp' + (s.enabled ? '' : ' off') + '" data-step-card="' + esc(s.id) + '">' +
                        '<input type="hidden" name="' + n + '[id]" value="' + esc(s.id) + '">' +
                        '<div class="rail"><span class="c">' + (i + 1) + '</span><i></i></div>' +
                        '<div class="body">' +
                        '<div class="hd"><b>Message ' + (i + 1) + '</b>' + pill +
                        '<input type="hidden" name="' + n + '[enabled]" value="0">' +
                        '<input class="swi" type="checkbox" name="' + n + '[enabled]" value="1"' + (s.enabled ? ' checked' : '') + ' aria-label="Message ' + (i + 1) + ' enabled" title="Enabled">' +
                        iconBtn('up', 'Move message ' + (i + 1) + ' up', i === 0) +
                        iconBtn('down', 'Move message ' + (i + 1) + ' down', i === steps.length - 1) +
                        iconBtn('dup', 'Duplicate message ' + (i + 1)) +
                        iconBtn('del', 'Delete message ' + (i + 1)) +
                        '</div>' +
                        '<textarea class="in" rows="3" name="' + n + '[body]" data-preview-source="' + esc(s.id) + '" aria-label="Message ' + (i + 1) + ' text">' + esc(s.body) + '</textarea>' +
                        '<div class="opts">' +
                        '<div class="fl"><label>Sent</label>' +
                        '<select class="in" style="height:42px" name="' + n + '[advance_on]" data-advance>' +
                        '<option value="reply"' + (s.advance_on === 'reply' ? ' selected' : '') + '>When the prospect replies</option>' +
                        '<option value="time"' + (s.advance_on === 'time' ? ' selected' : '') + '>After a wait, if they have not replied</option>' +
                        '</select></div>' +
                        '<div class="fl" style="flex:0 1 140px"' + (s.advance_on === 'time' ? '' : ' hidden') + ' data-delay><label>Wait (hours)</label>' +
                        '<input type="number" min="1" max="720" class="in" style="height:42px" name="' + n + '[delay_hours]" value="' + esc(s.delay_hours || 24) + '"></div>' +
                        '</div>' +
                        '<div class="prev" data-preview-target="' + esc(s.id) + '" aria-live="polite" aria-label="What a prospect will see">&nbsp;</div>' +
                        '</div></div>'
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
                var card = e.target.closest('[data-step-card]');
                if (!card) { return; }
                if (e.target.matches('[data-advance]')) {
                    card.querySelector('[data-delay]').hidden = e.target.value !== 'time';
                }
                if (e.target.matches('input.swi')) { card.classList.toggle('off', !e.target.checked); }
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
                    var ids = q.campaign_ids || [];
                    var camp = data.campaigns.map(function (c) {
                        var on = ids.indexOf(c.id) !== -1;
                        return '<label style="display:inline-flex;align-items:center;gap:6px;margin:0 12px 4px 0;font-size:13.5px;font-weight:400"><input type="checkbox" name="' + n + '[campaign_ids][]" value="' + c.id + '"' + (on ? ' checked' : '') + '> ' + esc(c.name) + '</label>';
                    }).join('');
                    var scope = ids.length ? ids.length + (ids.length === 1 ? ' campaign' : ' campaigns') : 'All campaigns';
                    var open = (!q.question && !q.answer) || q._open;
                    faqEl.appendChild(el(
                        '<details class="qa" data-faq-card="' + esc(q.id) + '"' + (open ? ' open' : '') + '>' +
                        '<summary><span class="q" aria-hidden="true">?</span>' +
                        '<span class="t"><b>' + esc(q.question || 'New question') + '</b><span>' + esc(q.answer || 'Write the approved answer') + '</span></span>' +
                        '<span class="pl n">' + esc(scope) + '</span>' +
                        '<svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M7 10l5 5 5-5"/></svg></summary>' +
                        '<div class="qb">' +
                        '<input type="hidden" name="' + n + '[id]" value="' + esc(q.id) + '">' +
                        '<input type="hidden" name="' + n + '[intent]" value="' + esc(q.intent || '') + '">' +
                        '<div class="fl"><label>Question</label>' +
                        '<input type="text" class="in" style="font-weight:600" name="' + n + '[question]" value="' + esc(q.question) + '"></div>' +
                        '<div class="fl"><label>Answer</label>' +
                        '<textarea class="in" rows="2" name="' + n + '[answer]">' + esc(q.answer) + '</textarea></div>' +
                        '<div class="row3">' +
                        '<div class="fl" style="flex:2 1 260px"><label>Also matches <span>(comma separated, optional)</span></label>' +
                        '<input type="text" class="in" name="' + n + '[keywords]" value="' + esc(q.keywords) + '" placeholder="price, cost, how much"></div>' +
                        '<div class="fl" style="flex:1 1 160px"><label>Niche <span>(optional)</span></label>' +
                        '<input type="text" class="in" name="' + n + '[niche]" value="' + esc(q.niche || '') + '" placeholder="e.g. dog training"></div>' +
                        '</div>' +
                        (data.campaigns.length ? '<div class="fl"><label>Use in <span>(none ticked = all campaigns)</span></label><div>' + camp + '</div></div>' : '') +
                        '<div class="acts2">' +
                        iconBtn('up', 'Move answer up', i === 0) + iconBtn('down', 'Move answer down', i === faq.length - 1) +
                        iconBtn('dup', 'Duplicate answer') + iconBtn('del', 'Delete answer') +
                        '</div></div></details>'
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
                        campaign_ids: Array.prototype.map.call(card.querySelectorAll('input[type="checkbox"]:checked'), function (c) { return parseInt(c.value, 10); }),
                        _open: card.open
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
                faq.push({id: uid('q'), question: '', answer: '', keywords: '', intent: '', niche: '', campaign_ids: [], _open: true});
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

            // ---- unsaved changes ------------------------------------------------------------------
            var bar = document.getElementById('savebar'), barText = document.getElementById('savebar-text');
            var dirty = false, submitting = false;
            function setDirty(v) {
                dirty = v;
                bar.classList.toggle('dirty', v);
                barText.textContent = v ? 'Unsaved changes' : 'All changes saved';
            }
            form.addEventListener('input', function (e) { if (e.target.id !== 'sequence_on') { setDirty(true); } });
            form.addEventListener('change', function () { setDirty(true); });
            form.addEventListener('click', function (e) { if (e.target.closest('[data-act],[data-role="add-step"],[data-role="add-faq"],[data-role="load-starter"],[data-merge-insert],[data-role="use-booking-type"]')) { setDirty(true); } });
            form.addEventListener('submit', function () { submitting = true; });
            window.addEventListener('beforeunload', function (e) { if (dirty && !submitting) { e.preventDefault(); e.returnValue = ''; } });

            // sequence on/off switch drives the stored "paused" flag
            var seqOn = document.getElementById('sequence_on'), seqPaused = document.getElementById('sequence_paused'), seqState = document.getElementById('seq-state');
            if (seqOn) {
                seqOn.addEventListener('change', function () {
                    seqPaused.value = seqOn.checked ? '0' : '1';
                    seqState.textContent = seqOn.checked ? 'Sequence on' : 'Sequence paused';
                    setDirty(true);
                });
            }

            // section navigation highlight
            var links = Array.prototype.slice.call(document.querySelectorAll('[data-spy]'));
            if ('IntersectionObserver' in window) {
                var io = new IntersectionObserver(function (entries) {
                    entries.forEach(function (en) {
                        if (en.isIntersecting) {
                            links.forEach(function (l) { l.classList.toggle('on', l.getAttribute('href') === '#' + en.target.id); });
                        }
                    });
                }, {rootMargin: '-20% 0px -65% 0px'});
                links.forEach(function (l) { var t = document.querySelector(l.getAttribute('href')); if (t) { io.observe(t); } });
            }

            renderSteps();
            renderFaq();
            // Analytics → "Edit this message": open the editor at that step (live script only; history is never edited).
            try {
                var wanted = new URLSearchParams(window.location.search).get('step');
                var target = wanted ? stepsEl.querySelector('[data-step-card="' + (window.CSS && CSS.escape ? CSS.escape(wanted) : wanted) + '"]') : null;
                if (target) { target.scrollIntoView({block: 'center'}); var ta = target.querySelector('textarea'); if (ta) { ta.focus(); } }
            } catch (e) {}
        })();
    </script>
@endsection
