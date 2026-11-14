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
        // A lone, still-unlinked platform location needs no visible choice; it is posted as a hidden field and the
        // server binds it exactly as before. Any other shape keeps the explicit platform-location selector.
        $eligibleLocations = $locations->filter(fn ($platformLocation) => ! isset($boundByLocationId[$platformLocation->id]))->values();
        $soleLocation = ($locations->count() === 1 && $eligibleLocations->count() === 1) ? $eligibleLocations->first() : null;
    @endphp

    <div class="gbp-page gbp-main">
        <div class="gbp-title-block gbp-wrap-sm">
            <a href="{{ $backUrl }}" class="gbp-back"><x-ds-icon name="arrow-left" :size="14" /> Google Business Profile</a>
            <h1 class="gbp-h1">Pick your Google listing</h1>
            <p class="gbp-sub">Choose the one that belongs to {{ $business->name }}. Nothing is linked until you choose and confirm.</p>
        </div>

        <x-flash-alert class="gbp-wrap-sm" />

        @if($errors->any())
            <x-alert variant="danger" class="gbp-wrap-sm mb-0" data-role="validation-summary">
                @foreach($errors->all() as $error)
                    <div>{{ $error }}</div>
                @endforeach
            </x-alert>
        @endif

        @if(count($offers) === 0)
            <section class="gbp-list-card gbp-wrap-sm">
                <div class="gbp-pick-msg" style="padding: 32px 20px; text-align: center">
                    <x-empty-state icon="search" title="No Google locations found"
                                   description="The connected Google account does not manage any Business Profile locations that this platform can read." />
                    <div class="d-flex flex-wrap justify-content-center gap-1 mt-2">
                        <a class="gbp-btn is-outline" href="{{ route('customer.workspaces.businesses.gbp.locations', [$workspaceUid, $businessUid]) }}">Check again</a>
                        <a class="gbp-btn is-outline" href="{{ route('customer.workspaces.businesses.gbp.settings', [$workspaceUid, $businessUid]) }}">Use a different Google account</a>
                    </div>
                </div>
            </section>
        @elseif($locations->isEmpty())
            <section class="gbp-list-card gbp-wrap-sm">
                <div class="gbp-pick-msg" style="padding: 32px 20px; text-align: center">
                    <x-empty-state icon="alert-triangle" title="No platform location to link to"
                                   description="Add a location to this business first; a Google location is linked to one of your own locations." />
                </div>
            </section>
        @else
            {{-- Google account identification --}}
            <section class="gbp-acct gbp-wrap-sm">
                <span class="gbp-acct-ok" aria-hidden="true"><x-ds-icon name="check" :size="16" /></span>
                <div class="gbp-acct-text">
                    <b>Google connected</b>
                    <span>
                        @if(! empty($connection?->google_account_email))
                            Signed in as {{ $connection->google_account_email }}
                        @else
                            Accounts reachable with this authorization:
                            @foreach($accounts as $account){{ $account->displayName() }} ({{ $account->roleLabel() }})@if(! $loop->last), @endif @endforeach
                        @endif
                    </span>
                </div>
                <a class="gbp-textlink" href="{{ route('customer.workspaces.businesses.gbp.settings', [$workspaceUid, $businessUid]) }}">Use a different account</a>
            </section>

            <form method="POST" action="{{ route('customer.workspaces.businesses.gbp.bind', [$workspaceUid, $businessUid]) }}" data-gbp-picker class="gbp-wrap-sm">
                @csrf

                <section class="gbp-list-card">
                    <div class="gbp-list-head">
                        <div>
                            <b>Your Google listings</b>
                            <span>{{ count($offers) }} found on this account</span>
                        </div>
                        <label class="gbp-search">
                            <x-ds-icon name="search" :size="16" />
                            <input type="search" placeholder="Search listings" aria-label="Search listings" data-gbp-pick-search>
                        </label>
                    </div>

                    <div class="gbp-rows" role="radiogroup" aria-label="Google listings">
                        @foreach($offers as $offer)
                            @php
                                $candidate = $offer['candidate'];
                                $alreadyClaimed = in_array($candidate->resourceName, $claimedProviderNames, true);
                            @endphp
                            <label class="gbp-row {{ $alreadyClaimed ? 'is-disabled' : '' }}" data-gbp-pick-row>
                                {{-- Deliberately never `checked`: the user chooses, always (contract §8.5). --}}
                                {{-- Item 5: the VALUE is the signed candidate token, not a raw resource name. Both provider names are
                                     derived server-side from it, so there is nothing to substitute. --}}
                                <input type="radio" name="candidate_token" value="{{ $offer['token'] }}" @disabled($alreadyClaimed) required>
                                <span class="lt" aria-hidden="true">{{ mb_strtoupper(mb_substr($candidate->displayTitle(), 0, 1)) }}</span>
                                <span class="body">
                                    <span class="name">
                                        <b>{{ $candidate->displayTitle() }}</b>
                                        @if($alreadyClaimed)
                                            <span class="gbp-tag">Already linked</span>
                                        @elseif($candidate->matchScore >= 50)
                                            <span class="gbp-tag is-brand">Likely match</span>
                                        @endif
                                    </span>
                                    <small>
                                        @if($candidate->storeCode)Store code {{ $candidate->storeCode }}@else No store code @endif
                                        @if($candidate->localityHint) &middot; {{ $candidate->localityHint }} @endif
                                        @if($candidate->regionCode) &middot; {{ $candidate->regionCode }} @endif
                                        &middot; {{ $candidate->resourceName }}
                                    </small>
                                </span>
                                <span class="rd" aria-hidden="true"></span>
                            </label>
                        @endforeach
                        <p class="gbp-pick-msg is-error d-none" role="alert" data-gbp-pick-notice>Select a listing from the list before linking.</p>
                        <p class="gbp-pick-msg d-none" data-gbp-pick-empty>No listings match your search.</p>
                    </div>

                    {{-- MULTI-LOCATION CORRECTION — the COMPLETE existing binding set arrives keyed by local business_locations.id, so
                         each platform location is shown as already bound or still bindable. One existing binding never prevents
                         another eligible BusinessLocation from being bound. A single unlinked location is posted as a hidden field. --}}
                    @if($soleLocation)
                        <input type="hidden" name="business_location_uid" value="{{ $soleLocation->uid }}">
                    @else
                        <div class="gbp-loc">
                            <label for="business_location_uid">Link to this platform location</label>
                            <select id="business_location_uid" name="business_location_uid" required>
                                <option value="">Choose one&hellip;</option>
                                @foreach($locations as $platformLocation)
                                    @php $existing = $boundByLocationId[$platformLocation->id] ?? null; @endphp
                                    <option value="{{ $platformLocation->uid }}" @disabled($existing !== null)>
                                        {{ $platformLocation->name }}@if($platformLocation->is_primary) (primary)@endif
                                        @if($existing)
                                            &mdash; already linked to {{ $existing->provider_location_resource_name }}
                                        @endif
                                    </option>
                                @endforeach
                            </select>
                            <small>
                                {{ $boundByLocationId->count() }} of {{ $locations->count() }} {{ $locations->count() === 1 ? 'location is' : 'locations are' }} already linked to Google.
                                Unlink one from connection settings to link it to a different Google location.
                            </small>
                        </div>
                    @endif

                    {{-- Item 5: there is deliberately NO hidden raw account or location field. Both are inside the signed candidate
                         token above, so a tampered or substituted pair cannot be posted. --}}
                    <div class="gbp-foot">
                        <span>Linking to <b>{{ $soleLocation ? $business->name . ' · ' . $soleLocation->name : $business->name }}</b> in MotionGrove</span>
                        <a href="{{ $backUrl }}" class="gbp-btn is-outline">Cancel</a>
                        <button type="submit" class="gbp-btn is-primary">Link this listing</button>
                    </div>
                </section>
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
                            // A hidden listing must never stay selected: only a visibly chosen listing may be submitted.
                            if (!hit) {
                                var radio = row.querySelector('input[type="radio"]');
                                if (radio && radio.checked) { radio.checked = false; }
                            }
                        });
                        if (empty) { empty.classList.toggle('d-none', shown !== 0); }
                    });

                    // Block submission unless a visible listing is selected (a required radio hidden by the filter
                    // would otherwise fail validation silently).
                    var form = document.querySelector('[data-gbp-picker]');
                    var notice = document.querySelector('[data-gbp-pick-notice]');
                    if (form) {
                        form.addEventListener('submit', function (event) {
                            var chosen = form.querySelector('input[name="candidate_token"]:checked');
                            if (!chosen || chosen.closest('[data-gbp-pick-row]').classList.contains('d-none')) {
                                event.preventDefault();
                                if (notice) { notice.classList.remove('d-none'); }
                                input.focus();
                            }
                        });
                        // Native `required` validation fires first when nothing is selected; surface the same notice.
                        form.addEventListener('invalid', function (event) {
                            if (event.target.name === 'candidate_token') {
                                event.preventDefault();
                                if (notice) { notice.classList.remove('d-none'); }
                                input.focus();
                            }
                        }, true);
                        form.addEventListener('change', function (event) {
                            if (notice && event.target.name === 'candidate_token') { notice.classList.add('d-none'); }
                        });
                    }
                })();
            </script>
        @endif
    </div>
@endsection
