@extends('layouts/contentLayoutMaster')

@section('title', 'Packages & Products by location')

@section('content')
    @php
        // Implementation Contract 16 §6, §12.E — `$locations` is ALREADY
        // filtered to the Locations this actor may reach (the controller builds
        // it from LocationAccessGuard). Nothing here can list, count or name
        // any other Location, and no total is shown, because a total would
        // disclose how many Locations the actor cannot reach.
        $scope = [$workspace->uid, $business->uid];
    @endphp

    @include('customer.business.catalog._messages')

    <div class="card">
        <div class="card-body">
            <h4 class="card-title">Packages &amp; Products by location</h4>
            <p class="card-text text-muted">
                Choose a location to decide which items it offers and what it charges.
            </p>

            @if ($locations->isEmpty())
                <p class="mb-0" data-role="catalog-no-locations">You don't have access to any locations for this business.</p>
            @else
                <ul class="list-group mb-2" data-role="catalog-location-list">
                    @foreach ($locations as $location)
                        <li class="list-group-item d-flex justify-content-between align-items-center" data-location="{{ $location->uid }}">
                            <span>
                                {{ $location->name ?: 'Unnamed location' }}
                                @if ($location->isArchived())
                                    <span class="badge badge-light-secondary">Archived</span>
                                @endif
                            </span>
                            <a href="{{ route('customer.workspaces.businesses.catalog.locations.show', array_merge($scope, [$location->uid])) }}">Manage</a>
                        </li>
                    @endforeach
                </ul>
            @endif

            <a href="{{ route('customer.workspaces.businesses.catalog.index', $scope) }}">Back to the catalog</a>
        </div>
    </div>
@endsection
