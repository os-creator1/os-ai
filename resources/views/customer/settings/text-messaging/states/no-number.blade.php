@extends('layouts/contentLayoutMaster')

@section('title', 'Text messaging')

@section('page-style')
    @include('customer.settings._redesign-assets')
@endsection

@section('content')
<div class="sr-page sr-page--wide">
    @include('customer.settings._sr-header', [
        'backUrl' => route('customer.workspaces.businesses.settings.show', [$workspaceUid, $businessUid]),
        'title' => 'Text messaging',
        'chip' => $numberTypeLocked !== null ? 'Approved' : 'No number yet',
        'chipVariant' => $numberTypeLocked !== null ? 'success' : '',
        'description' => $numberTypeLocked !== null
            ? 'The carrier approved your registration. Choose a local number to finish setup.'
            : 'Get a phone number so this Business can send and receive text messages. Pick the kind of number that suits you.',
    ])

    @if(request('focus') === 'toll_free')
        <x-alert variant="success" icon="check-circle" class="mb-2" data-role="toll-free-focus-banner">
            <strong class="d-block">Let's get your toll-free number</strong>
            Choose a toll-free prefix (optional), then compare available numbers and their prices before buying.
        </x-alert>
    @endif

    @unless($available)
        <div class="sr-callout sr-callout--warn" data-role="preview-notice" role="status">
            <x-ds-icon name="clock" size="16" />
            <div>
                <strong class="d-block">Preview only</strong>
                <p>Number setup isn't turned on in this environment yet. You can see how it works, but no number can be bought here right now.</p>
            </div>
        </div>
    @endunless

    @if($numberTypeLocked !== null)
        {{--
            Review correction — the verify-first sequence's own "number
            required" render: business verification is already Approved,
            with no number yet. Telnyx genuinely supports completing 10DLC
            brand+campaign registration before any number is purchased;
            this is the guided next step, restricted to local only — the
            toll-free/local choice never reappears here.
        --}}
        @if(isset($registration) && $registration !== null)
            {{-- Step 3 of the registration progress: the picker below is the existing, authorized number search/order. --}}
            @include('customer.settings.text-messaging._registration-progress', [
                'progress' => \App\Library\Messaging\Registration\RegistrationProgress::for($registration),
                'registration' => $registration,
                'phoneNumber' => null,
                'campaignAssignmentStatus' => null,
                'numberPickerAnchor' => '#choose-number',
            ])
            <div class="mb-2"></div>
        @else
            <x-alert variant="success" icon="check-circle" class="mb-2" data-role="verified-banner">
                <strong class="d-block">Business verified</strong>
                Your business verification is approved. Choose your local number below to finish setup — a charge for the number begins once you complete your order.
            </x-alert>
        @endif

        <x-card :padded="true" id="choose-number" data-role="number-picker">
            <x-empty-state icon="phone" title="Choose your local number" description="Choose a preferred area code and we'll find a number for this Business." />

            <div class="row">
                <div class="col-12 col-xl-8">
                    <form method="post" action="{{ route('customer.workspaces.businesses.text-messaging.number.search', [$workspaceUid, $businessUid]) }}" class="mt-2">
                        @csrf
                        <input type="hidden" name="number_type" value="local">

                        <div class="mb-1">
                            <label class="form-label" for="area_code">Preferred area code (optional)</label>
                            <input type="text" inputmode="numeric" maxlength="3" class="form-control @error('area_code') is-invalid @enderror"
                                   id="area_code" name="area_code" value="{{ old('area_code', $criteria['area_code'] ?? '') }}" placeholder="e.g. 415">
                            @error('area_code')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                            <p class="text-caption text-muted mb-0">We'll find the closest match if this exact area code isn't available.</p>
                        </div>

                        <x-button type="submit" variant="primary">{{ $searched ? 'Show different numbers' : 'Find a number' }}</x-button>
                    </form>
                </div>
            </div>

            @if($searched)
                <hr class="my-2">

                @if(count($candidateOffers) > 0)
                    @include('customer.settings.text-messaging._number-candidate-offers')
                    <p class="text-muted small mt-2" data-role="number-rotation-note">Want more choices? Click Show different numbers above to see the next available numbers, without repeating those already shown in this session.</p>
                @else
                    @if($numberOptionsExhausted ?? false)
                        <x-empty-state icon="search" title="No more different numbers" description="The carrier returned no further unseen numbers for this area code. Try another area code to see other choices." />
                    @else
                        <x-empty-state icon="search" title="No number found"
                                        description="We couldn't find a number matching those preferences right now. Try a different area code." />
                    @endif
                @endif
            @endif
        </x-card>
    @else
        {{--
            Review correction — the truthful, guided sequence for each
            number type, confirmed separately against current Telnyx
            documentation:
              - Local (10DLC): brand+campaign business verification can be,
                and now must be, completed BEFORE any number is purchased.
                This is NOT free — TelnyxProvisioningAdapter reserves real
                wallet funds for this step — so $verificationChargeDisclosure
                states the truth from the actual configured rate, never an
                invented one.
              - Toll-free: Telnyx's own verification submission requires
                the number to already be owned and assigned to a messaging
                profile, so it can never be verified before purchase — the
                number-first sequence here is a real provider constraint,
                not a product choice.
        --}}
        <div class="sr-grid-2" style="margin-bottom:var(--space-5)">
            <div class="sr-card" style="margin-bottom:0;height:100%;border:2px solid var(--primary, #2c5282)" data-role="toll-free-number-card">
                <div class="sr-card__body d-flex flex-column" style="height:100%;min-height:360px">
                    <div class="d-flex align-items-center gap-3 mb-3">
                        <span class="sr-tile sr-tile--lg" style="background:#e6eef7;color:#2c5282"><x-ds-icon name="globe" size="20" /></span>
                        <div>
                            <h2 class="sr-card__title" style="font-size:1.0625rem">Toll-free number <span class="badge bg-primary ms-1" data-role="recommended-badge">Recommended</span></h2>
                            <p class="sr-card__sub">Nationwide, and free for your customers to text. The simplest way to start texting a US audience.</p>
                        </div>
                    </div>

                    <ol class="sr-steps">
                        <li><span class="sr-num">1</span><div><strong>Choose and buy your number</strong><span class="sr-step-desc">The carrier requires the number before you can be verified for it.</span></div></li>
                        <li><span class="sr-num">2</span><div><strong>Verify your business</strong><span class="sr-step-desc">For that number.</span></div></li>
                    </ol>

                    <div class="sr-callout" data-role="toll-free-cost-notice">
                        <x-ds-icon name="credit-card" size="16" />
                        <p>{{ \App\Library\Messaging\BusinessMessagingRegistrationService::TOLL_FREE_DISCLOSURE }} Number rental starts as soon as you complete the order, <strong>before</strong> verification.</p>
                    </div>

                    <form method="post" class="mt-auto pt-3" action="{{ route('customer.workspaces.businesses.text-messaging.number.search', [$workspaceUid, $businessUid]) }}" data-role="toll-free-search-form">
                        @csrf
                        <input type="hidden" name="number_type" value="toll_free">

                        <div class="sr-field mb-3" style="max-width:220px">
                            <label for="toll_free_prefix">Toll-free prefix <span class="fw-normal text-muted">(optional)</span></label>
                            <select id="toll_free_prefix" name="toll_free_prefix" class="form-control @error('toll_free_prefix') is-invalid @enderror">
                                <option value="">Any toll-free prefix</option>
                                @foreach(['800', '888', '877', '866', '855', '844', '833'] as $prefix)
                                    <option value="{{ $prefix }}" @selected(old('toll_free_prefix', $criteria['toll_free_prefix'] ?? '') === $prefix)>{{ $prefix }}</option>
                                @endforeach
                            </select>
                            @error('toll_free_prefix')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                            <p class="text-caption text-muted mb-0">Toll-free prefixes aren't geographic area codes.</p>
                        </div>

                        <x-button type="submit" variant="primary" :disabled="! $available" data-role="find-toll-free">{{ $searched ? 'Show different toll-free numbers' : 'Find a toll-free number' }}</x-button>
                    </form>
                </div>
            </div>

            <div class="sr-card" style="margin-bottom:0;height:100%" data-role="local-number-card">
                <div class="sr-card__body d-flex flex-column" style="height:100%;min-height:360px">
                    <div class="d-flex align-items-center gap-3 mb-3">
                        <span class="sr-tile sr-tile--lg"><x-ds-icon name="map-pin" size="20" /></span>
                        <div>
                            <h2 class="sr-card__title" style="font-size:1.0625rem">Local number</h2>
                            <p class="sr-card__sub">A specific area code, like a local business. Needs carrier 10DLC registration, which has its own costs.</p>
                        </div>
                    </div>

                    <ol class="sr-steps">
                        <li><span class="sr-num">1</span><div><strong>Verify your business</strong><span class="sr-step-desc">{{ $verificationChargeDisclosure }}</span></div></li>
                        <li><span class="sr-num">2</span><div><strong>Choose your number</strong><span class="sr-step-desc">Once verification is approved.</span></div></li>
                    </ol>

                    <div class="sr-callout sr-callout--warn" data-role="local-cost-notice">
                        <x-ds-icon name="credit-card" size="16" />
                        <p>{{ \App\Library\Messaging\BusinessMessagingRegistrationService::LOCAL_COST_NOTICE }} The number itself is charged only when you complete that order, after you're verified.</p>
                    </div>

                    @include('customer.settings.text-messaging._charge-summary', ['preview' => $localRegistrationPreview, 'oneTimeLabel' => '10DLC registration: the carrier brand fee plus the first three months of campaign fees, charged when you submit', 'recurringLabel' => '10DLC campaign, billed monthly after those first three months', 'unconfiguredText' => 'Local registration pricing isn\'t set up on our side yet, so a local number can\'t be registered right now. Toll-free is available.'])
                    @if($lowVolumeRegistrationPreview['configured'])
                        <p class="text-caption text-muted mb-2" data-role="low-volume-note">Small business sending few texts? A lower-cost <strong>Low Volume Mixed</strong> option is available when you register: {{ $lowVolumeRegistrationPreview['one_time']['text'] }} to register, then {{ $lowVolumeRegistrationPreview['recurring']['text'] }} after the first three months. It has lower sending limits.</p>
                    @endif

                    <form method="post" class="mt-auto pt-3" action="{{ route('customer.workspaces.businesses.text-messaging.registration.start-local-verification', [$workspaceUid, $businessUid]) }}">
                        @csrf
                        <x-button type="submit" variant="secondary" :disabled="! $available">Continue with a local number</x-button>
                    </form>
                </div>
            </div>
        </div>

        @if($searched)
            <x-card :padded="true">
                @if(count($candidateOffers) > 0)
                    @include('customer.settings.text-messaging._number-candidate-offers')
                    <p class="text-muted small mt-2" data-role="number-rotation-note">Want more choices? Click Show different toll-free numbers above to see the next available numbers, without repeating those already shown in this session.</p>
                @else
                    @if($numberOptionsExhausted ?? false)
                        <x-empty-state icon="search" title="No more different numbers" description="The carrier returned no further unseen numbers for these filters. Choose a different toll-free prefix to see other numbers." />
                    @else
                        <x-empty-state icon="search" title="No number found"
                                        description="We couldn't find any toll-free numbers with confirmed pricing for that search. Try another prefix or search again later." />
                    @endif
                @endif
            </x-card>
        @endif
    @endif

    {{--
        Review correction — a Business can land on this "no active number"
        screen while still retaining a Suspended number (Slice 3's own
        active-only identity/number resolvers hide it from the state
        machine above). $retainedNumber is resolved independently via
        PortOutRequestManager's ownership-safe lookup, so the exit path
        stays reachable even here.
    --}}
    <div class="mt-2">
        @include('customer.settings.text-messaging._port-out-card')
    </div>
</div>
@endsection
