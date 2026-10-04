{{--
    Google Ads Module V1 — a sortable table header for the data pages.

        @include('customer.business.ads._table-head', [
            'columns' => [['name', 'Campaign', false], [null, 'Status', false], ['spend', 'Spend', true]],
            'sortRoute' => 'campaigns.index',
            'keep' => ['status' => 'enabled'],           // filters carried across sorts
        ])

    Each column is [sort key | null (not sortable), label, right-aligned?].
    Reads $sort, $direction, $period, $workspaceUid, $businessUid from the
    page. Links are plain GETs; the readers whitelist the sort key again.
--}}
@php
    $keep = $keep ?? [];
@endphp
<thead>
    <tr>
        @foreach($columns as [$key, $label, $numeric])
            <th class="text-label text-uppercase text-muted text-nowrap {{ $numeric ? 'text-end' : '' }}"
                @if($key !== null && $sort === $key) aria-sort="{{ $direction === 'asc' ? 'ascending' : 'descending' }}" @endif
                data-col="{{ $key ?? \Illuminate\Support\Str::slug($label) }}">
                @if($key === null)
                    {{ $label }}
                @else
                    <a href="{{ route('customer.workspaces.businesses.ads.' . $sortRoute, array_merge([$workspaceUid, $businessUid], $keep, ['period' => $period->key, 'sort' => $key, 'dir' => ($sort === $key && $direction === 'desc') ? 'asc' : 'desc'])) }}"
                       class="text-reset text-decoration-none" data-sort="{{ $key }}">{{ $label }}@if($sort === $key) <span aria-hidden="true">{{ $direction === 'asc' ? '↑' : '↓' }}</span>@endif</a>
                @endif
            </th>
        @endforeach
    </tr>
</thead>
