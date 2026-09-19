@extends('layouts/contentLayoutMaster')

@section('title', 'Packages & Products at ' . ($location->name ?: 'this location'))

@section('content')
    @php
        // Implementation Contract 16 §5.2, §12.E — presentation only. The
        // "effective price" column is whatever CatalogItemPricingResolver
        // answered (via CatalogLocationOfferReader); this view never computes a
        // price or decides whether an item is offered. Every form posts to a
        // route that runs the full §6 chain, including LocationAccessGuard.
        $scope = [$workspace->uid, $business->uid];
        $locationScope = array_merge($scope, [$location->uid]);
    @endphp

    @include('customer.business.catalog._messages')

    <div class="card">
        <div class="card-body">
            <h4 class="card-title">{{ $location->name ?: 'This location' }}</h4>
            <p class="card-text text-muted">
                Turn an item off to stop offering it here, or set a price that applies only at this location.
                Leave the price blank to use the business-wide price.
            </p>

            @if ($rows->isEmpty())
                <p class="mb-0" data-role="catalog-empty">There is nothing in the catalog to offer yet.</p>
            @else
                <div class="table-responsive">
                    <table class="table" data-role="catalog-location-offers">
                        <thead>
                            <tr>
                                <th>Item</th>
                                <th>Business-wide price</th>
                                <th>Price here</th>
                                <th>Offered here</th>
                                <th>Location price</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rows as $row)
                                @php
                                    $item = $row['item'];
                                    $hasFixedPrice = $item->price_minor !== null;
                                    $withItem = array_merge($locationScope, [$item->uid]);
                                @endphp
                                <tr data-item="{{ $item->uid }}" data-offered="{{ $row['isOffered'] ? 'yes' : 'no' }}">
                                    <td>{{ $item->name }}</td>
                                    <td>
                                        @if ($hasFixedPrice)
                                            {{ \App\Library\Catalog\CatalogMoney::format($item->price_minor, $item->currency_code) }}
                                        @else
                                            Quote only
                                        @endif
                                    </td>
                                    <td data-role="effective-price">
                                        @if (! $row['isOffered'])
                                            <span class="text-muted">Not offered here</span>
                                        @elseif ($row['price']->isQuoteOnly)
                                            Quote only
                                        @else
                                            {{ \App\Library\Catalog\CatalogMoney::format($row['price']->priceMinor, $row['price']->currencyCode) }}
                                            @if ($row['hasOverridePrice'])
                                                <span class="badge badge-light-info">Location price</span>
                                            @endif
                                        @endif
                                    </td>
                                    <td>
                                        <form method="POST" action="{{ route('customer.workspaces.businesses.catalog.locations.enabled', $withItem) }}">
                                            @csrf
                                            <input type="hidden" name="is_enabled" value="{{ $row['isOffered'] ? 0 : 1 }}">
                                            <button type="submit" class="btn btn-sm {{ $row['isOffered'] ? 'btn-outline-danger' : 'btn-outline-primary' }}" data-role="toggle-offered">
                                                {{ $row['isOffered'] ? 'Stop offering' : 'Offer here' }}
                                            </button>
                                        </form>
                                    </td>
                                    <td>
                                        @if ($hasFixedPrice)
                                            <form method="POST" action="{{ route('customer.workspaces.businesses.catalog.locations.price', $withItem) }}" class="form-inline">
                                                @csrf
                                                <input type="text" inputmode="decimal" name="price" class="form-control form-control-sm mr-1" style="max-width: 8rem"
                                                       value="{{ \App\Library\Catalog\CatalogMoney::toInput($row['override']?->price_minor_override, $item->currency_code) }}"
                                                       placeholder="{{ \App\Library\Catalog\CatalogMoney::toInput($item->price_minor, $item->currency_code) }}"
                                                       aria-label="Price at this location for {{ $item->name }}" autocomplete="off">
                                                <button type="submit" class="btn btn-sm btn-outline-secondary" data-role="save-price">Save</button>
                                            </form>
                                        @else
                                            <span class="text-muted">Add a business-wide price first</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            <a href="{{ route('customer.workspaces.businesses.catalog.locations.index', $scope) }}">All locations</a>
            &middot;
            <a href="{{ route('customer.workspaces.businesses.catalog.index', $scope) }}">Back to the catalog</a>
        </div>
    </div>
@endsection
