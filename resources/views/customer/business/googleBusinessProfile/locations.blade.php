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

@section('content')
    <div class="row mb-2">
        <div class="col-12 d-flex justify-content-between align-items-center flex-wrap">
            <h4 class="mb-0">Choose a Google location</h4>
            <a href="{{ route('customer.workspaces.businesses.gbp.index', [$workspaceUid, $businessUid]) }}" class="text-caption">Back</a>
        </div>
    </div>

    @if(session('message'))
        <x-alert :variant="session('status') === 'error' ? 'danger' : 'success'" class="mb-2">
            {{ session('message') }}
        </x-alert>
    @endif

    @if($errors->any())
        <x-alert variant="danger" class="mb-2">
            @foreach($errors->all() as $error)
                <div>{{ $error }}</div>
            @endforeach
        </x-alert>
    @endif

    @if(count($offers) === 0)
        <x-card :padded="true">
            <x-empty-state icon="search" title="No Google locations found"
                           description="The connected Google account does not manage any Business Profile locations that this platform can read." />
        </x-card>
    @elseif($locations->isEmpty())
        <x-card :padded="true">
            <x-empty-state icon="alert-triangle" title="No platform location to link to"
                           description="Add a location to this business first; a Google location is linked to one of your own locations." />
        </x-card>
    @else
        <x-card :padded="true">
            <p class="text-section-heading mb-1">Google accounts reachable with this authorization</p>
            <ul class="mb-2">
                @foreach($accounts as $account)
                    <li>{{ $account->displayName() }} <span class="text-caption">({{ $account->roleLabel() }})</span></li>
                @endforeach
            </ul>

            <form method="POST" action="{{ route('customer.workspaces.businesses.gbp.bind', [$workspaceUid, $businessUid]) }}">
                @csrf

                <div class="mb-2">
                    <label class="form-label" for="business_location_uid">Link to this platform location</label>
                    <select class="form-select" id="business_location_uid" name="business_location_uid" required>
                        <option value="">Choose one&hellip;</option>
                        @foreach($locations as $platformLocation)
                            <option value="{{ $platformLocation->uid }}">
                                {{ $platformLocation->name }}@if($platformLocation->is_primary) (primary)@endif
                            </option>
                        @endforeach
                    </select>
                </div>

                <p class="text-section-heading mb-1">Choose the matching Google location</p>
                <div class="list-group mb-2">
                    @foreach($offers as $offer)
                        @php($candidate = $offer['candidate'])
                        <label class="list-group-item d-flex align-items-start gap-1">
                            {{-- Deliberately never `checked`: the user
                                 chooses, always (contract §8.5). --}}
                            {{-- Item 5: the VALUE is the signed candidate token, not a
                                 raw resource name. Both provider names are derived
                                 server-side from it, so there is nothing to substitute. --}}
                            <input class="form-check-input mt-1" type="radio" name="candidate_token"
                                   value="{{ $offer['token'] }}" required>
                            <span>
                                <strong>{{ $candidate->displayTitle() }}</strong>
                                @if($candidate->matchScore >= 50)
                                    <x-badge variant="info">Likely match</x-badge>
                                @endif
                                <span class="text-caption d-block">
                                    {{ $candidate->resourceName }}
                                    @if($candidate->storeCode) &middot; store code {{ $candidate->storeCode }} @endif
                                    @if($candidate->localityHint) &middot; {{ $candidate->localityHint }} @endif
                                    @if($candidate->regionCode) &middot; {{ $candidate->regionCode }} @endif
                                </span>
                            </span>
                        </label>
                    @endforeach
                </div>

                {{-- Item 5: there is deliberately NO hidden raw account or
                     location field. Both are inside the signed candidate token
                     above, so a tampered or substituted pair cannot be posted. --}}

                <button type="submit" class="btn btn-primary">Link this location</button>
            </form>
        </x-card>
    @endif
@endsection
