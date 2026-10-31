@extends('layouts/contentLayoutMaster')

@section('title', 'Text messaging')

@section('page-style')
    @include('customer.settings._phone-messaging-assets')
@endsection

@section('content')
<div class="sr-page sr-page--wide pm-page">
    @include('customer.settings._sr-header', [
        'backUrl' => route('customer.workspaces.businesses.settings.show', [$workspaceUid, $businessUid]),
        'title' => 'Get a texting number',
        'action' => '<a class="btn btn-outline-secondary btn-sm" href="' . e(route('customer.workspaces.businesses.usage-billing.show', [$workspaceUid, $businessUid])) . '">Usage &amp; billing</a>',
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

    @if(isset($retainedNumbers) && $retainedNumbers->isNotEmpty())
        {{-- A number already on file takes priority over the purchase flow: say so before offering another. --}}
        <div class="sr-callout sr-callout--info mb-3" role="status" data-role="existing-number-notice">
            <x-ds-icon name="info" size="16" />
            <div>
                <strong class="d-block">This Business already has a number on file</strong>
                @foreach($retainedNumbers as $retainedNumber)
                    <span class="d-block">{{ $retainedNumber->phone_number }} — {{ ucfirst($retainedNumber->status->value) }}</span>
                @endforeach
                <a href="#port-out">Manage it below</a> before getting another number.
            </div>
        </div>
    @endif

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
        @php
            $tollFreeSelected = ($criteria['number_type'] ?? 'toll_free') !== 'local';
            $localAvailable = (bool) ($localRegistrationPreview['configured'] ?? false);
        @endphp
        @php
            $tfPreview = $numberPreview ?? ['configured' => false];
            $lowVolume = $lowVolumeRegistrationPreview ?? ['configured' => false];
            $searchRoute = route('customer.workspaces.businesses.text-messaging.number.search', [$workspaceUid, $businessUid]);
            $resetRoute = route('customer.workspaces.businesses.text-messaging.show', [$workspaceUid, $businessUid]);
        @endphp
        <div class="pm-flow" data-role="number-flow" data-state="{{ $searched ? 'results' : 'choose' }}" data-reset-url="{{ $resetRoute }}">
            {{-- State 1: the two choice cards. With JS the toll-free card morphs into state 2 in place. --}}
            <div class="pm-flow__choose" @if($searched) inert @endif>
              <div class="pm-flow__inner">
                <p class="pm-lede-sub">Choose the kind of number your customers will text.</p>
                <div class="pm-types">
                    <section class="pm-type pm-type--choice is-selected" data-role="toll-free-number-card" aria-labelledby="pm-type-toll-free">
                        <div class="pm-type__head">
                            <span class="sr-tile"><x-ds-icon name="globe" size="20" /></span>
                            <div>
                                <h2 class="pm-type__title" id="pm-type-toll-free">Toll-free <span class="sr-chip sr-chip--accent" data-role="recommended-badge">Recommended</span></h2>
                                <p class="pm-type__sub">Fastest way to start texting</p>
                            </div>
                        </div>
                        <dl class="pm-rows">
                            <div><dt>Looks like</dt><dd>+1 (844) 555-0123<small>800, 833, 844, 855, 866, 877, 888 · nationwide</small></dd></div>
                            <div><dt>Steps</dt><dd>Pick a number now, then a short verification</dd></div>
                            <div><dt>Cost</dt><dd>
                                @if($tfPreview['configured'] ?? false)
                                    {{ $tfPreview['one_time']['text'] }} today, then {{ $tfPreview['recurring']['text'] ?? '' }}
                                @else
                                    Shown for each number before you buy
                                @endif
                                <small>Verification is free</small></dd></div>
                            <div><dt>Best for</dt><dd>Customers across the country, getting started quickly</dd></div>
                        </dl>
                        <details class="pm-details" data-role="toll-free-cost-notice">
                            <summary>Cost details</summary>
                            <p>{{ \App\Library\Messaging\BusinessMessagingRegistrationService::TOLL_FREE_DISCLOSURE }} Number rental starts as soon as you complete the order, <strong>before</strong> verification. The carrier requires the number before it can be verified for it.</p>
                        </details>
                        <form method="post" action="{{ $searchRoute }}" data-role="toll-free-search-form" data-flow-search>
                            @csrf
                            <input type="hidden" name="number_type" value="toll_free">
                            @if(! empty($criteria['toll_free_prefix']))<input type="hidden" name="toll_free_prefix" value="{{ $criteria['toll_free_prefix'] }}">@endif
                            <x-button type="submit" variant="primary" class="w-100" :disabled="! $available" data-role="find-toll-free">Choose a toll-free number &rarr;</x-button>
                        </form>
                    </section>

                    <section class="pm-type pm-type--choice" data-role="local-number-card" aria-labelledby="pm-type-local">
                        <div class="pm-type__head">
                            <span class="sr-tile sr-tile--neutral"><x-ds-icon name="map-pin" size="20" /></span>
                            <div>
                                <h2 class="pm-type__title" id="pm-type-local">Local</h2>
                                <p class="pm-type__sub">A number in your own area code</p>
                            </div>
                        </div>
                        <dl class="pm-rows">
                            <div><dt>Looks like</dt><dd>+1 (601) 555-0123<small>Your city's area code</small></dd></div>
                            <div><dt>Steps</dt><dd>Register your business first, then pick a number</dd></div>
                            <div><dt>Cost</dt><dd>
                                @if($localRegistrationPreview['configured'] ?? false)
                                    Registration fee + number + monthly campaign fee
                                @else
                                    Registration pricing isn't set up yet
                                @endif
                                @if($lowVolume['configured'] ?? false)
                                    <small>Low-volume option: {{ $lowVolume['one_time']['text'] }} to register, {{ $lowVolume['recurring']['text'] }} after 3 months</small>
                                @endif
                            </dd></div>
                            <div><dt>Best for</dt><dd>Feeling local to customers nearby</dd></div>
                        </dl>
                        <details class="pm-details" data-role="local-cost-notice">
                            <summary>Cost details</summary>
                            <p>{{ \App\Library\Messaging\BusinessMessagingRegistrationService::LOCAL_COST_NOTICE }} The number itself is charged only when you complete that order, after you're verified.</p>
                            <p>{{ $verificationChargeDisclosure }}</p>
                            @include('customer.settings.text-messaging._charge-summary', ['preview' => $localRegistrationPreview, 'oneTimeLabel' => '10DLC registration: the carrier brand fee plus the first three months of campaign fees, charged when you submit', 'recurringLabel' => '10DLC campaign, billed monthly after those first three months', 'unconfiguredText' => 'Local registration pricing isn\'t set up on our side yet, so a local registration can\'t be submitted right now. You can still start by adding your business details.'])
                            @if($lowVolume['configured'] ?? false)
                                <p class="pm-hint" data-role="low-volume-note">Small business sending few texts? A lower-cost <strong>Low Volume Mixed</strong> option is available when you register: {{ $lowVolume['one_time']['text'] }} to register, then {{ $lowVolume['recurring']['text'] }} after the first three months. It has lower sending limits.</p>
                            @endif
                        </details>
                        <form method="post" action="{{ route('customer.workspaces.businesses.text-messaging.registration.start-local-verification', [$workspaceUid, $businessUid]) }}">
                            @csrf
                            <x-button type="submit" variant="secondary" class="w-100" :disabled="! $available">Register for a local number &rarr;</x-button>
                        </form>
                    </section>
                </div>
                <p class="pm-footnote">Not sure? Toll-free is quickest &mdash; you can text customers sooner.</p>
              </div>
            </div>

            {{-- State 2: the chooser compacts to a strip; number list and purchase summary open beneath it. --}}
            <div class="pm-flow__strip">
                <span class="sr-chip sr-chip--accent">Toll-free</span>
                <a href="{{ $resetRoute }}" class="pm-linkbtn" data-flow-reset>Change type</a>
            </div>
            <div class="pm-flow__results" @unless($searched) inert @endunless>
              <div class="pm-flow__inner">
                <div data-role="toll-free-results" data-flow-body aria-live="polite">
                    @if($searched)
                        @include('customer.settings.text-messaging._toll-free-results')
                    @endif
                </div>
              </div>
            </div>
        </div>
    @endif

    {{--
        Review correction — a Business can land on this "no active number"
        screen while still retaining a Suspended number (Slice 3's own
        active-only identity/number resolvers hide it from the state
        machine above). $retainedNumber is resolved independently via
        PortOutRequestManager's ownership-safe lookup, so the exit path
        stays reachable even here.
    --}}
    <div class="mt-2" id="port-out">
        @include('customer.settings.text-messaging._port-out-card')
    </div>
</div>
@endsection

@section('page-script')
    @include('customer.settings.text-messaging._number-flow-script')
@endsection
