@extends('layouts/contentLayoutMaster')

@section('title', 'Edit ' . $item->name)

@section('content')
    @php
        $scope = [$workspace->uid, $business->uid];
        $withItem = array_merge($scope, [$item->uid]);
    @endphp

    @include('customer.business.catalog._messages')

    <div class="card">
        <div class="card-body">
            <h4 class="card-title">{{ $item->name }}</h4>
            <p class="card-text text-muted">
                @if ($item->isArchived())
                    Archived — not offered anywhere. Make it active again from the list to offer it.
                @else
                    Changes apply everywhere it is offered. A location's own price, if it has one, still wins there.
                @endif
            </p>

            <div class="row">
                <div class="col-12 col-xl-8">
                    <form method="POST" action="{{ route('customer.workspaces.businesses.catalog.update', $withItem) }}" data-role="catalog-edit-form">
                    @csrf

                @include('customer.business.catalog._form')

                    <button type="submit" class="btn btn-primary">Save changes</button>
                    <a href="{{ route('customer.workspaces.businesses.catalog.index', $scope) }}" class="btn btn-outline-secondary">Back to list</a>
                    </form>
                </div>
            </div>
        </div>
    </div>

    @if ($item->isActive())
        <div class="card">
            <div class="card-body">
                <h4 class="card-title">Locations</h4>
                <p class="card-text">
                    Choose which locations offer this, and set a different price at a location.
                    <a href="{{ route('customer.workspaces.businesses.catalog.locations.index', $scope) }}">Manage by location</a>
                </p>
            </div>
        </div>
    @endif
@endsection
