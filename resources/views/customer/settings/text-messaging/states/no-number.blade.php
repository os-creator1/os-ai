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

    <x-card :padded="true">
        <x-empty-state icon="phone" title="Get a phone number" description="Choose a few preferences and we'll find a number for this Business." />

        <div class="row">
            <div class="col-12 col-xl-8">
                <form method="post" action="{{ route('customer.workspaces.businesses.text-messaging.number.search', [$workspaceUid, $businessUid]) }}" class="mt-2">
            @csrf

            <div class="mb-1">
                <label class="form-label">Country</label>
                {{-- Only United States numbers are supported today; shown as a
                     fact rather than a live choice so the page never implies
                     a country selection this platform cannot actually fulfil. --}}
                <input type="text" class="form-control" value="United States" disabled>
            </div>

            <div class="mb-1">
                <label class="form-label required">Number type</label>
                <div class="form-check">
                    <input type="radio" class="form-check-input" id="number_type_local" name="number_type" value="local"
                           {{ (($criteria['number_type'] ?? 'local') === 'local') ? 'checked' : '' }}>
                    <label class="form-check-label" for="number_type_local">Local number</label>
                    <p class="text-caption text-muted mb-0">A number with a specific area code, like a local business.</p>
                </div>
                <div class="form-check mt-1">
                    <input type="radio" class="form-check-input" id="number_type_toll_free" name="number_type" value="toll_free"
                           {{ (($criteria['number_type'] ?? '') === 'toll_free') ? 'checked' : '' }}>
                    <label class="form-check-label" for="number_type_toll_free">Toll-free number</label>
                    <p class="text-caption text-muted mb-0">A nationwide number, free for customers to text.</p>
                </div>
            </div>

            <div class="mb-1" id="area-code-field">
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
                                description="We couldn't find a number matching those preferences right now. Try a different area code or number type." />
            @endif
        @endif
    </x-card>
@endsection

@section('page-script')
    <script>
        $(document).ready(function () {
            function syncAreaCodeField() {
                var isLocal = $('#number_type_local').is(':checked');
                $('#area-code-field').prop('hidden', !isLocal);
            }
            $('input[name="number_type"]').on('change', syncAreaCodeField);
            syncAreaCodeField();
        });
    </script>
@endsection
