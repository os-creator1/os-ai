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
    // Toll-free verification already submitted and waiting on the carrier: the compact review screen.
    $tollFreeReview = $formNumberType === \App\Enums\Messaging\PhoneNumberType::TollFree && $status === 'pending' && filled($phoneNumber);
    $progressChip = match ($progress['campaign']['key']) {
        'approved' => ['Approved', 'success'],
        'under_review' => ['Carrier review', 'warn'],
        'needs_attention' => ['Needs correction', 'danger'],
        'issue' => ['Submission issue', 'warn'],
        'ready' => ['Ready to submit', 'accent'],
        default => $progress['brand']['key'] === 'needs_attention' ? ['Needs attention', 'danger'] : ['Verifying business', 'warn'],
    };
    $progressDescription = match ($progress['campaign']['key']) {
        'under_review' => 'Your texting registration is with the carrier. There\'s nothing you need to do right now.',
        'approved' => 'The carrier approved your registration. Finish setup by choosing a phone number.',
        'needs_attention' => 'The carrier needs a correction before this registration can continue.',
        'issue', 'ready' => 'Your business is verified. Review your campaign details and submit them to the carrier.',
        default => 'Track your messaging registration with the carrier.',
    };
@endphp

@section('page-style')
    @include('customer.settings._phone-messaging-assets')
@endsection

@section('content')
<div class="sr-page sr-page--wide pm-page">
    @include('customer.settings._sr-header', [
        'backUrl' => route('customer.workspaces.businesses.settings.show', [$workspaceUid, $businessUid]),
        'title' => 'Text messaging',
        'description' => $tollFreeReview ? 'Your toll-free number is being verified by the carrier. There\'s nothing you need to do right now.' : ($progressActive ? $progressDescription : 'One more step before this number is ready to send and receive texts.'),
        'chip' => $tollFreeReview ? 'Carrier review' : ($progressActive ? $progressChip[0] : null),
        'chipVariant' => $tollFreeReview ? 'warn' : ($progressActive ? $progressChip[1] : ''),
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

    @if($tollFreeReview)
        @include('customer.settings.text-messaging._tollfree-review', ['registration' => $registration, 'phoneNumber' => $phoneNumber, 'retainedNumbers' => $retainedNumbers, 'portOutRequestsByNumberId' => $portOutRequestsByNumberId])
    @else
    @php
        $statusChip = match (true) {
            $status === 'approved' => ['Approved', 'success'],
            $status === 'pending' => ['Pending review', 'warn'],
            $status === 'rejected' => ['Needs attention', 'danger'],
            $progressActive => ['In progress', 'accent'],
            default => ['Not started', ''],
        };
    @endphp
    <div class="pm-numbercard" data-role="registration-status-card">
        <div class="pm-numbercard__main">
            <div class="pm-numbercard__label">Your number</div>
            {{-- A null number is the verify-first local sequence's own "no number yet" state, not an error. --}}
            <div class="pm-numbercard__number @if($phoneNumber === null) pm-numbercard__number--empty @endif" data-role="phone-number">{{ $phoneNumber ?? 'Not yet chosen — you\'ll pick this once your verification is approved.' }}</div>
        </div>
        <span class="sr-chip sr-chip--{{ $statusChip[1] }}" data-role="registration-status-chip">{{ $statusChip[0] }}</span>
        <p class="pm-numbercard__text">Carriers need a few details about this Business before texts can be delivered reliably. It is a one-time step, and nothing is sent until you submit.</p>
        <div class="pm-numbercard__links">
            <a href="{{ route('customer.workspaces.businesses.usage-billing.show', [$workspaceUid, $businessUid]) }}">Usage &amp; billing</a>
        </div>
    </div>

    @include('customer.settings.text-messaging._registration-tracker', ['phoneNumber' => $phoneNumber, 'status' => $status, 'campaignAssignmentStatus' => $campaignAssignmentStatus ?? null])

    @if($progressActive)
        @include('customer.settings.text-messaging._registration-progress', ['progress' => $progress, 'registration' => $registration, 'phoneNumber' => $phoneNumber, 'campaignAssignmentStatus' => $campaignAssignmentStatus ?? null])
    @endif

    @unless($progressActive)
    <div class="mb-3">
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
    </div>
    @endunless
    @endif

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
        @php
$isTollFreeForm = $formNumberType === \App\Enums\Messaging\PhoneNumberType::TollFree;
$textLimit = $isTollFreeForm ? \App\Library\Messaging\Registration\RegistrationSubmissionValidator::TOLL_FREE_TEXT_LIMIT : 2000;
@endphp
        <div class="pm-layout">
        <form method="post" class="pm-form" id="registration-form" action="{{ route('customer.workspaces.businesses.text-messaging.registration.update', [$workspaceUid, $businessUid]) }}" aria-label="Messaging registration details">
            @csrf

            {{-- 1 · Your business --}}
            <fieldset class="pm-section" id="pm-sec-business" data-pm-section="Your business">
                <legend class="visually-hidden">Your business</legend>
                <div class="pm-section__head" aria-hidden="true">
                    <span class="pm-section__num">1</span>
                    <div><h2 class="pm-section__title">Your business</h2><p class="pm-section__help">Business details exactly as they appear on your registration or tax records.</p></div>
                </div>
                <div class="pm-section__body">
                    <div class="pm-field">
                        <label class="required" for="legal_business_name">Legal business name</label>
                        <input type="text" class="form-control @error('legal_business_name') is-invalid @enderror" id="legal_business_name" name="legal_business_name"
                               value="{{ old('legal_business_name', $prefill['values']['legal_business_name']) }}" maxlength="191" required>
                        @error('legal_business_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        @if(in_array('legal_business_name', $prefill['prefilled'], true))
                            <div class="pm-hint pm-hint--warn" data-role="legal-name-note">Prefilled from your public business name — not verified. Replace it with the exact legal name on your registration or tax records.</div>
                        @endif
                    </div>

                    <div class="pm-field">
                        <label for="doing_business_as">Customer-facing name (DBA) <span class="fw-normal text-muted">(optional)</span></label>
                        <input type="text" class="form-control @error('doing_business_as') is-invalid @enderror" id="doing_business_as" name="doing_business_as"
                               value="{{ old('doing_business_as', $registration->doing_business_as) }}" maxlength="191" aria-describedby="dba-help">
                        <p class="pm-hint" id="dba-help">Only if customers know you by a different name than the legal name above. It is sent to the carrier as your "doing business as" name, so enter it exactly as you operate under it.</p>
                        @error('doing_business_as')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="pm-field" role="radiogroup" aria-labelledby="entity-type-label">
                        <span class="pm-legend required" id="entity-type-label">Business type</span>
                        <div class="pm-choices">
                            <label class="pm-choice" for="entity_ein">
                                <input type="radio" id="entity_ein" name="entity_type" value="ein" {{ old('entity_type', $registration->entity_type?->value ?? 'ein') === 'ein' ? 'checked' : '' }}>
                                <span><span class="pm-choice__title">Registered business</span><span class="pm-choice__desc">Has an EIN</span></span>
                            </label>
                            <label class="pm-choice" for="entity_sole">
                                <input type="radio" id="entity_sole" name="entity_type" value="sole_proprietor" {{ old('entity_type', $registration->entity_type?->value ?? '') === 'sole_proprietor' ? 'checked' : '' }}>
                                <span><span class="pm-choice__title">Individual / sole proprietor</span><span class="pm-choice__desc">No EIN</span></span>
                            </label>
                        </div>
                    </div>

                    <div class="pm-field" id="ein-field">
                        <label for="ein">EIN</label>
                        <input type="text" class="form-control @error('ein') is-invalid @enderror" id="ein" name="ein" value="{{ old('ein', $registration->ein ?? '') }}" maxlength="20">
                        @error('ein')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="pm-field">
                        <label class="required" for="address_line_1">Business address</label>
                        <input type="text" class="form-control mb-1 @error('address_line_1') is-invalid @enderror" id="address_line_1" name="address_line_1"
                               value="{{ old('address_line_1', $prefill['values']['address_line_1']) }}" maxlength="191" placeholder="Street address" required>
                        @error('address_line_1')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <input type="text" class="form-control" id="address_line_2" name="address_line_2" aria-label="Apartment, suite, etc. (optional)"
                               value="{{ old('address_line_2', $prefill['values']['address_line_2']) }}" maxlength="191" placeholder="Apt, suite, etc. (optional)">
                    </div>

                    <div class="pm-grid pm-grid--3">
                        <div class="pm-field">
                            <label class="required" for="city">City</label>
                            <input type="text" class="form-control @error('city') is-invalid @enderror" id="city" name="city" value="{{ old('city', $prefill['values']['city']) }}" maxlength="120" required>
                            @error('city')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="pm-field">
                            <label class="required" for="region">State</label>
                            <input type="text" class="form-control @error('region') is-invalid @enderror" id="region" name="region" value="{{ old('region', $prefill['values']['region']) }}" maxlength="120" required>
                            @error('region')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="pm-field">
                            <label class="required" for="postal_code">ZIP code</label>
                            <input type="text" class="form-control @error('postal_code') is-invalid @enderror" id="postal_code" name="postal_code" value="{{ old('postal_code', $prefill['values']['postal_code']) }}" maxlength="20" required>
                            @error('postal_code')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    </div>

                    <div class="pm-field">
                        <label class="required" for="website_url">Website</label>
                        <input type="url" class="form-control @error('website_url') is-invalid @enderror" id="website_url" name="website_url" value="{{ old('website_url', $prefill['values']['website_url']) }}" maxlength="255" placeholder="https://" required>
                        @error('website_url')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="pm-field">
                        <label for="additional_information">Name or email differences <span class="fw-normal text-muted">(optional)</span></label>
                        <textarea class="form-control @error('additional_information') is-invalid @enderror" id="additional_information" name="additional_information" rows="2" maxlength="300" aria-describedby="addl-help">{{ old('additional_information', $registration->additional_information) }}</textarea>
                        <p class="pm-hint" id="addl-help">If your legal name, website brand or contact-email domain legitimately differ, say why in your own words. We send exactly what you write and never add an explanation for you.</p>
                        @error('additional_information')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    @if(in_array($registration->brand_status, ['needs_attention', null], true) && trim((string) $registration->provider_brand_id) !== '')
                        <x-alert variant="warning" icon="alert-circle" class="mb-0" data-role="brand-replacement">
                            <div class="form-check">
                                <input type="checkbox" class="form-check-input" id="authorize_brand_replacement" name="authorize_brand_replacement" value="1">
                                <label class="form-check-label" for="authorize_brand_replacement">{{ $registration->brand_status === 'needs_attention' ? 'The carrier rejected this business.' : 'The outcome of the earlier carrier brand was never recorded, so it is kept unless you choose otherwise.' }} If I change the business details above, I understand this replaces the earlier carrier brand and a new carrier brand fee applies.</label>
                            </div>
                        </x-alert>
                    @endif
                </div>
            </fieldset>

            {{-- 2 · Contact person --}}
            <fieldset class="pm-section" id="pm-sec-contact" data-pm-section="Contact person">
                <legend class="visually-hidden">Contact person</legend>
                <div class="pm-section__head" aria-hidden="true">
                    <span class="pm-section__num">2</span>
                    <div><h2 class="pm-section__title">Contact person</h2><p class="pm-section__help">Who the carrier can reach about this registration.</p></div>
                </div>
                <div class="pm-section__body">
                    <div class="pm-grid" data-role="carrier-contact-fields">
                        <div class="pm-field">
                            <label class="required" for="contact_first_name">First name</label>
                            <input type="text" class="form-control @error('contact_first_name') is-invalid @enderror" id="contact_first_name" name="contact_first_name" value="{{ old('contact_first_name', $registration->contact_first_name) }}" maxlength="100" required>
                            @error('contact_first_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="pm-field">
                            <label class="required" for="contact_last_name">Last name</label>
                            <input type="text" class="form-control @error('contact_last_name') is-invalid @enderror" id="contact_last_name" name="contact_last_name" value="{{ old('contact_last_name', $registration->contact_last_name) }}" maxlength="100" required>
                            @error('contact_last_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="pm-field">
                            <label class="required" for="contact_email">Email</label>
                            <input type="email" class="form-control @error('contact_email') is-invalid @enderror" id="contact_email" name="contact_email" value="{{ old('contact_email', $prefill['values']['contact_email']) }}" maxlength="191" required>
                            @error('contact_email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="pm-field">
                            <label class="required" for="contact_phone">Phone</label>
                            <input type="text" class="form-control @error('contact_phone') is-invalid @enderror" id="contact_phone" name="contact_phone" value="{{ old('contact_phone', $prefill['values']['contact_phone']) }}" maxlength="32" required>
                            @error('contact_phone')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    </div>

                    @if($formNumberType === \App\Enums\Messaging\PhoneNumberType::Local)
                        <div class="pm-field" data-role="support-contact">
                            <label class="required" for="support_contact">Text-message support contact</label>
                            <input type="text" class="form-control @error('support_contact') is-invalid @enderror" id="support_contact" name="support_contact"
                                   value="{{ old('support_contact', $registration->support_contact) }}" maxlength="191" placeholder="support@yourbusiness.com or (555) 555-0100" aria-describedby="support-contact-help">
                            <div class="pm-hint" id="support-contact-help">Your Business's own customer-facing email or phone. It appears in the HELP reply your customers get, so it must be one you publish and answer. We never fill this in for you.</div>
                            <input type="hidden" id="support_contact_confirmed_for" name="support_contact_confirmed_for" value="{{ $registration->support_contact_confirmed_at !== null ? $registration->support_contact : '' }}">
                            <label class="pm-check mt-1" for="support_contact_confirmed">
                                <input type="checkbox" id="support_contact_confirmed" name="support_contact_confirmed" value="1" {{ old('support_contact_confirmed', $registration->support_contact_confirmed_at !== null) ? 'checked' : '' }}>
                                <span>This contact belongs to my Business, is public to my customers, and is monitored.</span>
                            </label>
                            @error('support_contact')<div class="pm-error">{{ $message }}</div>@enderror
                        </div>
                    @endif
                </div>
            </fieldset>

            {{-- 3 · How you'll text --}}
            <fieldset class="pm-section" id="pm-sec-texting" data-pm-section="How you'll text">
                <legend class="visually-hidden">How you'll text</legend>
                <div class="pm-section__head" aria-hidden="true">
                    <span class="pm-section__num">3</span>
                    <div><h2 class="pm-section__title">How you'll text</h2><p class="pm-section__help">What you will send, how often, and how customers agree to it.</p></div>
                </div>
                <div class="pm-section__body">
                    <div class="pm-field" data-role="use-case-choices" role="radiogroup" aria-labelledby="use-case-label">
                        <span class="pm-legend required" id="use-case-label">What will you use texting for?</span>
                        <div class="pm-choices">
                            @foreach($useCaseChoices as $choice => $definition)
                                @continue($definition['use_case'] === 'mixed' && ! config('messaging.ten_dlc_mixed_enabled') && $useCase !== 'mixed')
                                @continue($definition['use_case'] === 'low_volume_mixed' && $isTollFreeForm)
                                <label class="pm-choice" for="use_case_{{ $choice }}">
                                    <input type="radio" id="use_case_{{ $choice }}" name="use_case" value="{{ $definition['use_case'] }}" data-choice="{{ $choice }}" data-care-only="{{ \App\Library\Messaging\Registration\RegistrationTemplateCatalog::isCareOnly($definition['use_case']) ? 1 : 0 }}" {{ $useCase === $definition['use_case'] ? 'checked' : '' }} required>
                                    <span class="pm-choice__title">{{ $definition['label'] }}</span>
                                </label>
                            @endforeach
                            @if($useCase && $selectedChoice === null && isset($useCases[$useCase]))
                                <label class="pm-choice" for="use_case_existing">
                                    <input type="radio" id="use_case_existing" name="use_case" value="{{ $useCase }}" checked>
                                    <span class="pm-choice__title">{{ $useCases[$useCase] }} <span class="pm-choice__desc">Your saved choice</span></span>
                                </label>
                            @endif
                        </div>
                        @error('use_case')<div class="pm-error">{{ $message }}</div>@enderror
                        <p class="pm-hint">Pick what you will actually send. Promotional messages are only registered if you choose them — we never add them for you. The carrier decides whether the campaign is approved.</p>

                        <div class="sr-callout sr-callout--warn mt-1" data-low-volume data-role="low-volume-explainer" @if($useCase !== 'low_volume_mixed') hidden @endif>
                            <x-ds-icon name="info" size="16" />
                            <div>
                                <strong class="d-block">Low Volume Mixed — for small businesses</strong>
                                <p class="mb-1">A separate, lower-cost carrier campaign type for a business that sends only a small number of texts. Carriers limit how fast and how much it can send, and it is not for growing or high-volume senders. Fees are shown below before you submit; number rental and text usage are separate.</p>
                                <p class="mb-1"><strong>Low Volume Mixed is only for a business that really sends BOTH kinds of text.</strong> Tick both and give a sample message and your opt-in details for each. If you only send one kind, choose that message type above instead — we never relabel single-purpose traffic to lower the fee.</p>
                                <div class="pm-checks">
                                    @foreach($lowVolumePurposes as $key => $purpose)
                                        <label class="pm-check" for="purpose_{{ $key }}">
                                            <input type="checkbox" id="purpose_{{ $key }}" name="message_purposes[]" value="{{ $key }}" {{ in_array($key, (array) old('message_purposes', $registration->message_purposes ?? []), true) ? 'checked' : '' }}>
                                            <span>{{ $purpose['label'] }}</span>
                                        </label>
                                    @endforeach
                                </div>
                                @error('message_purposes')<div class="pm-error">{{ $message }}</div>@enderror
                            </div>
                        </div>
                        <div class="pm-hint" data-role="suggested-topics" @if(empty($prefill['topics'])) hidden @endif>
                            Suggested message types: <span data-role="topics">{{ implode(', ', $prefill['topics']) }}</span>
                        </div>
                    </div>

                    @if($isTollFreeForm)
                        <div class="pm-grid" data-role="toll-free-carrier-fields">
                            <div class="pm-field">
                                <label class="required" for="message_volume">Messages per month</label>
                                <select class="form-select @error('message_volume') is-invalid @enderror" id="message_volume" name="message_volume" required>
                                    <option value="">Choose…</option>
                                    @foreach($messageVolumes as $value => $label)
                                        <option value="{{ $value }}" @selected(old('message_volume', $registration->message_volume) === (string) $value)>{{ $label }}</option>
                                    @endforeach
                                </select>
                                @error('message_volume')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="pm-field">
                                <label class="required" for="opt_in_image_url">Link to your opt-in page or a screenshot of it</label>
                                <input type="url" class="form-control @error('opt_in_image_url') is-invalid @enderror" id="opt_in_image_url" name="opt_in_image_url"
                                       value="{{ old('opt_in_image_url', $registration->opt_in_image_url) }}" maxlength="2083" placeholder="https://… (public page or image where customers agree to texts)" required aria-describedby="opt-in-image-help">
                                <p class="pm-hint" id="opt-in-image-help">The carrier requires evidence of how customers opt in: a link to your public opt-in page, or to a screenshot of it. Make sure the link is publicly viewable — we can't check that for you.</p>
                                @error('opt_in_image_url')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>
                    @else
                        <div class="pm-field" data-role="local-carrier-fields">
                            <label class="required" for="brand_vertical">Industry category</label>
                            <select class="form-select @error('brand_vertical') is-invalid @enderror" id="brand_vertical" name="brand_vertical" required>
                                <option value="">Choose…</option>
                                @foreach($brandVerticals as $value => $label)
                                    <option value="{{ $value }}" @selected(old('brand_vertical', $prefill['values']['brand_vertical'] ?? '') === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                            @error('brand_vertical')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    @endif

                    <div class="pm-field">
                        <label class="required" for="campaign_description">What will you text about?</label>
                        <textarea class="form-control @error('campaign_description') is-invalid @enderror" id="campaign_description" name="campaign_description" rows="3" maxlength="{{ $textLimit }}" required data-suggestible data-counter="campaign_description_count">{{ old('campaign_description', $prefill['values']['campaign_description']) }}</textarea>
                        <div class="pm-counter" id="campaign_description_count" aria-live="polite" data-limit="{{ $textLimit }}"></div>
                        @error('campaign_description')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="pm-field">
                        <label class="required" for="opt_in_method">How do customers agree to get texts? (message flow)</label>
                        <textarea class="form-control @error('opt_in_method') is-invalid @enderror" id="opt_in_method" name="opt_in_method" data-suggestible rows="3" maxlength="{{ $textLimit }}" required data-counter="opt_in_method_count"
                                  placeholder="e.g. Customers check a box on our signup form agreeing to receive text updates." aria-describedby="opt-in-method-help">{{ old('opt_in_method', $prefill['values']['opt_in_method']) }}</textarea>
                        <div class="pm-counter" id="opt_in_method_count" aria-live="polite" data-limit="{{ $textLimit }}"></div>
                        <p class="pm-hint" id="opt-in-method-help">Suggested wording only — edit it to match what your website really does. It is not confirmed until you check the consent items below.</p>
                        @error('opt_in_method')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>
            </fieldset>

            {{-- 4 · Sample messages --}}
            <fieldset class="pm-section" id="pm-sec-samples" data-pm-section="Sample messages">
                <legend class="visually-hidden">Sample messages</legend>
                <div class="pm-section__head" aria-hidden="true">
                    <span class="pm-section__num">4</span>
                    <div><h2 class="pm-section__title">Sample messages</h2><p class="pm-section__help">Real examples the carrier can review. Include your business name and how to opt out.</p></div>
                </div>
                <div class="pm-section__body">
                    <div class="pm-field">
                        <label class="required" for="sample_message_1">Sample 1</label>
                        <textarea class="form-control @error('sample_message_1') is-invalid @enderror" id="sample_message_1" name="sample_message_1" data-suggestible rows="2" maxlength="{{ $isTollFreeForm ? \App\Library\Messaging\Registration\RegistrationSubmissionValidator::TOLL_FREE_SAMPLE_LIMIT : 500 }}" required
                                  placeholder="Include your business name and how to opt out, e.g. &quot;Reply STOP to unsubscribe.&quot;">{{ old('sample_message_1', $prefill['values']['sample_message_1']) }}</textarea>
                        @error('sample_message_1')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="pm-field">
                        <label @unless($isTollFreeForm) class="required" @endunless for="sample_message_2">Sample 2 @if($isTollFreeForm)<span class="fw-normal text-muted">(optional)</span>@endif</label>
                        <textarea class="form-control @error('sample_message_2') is-invalid @enderror" id="sample_message_2" name="sample_message_2" data-suggestible rows="2" maxlength="500" @unless($isTollFreeForm) required @endunless>{{ old('sample_message_2', $prefill['values']['sample_message_2']) }}</textarea>
                        @error('sample_message_2')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        @if($isTollFreeForm)<div class="form-text">For toll-free verification the carrier receives Sample 1 only, so Sample 2 is optional. If you add one, brand it with your business name.</div>@endif
                    </div>
                </div>
            </fieldset>

            {{-- 5 · Policies & consent --}}
            <fieldset class="pm-section" id="pm-sec-consent" data-pm-section="Policies & consent">
                <legend class="visually-hidden">Policies and consent</legend>
                <div class="pm-section__head" aria-hidden="true">
                    <span class="pm-section__num">5</span>
                    <div><h2 class="pm-section__title">Policies &amp; consent</h2><p class="pm-section__help">Carriers check these on your website. Only tick what is already true there.</p></div>
                </div>
                <div class="pm-section__body">
                    <div class="pm-grid">
                        <div class="pm-field">
                            <label class="required" for="privacy_policy_url">Privacy policy URL</label>
                            <input type="url" class="form-control @error('privacy_policy_url') is-invalid @enderror" id="privacy_policy_url" name="privacy_policy_url" value="{{ old('privacy_policy_url', $registration->privacy_policy_url ?? '') }}" maxlength="255" placeholder="https://" required>
                            @error('privacy_policy_url')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="pm-field">
                            <label class="required" for="terms_url">Terms of service URL</label>
                            <input type="url" class="form-control @error('terms_url') is-invalid @enderror" id="terms_url" name="terms_url" value="{{ old('terms_url', $registration->terms_url ?? '') }}" maxlength="255" placeholder="https://" required>
                            @error('terms_url')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    </div>

                    <div class="pm-field" data-role="consent-checklist" role="group" aria-labelledby="consent-label">
                        <div class="d-flex justify-content-between align-items-baseline gap-2">
                            <span class="pm-legend" id="consent-label">Consent checklist</span>
                            <span class="pm-counter" data-role="consent-counter" aria-live="polite"></span>
                        </div>
                        <p class="pm-hint mb-2">We have not checked your website. Check each item only once you have confirmed it there; unchecked items must be fixed before you can submit. These are your own confirmations — we don't verify them, and checking them doesn't guarantee carrier approval.</p>
                        <div class="pm-checks">
                            @foreach($consentItems as $key => $label)
                                <label class="pm-check" for="consent_{{ $key }}">
                                    <input type="checkbox" id="consent_{{ $key }}" name="consent_confirmations[]" value="{{ $key }}" {{ in_array($key, $confirmedConsent, true) ? 'checked' : '' }}>
                                    <span>{{ $label }} <span class="pm-check__flag">— needs your confirmation</span></span>
                                </label>
                            @endforeach
                        </div>
                        <div class="pm-checks mt-1" data-low-volume data-role="low-volume-consent" @if($useCase !== 'low_volume_mixed') hidden @endif>
                            @foreach($lowVolumeConsentItems as $key => $label)
                                <label class="pm-check" for="consent_{{ $key }}" @if(str_starts_with($key, 'purpose_')) data-purpose="{{ str_replace(['purpose_', '_consent'], '', $key) }}" @endif>
                                    <input type="checkbox" id="consent_{{ $key }}" name="consent_confirmations[]" value="{{ $key }}" {{ in_array($key, $confirmedConsent, true) ? 'checked' : '' }}>
                                    <span>{{ $label }} <span class="pm-check__flag">— needs your confirmation</span></span>
                                </label>
                            @endforeach
                        </div>
                    </div>
                </div>
            </fieldset>

            @if($prefill['prefilled'] !== [])
                <p class="pm-hint" data-role="prefill-note">Some fields were prefilled from your business profile or suggested for your industry. Please review them before saving.</p>
            @endif

            <div class="pm-actions">
                <p class="pm-actions__note">Saving keeps your answers as a draft. Nothing is sent to the carrier until you submit below.</p>
                <x-button type="submit" variant="secondary" data-role="save-draft">Save draft</x-button>
            </div>
        </form>

        <aside class="pm-side" aria-label="Registration help">
            <nav class="pm-sidecard pm-sidecard--nav" aria-label="Sections">
                <h2>Sections</h2>
                <ul class="pm-nav" data-role="section-nav">
                    @foreach(['pm-sec-business' => 'Your business', 'pm-sec-contact' => 'Contact person', 'pm-sec-texting' => 'How you\'ll text', 'pm-sec-samples' => 'Sample messages', 'pm-sec-consent' => 'Policies & consent'] as $anchor => $navLabel)
                        <li><a href="#{{ $anchor }}" data-target="{{ $anchor }}"><span class="pm-nav__mark" aria-hidden="true"></span>{{ $navLabel }}<span class="pm-nav__count" data-count></span></a></li>
                    @endforeach
                </ul>
            </nav>
            <div class="pm-sidecard" data-role="registration-costs">
                <h2>Costs</h2>
                <p>{{ $verificationChargeDisclosure }}</p>
                <p><a href="{{ route('customer.workspaces.businesses.usage-billing.show', [$workspaceUid, $businessUid]) }}">Usage &amp; billing</a></p>
            </div>
            <div class="pm-sidecard">
                <h2>Moving carriers?</h2>
                <p>You can move your number to another provider at any time. See the number options at the bottom of this page.</p>
            </div>
        </aside>
        </div>

        @if(($registration->legal_business_name ?? null) !== null)
            <section class="pm-submit mt-3" id="pm-submit" aria-labelledby="pm-submit-title"><div class="pm-submit__body">
                <h2 class="pm-section__title" id="pm-submit-title">Submit for carrier review</h2>
                @if($submissionIssues !== [])
                    @php
                        // Presentation only: point each exact validator message at the section holding the field.
                        $sectionFor = static function (string $issue): array {
                            return match (true) {
                                (bool) preg_match('/support/i', $issue) => ['pm-sec-contact', 'Contact person'],
                                (bool) preg_match('/Privacy Policy|SMS Terms|Confirm on your website|separate pages/i', $issue) => ['pm-sec-consent', 'Policies & consent'],
                                (bool) preg_match('/sample message|opt-out wording|business name in at least|promotional example|customer-care example|still the suggestion/i', $issue) => ['pm-sec-samples', 'Sample messages'],
                                (bool) preg_match('/messaging type|campaign description|message-flow|opt in|screenshot|per month|industry|promotion|Low Volume|both message types|toll-free|Registering both/i', $issue) => ['pm-sec-texting', 'How you\'ll text'],
                                (bool) preg_match('/contact (first|last|email|phone)/i', $issue) => ['pm-sec-contact', 'Contact person'],
                                default => ['pm-sec-business', 'Your business'],
                            };
                        };
                        $fieldFor = static function (string $issue): ?string {
                            foreach ([
                                'Confirm on your website' => 'consent_public_opt_in_url',
                                'screenshot' => 'opt_in_image_url', 'opt-in screenshot' => 'opt_in_image_url', 'messages per month' => 'message_volume',
                                'sample message 1' => 'sample_message_1', 'sample message 2' => 'sample_message_2', 'Sample messages 1 and 2' => 'sample_message_1',
                                'business name in at least' => 'sample_message_1', 'opt-out wording' => 'sample_message_1',
                                'campaign description' => 'campaign_description', 'message-flow' => 'opt_in_method', 'opt in' => 'opt_in_method',
                                'Privacy Policy URL' => 'privacy_policy_url', 'SMS Terms URL' => 'terms_url', 'separate pages' => 'terms_url',
                                'EIN' => 'ein', 'website' => 'website_url', 'state' => 'region', 'ZIP' => 'postal_code', 'street address' => 'address_line_1', 'city' => 'city',
                                'contact first name' => 'contact_first_name', 'contact last name' => 'contact_last_name', 'contact email' => 'contact_email', 'contact phone' => 'contact_phone',
                                'support email or phone' => 'support_contact', 'legal business name' => 'legal_business_name',
                            ] as $needle => $id) {
                                if (stripos($issue, $needle) !== false) { return $id; }
                            }
                            return null;
                        };
                    @endphp
                    @if(session('registration_blocked'))
                        <div class="sr-callout sr-callout--warn mb-2" role="alert" data-role="submission-blocked">
                            <x-ds-icon name="alert-circle" size="16" />
                            <p><strong>Not submitted.</strong> This was our own check of your saved draft — the carrier did not review or reject anything. Nothing was sent to the carrier and no registration charge was made. Your saved details are unchanged.</p>
                        </div>
                    @endif
                    <x-alert variant="warning" icon="alert-circle" class="mb-2" data-role="submission-issues" tabindex="-1" id="pm-submission-issues">
                        <strong class="d-block">Before you submit</strong>
                        <ul class="mb-0 ps-2">
                            @foreach($submissionIssues as $issue)
                                @php
                                    [$anchor, $sectionName] = $sectionFor($issue);
                                    $fieldId = $fieldFor($issue);
                                @endphp
                                <li data-issue-section="{{ $anchor }}">{{ $issue }} <a href="#{{ $fieldId ?? $anchor }}" class="text-nowrap">Go to {{ $sectionName }}@if($fieldId) &rsaquo; field @endif</a></li>
                            @endforeach
                        </ul>
                    </x-alert>
                    <p class="pm-hint" data-role="submit-uses-saved-draft">Submitting uses your <strong>last saved draft</strong>. If you just changed something above, press <strong>Save draft</strong> first.</p>
                @else
                    <p class="text-caption text-muted mb-2">Everything required is filled in. Nothing is sent until you press the button. Submitting uses your last saved draft — press Save draft first if you just changed something.</p>
                @endif
                @php
$isLocalRegistration = $formNumberType === \App\Enums\Messaging\PhoneNumberType::Local;
@endphp
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
                        <x-button type="submit" variant="primary" data-role="submit-business" :disabled="! $available || ! $registrationPreview['configured'] || ! $registrationPreview['sufficient']">{{ trim((string) $registration->provider_brand_id) !== '' ? 'Check verification and continue' : 'Submit business for verification' }}</x-button>
                    @else
                        <x-button type="submit" variant="primary" :disabled="! $available">Submit for review</x-button>
                    @endif
                </form>
                @unless($available)
                    <p class="text-caption text-muted mt-1 mb-0">Preview — submission isn't available in this environment yet.</p>
                @endunless
            </div></section>
        @endif
    @endif

    <div class="mt-2">
        @unless($tollFreeReview)
        @include('customer.settings.text-messaging._port-out-card')
        @endunless
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
            // Submit sends the last SAVED draft, not unsaved edits: say so when the form has changed.
            $('#registration-form').one('input change', function () {
                $('.pm-actions__note').append(' <strong class="text-warning" data-role="unsaved-note">Unsaved changes — Save draft before submitting.</strong>');
                $('#pm-submit button[type="submit"]').prop('disabled', true).attr('title', 'Save draft first: submitting uses your last saved draft.');
            });
            // After a blocked submission, bring the exact reasons into view.
            if ($('[data-role="submission-blocked"]').length) {
                var el = document.getElementById('pm-submission-issues');
                if (el) { el.scrollIntoView({block: 'center'}); el.focus(); }
            }
            // Character counters (carrier limit): shown live; the server also refuses over-long text.
            $('textarea[data-counter]').each(function () {
                var $ta = $(this), $out = $('#' + $ta.data('counter')), limit = parseInt($out.data('limit'), 10);
                function upd() { var n = $ta.val().length; $out.text(n + ' / ' + limit + ' characters').css('color', n > limit ? '#a03626' : ''); }
                $ta.on('input', upd); upd();
            });
            // Section progress in the sidebar and the consent counter: display only.
            function syncProgress() {
                $('[data-pm-section]').each(function () {
                    var $sec = $(this), id = $sec.attr('id'), total = 0, done = 0, seen = {};
                    $sec.find('input,select,textarea').filter('[required]').each(function () {
                        if (this.type === 'radio') { if (seen[this.name]) { return; } seen[this.name] = true; total++; if ($sec.find('input[name="' + this.name + '"]:checked').length) { done++; } return; }
                        total++; if ($.trim($(this).val()) !== '') { done++; }
                    });
                    var $a = $('.pm-nav a[data-target="' + id + '"]');
                    $a.find('[data-count]').text(total ? done + '/' + total : '');
                    $a.toggleClass('is-complete', total > 0 && done === total);
                });
                var $boxes = $('input[name="consent_confirmations[]"]:visible');
                $('[data-role="consent-counter"]').text($boxes.filter(':checked').length + ' of ' + $boxes.length + ' confirmed');
            }
            $('#registration-form').on('input change', syncProgress);
            syncProgress();
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
                // Consent ticks are cleared only when the new type adds promotions (they were
                // made for service messages); a service-only type keeps the common ticks.
                if ((choice || null) !== lastChoice) {
                    if (String($(this).data('care-only')) === '1') { $('[data-role="low-volume-consent"] input[name="consent_confirmations[]"]').prop('checked', false); }
                    else { $('input[name="consent_confirmations[]"]').prop('checked', false); }
                }
                lastChoice = choice || null;
                $('textarea[data-counter]').trigger('input');
            });
        });
    </script>
</div>
@endsection
