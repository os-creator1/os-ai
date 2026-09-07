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

    @if(count($candidates) === 0)
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
                    @foreach($candidates as $candidate)
                        <label class="list-group-item d-flex align-items-start gap-1">
                            {{-- Deliberately never `checked`: the user
                                 chooses, always (contract §8.5). --}}
                            <input class="form-check-input mt-1" type="radio" name="provider_location_resource_name"
                                   value="{{ $candidate->resourceName }}" required
                                   data-account="{{ $candidate->accountResourceName }}">
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

                {{-- The account is carried alongside the chosen location
                     because every future Google surface addresses
                     accounts/{a}/locations/{l} (contract §8.4). Populated
                     from the chosen radio on submit; validated server-side
                     against ^accounts/[A-Za-z0-9_-]+$ regardless. --}}
                <input type="hidden" name="provider_account_resource_name" id="provider_account_resource_name"
                       value="{{ $candidates[0]->accountResourceName ?? '' }}">

                <button type="submit" class="btn btn-primary">Link this location</button>
            </form>
        </x-card>

        <script>
            document.querySelectorAll('input[name="provider_location_resource_name"]').forEach(function (input) {
                input.addEventListener('change', function () {
                    document.getElementById('provider_account_resource_name').value = this.dataset.account || '';
                });
            });
        </script>
    @endif
@endsection
