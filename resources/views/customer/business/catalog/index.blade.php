@extends('layouts/contentLayoutMaster')

@section('title', 'Packages & Products')

@section('page-style')
    @include('customer.business.catalog._styles')
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

    <div class="cg">
        <header class="cg-head">
            <div class="cg-head-text">
                <h1 class="cg-title">Packages &amp; Products</h1>
                <p class="cg-sub">{{ $counts['all'] }} {{ \Illuminate\Support\Str::plural('item', $counts['all']) }} in your catalog</p>
            </div>
            <div class="cg-head-actions">
                <a href="{{ route('customer.workspaces.businesses.catalog.locations.index', $scope) }}" class="cg-btn is-outline" data-role="catalog-locations">
                    <x-ds-icon name="map-pin" size="16" aria-hidden="true" />Prices by location
                </a>
                <a href="{{ $createUrl }}" class="cg-btn is-primary" data-role="catalog-add" data-catalog-add>
                    <x-ds-icon name="plus" size="16" aria-hidden="true" />Add item
                </a>
            </div>
        </header>

        @if ($counts['all'] > 0)
            <nav class="cg-seg is-sm is-filter" aria-label="Filter by type" style="align-self:flex-start">
                <a href="{{ $indexUrl }}" @if ($filter === 'all') aria-current="page" @endif>All <span class="cg-count">{{ $counts['all'] }}</span></a>
                <a href="{{ $indexUrl }}?type=package" @if ($filter === 'package') aria-current="page" @endif>Packages <span class="cg-count">{{ $counts['package'] }}</span></a>
                <a href="{{ $indexUrl }}?type=product" @if ($filter === 'product') aria-current="page" @endif>Products <span class="cg-count">{{ $counts['product'] }}</span></a>
            </nav>
        @endif

        @if ($activeItems->isEmpty())
            <p class="cg-empty mb-0" data-role="catalog-empty">Nothing here yet. Add your first package or product.</p>
        @elseif ($visible->isEmpty())
            <p class="cg-empty mb-0">No {{ $filter === 'package' ? 'packages' : 'products' }} yet. <a href="{{ $indexUrl }}">Show everything</a>.</p>
        @else
            @if ($filter !== 'all' && $activeItems->count() > 1)
                <p class="cg-sub mb-0">To change the order, switch to <a href="{{ $indexUrl }}">All</a>.</p>
            @endif
            <ul class="cg-grid" data-role="catalog-active">
                @foreach ($visible as $item)
                    @php
                        $index = array_search($item->uid, $activeUids, true);
                        $editUrl = route('customer.workspaces.businesses.catalog.edit', array_merge($scope, [$item->uid]));
                    @endphp
                    <li class="cg-item" data-item="{{ $item->uid }}">
                        <span class="cg-kind">{{ ucfirst($item->type->value) }}</span>
                        <a href="{{ $editUrl }}" class="cg-name">{{ $item->name }}</a>
                        @if (filled($item->description))
                            <span class="cg-desc">{{ $item->description }}</span>
                        @endif
                        @if ($item->price_minor === null)
                            <b class="cg-price">Quote only</b>
                        @else
                            <b class="cg-price">{{ \App\Library\Catalog\CatalogMoney::format($item->price_minor, $item->currency_code) }}</b>
                            @if (strtoupper((string) $item->currency_code) !== strtoupper((string) $business->currency_code))
                                <small class="cg-note" data-role="currency-mismatch">
                                    Not in your business currency ({{ strtoupper((string) $business->currency_code) }}), so it can't be added to a quote or invoice.
                                </small>
                            @endif
                        @endif
                        <div class="cg-tools">
                            @if ($canReorder)
                                @if ($index > 0)
                                    <form method="POST" action="{{ route('customer.workspaces.businesses.catalog.reorder', $scope) }}">
                                        @csrf
                                        @foreach ($orderWithSwap($index, $index - 1) as $uid)
                                            <input type="hidden" name="order[]" value="{{ $uid }}">
                                        @endforeach
                                        <button type="submit" class="cg-iconbtn" data-role="move-up" aria-label="Move {{ $item->name }} up">
                                            <x-ds-icon name="chevron-up" size="14" aria-hidden="true" />
                                        </button>
                                    </form>
                                @endif
                                @if ($index < count($activeUids) - 1)
                                    <form method="POST" action="{{ route('customer.workspaces.businesses.catalog.reorder', $scope) }}">
                                        @csrf
                                        @foreach ($orderWithSwap($index, $index + 1) as $uid)
                                            <input type="hidden" name="order[]" value="{{ $uid }}">
                                        @endforeach
                                        <button type="submit" class="cg-iconbtn" data-role="move-down" aria-label="Move {{ $item->name }} down">
                                            <x-ds-icon name="chevron-down" size="14" aria-hidden="true" />
                                        </button>
                                    </form>
                                @endif
                            @endif
                            <span class="cg-spacer"></span>
                            <a href="{{ $editUrl }}" class="cg-link"><x-ds-icon name="pencil" size="14" aria-hidden="true" />Edit</a>
                            <form method="POST" action="{{ route('customer.workspaces.businesses.catalog.archive', array_merge($scope, [$item->uid])) }}">
                                @csrf
                                <button type="submit" class="cg-link" data-role="archive" aria-label="Archive {{ $item->name }}">
                                    <x-ds-icon name="archive" size="14" aria-hidden="true" />Archive
                                </button>
                            </form>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif

        @if ($archivedItems->isNotEmpty())
            <section>
                <h2 class="cg-section-title">Archived</h2>
                <p class="cg-section-sub">Archived items are not offered anywhere. Past quotes and invoices keep the price they were made with.</p>
                <ul class="cg-grid mt-1" data-role="catalog-archived">
                    @foreach ($archivedItems as $item)
                        <li class="cg-item is-muted" data-item="{{ $item->uid }}">
                            <span class="cg-kind">{{ ucfirst($item->type->value) }}</span>
                            <span class="cg-name">{{ $item->name }}</span>
                            <div class="cg-tools">
                                <form method="POST" action="{{ route('customer.workspaces.businesses.catalog.reactivate', array_merge($scope, [$item->uid])) }}">
                                    @csrf
                                    <button type="submit" class="cg-btn is-outline is-sm" data-role="reactivate">Make active again</button>
                                </form>
                            </div>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        @include('customer.business.catalog._add-modal')
    </div>
@endsection
