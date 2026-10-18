{{--
    Meta Ads Module V1 — Previous / Next for a data table.

        @include('customer.business.ads.meta._data-pager', ['pagerRoute' => 'campaigns.index', 'noun' => 'campaigns', 'keep' => [...]])

    Reads $result (GoogleAdsPagedResult), $period, $sort, $direction and the
    route identifiers from the page.
--}}
@if($result->lastPage > 1)
    @php
        $pagerKeep = array_merge($keep ?? [], ['period' => $period->key, 'sort' => $sort, 'dir' => $direction]);
        $pagerRoute = 'customer.workspaces.businesses.ads.meta.' . $pagerRoute;
    @endphp
    <nav class="d-flex justify-content-between align-items-center flex-wrap gap-1 mt-1" aria-label="Pagination" data-role="pagination">
        <span class="text-caption">Page {{ $result->page }} of {{ $result->lastPage }} &middot; {{ number_format($result->total) }} {{ $noun }}</span>
        <span class="d-flex gap-1">
            @if($result->page > 1)
                <a class="btn btn-sm btn-outline-secondary" rel="prev" href="{{ route($pagerRoute, array_merge([$workspaceUid, $businessUid], $pagerKeep, ['page' => $result->page - 1])) }}">Previous</a>
            @endif
            @if($result->page < $result->lastPage)
                <a class="btn btn-sm btn-outline-secondary" rel="next" href="{{ route($pagerRoute, array_merge([$workspaceUid, $businessUid], $pagerKeep, ['page' => $result->page + 1])) }}">Next</a>
            @endif
        </span>
    </nav>
@endif
