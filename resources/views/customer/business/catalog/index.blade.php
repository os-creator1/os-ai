@extends('layouts/contentLayoutMaster')

@section('title', 'Packages & Products')

@section('page-style')
    @include('partials.cx._styles')
    <style>
        .catalog-order { display: flex; flex-direction: column; gap: .125rem; flex: 0 0 auto; }
        .catalog-order form { margin: 0; }
        .catalog-order .cx-icon-btn { width: 1.5rem; height: 1.25rem; border-color: transparent; background: transparent; }
        .catalog-order-spacer { display: block; width: 1.5rem; height: 1.25rem; }
        .catalog-price { min-width: 9rem; }
        .catalog-price .cx-meta-value { font-weight: 600; }
        @media (max-width: 575.98px) {
            .catalog-price { min-width: 0; }
        }
    </style>
@endsection

@section('content')
    @php
        // Implementation Contract 16 §12.E — presentation only. Every
        // authorization answer (tenancy, capability, entitlement) was decided
        // before this view rendered; nothing here re-derives one, and every
        // control below posts to a route that runs the full chain again.
        $scope = [$workspace->uid, $business->uid];
        $activeUids = $activeItems->pluck('uid')->values()->all();

        // A reorder is submitted as the COMPLETE list of active uids, so each
        // button carries the full order with this row swapped one place. The
        // manager refuses a list that is incomplete or stale; nothing here
        // decides ordering.
        $orderWithSwap = static function (int $a, int $b) use ($activeUids): array {
            $order = $activeUids;
            [$order[$a], $order[$b]] = [$order[$b], $order[$a]];

            return $order;
        };

        // The type filter only narrows what is shown. Reordering is offered on
        // the unfiltered list alone, because a swap is always with the true
        // neighbour in the saved order, which a filtered view may hide.
        $filter = in_array(request()->query('type'), ['package', 'product'], true) ? request()->query('type') : 'all';
        $counts = [
            'all' => $activeItems->count(),
            'package' => $activeItems->filter(fn ($item) => $item->type === \App\Enums\Catalog\CatalogItemType::Package)->count(),
            'product' => $activeItems->filter(fn ($item) => $item->type === \App\Enums\Catalog\CatalogItemType::Product)->count(),
        ];
        $visible = $filter === 'all' ? $activeItems : $activeItems->filter(fn ($item) => $item->type->value === $filter);
        $canReorder = $filter === 'all' && $activeItems->count() > 1;
        $indexUrl = route('customer.workspaces.businesses.catalog.index', $scope);
        $createUrl = route('customer.workspaces.businesses.catalog.create', $scope);
    @endphp

    @include('customer.business.catalog._messages')

    <header class="cx-page-head">
        <div>
            <h1 class="cx-page-title">Packages &amp; Products</h1>
            <p class="cx-page-sub">
                What <strong>{{ $business->name }}</strong> sells, in one list. Each location can choose to offer an item or not,
                and can set its own price — without copying the list.
            </p>
            <div class="cx-page-actions mt-1">
                <x-button variant="secondary" icon="map-pin" :href="route('customer.workspaces.businesses.catalog.locations.index', $scope)" data-role="catalog-locations">
                    Manage by location
                </x-button>
                <x-button variant="primary" icon="plus" :href="$createUrl" data-role="catalog-add">
                    Add package or product
                </x-button>
            </div>
        </div>
    </header>

    <section class="cx-card">
        <div class="cx-card-head">
            <div>
                <h2 class="cx-card-title">Your list</h2>
                <p class="cx-card-sub">{{ $counts['all'] }} {{ \Illuminate\Support\Str::plural('item', $counts['all']) }} · shown to customers in this order</p>
            </div>
            @if ($counts['all'] > 0)
                <nav class="cx-seg" aria-label="Filter by type">
                    <a href="{{ $indexUrl }}" @if ($filter === 'all') aria-current="page" @endif>All <span class="cx-count">{{ $counts['all'] }}</span></a>
                    <a href="{{ $indexUrl }}?type=package" @if ($filter === 'package') aria-current="page" @endif>Packages <span class="cx-count">{{ $counts['package'] }}</span></a>
                    <a href="{{ $indexUrl }}?type=product" @if ($filter === 'product') aria-current="page" @endif>Products <span class="cx-count">{{ $counts['product'] }}</span></a>
                </nav>
            @endif
        </div>

        @if ($activeItems->isEmpty())
            <div class="cx-card-body">
                <p class="mb-0 text-caption" data-role="catalog-empty">Nothing here yet. Add your first package or product.</p>
            </div>
        @elseif ($visible->isEmpty())
            <div class="cx-card-body">
                <p class="mb-0 text-caption">No {{ $filter === 'package' ? 'packages' : 'products' }} yet. <a href="{{ $indexUrl }}">Show everything</a>.</p>
            </div>
        @else
            @if ($filter !== 'all' && $activeItems->count() > 1)
                <p class="text-caption px-2 pt-1 mb-0">To change the order, switch to <a href="{{ $indexUrl }}">All</a>.</p>
            @endif
            <ul class="cx-list" data-role="catalog-active">
                @foreach ($visible as $item)
                    @php
                        $index = array_search($item->uid, $activeUids, true);
                        $isPackage = $item->type === \App\Enums\Catalog\CatalogItemType::Package;
                        $editUrl = route('customer.workspaces.businesses.catalog.edit', array_merge($scope, [$item->uid]));
                    @endphp
                    <li class="cx-row" data-item="{{ $item->uid }}">
                        <div class="cx-row-main">
                            @if ($canReorder)
                                <div class="catalog-order">
                                    @if ($index > 0)
                                        <form method="POST" action="{{ route('customer.workspaces.businesses.catalog.reorder', $scope) }}">
                                            @csrf
                                            @foreach ($orderWithSwap($index, $index - 1) as $uid)
                                                <input type="hidden" name="order[]" value="{{ $uid }}">
                                            @endforeach
                                            <button type="submit" class="cx-icon-btn" data-role="move-up" aria-label="Move {{ $item->name }} up">
                                                <x-ds-icon name="chevron-up" size="14" aria-hidden="true" />
                                            </button>
                                        </form>
                                    @else
                                        <span class="catalog-order-spacer" aria-hidden="true"></span>
                                    @endif
                                    @if ($index < count($activeUids) - 1)
                                        <form method="POST" action="{{ route('customer.workspaces.businesses.catalog.reorder', $scope) }}">
                                            @csrf
                                            @foreach ($orderWithSwap($index, $index + 1) as $uid)
                                                <input type="hidden" name="order[]" value="{{ $uid }}">
                                            @endforeach
                                            <button type="submit" class="cx-icon-btn" data-role="move-down" aria-label="Move {{ $item->name }} down">
                                                <x-ds-icon name="chevron-down" size="14" aria-hidden="true" />
                                            </button>
                                        </form>
                                    @else
                                        <span class="catalog-order-spacer" aria-hidden="true"></span>
                                    @endif
                                </div>
                            @endif
                            <span class="cx-tile {{ $isPackage ? '' : 'is-info' }}" aria-hidden="true">
                                <x-ds-icon :name="$isPackage ? 'package' : 'tag'" size="17" />
                            </span>
                            <span class="cx-row-text">
                                <a href="{{ $editUrl }}" class="cx-row-title">{{ $item->name }}</a>
                                <span class="cx-pill {{ $isPackage ? 'is-accent' : 'is-info' }} mt-25">{{ ucfirst($item->type->value) }}</span>
                            </span>
                        </div>
                        <div class="cx-row-meta">
                            <div class="cx-meta catalog-price">
                                <span class="cx-meta-label">Price</span>
                                @if ($item->price_minor === null)
                                    <span class="cx-meta-value">Quote only</span>
                                @else
                                    <span class="cx-meta-value">{{ \App\Library\Catalog\CatalogMoney::format($item->price_minor, $item->currency_code) }}</span>
                                    @if (strtoupper((string) $item->currency_code) !== strtoupper((string) $business->currency_code))
                                        <small class="d-block text-warning" data-role="currency-mismatch">
                                            Not in your business currency ({{ strtoupper((string) $business->currency_code) }}), so it can't be added to a quote or invoice.
                                        </small>
                                    @endif
                                @endif
                            </div>
                        </div>
                        <div class="cx-row-actions">
                            <a href="{{ $editUrl }}" class="cx-link-btn"><x-ds-icon name="pencil" size="14" aria-hidden="true" />Edit</a>
                            <form method="POST" action="{{ route('customer.workspaces.businesses.catalog.archive', array_merge($scope, [$item->uid])) }}" class="d-inline">
                                @csrf
                                <button type="submit" class="cx-link-btn" data-role="archive" aria-label="Archive {{ $item->name }}">
                                    <x-ds-icon name="archive" size="14" aria-hidden="true" />Archive
                                </button>
                            </form>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif

        <a href="{{ $createUrl }}" class="cx-add-row"><x-ds-icon name="plus" size="15" aria-hidden="true" />Add package or product</a>
    </section>

    @if ($archivedItems->isNotEmpty())
        <section class="cx-card">
            <div class="cx-card-head">
                <div>
                    <h2 class="cx-card-title">Archived</h2>
                    <p class="cx-card-sub">Archived items are not offered anywhere. Past quotes and invoices keep the price they were made with.</p>
                </div>
            </div>
            <ul class="cx-list" data-role="catalog-archived">
                @foreach ($archivedItems as $item)
                    <li class="cx-row" data-item="{{ $item->uid }}">
                        <div class="cx-row-main">
                            <span class="cx-tile is-neutral" aria-hidden="true"><x-ds-icon name="archive" size="16" /></span>
                            <span class="cx-row-text">
                                <span class="cx-row-title">{{ $item->name }}</span>
                                <span class="cx-row-sub">{{ ucfirst($item->type->value) }}</span>
                            </span>
                        </div>
                        <div class="cx-row-actions">
                            <form method="POST" action="{{ route('customer.workspaces.businesses.catalog.reactivate', array_merge($scope, [$item->uid])) }}" class="d-inline">
                                @csrf
                                <button type="submit" class="btn btn-sm btn-outline-primary" data-role="reactivate">Make active again</button>
                            </form>
                        </div>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif
@endsection
