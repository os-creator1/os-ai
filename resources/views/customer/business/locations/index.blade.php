{{--
    Customer Experience Slice 1A — "Physical locations & service areas",
    under Business Settings.

    PLAIN LANGUAGE ONLY. A location here is a physical branch, storefront,
    office or service area inside this business. It is never described as
    another account, another workspace, or a sub-account, and this view
    never renders a catalog key, a counter name, a transition name, a raw
    feature key or any other database terminology.

    Slice 1A owns only this capacity-and-lifecycle surface. The global
    shell, navigation, account switcher and Workspace/Business context
    belong to Slice 1B and are untouched here.
--}}
@extends('layouts/contentLayoutMaster')

@section('title', 'Locations')

@section('content')
    <div class="row mb-2">
        <div class="col-12 d-flex justify-content-between align-items-center flex-wrap">
            <h4 class="mb-0">Locations</h4>
            <span class="text-caption">{{ $business->name }}</span>
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

    {{-- What a location is, and how many are covered. --}}
    <x-card :padded="true" class="mb-2">
        <p class="text-section-heading mb-1">Your locations</p>
        <p class="text-caption mb-2">
            A location is a physical place this business operates from &mdash; a shop, branch,
            office, or an area you travel to serve customers. It is part of this business,
            not a separate account.
        </p>

        @if($capacity->unlimited)
            <p class="mb-0">
                You have <strong>{{ count($activeLocations) }}</strong>
                {{ count($activeLocations) === 1 ? 'open location' : 'open locations' }}.
                Your plan covers as many as you need.
            </p>
        @else
            <p class="mb-1">
                <strong>{{ count($activeLocations) }}</strong> of
                <strong>{{ $capacity->effectiveCapacity }}</strong>
                open {{ $capacity->effectiveCapacity === 1 ? 'location' : 'locations' }} in use.
            </p>
            <p class="text-caption mb-0">
                <span>Your plan includes {{ $capacity->includedSlots }} open {{ $capacity->includedSlots === 1 ? 'location' : 'locations' }}.</span>
                @if($capacity->additionalSlotsAllocated > 0)
                    You have added {{ $capacity->additionalSlotsAllocated }} extra
                    {{ $capacity->additionalSlotsAllocated === 1 ? 'location' : 'locations' }} to your plan.
                @endif
                @if($capacity->grandfatheredSlots > 0)
                    {{ $capacity->grandfatheredSlots }} further
                    {{ $capacity->grandfatheredSlots === 1 ? 'location is' : 'locations are' }}
                    included at no extra cost because {{ $capacity->grandfatheredSlots === 1 ? 'it was' : 'they were' }}
                    already open before your plan changed.
                @endif
            </p>
        @endif
    </x-card>

    {{-- Honest next step when capacity is exhausted. --}}
    @unless($capacity->unlimited)
        @if($capacity->remaining() === 0)
            @if($capacity->denialReason === 'location_slot_limit_exceeded')
                <x-alert variant="warning" class="mb-2">
                    <strong>You have reached the most locations this plan can open.</strong>
                    Your current plan covers up to {{ $capacity->hardMaximum }} open locations.
                    To run more than that, move to the Agency plan.
                </x-alert>
            @else
                <x-alert variant="info" class="mb-2">
                    <strong>All your location slots are in use.</strong>
                    To open another location, add an extra one to your plan below,
                    or close a location you no longer operate from.
                </x-alert>
            @endif
        @endif
    @endunless

    {{-- Open locations. --}}
    <x-card :padded="true" class="mb-2">
        <p class="text-section-heading mb-1">Open locations</p>

        @if(count($activeLocations) === 0)
            <x-empty-state icon="map-pin" title="No open locations yet"
                           description="Add the place this business operates from so it can appear on your website, in your Google listing, and in your opening hours." />
        @else
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th scope="col">Location</th>
                            <th scope="col">Where</th>
                            <th scope="col">Main</th>
                            <th scope="col"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($activeLocations as $location)
                            <tr>
                                <td>{{ $location->name }}</td>
                                <td>{{ $location->city ?? '—' }}</td>
                                <td>@if($location->is_primary)<x-badge variant="success">Main location</x-badge>@endif</td>
                                <td class="text-end">
                                    <form method="POST" action="{{ route('customer.workspaces.businesses.locations.archive', [$workspaceUid, $businessUid]) }}" class="d-inline-flex align-items-end gap-1">
                                        @csrf
                                        <input type="hidden" name="location_uid" value="{{ $location->uid }}">

                                        @if($location->is_primary && count($activeLocations) > 1)
                                            <div>
                                                <label class="form-label text-label" for="new-primary-{{ $location->uid }}">Make this the main location</label>
                                                <select class="form-select" id="new-primary-{{ $location->uid }}" name="new_primary_uid" required>
                                                    <option value="">Choose one&hellip;</option>
                                                    @foreach($activeLocations as $candidate)
                                                        @continue($candidate->uid === $location->uid)
                                                        <option value="{{ $candidate->uid }}">{{ $candidate->name }}</option>
                                                    @endforeach
                                                </select>
                                            </div>
                                        @endif

                                        <button type="submit" class="btn btn-outline-secondary">Close this location</button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="text-caption mb-0">
                Closing a location keeps all of its history and frees the slot for another location.
                Nothing is deleted.
            </p>
        @endif
    </x-card>

    {{-- Closed locations, retained in full. --}}
    @if(count($archivedLocations) > 0)
        <x-card :padded="true" class="mb-2">
            <p class="text-section-heading mb-1">Closed locations</p>
            <p class="text-caption mb-2">
                These are kept for your records and do not use a location slot. You can reopen one at any time
                if your plan has room.
            </p>

            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th scope="col">Location</th>
                            <th scope="col">Where</th>
                            <th scope="col">Closed</th>
                            <th scope="col"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($archivedLocations as $location)
                            <tr>
                                <td>{{ $location->name }}</td>
                                <td>{{ $location->city ?? '—' }}</td>
                                <td>{{ $location->archived_at?->toFormattedDateString() ?? '—' }}</td>
                                <td class="text-end">
                                    <form method="POST" action="{{ route('customer.workspaces.businesses.locations.reactivate', [$workspaceUid, $businessUid]) }}">
                                        @csrf
                                        <input type="hidden" name="location_uid" value="{{ $location->uid }}">
                                        <button type="submit" class="btn btn-outline-primary">Reopen</button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-card>
    @endif

    {{-- Extra locations on the plan. Slice 1A records capacity only and
         never claims a payment was taken. --}}
    @unless($capacity->unlimited)
        <x-card :padded="true" class="mb-2">
            <p class="text-section-heading mb-1">Extra locations on your plan</p>
            <p class="text-caption mb-2">
                Your plan includes {{ $capacity->includedSlots }} open
                {{ $capacity->includedSlots === 1 ? 'location' : 'locations' }}.
                Locations {{ $capacity->includedSlots + 1 }} and {{ $capacity->includedSlots + 2 }} can be added
                for half your plan price each. Beyond that you would need the Agency plan.
                Adding one here updates your plan &mdash; it does not take a payment now.
            </p>

            <div class="d-flex gap-1 flex-wrap">
                @if($capacity->hardMaximum === null || $capacity->includedSlots + $capacity->additionalSlotsAllocated < $capacity->hardMaximum)
                    <form method="POST" action="{{ route('customer.workspaces.businesses.locations.allocations.store', [$workspaceUid, $businessUid]) }}">
                        @csrf
                        <button type="submit" class="btn btn-primary">Add an extra location to my plan</button>
                    </form>
                @endif

                @if($capacity->additionalSlotsAllocated > 0)
                    <form method="POST" action="{{ route('customer.workspaces.businesses.locations.allocations.cancel', [$workspaceUid, $businessUid]) }}">
                        @csrf
                        <button type="submit" class="btn btn-outline-secondary">Remove an extra location</button>
                    </form>
                @endif
            </div>
        </x-card>
    @endunless

    {{-- Add a location. --}}
    <x-card :padded="true">
        <p class="text-section-heading mb-1">Add a location</p>

        <form method="POST" action="{{ route('customer.workspaces.businesses.locations.store', [$workspaceUid, $businessUid]) }}">
            @csrf

            <div class="row">
                <div class="col-md-6 mb-1">
                    <label class="form-label" for="name">Location name</label>
                    <input type="text" class="form-control" id="name" name="name" value="{{ old('name') }}" required
                           placeholder="e.g. High Street shop">
                </div>

                <div class="col-md-6 mb-1">
                    <label class="form-label" for="service_mode">How you serve customers here</label>
                    <select class="form-select" id="service_mode" name="service_mode" required>
                        <option value="storefront" @selected(old('service_mode') === 'storefront')>Customers come to this place</option>
                        <option value="service_area" @selected(old('service_mode') === 'service_area')>We travel to customers</option>
                        <option value="hybrid" @selected(old('service_mode') === 'hybrid')>Both</option>
                    </select>
                </div>

                <div class="col-md-6 mb-1">
                    <label class="form-label" for="address_line_1">Street address</label>
                    <input type="text" class="form-control" id="address_line_1" name="address_line_1" value="{{ old('address_line_1') }}">
                </div>

                <div class="col-md-3 mb-1">
                    <label class="form-label" for="city">Town or city</label>
                    <input type="text" class="form-control" id="city" name="city" value="{{ old('city') }}">
                </div>

                <div class="col-md-3 mb-1">
                    <label class="form-label" for="country_code">Country</label>
                    <input type="text" class="form-control" id="country_code" name="country_code" maxlength="2"
                           value="{{ old('country_code', $business->country_code) }}" required>
                </div>

                <div class="col-12 mb-1">
                    <label class="form-label" for="public_address">Show this address publicly</label>
                    <select class="form-select" id="public_address" name="public_address" required>
                        <option value="1" @selected(old('public_address') === '1')>Yes &mdash; customers can visit us here</option>
                        <option value="0" @selected(old('public_address') === '0')>No &mdash; keep the address private</option>
                    </select>
                </div>
            </div>

            <button type="submit" class="btn btn-primary">Add location</button>
        </form>
    </x-card>
@endsection
