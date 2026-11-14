{{--
    GBP Slice A contract §25.4 — the EXPLICIT account/location chooser.

    NOTHING IS PRE-SELECTED (contract §8.5, test T-BIND-3): no radio is
    checked, no checkbox is ticked, and the form never submits itself. The
    "likely match" hint below is ordering guidance only and carries no
    behaviour.

    Candidates shown here are REQUEST-SCOPED and are never persisted
    (contract §8.3, test T-BIND-2).

    Only a locality hint is ever shown for a candidate, never a street
    address — GoogleLocationCandidate has no field that could hold one
    (contract §23.3/§23.4 point 3).
--}}
@extends('layouts/contentLayoutMaster')

@section('title', 'Choose Google location')

@section('page-style')
    @include('customer.business.googleBusinessProfile._overview-style')
@endsection

@section('content')
    @php
        $backUrl = route('customer.workspaces.businesses.gbp.index', [$workspaceUid, $businessUid]);
        $claimedProviderNames = $boundByLocationId->pluck('provider_location_resource_name')->all();
    @endphp

    <div class="gbp-narrow mb-2">
        <a href="{{ $backUrl }}" class="gbp-link d-inline-flex align-items-center gap-50 mb-50"><x-ds-icon name="arrow-left" :size="14" /> Google Business Profile</a>
        <h1 class="h3 mb-25">Pick your Google listing</h1>
        <p class="text-caption mb-0">Choose the one that belongs to {{ $business->name }}. Nothing is linked until you choose and confirm.</p>
    </div>

    <x-flash-alert class="mb-2" />

    @if($errors->any())
        <x-alert variant="danger" class="mb-2 gbp-narrow" data-role="validation-summary">
            @foreach($errors->all() as $error)
                <div>{{ $error }}</div>
            @endforeach
        </x-alert>
    @endif

    @if(count($offers) === 0)
        <x-card :padded="true" class="gbp-narrow">
            <x-empty-state icon="search" title="No Google locations found"
                           description="The connected Google account does not manage any Business Profile locations that this platform can read." />
            <div class="d-flex flex-wrap justify-content-center gap-1 mt-2">
                <a class="btn btn-outline-primary" href="{{ route('customer.workspaces.businesses.gbp.locations', [$workspaceUid, $businessUid]) }}">Check again</a>
                <a class="btn btn-outline-secondary" href="{{ route('customer.workspaces.businesses.gbp.settings', [$workspaceUid, $businessUid]) }}">Use a different Google account</a>
            </div>
        </x-card>
    @elseif($locations->isEmpty())
        <x-card :padded="true" class="gbp-narrow">
            <x-empty-state icon="alert-triangle" title="No platform location to link to"
                           description="Add a location to this business first; a Google location is linked to one of your own locations." />
        </x-card>
    @else
        {{-- Google account identification --}}
        <x-card :padded="false" class="gbp-narrow mb-2">
            <div class="gbp-account">
                <span class="gbp-match-icon" aria-hidden="true"><x-ds-icon name="check" :size="16" /></span>
                <div>
                    <strong class="d-block">Google connected</strong>
                    <span class="text-caption">
                        Accounts reachable with this authorization:
                        @foreach($accounts as $account)
                            {{ $account->displayName() }} ({{ $account->roleLabel() }})@if(! $loop->last), @endif
                        @endforeach
                    </span>
                </div>
                <a class="gbp-link" href="{{ route('customer.workspaces.businesses.gbp.settings', [$workspaceUid, $businessUid]) }}">Use a different account</a>
            </div>
        </x-card>

        <form method="POST" action="{{ route('customer.workspaces.businesses.gbp.bind', [$workspaceUid, $businessUid]) }}" data-gbp-picker>
            @csrf

            {{-- MULTI-LOCATION CORRECTION — the COMPLETE existing binding
                 set arrives keyed by local business_locations.id, so each
                 platform location is shown as already bound or still
                 bindable. One existing binding never prevents another
                 eligible BusinessLocation from being bound: only the
                 locations that are actually taken are disabled, and a
                 Business may hold one binding per location. --}}
            <x-card :padded="true" class="gbp-narrow mb-2">
                <label class="form-label" for="business_location_uid">Link to this platform location</label>
                <select class="form-select" id="business_location_uid" name="business_location_uid" required>
                    <option value="">Choose one&hellip;</option>
                    @foreach($locations as $platformLocation)
                        @php($existing = $boundByLocationId[$platformLocation->id] ?? null)
                        <option value="{{ $platformLocation->uid }}" @disabled($existing !== null)>
                            {{ $platformLocation->name }}@if($platformLocation->is_primary) (primary)@endif
                            @if($existing)
                                &mdash; already linked to {{ $existing->provider_location_resource_name }}
                            @endif
                        </option>
                    @endforeach
                </select>
                <span class="text-caption d-block mt-50">{{ $boundByLocationId->count() }} of {{ $locations->count() }} {{ $locations->count() === 1 ? 'location is' : 'locations are' }} already linked to Google.</span>
                <span class="text-caption d-block">
                    Unlink one from connection settings to link it to a different Google location.
                </span>
            </x-card>

            <x-card :padded="false" class="gbp-narrow">
                <div class="gbp-pick-head">
                    <div>
                        <strong class="d-block">Your Google listings</strong>
                        <span class="text-caption">{{ count($offers) }} found on this account</span>
                    </div>
                    <input type="search" class="form-control gbp-pick-search" placeholder="Search listings" aria-label="Search listings" data-gbp-pick-search>
                </div>

                <div class="gbp-pick-list" role="radiogroup" aria-label="Google listings">
                    @foreach($offers as $offer)
                        @php($candidate = $offer['candidate'])
                        @php($alreadyClaimed = in_array($candidate->resourceName, $claimedProviderNames, true))
                        <label class="gbp-pick-row {{ $alreadyClaimed ? 'is-disabled' : '' }}" data-gbp-pick-row>
                            {{-- Deliberately never `checked`: the user
                                 chooses, always (contract §8.5). --}}
                            {{-- Item 5: the VALUE is the signed candidate token, not a
                                 raw resource name. Both provider names are derived
                                 server-side from it, so there is nothing to substitute. --}}
                            <input class="form-check-input flex-shrink-0 m-0" type="radio" name="candidate_token"
                                   value="{{ $offer['token'] }}" @disabled($alreadyClaimed) required>
                            <span class="gbp-letter" aria-hidden="true">{{ mb_strtoupper(mb_substr($candidate->displayTitle(), 0, 1)) }}</span>
                            <span class="gbp-pick-body">
                                <span class="d-flex flex-wrap align-items-center gap-50">
                                    <strong class="gbp-break">{{ $candidate->displayTitle() }}</strong>
                                    @if($alreadyClaimed)
                                        <x-badge variant="secondary">Already linked</x-badge>
                                    @elseif($candidate->matchScore >= 50)
                                        <x-badge variant="info">Likely match</x-badge>
                                    @endif
                                </span>
                                <span class="text-caption gbp-break">
                                    {{ $candidate->resourceName }}
                                    @if($candidate->storeCode) &middot; store code {{ $candidate->storeCode }} @else &middot; No store code @endif
                                    @if($candidate->localityHint) &middot; {{ $candidate->localityHint }} @endif
                                    @if($candidate->regionCode) &middot; {{ $candidate->regionCode }} @endif
                                </span>
                            </span>
                        </label>
                    @endforeach
                    <p class="text-caption p-2 mb-0 d-none" data-gbp-pick-empty>No listings match your search.</p>
                </div>

                {{-- Item 5: there is deliberately NO hidden raw account or
                     location field. Both are inside the signed candidate token
                     above, so a tampered or substituted pair cannot be posted. --}}
                <div class="gbp-pick-foot">
                    <span class="text-caption">Linking to <strong>{{ $business->name }}</strong> in MotionGrove</span>
                    <div class="d-flex flex-wrap gap-1">
                        <a href="{{ $backUrl }}" class="btn btn-outline-secondary">Cancel</a>
                        <button type="submit" class="btn btn-primary">Link this location</button>
                    </div>
                </div>
            </x-card>
        </form>

        <script>
            (function () {
                var input = document.querySelector('[data-gbp-pick-search]');
                if (!input) { return; }
                var rows = document.querySelectorAll('[data-gbp-pick-row]');
                var empty = document.querySelector('[data-gbp-pick-empty]');
                input.addEventListener('input', function () {
                    var q = input.value.trim().toLowerCase(), shown = 0;
                    rows.forEach(function (row) {
                        var hit = q === '' || row.textContent.toLowerCase().indexOf(q) !== -1;
                        row.classList.toggle('d-none', !hit);
                        if (hit) { shown++; }
                    });
                    if (empty) { empty.classList.toggle('d-none', shown !== 0); }
                });
            })();
        </script>
    @endif
@endsection
