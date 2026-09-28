@extends('layouts/contentLayoutMaster')

@section('title', 'Text messaging')

@section('content')
    @include('customer.settings._module-header', [
        'backUrl' => route('customer.workspaces.businesses.settings.show', [$workspaceUid, $businessUid]),
        'title' => 'Text messaging',
        'description' => 'Get a phone number so this Business can send and receive text messages.',
    ])

    @unless($available)
        <x-alert variant="warning" icon="alert-triangle" class="mb-2" data-role="preview-notice">
            <strong class="d-block">Preview</strong>
            Number setup isn't turned on in this environment yet. You can see how this works below, but no number can be purchased here right now.
        </x-alert>
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
        <x-alert variant="success" icon="check-circle" class="mb-2" data-role="verified-banner">
            <strong class="d-block">Business verified</strong>
            Your business verification is approved. Choose your local number below to finish setup — a charge for the number begins once you complete your order.
        </x-alert>

        <x-card :padded="true">
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

                        <x-button type="submit" variant="primary">Find a number</x-button>
                    </form>
                </div>
            </div>

            @if($searched)
                <hr class="my-2">

                @if($candidate)
                    <div data-role="number-candidate">
                        <p class="text-section-heading mb-1">We found a number for this Business</p>
                        <p class="h4 mb-2" data-role="candidate-phone-number">{{ $candidate->phoneNumber }}</p>

                        <form method="post" action="{{ route('customer.workspaces.businesses.text-messaging.number.order', [$workspaceUid, $businessUid]) }}">
                            @csrf
                            <input type="hidden" name="candidate_token" value="{{ $candidateToken }}">
                            <x-button type="submit" variant="primary" :disabled="! $available">Get this number</x-button>
                        </form>
                    </div>
                @else
                    <x-empty-state icon="search" title="No number found"
                                    description="We couldn't find a number matching those preferences right now. Try a different area code." />
                @endif
            @endif
        </x-card>
    @else
        {{--
            Review correction — the truthful, guided sequence for each
            number type, confirmed separately against current Telnyx
            documentation:
              - Local (10DLC): brand+campaign business verification can be,
                and now must be, completed BEFORE any number is purchased
                — genuinely free until you order a number.
              - Toll-free: Telnyx's own verification submission requires
                the number to already be owned and assigned to a messaging
                profile, so it can never be verified before purchase — the
                number-first sequence here is a real provider constraint,
                not a product choice.
        --}}
        <div class="row">
            <div class="col-12 col-lg-6 mb-2">
                <x-card :padded="true" class="h-100">
                    <p class="text-section-heading mb-1">Local number</p>
                    <p class="text-caption text-muted mb-2">A number with a specific area code, like a local business. We verify your business first, at no charge — you'll choose your number once your verification is approved, and a charge for the number begins only when you complete that order.</p>

                    <form method="post" action="{{ route('customer.workspaces.businesses.text-messaging.registration.start-local-verification', [$workspaceUid, $businessUid]) }}">
                        @csrf
                        <x-button type="submit" variant="primary" :disabled="! $available">Verify your business first</x-button>
                    </form>
                </x-card>
            </div>
            <div class="col-12 col-lg-6 mb-2">
                <x-card :padded="true" class="h-100">
                    <p class="text-section-heading mb-1">Toll-free number</p>
                    <p class="text-caption text-muted mb-2">A nationwide number, free for customers to text. The carrier requires this number to already be purchased before your business can be verified for it — a charge for the number begins as soon as you complete your order below, before verification.</p>

                    <form method="post" action="{{ route('customer.workspaces.businesses.text-messaging.number.search', [$workspaceUid, $businessUid]) }}" data-role="toll-free-search-form">
                        @csrf
                        <input type="hidden" name="number_type" value="toll_free">

                        <div class="mb-1" id="area-code-field">
                            <label class="form-label" for="area_code">Preferred area code (optional)</label>
                            <input type="text" inputmode="numeric" maxlength="3" class="form-control @error('area_code') is-invalid @enderror"
                                   id="area_code" name="area_code" value="{{ old('area_code', ($criteria['number_type'] ?? null) === 'toll_free' ? ($criteria['area_code'] ?? '') : '') }}" placeholder="e.g. 415">
                            @error('area_code')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <x-button type="submit" variant="primary" :disabled="! $available">Find a toll-free number</x-button>
                    </form>
                </x-card>
            </div>
        </div>

        @if($searched)
            <x-card :padded="true">
                @if($candidate)
                    <div data-role="number-candidate">
                        <p class="text-section-heading mb-1">We found a number for this Business</p>
                        <p class="h4 mb-2" data-role="candidate-phone-number">{{ $candidate->phoneNumber }}</p>

                        <form method="post" action="{{ route('customer.workspaces.businesses.text-messaging.number.order', [$workspaceUid, $businessUid]) }}">
                            @csrf
                            {{-- PR #295 Correction Round 1, item 2 — the browser
                                 never gets the raw phone_number/provider_candidate_reference/
                                 number_type back as independently-editable
                                 fields; it only carries this one opaque,
                                 short-lived, Business-bound token, so an order
                                 can never be bound to a provider resource this
                                 platform did not itself just verify. --}}
                            <input type="hidden" name="candidate_token" value="{{ $candidateToken }}">
                            <x-button type="submit" variant="primary" :disabled="! $available">Get this number</x-button>
                        </form>
                    </div>
                @else
                    <x-empty-state icon="search" title="No number found"
                                    description="We couldn't find a number matching those preferences right now. Try a different area code." />
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
@endsection
