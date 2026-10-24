@extends('layouts/contentLayoutMaster')

@section('title', 'Text messaging')

@php
    $status = $registration->status?->value ?? 'not_started';
    $useCase = old('use_case', $registration->use_case ?? null);
    $confirmedConsent = old('consent_confirmations', $registration->consent_confirmations ?? []);
    $confirmedConsent = is_array($confirmedConsent) ? $confirmedConsent : [];
@endphp

@section('page-style')
    @include('customer.settings._redesign-assets')
@endsection

@section('content')
<div class="sr-page sr-page--wide">
    @include('customer.settings._sr-header', [
        'backUrl' => route('customer.workspaces.businesses.settings.show', [$workspaceUid, $businessUid]),
        'title' => 'Text messaging',
        'description' => 'One more step before this number is ready to send and receive texts.',
    ])

    <x-card :padded="true" class="mb-2">
        <dl class="row mb-0">
            <dt class="col-sm-4">Phone number</dt>
            <dd class="col-sm-8" data-role="phone-number">
                {{-- Review correction — null here is the verify-first local
                     sequence's own "no number yet" state: Telnyx genuinely
                     supports completing business verification before a
                     number is purchased, so this is not an error. --}}
                {{ $phoneNumber ?? 'Not yet chosen — you\'ll pick this once your verification is approved.' }}
            </dd>

            <dt class="col-sm-4">Messaging registration</dt>
            <dd class="col-sm-8">
                @switch($status)
                    @case('pending')
                        <x-badge variant="warning">Pending review</x-badge>
                        @break
                    @case('rejected')
                        <x-badge variant="danger">Needs attention</x-badge>
                        @break
                    @case('approved')
                        <x-badge variant="success">Approved</x-badge>
                        @break
                    @default
                        <x-badge variant="neutral">Not started</x-badge>
                @endswitch
            </dd>
        </dl>

        @switch($status)
            @case('pending')
                <p class="text-caption text-muted mt-2 mb-0">We've submitted your business details for messaging registration. This is typically reviewed within a few business days — nothing else to do right now.</p>
                @break
            @case('rejected')
                <x-alert variant="danger" icon="alert-circle" class="mt-2 mb-0" data-role="rejection-reason">
                    <strong class="d-block">This needs a correction</strong>
                    {{-- Review correction — a specific, carrier-supplied
                         reason when the provider's own response actually
                         carried one; otherwise an EXPLICIT statement that
                         none was supplied, never a message that reads as
                         if a real reason were being paraphrased. --}}
                    @if($registration->rejection_reason)
                        {{ $registration->rejection_reason }}
                    @else
                        The carrier did not supply a detailed reason for this rejection. Please review the details below and try again.
                    @endif
                </x-alert>
                @break
            @case('approved')
                {{--
                    Review correction — an Approved registration with a
                    genuine number can still be waiting here, never Ready:
                    a local number's own carrier-side campaign assignment
                    is Requested (Telnyx's own assignment endpoint returns
                    a background task, never an immediate confirmation)
                    until it is actually polled and found complete — never
                    equated with Confirmed anywhere the customer can see.
                --}}
                @if($campaignAssignmentStatus === 'failed')
                    <x-alert variant="danger" icon="alert-circle" class="mt-2 mb-0" data-role="campaign-assignment-status">
                        <strong class="d-block">We couldn't confirm your number is enabled yet</strong>
                        Your number has already been purchased and charged, but we could not confirm the carrier finished enabling it for messaging. Our team has been notified and will follow up — texting will not be available on this number until that is resolved.
                    </x-alert>
                @elseif($campaignAssignmentStatus === 'requested')
                    <x-alert variant="info" icon="clock" class="mt-2 mb-0" data-role="campaign-assignment-status">
                        <strong class="d-block">Almost ready</strong>
                        Your business is verified and your number has been purchased. We're waiting for the carrier to confirm it's enabled for messaging — this is usually quick, and texting will become available on this number as soon as that's confirmed.
                    </x-alert>
                @endif
                @break
            @default
                {{--
                    Review correction — never claim business verification is
                    free: TelnyxProvisioningAdapter reserves real wallet
                    funds for both regimes before ever submitting a
                    registration. $verificationChargeDisclosure states the
                    truth from the actual configured rate — no rate is
                    invented here, and every environment today has none
                    configured, so this currently reads as an honest
                    "no fee is currently configured" statement.
                --}}
                <p class="text-caption text-muted mt-2 mb-0">
                    Carriers require a few details about this Business before texts can be delivered reliably. This is a one-time step. {{ $verificationChargeDisclosure }}
                    @if($phoneNumber === null)
                        A charge for your number begins once you choose it, after your verification is approved.
                    @else
                        You have already been charged for this number.
                    @endif
                </p>
        @endswitch
    </x-card>

    {{-- PR #295 Correction Round 1, item 8 — once a carrier has approved
         this registration, the normal customer edit form is never shown
         again; the record is read-only through this surface. --}}
    @if($status === 'approved')
        <x-card title="Business details" :padded="true">
            <p class="text-caption text-muted mb-2">This registration has been approved and its details can no longer be edited here.</p>
            <dl class="row mb-0">
                <dt class="col-sm-4">Legal business name</dt>
                <dd class="col-sm-8">{{ $registration->legal_business_name }}</dd>
                <dt class="col-sm-4">Website</dt>
                <dd class="col-sm-8">{{ $registration->website_url }}</dd>
                <dt class="col-sm-4">Contact email</dt>
                <dd class="col-sm-8">{{ $registration->contact_email }}</dd>
            </dl>
        </x-card>
    @elseif($status !== 'pending')
        <x-card title="Business details" :padded="true">
            <form method="post" action="{{ route('customer.workspaces.businesses.text-messaging.registration.update', [$workspaceUid, $businessUid]) }}">
                @csrf

                <div class="mb-1">
                    <label class="form-label required" for="legal_business_name">Legal business name</label>
                    <input type="text" class="form-control @error('legal_business_name') is-invalid @enderror" id="legal_business_name" name="legal_business_name"
                           value="{{ old('legal_business_name', $prefill['values']['legal_business_name']) }}" maxlength="191" required>
                    @error('legal_business_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    @if(in_array('legal_business_name', $prefill['prefilled'], true))
                        <div class="text-caption text-warning" data-role="legal-name-note">Prefilled from your public business name — not verified. Replace it with the exact legal name on your registration or tax records.</div>
                    @endif
                </div>

                <div class="mb-1">
                    <label class="form-label required">Business type</label>
                    <div class="form-check">
                        <input type="radio" class="form-check-input" id="entity_ein" name="entity_type" value="ein"
                               {{ old('entity_type', $registration->entity_type?->value ?? 'ein') === 'ein' ? 'checked' : '' }}>
                        <label class="form-check-label" for="entity_ein">Registered business (has an EIN)</label>
                    </div>
                    <div class="form-check">
                        <input type="radio" class="form-check-input" id="entity_sole" name="entity_type" value="sole_proprietor"
                               {{ old('entity_type', $registration->entity_type?->value ?? '') === 'sole_proprietor' ? 'checked' : '' }}>
                        <label class="form-check-label" for="entity_sole">Individual / sole proprietor (no EIN)</label>
                    </div>
                </div>

                <div class="mb-1" id="ein-field">
                    <label class="form-label" for="ein">EIN</label>
                    <input type="text" class="form-control @error('ein') is-invalid @enderror" id="ein" name="ein"
                           value="{{ old('ein', $registration->ein ?? '') }}" maxlength="20">
                    @error('ein')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="mb-1">
                    <label class="form-label required" for="address_line_1">Business address</label>
                    <input type="text" class="form-control mb-1 @error('address_line_1') is-invalid @enderror" id="address_line_1" name="address_line_1"
                           value="{{ old('address_line_1', $prefill['values']['address_line_1']) }}" maxlength="191" placeholder="Street address" required>
                    @error('address_line_1')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    <input type="text" class="form-control" name="address_line_2"
                           value="{{ old('address_line_2', $prefill['values']['address_line_2']) }}" maxlength="191" placeholder="Apt, suite, etc. (optional)">
                </div>

                <div class="row">
                    <div class="col-md-5 mb-1">
                        <label class="form-label required" for="city">City</label>
                        <input type="text" class="form-control @error('city') is-invalid @enderror" id="city" name="city"
                               value="{{ old('city', $prefill['values']['city']) }}" maxlength="120" required>
                        @error('city')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-4 mb-1">
                        <label class="form-label required" for="region">State</label>
                        <input type="text" class="form-control @error('region') is-invalid @enderror" id="region" name="region"
                               value="{{ old('region', $prefill['values']['region']) }}" maxlength="120" required>
                        @error('region')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-3 mb-1">
                        <label class="form-label required" for="postal_code">ZIP code</label>
                        <input type="text" class="form-control @error('postal_code') is-invalid @enderror" id="postal_code" name="postal_code"
                               value="{{ old('postal_code', $prefill['values']['postal_code']) }}" maxlength="20" required>
                        @error('postal_code')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>

                <div class="mb-1">
                    <label class="form-label required" for="website_url">Website</label>
                    <input type="url" class="form-control @error('website_url') is-invalid @enderror" id="website_url" name="website_url"
                           value="{{ old('website_url', $prefill['values']['website_url']) }}" maxlength="255" placeholder="https://" required>
                    @error('website_url')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="row">
                    <div class="col-md-6 mb-1">
                        <label class="form-label required" for="contact_email">Contact email</label>
                        <input type="email" class="form-control @error('contact_email') is-invalid @enderror" id="contact_email" name="contact_email"
                               value="{{ old('contact_email', $prefill['values']['contact_email']) }}" maxlength="191" required>
                        @error('contact_email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-6 mb-1">
                        <label class="form-label required" for="contact_phone">Contact phone</label>
                        <input type="text" class="form-control @error('contact_phone') is-invalid @enderror" id="contact_phone" name="contact_phone"
                               value="{{ old('contact_phone', $prefill['values']['contact_phone']) }}" maxlength="32" required>
                        @error('contact_phone')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>

                <div class="mb-1" data-role="use-case-choices">
                    <label class="form-label required">What will you use texting for?</label>
                    @foreach($useCaseChoices as $choice => $definition)
                        <div class="form-check">
                            <input type="radio" class="form-check-input" id="use_case_{{ $choice }}" name="use_case" value="{{ $definition['use_case'] }}" data-choice="{{ $choice }}"
                                   {{ $useCase === $definition['use_case'] ? 'checked' : '' }} required>
                            <label class="form-check-label" for="use_case_{{ $choice }}">{{ $definition['label'] }}</label>
                        </div>
                    @endforeach
                    @if($useCase && $selectedChoice === null && isset($useCases[$useCase]))
                        <div class="form-check">
                            <input type="radio" class="form-check-input" id="use_case_existing" name="use_case" value="{{ $useCase }}" checked>
                            <label class="form-check-label" for="use_case_existing">{{ $useCases[$useCase] }} (your saved choice)</label>
                        </div>
                    @endif
                    @error('use_case')<div class="text-danger text-caption">{{ $message }}</div>@enderror
                    <p class="text-caption text-muted mt-1 mb-0">Pick what you will actually send. Promotional messages are only registered if you choose them — we never add them for you. The carrier decides whether the campaign is approved.</p>
                    <div class="text-caption mt-1" data-role="suggested-topics" @if(empty($prefill['topics'])) hidden @endif>
                        Suggested message types: <span data-role="topics">{{ implode(', ', $prefill['topics']) }}</span>
                    </div>
                </div>

                <div class="mb-1">
                    <label class="form-label required" for="campaign_description">Campaign description</label>
                    <textarea class="form-control @error('campaign_description') is-invalid @enderror" id="campaign_description" name="campaign_description" rows="3" maxlength="2000" required
                              data-suggestible>{{ old('campaign_description', $prefill['values']['campaign_description']) }}</textarea>
                    @error('campaign_description')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="mb-1">
                    <label class="form-label required" for="opt_in_method">How do customers agree to receive texts? (message flow)</label>
                    <textarea class="form-control @error('opt_in_method') is-invalid @enderror" id="opt_in_method" name="opt_in_method" data-suggestible rows="2" maxlength="2000" required
                              placeholder="e.g. Customers check a box on our signup form agreeing to receive text updates.">{{ old('opt_in_method', $prefill['values']['opt_in_method']) }}</textarea>
                    @error('opt_in_method')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    <div class="text-caption text-muted">Suggested wording only — edit it to match what your website really does. It is not confirmed until you check the consent items below.</div>
                </div>

                <div class="mb-1">
                    <label class="form-label required" for="sample_message_1">Sample text message 1</label>
                    <textarea class="form-control @error('sample_message_1') is-invalid @enderror" id="sample_message_1" name="sample_message_1" data-suggestible rows="2" maxlength="500" required
                              placeholder="Include your business name and how to opt out, e.g. &quot;Reply STOP to unsubscribe.&quot;">{{ old('sample_message_1', $prefill['values']['sample_message_1']) }}</textarea>
                    @error('sample_message_1')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="mb-1">
                    <label class="form-label required" for="sample_message_2">Sample text message 2</label>
                    <textarea class="form-control @error('sample_message_2') is-invalid @enderror" id="sample_message_2" name="sample_message_2" data-suggestible rows="2" maxlength="500" required>{{ old('sample_message_2', $prefill['values']['sample_message_2']) }}</textarea>
                    @error('sample_message_2')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="row">
                    <div class="col-md-6 mb-1">
                        <label class="form-label required" for="privacy_policy_url">Privacy policy URL</label>
                        <input type="url" class="form-control @error('privacy_policy_url') is-invalid @enderror" id="privacy_policy_url" name="privacy_policy_url"
                               value="{{ old('privacy_policy_url', $registration->privacy_policy_url ?? '') }}" maxlength="255" placeholder="https://" required>
                        @error('privacy_policy_url')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-6 mb-1">
                        <label class="form-label required" for="terms_url">Terms of service URL</label>
                        <input type="url" class="form-control @error('terms_url') is-invalid @enderror" id="terms_url" name="terms_url"
                               value="{{ old('terms_url', $registration->terms_url ?? '') }}" maxlength="255" placeholder="https://" required>
                        @error('terms_url')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>

                <div class="mb-1" data-role="consent-checklist">
                    <label class="form-label">Consent checklist</label>
                    <p class="text-caption text-muted mb-1">We have not checked your website. Check each item only once you have confirmed it there; unchecked items must be fixed before you can submit. These are your own confirmations — we don't verify them, and checking them doesn't guarantee carrier approval.</p>
                    @foreach($consentItems as $key => $label)
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" id="consent_{{ $key }}" name="consent_confirmations[]" value="{{ $key }}"
                                   {{ in_array($key, $confirmedConsent, true) ? 'checked' : '' }}>
                            <label class="form-check-label" for="consent_{{ $key }}">{{ $label }}
                                @unless(in_array($key, $confirmedConsent, true))<span class="text-warning">— needs your confirmation</span>@endunless</label>
                        </div>
                    @endforeach
                </div>

                @if($prefill['prefilled'] !== [])
                    <p class="text-caption text-muted" data-role="prefill-note">Some fields were prefilled from your business profile or suggested for your industry. Please review them before saving.</p>
                @endif

                <x-button type="submit" variant="primary">Save details</x-button>
            </form>
        </x-card>

        @if(($registration->legal_business_name ?? null) !== null)
            <x-card :padded="true" class="mt-2">
                @if($submissionIssues !== [])
                    <x-alert variant="warning" icon="alert-circle" class="mb-2" data-role="submission-issues">
                        <strong class="d-block">Before you submit</strong>
                        <ul class="mb-0 ps-2">
                            @foreach($submissionIssues as $issue)<li>{{ $issue }}</li>@endforeach
                        </ul>
                    </x-alert>
                @else
                    <p class="text-caption text-muted mb-2">Everything required is filled in. Submitting is a paid step; nothing is sent until you press the button.</p>
                @endif
                <form method="post" action="{{ route('customer.workspaces.businesses.text-messaging.registration.submit', [$workspaceUid, $businessUid]) }}">
                    @csrf
                    <x-button type="submit" variant="primary" :disabled="! $available">Submit for review</x-button>
                </form>
                @unless($available)
                    <p class="text-caption text-muted mt-1 mb-0">Preview — submission isn't available in this environment yet.</p>
                @endunless
            </x-card>
        @endif
    @endif

    <div class="mt-2">
        @include('customer.settings.text-messaging._port-out-card')
    </div>
@endsection

@section('page-script')
    <script>
        $(document).ready(function () {
            function syncEinField() {
                $('#ein-field').prop('hidden', !$('#entity_ein').is(':checked'));
            }
            $('input[name="entity_type"]').on('change', syncEinField);
            syncEinField();
            var templates = @json($templatesByChoice);
            // Brand the currently displayed suggestions were generated with.
            var lastBrand = @json($templateBrand);
            var lastChoice = $('input[name="use_case"]:checked').data('choice') || null;
            var fieldMap = {campaign_description: 'campaign_description', opt_in_method: 'opt_in_method', sample_message_1: 'sample_message_1', sample_message_2: 'sample_message_2'};
            function render(text, brand) { return String(text).split('{brand}').join(brand); }
            // Swap in the new choice's suggestion only where the field is
            // empty or still exactly the previous suggestion (for the brand it
            // was generated with, or the current one) — edited text is kept.
            // The new text uses the legal name currently typed in the form.
            // Editing the legal name re-brands only suggestions the customer
            // has not touched; edited text is never rewritten.
            $('#legal_business_name').on('input', function () {
                var typed = $.trim($(this).val());
                var current = lastChoice ? templates[lastChoice] : null;
                if (typed === '' || typed === lastBrand || !current) { return; }
                $.each(fieldMap, function (id, key) {
                    var $el = $('#' + id);
                    if ($.trim($el.val()) === render(current[key], lastBrand)) { $el.val(render(current[key], typed)); }
                });
                lastBrand = typed;
            });
            $('input[name="use_case"]').on('change', function () {
                var choice = $(this).data('choice');
                var next = choice ? templates[choice] : null;
                var prev = lastChoice ? templates[lastChoice] : null;
                var typed = $.trim($('#legal_business_name').val());
                var brand = typed !== '' ? typed : lastBrand;
                if (next) {
                    $.each(fieldMap, function (id, key) {
                        var $el = $('#' + id);
                        var cur = $.trim($el.val());
                        var untouched = cur === '' || (prev && (cur === render(prev[key], lastBrand) || cur === render(prev[key], brand)));
                        if (untouched) { $el.val(render(next[key], brand)); }
                    });
                    lastBrand = brand;
                    $('[data-role="topics"]').text(next.topics.join(', '));
                    $('[data-role="suggested-topics"]').prop('hidden', false);
                }
                // Attestations were made for the previous messaging type.
                if ((choice || null) !== lastChoice) { $('input[name="consent_confirmations[]"]').prop('checked', false); }
                lastChoice = choice || null;
            });
        });
    </script>
</div>
@endsection
