@extends('layouts/contentLayoutMaster')

@section('title', 'Prices by location')

@section('page-style')
    @include('customer.business.catalog._styles')
@endsection

@section('content')
    @php
        // Implementation Contract 16 §6, §12.E — `$cards` holds ONLY the
        // Locations this actor may reach (the controller builds them from
        // LocationAccessGuard), each with counts taken from
        // CatalogLocationOfferReader. Nothing here can list, count or name any
        // other Location, and no total is shown, because a total would disclose
        // how many Locations the actor cannot reach. No price is computed here.
        $scope = [$workspace->uid, $business->uid];
    @endphp

    @include('customer.business.catalog._messages')

    <div class="cg">
        <div class="cg-stack">
            <a href="{{ route('customer.workspaces.businesses.catalog.index', $scope) }}" class="cg-back" data-role="catalog-back">
                <x-ds-icon name="arrow-left" size="14" aria-hidden="true" />Packages &amp; Products
            </a>
            <h1 class="cg-title is-sub">Prices by location</h1>
            <p class="cg-sub is-lg">Choose what each location offers and what it charges.</p>
        </div>

        @if ($cards->isEmpty())
            <p class="cg-empty mb-0" data-role="catalog-no-locations">You don't have access to any locations for this business.</p>
        @else
            <section class="cg-card" data-role="catalog-location-list">
                @foreach ($cards as $card)
                    @php
                        $location = $card['location'];
                        $itemWord = \Illuminate\Support\Str::plural('item', $card['offered']);
                        $manageUrl = route('customer.workspaces.businesses.catalog.locations.show', array_merge($scope, [$location->uid]));
                    @endphp
                    <div class="cg-loc" data-location="{{ $location->uid }}">
                        <span class="cg-tile" aria-hidden="true"><x-ds-icon name="map-pin" size="20" /></span>
                        <div class="cg-loc-text">
                            <b class="cg-loc-name">
                                {{ $location->name ?: 'Unnamed location' }}
                                @if ($location->isArchived())
                                    <span class="cg-tag">Archived</span>
                                @endif
                            </b>
                            <span class="cg-loc-meta" data-role="location-summary">
                                @if ($card['total'] === 0)
                                    No items in your catalog yet
                                @elseif ($card['offered'] === 0)
                                    No items offered
                                @elseif ($card['offered'] === $card['total'])
                                    All {{ $card['total'] }} {{ $itemWord }} offered
                                @else
                                    {{ $card['offered'] }} of {{ $card['total'] }} items offered
                                @endif
                                @if ($card['offered'] > 0)
                                    &middot;
                                    @if ($card['ownPrices'] === 0)
                                        Same prices as your catalog
                                    @else
                                        {{ $card['ownPrices'] }} {{ \Illuminate\Support\Str::plural('item', $card['ownPrices']) }} with {{ $card['ownPrices'] === 1 ? 'its' : 'their' }} own {{ \Illuminate\Support\Str::plural('price', $card['ownPrices']) }}
                                    @endif
                                @endif
                            </span>
                        </div>
                        <a href="{{ $manageUrl }}" class="cg-btn is-outline" data-role="manage-location" aria-label="Manage prices for {{ $location->name ?: 'Unnamed location' }}">
                            Manage<x-ds-icon name="chevron-right" size="16" aria-hidden="true" />
                        </a>
                    </div>
                @endforeach
            </section>
        @endif

        @if ($canAddLocation)
            <div class="cg-hint" data-role="catalog-add-location">
                <x-ds-icon name="plus" size="16" aria-hidden="true" />
                <span>Have another location? Add it in Settings to give it its own prices.</span>
                <a href="{{ route('customer.workspaces.businesses.locations.create', $scope) }}">Add location</a>
            </div>
        @endif
    </div>
@endsection
