@extends('layouts/contentLayoutMaster')

@section('title', 'Packages & Products')

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
    @endphp

    @include('customer.business.catalog._messages')

    <div class="card">
        <div class="card-body">
            <h4 class="card-title">Packages &amp; Products</h4>
            <p class="card-text text-muted">
                What <strong>{{ $business->name }}</strong> sells, in one list. Each location can choose to offer an item or not,
                and can set its own price — without copying the list.
            </p>

            <a href="{{ route('customer.workspaces.businesses.catalog.create', $scope) }}" class="btn btn-primary mb-2" data-role="catalog-add">
                Add package or product
            </a>
            <a href="{{ route('customer.workspaces.businesses.catalog.locations.index', $scope) }}" class="btn btn-outline-secondary mb-2" data-role="catalog-locations">
                Manage by location
            </a>

            @if ($activeItems->isEmpty())
                <p class="mb-0" data-role="catalog-empty">Nothing here yet. Add your first package or product.</p>
            @else
                <div class="table-responsive">
                    <table class="table" data-role="catalog-active">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Type</th>
                                <th>Price</th>
                                <th>Order</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($activeItems as $index => $item)
                                <tr data-item="{{ $item->uid }}">
                                    <td>
                                        <a href="{{ route('customer.workspaces.businesses.catalog.edit', array_merge($scope, [$item->uid])) }}">{{ $item->name }}</a>
                                    </td>
                                    <td>{{ ucfirst($item->type->value) }}</td>
                                    <td>
                                        @if ($item->price_minor === null)
                                            Quote only
                                        @else
                                            {{ \App\Library\Catalog\CatalogMoney::format($item->price_minor, $item->currency_code) }}
                                        @endif
                                    </td>
                                    <td class="text-nowrap">
                                        @if (! $loop->first)
                                            <form method="POST" action="{{ route('customer.workspaces.businesses.catalog.reorder', $scope) }}" class="d-inline">
                                                @csrf
                                                @foreach ($orderWithSwap($index, $index - 1) as $uid)
                                                    <input type="hidden" name="order[]" value="{{ $uid }}">
                                                @endforeach
                                                <button type="submit" class="btn btn-sm btn-outline-secondary" data-role="move-up" aria-label="Move {{ $item->name }} up">Up</button>
                                            </form>
                                        @endif
                                        @if (! $loop->last)
                                            <form method="POST" action="{{ route('customer.workspaces.businesses.catalog.reorder', $scope) }}" class="d-inline">
                                                @csrf
                                                @foreach ($orderWithSwap($index, $index + 1) as $uid)
                                                    <input type="hidden" name="order[]" value="{{ $uid }}">
                                                @endforeach
                                                <button type="submit" class="btn btn-sm btn-outline-secondary" data-role="move-down" aria-label="Move {{ $item->name }} down">Down</button>
                                            </form>
                                        @endif
                                    </td>
                                    <td class="text-right">
                                        <form method="POST" action="{{ route('customer.workspaces.businesses.catalog.archive', array_merge($scope, [$item->uid])) }}" class="d-inline">
                                            @csrf
                                            <button type="submit" class="btn btn-sm btn-outline-danger" data-role="archive">Archive</button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>

    @if ($archivedItems->isNotEmpty())
        <div class="card">
            <div class="card-body">
                <h4 class="card-title">Archived</h4>
                <p class="card-text text-muted">Archived items are not offered anywhere. Past quotes and invoices keep the price they were made with.</p>

                <div class="table-responsive">
                    <table class="table" data-role="catalog-archived">
                        <tbody>
                            @foreach ($archivedItems as $item)
                                <tr data-item="{{ $item->uid }}">
                                    <td>{{ $item->name }}</td>
                                    <td>{{ ucfirst($item->type->value) }}</td>
                                    <td class="text-right">
                                        <form method="POST" action="{{ route('customer.workspaces.businesses.catalog.reactivate', array_merge($scope, [$item->uid])) }}" class="d-inline">
                                            @csrf
                                            <button type="submit" class="btn btn-sm btn-outline-primary" data-role="reactivate">Make active again</button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @endif
@endsection
