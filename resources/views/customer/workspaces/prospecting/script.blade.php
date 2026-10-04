@extends('layouts/contentLayoutMaster')

@section('title', 'Outreach script and settings')

@section('content')
    <div class="row mb-2">
        <div class="col-12">
            <h4 class="mb-0">Outreach</h4>
            <p class="text-caption mb-0">The texts your prospects receive. Write them the way you would say them; Outreach follows this order: Message 1, then 2, then 3.</p>
        </div>
    </div>

    @include('customer.workspaces.prospecting._nav', ['prospectingActive' => 'script'])

    @foreach((array) session('outreach_warnings', []) as $warning)
        <x-alert variant="warning" data-role="script-warning">{{ $warning }}</x-alert>
    @endforeach

    @php
        $messages = [
            'message_1' => ['Message 1', 'Your first message after a prospect replies. Introduce yourself and what you offer.'],
            'message_2' => ['Message 2', 'Ask for a short call.'],
            'message_3' => ['Message 3', 'Send your calendar link so they can book. This message must include the calendar link.'],
        ];
        $answers = [
            'pricing_answer' => 'Pricing / cost',
            'location_answer' => 'Where are you based?',
            'found_you_answer' => 'How did you find me?',
            'what_we_do_answer' => 'What do you do?',
            'website_answer' => 'Website',
            'clarify_answer' => 'Specific questions (dates, availability, details)',
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

        @foreach($messages as $field => [$label, $help])
            <x-card :title="$label" :padded="true" class="mb-2" data-script-card="{{ $field }}">
                <p class="text-caption">{{ $help }}</p>
                <textarea name="{{ $field }}" id="{{ $field }}" class="form-control @error($field) is-invalid @enderror" rows="3" data-preview-source="{{ $field }}">{{ old($field, $values[$field]) }}</textarea>
                @error($field)<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                <div class="mt-1">
                    <x-merge-field-picker :picker="$picker" :target="'#' . $field" label="Insert a detail" />
                </div>
                <p class="text-caption mt-1 mb-25">What a prospect will see:</p>
                <div class="border rounded p-1" data-preview-target="{{ $field }}" aria-live="polite">&nbsp;</div>
                <p class="small text-warning mt-50 mb-0" data-preview-warning="{{ $field }}" hidden role="status"></p>
            </x-card>
        @endforeach

        <x-card title="Common answers" :padded="true" class="mb-2">
            <p class="text-caption">If a prospect asks one of these, Outreach answers with your words first, then continues with the next message.</p>
            @foreach($answers as $field => $label)
                <div class="mb-1" data-script-card="{{ $field }}">
                    <label class="form-label" for="{{ $field }}">{{ $label }}</label>
                    <textarea name="{{ $field }}" id="{{ $field }}" class="form-control @error($field) is-invalid @enderror" rows="2" data-preview-source="{{ $field }}">{{ old($field, $values[$field]) }}</textarea>
                    @error($field)<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                    <div class="text-caption" data-preview-target="{{ $field }}" aria-live="polite"></div>
                </div>
            @endforeach
        </x-card>

        <x-card title="Follow-up" :padded="true" class="mb-2">
            <div class="form-check mb-1">
                <input type="hidden" name="followup_enabled" value="0">
                <input class="form-check-input" type="checkbox" id="followup_enabled" name="followup_enabled" value="1" @checked(old('followup_enabled', $followupEnabled))>
                <label class="form-check-label" for="followup_enabled">Send one follow-up if they have not replied</label>
            </div>
            <div class="mb-1">
                <label class="form-label" for="follow_up_delay_hours">Wait this many hours</label>
                <input type="number" id="follow_up_delay_hours" name="follow_up_delay_hours" class="form-control @error('follow_up_delay_hours') is-invalid @enderror" min="1" max="168" value="{{ old('follow_up_delay_hours', $followupDelay) }}" style="max-width: 160px;">
                @error('follow_up_delay_hours')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="mb-1" data-script-card="followup_message">
                <label class="form-label" for="followup_message">Follow-up message</label>
                <textarea name="followup_message" id="followup_message" class="form-control" rows="2" data-preview-source="followup_message">{{ old('followup_message', $values['followup_message']) }}</textarea>
                <div class="text-caption" data-preview-target="followup_message" aria-live="polite"></div>
            </div>
        </x-card>

        <x-card title="Booking" :padded="true" class="mb-2">
            <div class="mb-1">
                <label class="form-label" for="booking_url">Calendar link</label>
                <input type="text" id="booking_url" name="booking_url" class="form-control @error('booking_url') is-invalid @enderror" value="{{ old('booking_url', $bookingUrl) }}" placeholder="https://">
                @error('booking_url')<div class="invalid-feedback">{{ $message }}</div>@enderror
                <p class="text-caption mb-0">Any booking page you already use. Prospects get this link in Message 3.</p>
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
                <label class="form-check-label" for="ai_enabled">Let AI answer a question your common answers do not cover</label>
            </div>
            <p class="text-caption mb-0">AI only writes one short sentence using the facts on this page, then your next message follows. Prospects who reply STOP are never texted again, whatever you choose here.</p>
        </x-card>

        <x-button type="submit" variant="primary">Save script and settings</x-button>
        <a class="ms-2 text-caption" href="{{ route('customer.workspaces.prospecting.settings.show', $workspaceUid) }}">Older agent setup</a>
    </form>
@endsection

@section('page-script')
    <script src="{{ asset('js/merge-fields/insert-field.js') }}"></script>
    <script>
        (function () {
            var form = document.querySelector('[data-role="script-form"]');
            if (!form) { return; }
            var url = form.getAttribute('data-preview-url');
            var token = form.querySelector('input[name="_token"]').value;
            var timers = {};

            function refresh(source) {
                var field = source.getAttribute('data-preview-source');
                var target = form.querySelector('[data-preview-target="' + field + '"]');
                var warning = form.querySelector('[data-preview-warning="' + field + '"]');
                if (!target) { return; }

                fetch(url, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': token},
                    body: JSON.stringify({text: source.value})
                }).then(function (r) { return r.ok ? r.json() : null; }).then(function (data) {
                    if (!data) { return; }
                    target.textContent = data.preview || '';
                    if (warning) {
                        var notes = [];
                        if (field === 'message_3' && !data.has_calendar_link) { notes.push('This message does not include your calendar link.'); }
                        if (data.unknown && data.unknown.length) { notes.push('Not recognised, will be left blank: ' + data.unknown.join(', ')); }
                        warning.textContent = notes.join(' ');
                        warning.hidden = notes.length === 0;
                    }
                }).catch(function () {});
            }

            form.querySelectorAll('[data-preview-source]').forEach(function (el) {
                var queue = function () {
                    clearTimeout(timers[el.id]);
                    timers[el.id] = setTimeout(function () { refresh(el); }, 350);
                };
                el.addEventListener('input', queue);
                refresh(el);
            });

            // Inserting a detail from the picker changes the value without an input event.
            form.addEventListener('click', function (event) {
                if (event.target.closest('[data-merge-insert]')) {
                    setTimeout(function () {
                        form.querySelectorAll('[data-preview-source]').forEach(function (el) { refresh(el); });
                    }, 0);
                }
                if (event.target.closest('[data-role="use-booking-type"]')) {
                    var choice = document.getElementById('booking_type_choice');
                    if (choice) { document.getElementById('booking_url').value = choice.value; }
                }
            });
        })();
    </script>
@endsection
