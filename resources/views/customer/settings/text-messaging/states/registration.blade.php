@extends('layouts/contentLayoutMaster')

@section('title', 'Text messaging')

@php
    $status = $registration->status?->value ?? 'not_started';
    $useCase = old('use_case', $registration->use_case ?? null);
    $confirmedConsent = old('consent_confirmations', $registration->consent_confirmations ?? []);
    $confirmedConsent = is_array($confirmedConsent) ? $confirmedConsent : [];
    $progress = \App\Library\Messaging\Registration\RegistrationProgress::for($registration);
    // Once a brand or campaign exists at the carrier, the customer is tracking
    // real progress: no "back to number choices", no "nothing was charged".
    $progressActive = $formNumberType === \App\Enums\Messaging\PhoneNumberType::Local && $progress['committed'];
@endphp

@section('page-style')
    @include('customer.settings._redesign-assets')
@endsection

@section('content')
<div class="sr-page sr-page--wide">
    @include('customer.settings._sr-header', [
        'backUrl' => route('customer.workspaces.businesses.settings.show', [$workspaceUid, $businessUid]),
        'title' => 'Text messaging',
        'description' => $progressActive ? 'Track your messaging registration with the carrier.' : 'One more step before this number is ready to send and receive texts.',
    ])

    @if($phoneNumber === null && $formNumberType === \App\Enums\Messaging\PhoneNumberType::Local && ! $progressActive)
        {{-- The way out is at the TOP, not buried under a long form. Stepping back only
             pauses local setup: every saved detail stays, and nothing is deleted. A
             submitted/approved registration is refused with an explanation instead. --}}
        <div class="d-flex flex-wrap align-items-center gap-2 mb-2" data-role="local-setup-exit">
            <form method="post" action="{{ route('customer.workspaces.businesses.text-messaging.registration.leave-local-setup', [$workspaceUid, $businessUid]) }}" class="m-0">
                @csrf
                <x-button type="submit" variant="secondary" data-role="back-to-number-choices">← Back to number choices</x-button>
            </form>
            <form method="post" action="{{ route('customer.workspaces.businesses.text-messaging.registration.leave-local-setup', [$workspaceUid, $businessUid]) }}" class="m-0">
                @csrf
                <input type="hidden" name="then" value="toll_free">
                <x-button type="submit" variant="primary" data-role="choose-toll-free-instead">Choose toll-free instead (recommended)</x-button>
            </form>
            <span class="text-caption text-muted">Your business details stay saved. Nothing has been charged or sent to the carrier yet.</span>
        </div>
    @endif

    @if($progressActive)
        @include('customer.settings.text-messaging._registration-progress', ['progress' => $progress])
    @endif

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
                        @if($progressActive)
                            <x-badge variant="info" data-role="registration-in-progress">In progress</x-badge>
                        @else
                            <x-badge variant="neutral">Not started</x-badge>
                        @endif
                @endswitch
            </dd>
        </dl>

        @switch($status)
            @case('pending')
                @unless($progressActive)<p class="text-caption text-muted mt-2 mb-0">We've submitted your business details for messaging registration. This is typically reviewed within a few business days — nothing else to do right now.</p>@endunless
                @break
            @case('rejected')
                @unless($progressActive)
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
                @endunless
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
                @unless($progressActive)
                <p class="text-caption text-muted mt-2 mb-0">
                    Carriers require a few details about this Business before texts can be delivered reliably. This is a one-time step. {{ $verificationChargeDisclosure }}
                    @if($formNumberType === \App\Enums\Messaging\PhoneNumberType::Local)
                        {{ \App\Library\Messaging\BusinessMessagingRegistrationService::LOCAL_COST_NOTICE }}
                    @endif
                    @if($phoneNumber === null)
                        A charge for your number begins once you choose it, after your verification is approved.
                    @else
                        You have already been charged for this number.
                    @endif
                </p>
                @endunless
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
    @elseif($status !== 'pending' && $progress['show_form'])
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

                @if($registration->brand_status === 'needs_attention' && trim((string) $registration->provider_brand_id) !== '')
                    <x-alert variant="warning" icon="alert-circle" class="mb-2" data-role="brand-replacement">
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" id="authorize_brand_replacement" name="authorize_brand_replacement" value="1">
                            <label class="form-check-label" for="authorize_brand_replacement">The carrier rejected this business. If I change the business details above, I understand this replaces the rejected carrier brand and a new carrier brand fee applies.</label>
                        </div>
                    </x-alert>
                @endif

                @if($formNumberType === \App\Enums\Messaging\PhoneNumberType::Local)
                    <div class="mb-1" data-role="support-contact">
                        <label class="form-label required" for="support_contact">Text-message support contact</label>
                        <input type="text" class="form-control @error('support_contact') is-invalid @enderror" id="support_contact" name="support_contact"
                               value="{{ old('support_contact', $registration->support_contact) }}" maxlength="191" placeholder="support@yourbusiness.com or (555) 555-0100">
                        <div class="text-caption text-muted">Your Business's own customer-facing email or phone. It appears in the HELP reply your customers get, so it must be one you publish and answer. We never fill this in for you.</div>
                        <input type="hidden" id="support_contact_confirmed_for" name="support_contact_confirmed_for" value="{{ $registration->support_contact_confirmed_at !== null ? $registration->support_contact : '' }}">
                        <div class="form-check mt-1">
                            <input type="checkbox" class="form-check-input" id="support_contact_confirmed" name="support_contact_confirmed" value="1"
                                   {{ old('support_contact_confirmed', $registration->support_contact_confirmed_at !== null) ? 'checked' : '' }}>
                            <label class="form-check-label" for="support_contact_confirmed">This contact belongs to my Business, is public to my customers, and is monitored.</label>
                        </div>
                        @error('support_contact')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                    </div>
                @endif

                <div class="row" data-role="carrier-contact-fields">
                    <div class="col-md-6 mb-1">
                        <label class="form-label required" for="contact_first_name">Contact first name</label>
                        <input type="text" class="form-control @error('contact_first_name') is-invalid @enderror" id="contact_first_name" name="contact_first_name"
                               value="{{ old('contact_first_name', $registration->contact_first_name) }}" maxlength="100" required>
                        @error('contact_first_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-6 mb-1">
                        <label class="form-label required" for="contact_last_name">Contact last name</label>
                        <input type="text" class="form-control @error('contact_last_name') is-invalid @enderror" id="contact_last_name" name="contact_last_name"
                               value="{{ old('contact_last_name', $registration->contact_last_name) }}" maxlength="100" required>
                        @error('contact_last_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>

                @if($formNumberType === \App\Enums\Messaging\PhoneNumberType::TollFree)
                    <div class="row" data-role="toll-free-carrier-fields">
                        <div class="col-md-5 mb-1">
                            <label class="form-label required" for="message_volume">Expected messages per month</label>
                            <select class="form-select @error('message_volume') is-invalid @enderror" id="message_volume" name="message_volume" required>
                                <option value="">Choose…</option>
                                @foreach($messageVolumes as $value => $label)
                                    <option value="{{ $value }}" @selected(old('message_volume', $registration->message_volume) === (string) $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                            @error('message_volume')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-7 mb-1">
                            <label class="form-label required" for="opt_in_image_url">Link to a screenshot of your opt-in form</label>
                            <input type="url" class="form-control @error('opt_in_image_url') is-invalid @enderror" id="opt_in_image_url" name="opt_in_image_url"
                                   value="{{ old('opt_in_image_url', $registration->opt_in_image_url) }}" maxlength="2083" placeholder="https://… (an image of where customers agree to texts)" required>
                            <p class="text-caption text-muted mb-0">The carrier requires an image showing how customers opt in.</p>
                            @error('opt_in_image_url')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    </div>
                @else
                    <div class="mb-1" data-role="local-carrier-fields">
                        <label class="form-label required" for="brand_vertical">Industry category</label>
                        <select class="form-select @error('brand_vertical') is-invalid @enderror" id="brand_vertical" name="brand_vertical" required>
                            <option value="">Choose…</option>
                            @foreach($brandVerticals as $value => $label)
                                <option value="{{ $value }}" @selected(old('brand_vertical', $prefill['values']['brand_vertical'] ?? '') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('brand_vertical')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                @endif

                <div class="mb-1" data-role="use-case-choices">
                    <label class="form-label required">What will you use texting for?</label>
                    @foreach($useCaseChoices as $choice => $definition)
                        @continue($definition['use_case'] === 'mixed' && ! config('messaging.ten_dlc_mixed_enabled') && $useCase !== 'mixed')
                        @continue($definition['use_case'] === 'low_volume_mixed' && $formNumberType === \App\Enums\Messaging\PhoneNumberType::TollFree)
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
                    <div class="sr-callout sr-callout--warn mt-1" data-low-volume data-role="low-volume-explainer" @if($useCase !== 'low_volume_mixed') hidden @endif>
                        <x-ds-icon name="info" size="16" />
                        <div>
                            <strong class="d-block">Low Volume Mixed — for small businesses</strong>
                            <p class="mb-1">A separate, lower-cost carrier campaign type for a business that sends only a small number of texts. Carriers limit how fast and how much it can send, and it is not for growing or high-volume senders. Fees are shown below before you submit; number rental and text usage are separate.</p>
                            <p class="mb-1"><strong>Low Volume Mixed is only for a business that really sends BOTH kinds of text.</strong> Tick both and give a sample message and your opt-in details for each. If you only send one kind, choose that message type above instead — we never relabel single-purpose traffic to lower the fee.</p>
                            @foreach($lowVolumePurposes as $key => $purpose)
                                <div class="form-check">
                                    <input type="checkbox" class="form-check-input" id="purpose_{{ $key }}" name="message_purposes[]" value="{{ $key }}"
                                           {{ in_array($key, (array) old('message_purposes', $registration->message_purposes ?? []), true) ? 'checked' : '' }}>
                                    <label class="form-check-label" for="purpose_{{ $key }}">{{ $purpose['label'] }}</label>
                                </div>
                            @endforeach
                            @error('message_purposes')<div class="text-danger text-caption">{{ $message }}</div>@enderror
                        </div>
                    </div>
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
                    <div data-low-volume data-role="low-volume-consent" @if($useCase !== 'low_volume_mixed') hidden @endif>
                        @foreach($lowVolumeConsentItems as $key => $label)
                            <div class="form-check" @if(str_starts_with($key, 'purpose_')) data-purpose="{{ str_replace(['purpose_', '_consent'], '', $key) }}" @endif>
                                <input type="checkbox" class="form-check-input" id="consent_{{ $key }}" name="consent_confirmations[]" value="{{ $key }}"
                                       {{ in_array($key, $confirmedConsent, true) ? 'checked' : '' }}>
                                <label class="form-check-label" for="consent_{{ $key }}">{{ $label }}
                                    @unless(in_array($key, $confirmedConsent, true))<span class="text-warning">— needs your confirmation</span>@endunless</label>
                            </div>
                        @endforeach
                    </div>
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
                    <p class="text-caption text-muted mb-2">Everything required is filled in. Nothing is sent until you press the button.</p>
                @endif
                @php($isLocalRegistration = $formNumberType === \App\Enums\Messaging\PhoneNumberType::Local)
                @if($isLocalRegistration)
                    @foreach(['standard' => 'First three months of 10DLC campaign fees', 'low_volume_mixed' => 'First three months of Low Volume Mixed campaign fees'] as $pricingKey => $oneTimeLabel)
                        <div data-pricing-for="{{ $pricingKey }}" @if(($useCase === 'low_volume_mixed' ? 'low_volume_mixed' : 'standard') !== $pricingKey) hidden @endif>
                            @include('customer.settings.text-messaging._charge-summary', ['preview' => $registrationPreviews[$pricingKey], 'oneTimeLabel' => $oneTimeLabel, 'recurringLabel' => $pricingKey === 'low_volume_mixed' ? 'Low Volume Mixed campaign, billed monthly after those first three months' : '10DLC campaign, billed monthly after those first three months', 'unconfiguredText' => 'Registration pricing for this campaign type isn\'t set up on our side yet, so it can\'t be submitted right now. Nothing will be charged.'])
                        </div>
                    @endforeach
                @else
                    <p class="text-caption text-muted mb-2" data-role="toll-free-verification-cost">{{ \App\Library\Messaging\BusinessMessagingRegistrationService::TOLL_FREE_DISCLOSURE }}</p>
                @endif
                <form method="post" action="{{ route('customer.workspaces.businesses.text-messaging.registration.submit', [$workspaceUid, $businessUid]) }}">
                    @csrf
                    @if($isLocalRegistration && $progress['campaign_ready'])
                        <div class="form-check mb-1" data-role="campaign-authorization">
                            <input type="checkbox" class="form-check-input" id="authorize_campaign" name="authorize_campaign" value="1">
                            <label class="form-check-label" for="authorize_campaign">I authorize charging the first three months of campaign fees ({{ $registrationPreview['campaign_prepay']['text'] ?? '' }}) when this campaign is submitted. Possible carrier review fees are billed separately, as described above.</label>
                        </div>
                        <x-button type="submit" variant="primary" data-role="submit-campaign" :disabled="! $available || ! $registrationPreview['configured'] || ! $registrationPreview['sufficient']">Submit campaign for carrier review</x-button>
                    @elseif($isLocalRegistration)
                        <x-button type="submit" variant="primary" data-role="submit-business" :disabled="! $available || ! $registrationPreview['configured'] || ! $registrationPreview['sufficient']">Submit business for verification</x-button>
                    @else
                        <x-button type="submit" variant="primary" :disabled="! $available">Submit for review</x-button>
                    @endif
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
            // The confirmation belongs to the exact contact it was given for:
            // editing the contact clears it, and ticking records the value.
            $('#support_contact').on('input', function () {
                $('#support_contact_confirmed').prop('checked', false);
                $('#support_contact_confirmed_for').val('');
            });
            $('#support_contact_confirmed').on('change', function () {
                $('#support_contact_confirmed_for').val(this.checked ? $.trim($('#support_contact').val()) : '');
            });
            function syncLowVolume() {
                var on = $('input[name="use_case"]:checked').val() === 'low_volume_mixed';
                $('[data-low-volume]').prop('hidden', !on);
                $('[data-pricing-for]').each(function () {
                    $(this).prop('hidden', $(this).data('pricing-for') !== (on ? 'low_volume_mixed' : 'standard'));
                });
                $('[data-low-volume] [data-purpose]').each(function () {
                    $(this).prop('hidden', !$('#purpose_' + $(this).data('purpose')).is(':checked'));
                });
            }
            $('input[name="use_case"], input[name="message_purposes[]"]').on('change', syncLowVolume);
            syncLowVolume();
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
