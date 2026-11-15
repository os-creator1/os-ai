@extends('layouts/contentLayoutMaster')

@section('title', 'Packages & Products')

@section('page-style')
    @include('partials.cx._styles')
    @include('customer.business.catalog._styles')
    <link href="https://fonts.googleapis.com/css2?family=Figtree:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        /* Packages & Products — values from the approved design (Catalog.dc.html). Scoped to .cat-page. */
        .cat-page { --cat-brand: #B4514A; --cat-brand-d: #8F3B35; --cat-ink: #1F1A19; --cat-mute: #5E5652; display: flex; flex-direction: column; gap: 20px; font-family: 'Figtree', var(--font-family-app, system-ui), system-ui, -apple-system, 'Segoe UI', sans-serif; color: var(--cat-ink); }
        .cat-page > * { margin-bottom: 0 !important; }
        .cat-page a:hover { text-decoration: none; }
        .cat-head { display: flex; flex-wrap: wrap; align-items: flex-end; justify-content: space-between; gap: 16px; }
        .cat-head-text { display: flex; flex-direction: column; gap: 6px; }
        .cat-title { margin: 0; font-size: 28px; line-height: 1.2; font-weight: 700; letter-spacing: -.015em; color: var(--cat-ink); }
        .cat-sub { margin: 0; max-width: 700px; font-size: 15px; line-height: 1.45; color: var(--cat-mute); }
        .cat-sub strong { font-weight: 600; color: var(--cat-ink); }
        .cat-actions { display: flex; flex-wrap: wrap; gap: 10px; }
        .cat-btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; min-height: 44px; padding: 0 16px; border: 0; border-radius: 10px; font: inherit; font-size: 14px; font-weight: 600; line-height: 1.2; white-space: nowrap; cursor: pointer; }
        .cat-btn:focus-visible, .cat-arch:focus-visible, .cat-mv button:focus-visible, .cat-add:focus-visible, .cat-seg a:focus-visible, .cat-name:focus-visible { outline: 2px solid var(--cat-brand); outline-offset: 2px; }
        .cat-btn.is-quiet { border: 1px solid #D6CFC8; background: #fff; color: #2E2826; }
        .cat-btn.is-quiet:hover { background: #FBF9F7; color: #2E2826; }
        .cat-btn.is-primary { background: var(--cat-brand); color: #fff; }
        .cat-btn.is-primary:hover { background: var(--cat-brand-d); color: #fff; }
        .cat-ic { width: 16px; height: 16px; flex: none; }

        .cat-card { overflow: hidden; background: #fff; border: 1px solid #E9E4DF; border-radius: 14px; }
        .cat-card-head { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px; padding: 16px 20px; }
        .cat-card-head h2 { margin: 0; font-size: 17px; font-weight: 700; color: var(--cat-ink); }
        .cat-card-head p { margin: 2px 0 0; font-size: 13px; color: var(--cat-mute); }
        .cat-seg { display: flex; align-items: center; gap: 4px; padding: 3px; border-radius: 10px; background: #EFEBE6; }
        .cat-seg a { display: inline-flex; align-items: center; gap: 4px; height: 38px; padding: 0 14px; border-radius: 8px; font-size: 13.5px; font-weight: 500; white-space: nowrap; color: var(--cat-mute); }
        .cat-seg a:hover { color: var(--cat-ink); }
        .cat-seg a[aria-current="page"] { background: #fff; box-shadow: 0 1px 2px rgba(31, 26, 25, .12); font-weight: 600; color: var(--cat-ink); }
        .cat-note { margin: 0; padding: 0 20px 12px; font-size: 13px; color: var(--cat-mute); }
        .cat-empty { margin: 0; padding: 8px 20px 24px; font-size: 14px; color: var(--cat-mute); }

        .cat-list { margin: 0; padding: 0; list-style: none; }
        .cat-item { display: flex; flex-wrap: wrap; align-items: center; gap: 14px; min-height: 76px; padding: 12px 16px 12px 8px; border-top: 1px solid #EEE9E4; }
        .cat-item:hover { background: #FBF9F7; }
        .cat-item.is-static { padding-left: 20px; }
        .cat-mv { display: flex; flex-direction: column; flex: none; }
        .cat-mv form { margin: 0; }
        .cat-mv button { display: inline-flex; align-items: center; justify-content: center; width: 44px; height: 26px; padding: 0; border: 0; border-radius: 6px; background: transparent; color: var(--cat-mute); cursor: pointer; }
        .cat-mv button:hover { background: #EFEBE6; color: var(--cat-ink); }
        .cat-mv button[disabled] { color: #CFC6BE; cursor: default; background: transparent; }
        .cat-tile { display: flex; align-items: center; justify-content: center; width: 44px; height: 44px; flex: none; border-radius: 12px; background: #F8E9E6; color: var(--cat-brand-d); }
        .cat-tile.is-product { background: #E4EEF6; color: #1F5682; }
        .cat-tile.is-neutral { background: #EDE8E3; color: #4A4340; }
        .cat-tile .ds-icon { width: 20px; height: 20px; }
        .cat-text { display: flex; flex-direction: column; align-items: flex-start; gap: 6px; flex: 2 1 240px; min-width: 0; }
        .cat-page .cat-name { font-size: 16px; font-weight: 700; color: var(--cat-ink); overflow-wrap: anywhere; }
        .cat-page a.cat-name:hover { color: var(--cat-brand-d); }
        .cat-page .cat-add, .cat-page .cat-add:hover { color: var(--cat-brand-d); }
        .cat-page .cat-seg a { color: var(--cat-mute); }
        .cat-page .cat-seg a[aria-current="page"] { color: var(--cat-ink); }
        .cat-page .cat-btn.is-quiet { color: #2E2826; }
        .cat-page .cat-btn.is-primary { color: #fff; }
        .cat-pill { display: inline-flex; align-items: center; height: 26px; padding: 0 10px; border-radius: 999px; font-size: 12.5px; font-weight: 600; background: #F8E9E6; color: var(--cat-brand-d); }
        .cat-pill.is-product { background: #E4EEF6; color: #1F5682; }
        .cat-price { display: flex; flex-direction: column; gap: 3px; flex: 1 1 120px; min-width: 0; }
        .cat-price-label { font-size: 11.5px; font-weight: 600; letter-spacing: .06em; text-transform: uppercase; color: var(--cat-mute); }
        .cat-price-value { font-size: 14.5px; font-weight: 600; }
        .cat-price small { font-size: 12.5px; color: #7A4A05; }
        .cat-item form { margin: 0; }
        .cat-arch { display: inline-flex; align-items: center; gap: 6px; min-height: 44px; padding: 0 12px; border: 0; border-radius: 10px; background: transparent; font: inherit; font-size: 13.5px; font-weight: 600; color: var(--cat-mute); cursor: pointer; }
        .cat-arch:hover { background: #EFEBE6; color: var(--cat-ink); }
        .cat-arch.is-restore { color: var(--cat-brand-d); }
        .cat-add { display: flex; align-items: center; justify-content: center; gap: 8px; min-height: 56px; border-top: 1px solid #EEE9E4; background: #FBF9F7; font-size: 14.5px; font-weight: 600; color: var(--cat-brand-d); }
        .cat-add:hover { background: #F8E9E6; color: var(--cat-brand-d); }
        .cat-row-sub { font-size: 13px; color: var(--cat-mute); }

        @media (max-width: 575.98px) {
            .cat-title { font-size: 24px; }
            .cat-actions, .cat-actions .cat-btn { width: 100%; }
            .cat-card-head { padding: 14px 16px; }
            .cat-item { padding-right: 12px; }
            .cat-text { flex-basis: 140px; }
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

    <div class="cat-page">
    @include('customer.business.catalog._messages')

    <header class="cat-head">
        <div class="cat-head-text">
            <h1 class="cat-title">Packages &amp; Products</h1>
            <p class="cat-sub">
                What <strong>{{ $business->name }}</strong> sells, in one list. Each location can choose to offer an item or not,
                and can set its own price.
            </p>
        </div>
        <div class="cat-actions">
            <a class="cat-btn is-quiet" href="{{ route('customer.workspaces.businesses.catalog.locations.index', $scope) }}" data-role="catalog-locations"><x-ds-icon name="map-pin" class="cat-ic" size="16" aria-hidden="true" />Manage by location</a>
            <a class="cat-btn is-primary" href="{{ $createUrl }}" data-role="catalog-add" data-catalog-add><x-ds-icon name="plus" class="cat-ic" size="16" aria-hidden="true" />Add package or product</a>
        </div>
    </header>

    <section class="cat-card" aria-label="Catalog">
        <div class="cat-card-head">
            <div>
                <h2>Your list</h2>
                <p>{{ $counts['all'] }} {{ \Illuminate\Support\Str::plural('item', $counts['all']) }} · shown to customers in this order</p>
            </div>
            @if ($counts['all'] > 0)
                <nav class="cat-seg" aria-label="Filter by type">
                    <a href="{{ $indexUrl }}" @if ($filter === 'all') aria-current="page" @endif>All {{ $counts['all'] }}</a>
                    <a href="{{ $indexUrl }}?type=package" @if ($filter === 'package') aria-current="page" @endif>Packages {{ $counts['package'] }}</a>
                    <a href="{{ $indexUrl }}?type=product" @if ($filter === 'product') aria-current="page" @endif>Products {{ $counts['product'] }}</a>
                </nav>
            @endif
        </div>

        @if ($activeItems->isEmpty())
            <p class="cat-empty" data-role="catalog-empty">Nothing here yet. Add your first package or product.</p>
        @elseif ($visible->isEmpty())
            <p class="cat-empty">No {{ $filter === 'package' ? 'packages' : 'products' }} yet. <a href="{{ $indexUrl }}">Show everything</a>.</p>
        @else
            @if ($filter !== 'all' && $activeItems->count() > 1)
                <p class="cat-note">To change the order, switch to <a href="{{ $indexUrl }}">All</a>.</p>
            @endif
            <ul class="cat-list" data-role="catalog-active">
                @foreach ($visible as $item)
                    @php
                        $index = array_search($item->uid, $activeUids, true);
                        $isPackage = $item->type === \App\Enums\Catalog\CatalogItemType::Package;
                        $editUrl = route('customer.workspaces.businesses.catalog.edit', array_merge($scope, [$item->uid]));
                    @endphp
                    <li class="cat-item {{ $canReorder ? '' : 'is-static' }}" data-item="{{ $item->uid }}">
                        @if ($canReorder)
                            <div class="cat-mv">
                                @if ($index > 0)
                                    <form method="POST" action="{{ route('customer.workspaces.businesses.catalog.reorder', $scope) }}">
                                        @csrf
                                        @foreach ($orderWithSwap($index, $index - 1) as $uid)
                                            <input type="hidden" name="order[]" value="{{ $uid }}">
                                        @endforeach
                                        <button type="submit" data-role="move-up" aria-label="Move {{ $item->name }} up"><x-ds-icon name="chevron-up" class="cat-ic" size="16" aria-hidden="true" /></button>
                                    </form>
                                @else
                                    <button type="button" disabled aria-label="Move {{ $item->name }} up (already first)"><x-ds-icon name="chevron-up" class="cat-ic" size="16" aria-hidden="true" /></button>
                                @endif
                                @if ($index < count($activeUids) - 1)
                                    <form method="POST" action="{{ route('customer.workspaces.businesses.catalog.reorder', $scope) }}">
                                        @csrf
                                        @foreach ($orderWithSwap($index, $index + 1) as $uid)
                                            <input type="hidden" name="order[]" value="{{ $uid }}">
                                        @endforeach
                                        <button type="submit" data-role="move-down" aria-label="Move {{ $item->name }} down"><x-ds-icon name="chevron-down" class="cat-ic" size="16" aria-hidden="true" /></button>
                                    </form>
                                @else
                                    <button type="button" disabled aria-label="Move {{ $item->name }} down (already last)"><x-ds-icon name="chevron-down" class="cat-ic" size="16" aria-hidden="true" /></button>
                                @endif
                            </div>
                        @endif
                        <span class="cat-tile {{ $isPackage ? '' : 'is-product' }}" aria-hidden="true">
                            <x-ds-icon :name="$isPackage ? 'package' : 'tag'" size="20" />
                        </span>
                        <span class="cat-text">
                            <a href="{{ $editUrl }}" class="cat-name">{{ $item->name }}</a>
                            <span class="cat-pill {{ $isPackage ? '' : 'is-product' }}">{{ ucfirst($item->type->value) }}</span>
                        </span>
                        <div class="cat-price">
                            <span class="cat-price-label">Price</span>
                            @if ($item->price_minor === null)
                                <span class="cat-price-value">Quote only</span>
                            @else
                                <span class="cat-price-value">{{ \App\Library\Catalog\CatalogMoney::format($item->price_minor, $item->currency_code) }}</span>
                                @if (strtoupper((string) $item->currency_code) !== strtoupper((string) $business->currency_code))
                                    <small data-role="currency-mismatch">
                                        Not in your business currency ({{ strtoupper((string) $business->currency_code) }}), so it can't be added to a quote or invoice.
                                    </small>
                                @endif
                            @endif
                        </div>
                        <form method="POST" action="{{ route('customer.workspaces.businesses.catalog.archive', array_merge($scope, [$item->uid])) }}">
                            @csrf
                            <button type="submit" class="cat-arch" data-role="archive" aria-label="Archive {{ $item->name }}"><x-ds-icon name="archive" class="cat-ic" size="16" aria-hidden="true" />Archive</button>
                        </form>
                    </li>
                @endforeach
            </ul>
        @endif

        <a href="{{ $createUrl }}" class="cat-add" data-catalog-add><x-ds-icon name="plus" class="cat-ic" size="16" aria-hidden="true" />Add package or product</a>
    </section>

    @if ($archivedItems->isNotEmpty())
        <section class="cat-card" aria-label="Archived">
            <div class="cat-card-head">
                <div>
                    <h2>Archived</h2>
                    <p>Archived items are not offered anywhere. Past quotes and invoices keep the price they were made with.</p>
                </div>
            </div>
            <ul class="cat-list" data-role="catalog-archived">
                @foreach ($archivedItems as $item)
                    <li class="cat-item is-static" data-item="{{ $item->uid }}">
                        <span class="cat-tile is-neutral" aria-hidden="true"><x-ds-icon name="archive" size="20" /></span>
                        <span class="cat-text">
                            <span class="cat-name">{{ $item->name }}</span>
                            <span class="cat-row-sub">{{ ucfirst($item->type->value) }}</span>
                        </span>
                        <form method="POST" action="{{ route('customer.workspaces.businesses.catalog.reactivate', array_merge($scope, [$item->uid])) }}">
                            @csrf
                            <button type="submit" class="cat-arch is-restore" data-role="reactivate">Make active again</button>
                        </form>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    {{-- The "Add to catalog" dialog. The links above still point at the full-page create URL (non-JavaScript fallback). --}}
    <div class="cg" style="display: contents">
        @include('customer.business.catalog._add-modal')
    </div>
    </div>
@endsection
