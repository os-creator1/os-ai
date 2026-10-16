{{--
    Meta Ads Module V1 — Campaigns.

    Cached data only. Absent figures are an em dash, never 0. Campaign names
    are provider data and are escaped. No Meta id is rendered or linked:
    campaigns are addressed by their uid. Pause / resume are confirmed in a
    dialog that states exactly what changes, then POST to the mutation route.
--}}
@extends('layouts/contentLayoutMaster')

@section('title', 'Meta Ads performance')

@php
    use App\Library\MetaAds\MetaAdsDisplay as D;

    $ready = $metaState === 'ready' && $result !== null;
    $currency = $account?->currency_code;
    $prefix = 'customer.workspaces.businesses.ads.meta.';
    $keep = $ready && $statusFilter !== null ? ['status' => $statusFilter] : [];
@endphp

@section('content')
    @include('customer.business.ads._header', ['title' => 'Meta Ads', 'subtitle' => 'Campaigns, ad sets and ads, from your last Meta Ads update.', 'provider' => 'meta'])

    <x-flash-alert class="mb-2" />

    @if(! $ready)
        @include('customer.business.ads.meta._empty-state')
    @else
        @include('customer.business.ads.meta._data-period', ['periodRoute' => 'campaigns.index', 'keep' => array_merge($keep, ['sort' => $sort, 'dir' => $direction])])

        <div class="d-flex gap-1 flex-wrap mb-2" data-role="status-filter" aria-label="Filter by status">
            @foreach([[null, 'All'], ['active', 'Active'], ['paused', 'Paused']] as [$value, $label])
                <a href="{{ route($prefix . 'campaigns.index', array_filter([$workspaceUid, $businessUid, 'period' => $period->key, 'status' => $value], fn ($v) => $v !== null)) }}"
                   class="btn btn-sm {{ $statusFilter === $value ? 'btn-primary' : 'btn-outline-secondary' }}" data-status-filter="{{ $value ?? 'all' }}">{{ $label }}</a>
            @endforeach
        </div>

        @php
            $adSetsByCampaign = $tree['adSetsByCampaign'];
            $adsByAdSet = $tree['adsByAdSet'];
            $totals = $tree['totals'];
            $nodeCount = count($result->items) + array_sum(array_map('count', $adSetsByCampaign)) + array_sum(array_map('count', $adsByAdSet));
            $colCount = 8 + ($metaCanManage ? 1 : 0);
            $levelBadge = ['campaign' => 'Campaign', 'ad_set' => 'Ad set', 'ad' => 'Ad'];
            $budgetCell = function ($row) use ($currency) {
                if ($row->dailyBudgetMinor !== null) {
                    return D::minorMoney($row->dailyBudgetMinor, $currency) . '<span class="text-caption text-muted">/day</span>';
                }
                if ($row->lifetimeBudgetMinor !== null) {
                    return D::minorMoney($row->lifetimeBudgetMinor, $currency) . '<span class="text-caption text-muted"> lifetime</span>';
                }

                return D::DASH;
            };
        @endphp

        <style>
            .ads-tree-wrap { overflow-x: auto; max-width: 100%; }
            .ads-tree { min-width: 880px; }
            .ads-tree .lvl-badge { font-size: .6rem; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; padding: .15rem .4rem; border-radius: .25rem; background: rgba(120,113,108,.12); color: inherit; white-space: nowrap; }
            .ads-tree tr[data-level="campaign"] > td { background: var(--cx-surface, #fff); font-weight: 500; }
            .ads-tree tr[data-level="ad_set"] > td { background: rgba(120,113,108,.05); }
            .ads-tree tr[data-level="ad"] > td { background: rgba(120,113,108,.09); }
            .ads-tree tr[data-level="ad_set"] .tree-name { padding-left: 1.4rem; }
            .ads-tree tr[data-level="ad"] .tree-name { padding-left: 2.8rem; }
            .ads-tree .tree-name { display: flex; align-items: flex-start; gap: .5rem; min-width: 14rem; }
            .ads-tree .tree-name > div { min-width: 0; overflow-wrap: anywhere; }
            .ads-tree .tree-toggle { background: none; border: 0; padding: .1rem; line-height: 1; cursor: pointer; color: inherit; flex: 0 0 auto; }
            .ads-tree .tree-toggle .chev { display: inline-block; transition: transform .12s; }
            .ads-tree .tree-toggle[aria-expanded="false"] .chev { transform: rotate(-90deg); }
            .ads-tree .tree-spacer { display: inline-block; width: 1.1rem; flex: 0 0 auto; }
            .ads-tree tr[hidden] { display: none; }
            .ads-tree tfoot td { font-weight: 700; border-top: 2px solid currentColor; }
        </style>

        <x-card :padded="true">
            @if($result->total === 0)
                <x-empty-state icon="megaphone" title="No campaigns to show"
                               :description="$statusFilter !== null ? 'No campaigns match this filter.' : 'We have not found any campaigns in your Meta ad account yet. They appear here after the next update.'" data-role="no-campaigns" />
            @else
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-1 mb-1" data-role="tree-toolbar">
                    <strong data-role="tree-summary">{{ $result->total }} {{ $result->total === 1 ? 'campaign' : 'campaigns' }} &middot; {{ array_sum(array_map('count', $adSetsByCampaign)) }} ad sets &middot; {{ array_sum(array_map('count', $adsByAdSet)) }} ads <span class="text-caption text-muted fw-normal">on this page</span></strong>
                    <div class="d-flex gap-1">
                        <button type="button" class="btn btn-sm btn-outline-secondary" data-role="expand-all">Expand all</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" data-role="collapse-all">Collapse all</button>
                    </div>
                </div>

                @include('customer.business.ads.meta._data-result-note')

                <div class="table-responsive ads-tree-wrap" data-role="campaigns-table-wrap">
                    <table class="table ds-table align-middle ads-tree" data-role="campaigns-table" data-tree-table>
                        @include('customer.business.ads.meta._data-head', [
                            'sortRoute' => 'campaigns.index',
                            'keep' => $keep,
                            'columns' => array_values(array_filter([
                                ['name', 'Name', false],
                                ['budget', 'Budget', true],
                                ['spend', 'Spend', true],
                                ['results', 'Results', true],
                                ['cost_per_result', 'Cost / result', true],
                                ['link_clicks', 'Link clicks', true],
                                [null, 'Frequency', true],
                                ['impressions', 'Impressions', true],
                                $metaCanManage ? [null, 'Action', false] : null,
                            ])),
                        ])
                        <tbody>
                            @foreach($result->items as $row)
                                @php
                                    $children = $adSetsByCampaign[$row->uid] ?? [];
                                    $cid = 'c-' . $row->uid;
                                @endphp
                                <tr data-role="campaign-row" data-level="campaign" data-node="{{ $cid }}">
                                    <td>
                                        <div class="tree-name">
                                            @if(count($children) > 0 || $tree['adSetsTruncated'])
                                                <button type="button" class="tree-toggle" data-tree-toggle="{{ $cid }}" aria-expanded="true" aria-label="Toggle ad sets of {{ $row->name }}"><span class="chev" aria-hidden="true">&#9662;</span></button>
                                            @else
                                                <span class="tree-spacer" aria-hidden="true"></span>
                                            @endif
                                            <div>
                                                <span class="lvl-badge">{{ $levelBadge['campaign'] }}</span>
                                                <a href="{{ route($prefix . 'campaigns.show', [$workspaceUid, $businessUid, $row->uid, 'period' => $period->key]) }}" data-role="campaign-link">{{ $row->name }}</a>
                                                <div>@include('customer.business.ads.meta._data-status')</div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="text-numeric text-end" data-role="campaign-budget">{!! $budgetCell($row) !!}</td>
                                    <td class="text-numeric text-end">{{ D::money($row->spendMicros(), $currency) }}</td>
                                    <td class="text-numeric text-end">{{ D::count($row->totals->resultsDisplay()) }}</td>
                                    <td class="text-numeric text-end">{{ D::money($row->costPerResultMicros(), $currency) }}</td>
                                    <td class="text-numeric text-end">{{ D::integer($row->totals->linkClicks) }}</td>
                                    <td class="text-numeric text-end">{{ D::DASH }}</td>
                                    <td class="text-numeric text-end">{{ D::integer($row->totals->impressions) }}</td>
                                    @if($metaCanManage)
                                        @include('customer.business.ads.meta._data-action-cell', ['kind' => 'campaign'])
                                    @endif
                                </tr>
                                @if(count($children) === 0)
                                    <tr data-level="ad_set" data-parent="{{ $cid }}" data-role="no-ad-sets">
                                        <td colspan="{{ $colCount }}" class="text-caption text-muted"><div class="tree-name"><span>{{ $tree['adSetsTruncated'] ? 'Some ad sets are not listed here. Open the Ad sets page for the full list.' : 'No ad sets in this campaign yet.' }}</span></div></td>
                                    </tr>
                                @endif
                                @foreach($children as $adSet)
                                    @php
                                        $grand = $adsByAdSet[$adSet->uid] ?? [];
                                        $sid = 's-' . $adSet->uid;
                                    @endphp
                                    <tr data-role="ad-set-row" data-level="ad_set" data-node="{{ $sid }}" data-parent="{{ $cid }}">
                                        <td>
                                            <div class="tree-name">
                                                @if(count($grand) > 0 || $tree['adsTruncated'])
                                                    <button type="button" class="tree-toggle" data-tree-toggle="{{ $sid }}" aria-expanded="true" aria-label="Toggle ads of {{ $adSet->name }}"><span class="chev" aria-hidden="true">&#9662;</span></button>
                                                @else
                                                    <span class="tree-spacer" aria-hidden="true"></span>
                                                @endif
                                                <div>
                                                    <span class="lvl-badge">{{ $levelBadge['ad_set'] }}</span> {{ $adSet->name }}
                                                    @if(filled($adSet->targetingSummary))<div class="text-caption text-muted">{{ $adSet->targetingSummary }}</div>@endif
                                                    <div>@include('customer.business.ads.meta._data-status', ['row' => $adSet])</div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="text-numeric text-end">{!! $budgetCell($adSet) !!}</td>
                                        <td class="text-numeric text-end">{{ D::money($adSet->spendMicros(), $currency) }}</td>
                                        <td class="text-numeric text-end">{{ D::count($adSet->totals->resultsDisplay()) }}</td>
                                        <td class="text-numeric text-end">{{ D::money($adSet->costPerResultMicros(), $currency) }}</td>
                                        <td class="text-numeric text-end">{{ D::integer($adSet->totals->linkClicks) }}</td>
                                        <td class="text-numeric text-end" title="Last 7 days">{{ D::frequency($adSet->frequency7d) }}</td>
                                        <td class="text-numeric text-end">{{ D::integer($adSet->totals->impressions) }}</td>
                                        @if($metaCanManage)
                                            @include('customer.business.ads.meta._data-action-cell', ['kind' => 'ad_set', 'row' => $adSet])
                                        @endif
                                    </tr>
                                    @if(count($grand) === 0)
                                        <tr data-level="ad" data-parent="{{ $sid }}" data-ancestor="{{ $cid }}" data-role="no-ads">
                                            <td colspan="{{ $colCount }}" class="text-caption text-muted"><div class="tree-name"><span>{{ $tree['adsTruncated'] ? 'Some ads are not listed here. Open the Ads page for the full list.' : 'No ads in this ad set yet.' }}</span></div></td>
                                        </tr>
                                    @endif
                                    @foreach($grand as $ad)
                                        <tr data-role="ad-row" data-level="ad" data-parent="{{ $sid }}" data-ancestor="{{ $cid }}">
                                            <td>
                                                <div class="tree-name">
                                                    <span class="tree-spacer" aria-hidden="true"></span>
                                                    <div>
                                                        <span class="lvl-badge">{{ $levelBadge['ad'] }}</span> {{ $ad->name }}
                                                        @if(filled($ad->creative['title'] ?? null))<div class="text-caption text-muted">&ldquo;{{ $ad->creative['title'] }}&rdquo;</div>@endif
                                                        <div>@include('customer.business.ads.meta._data-status', ['row' => $ad])</div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="text-numeric text-end">{{ D::DASH }}</td>
                                            <td class="text-numeric text-end">{{ D::money($ad->spendMicros(), $currency) }}</td>
                                            <td class="text-numeric text-end">{{ D::count($ad->totals->resultsDisplay()) }}</td>
                                            <td class="text-numeric text-end">{{ D::money($ad->costPerResultMicros(), $currency) }}</td>
                                            <td class="text-numeric text-end">{{ D::integer($ad->totals->linkClicks) }}</td>
                                            <td class="text-numeric text-end">{{ D::DASH }}</td>
                                            <td class="text-numeric text-end">{{ D::integer($ad->totals->impressions) }}</td>
                                            @if($metaCanManage)
                                                @include('customer.business.ads.meta._data-action-cell', ['kind' => 'ad', 'row' => $ad])
                                            @endif
                                        </tr>
                                    @endforeach
                                @endforeach
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr data-role="totals-row">
                                <td>Total @if($totals['partial'])<span class="text-caption text-muted fw-normal">(top {{ \App\Library\MetaAds\Reporting\MetaAdsPerformanceTree::TOTALS_LIMIT }} of {{ $totals['campaignCount'] }} campaigns by spend)</span>@else<span class="text-caption text-muted fw-normal">({{ $totals['campaignCount'] }} {{ $totals['campaignCount'] === 1 ? 'campaign' : 'campaigns' }})</span>@endif</td>
                                <td class="text-numeric text-end" data-role="total-budget">@if($totals['dailyBudgetMinor'] !== null){{ D::minorMoney($totals['dailyBudgetMinor'], $currency) }}<span class="text-caption text-muted">/day</span>@else{{ D::DASH }}@endif</td>
                                <td class="text-numeric text-end" data-role="total-spend">{{ D::money($totals['spendMicros'], $currency) }}</td>
                                <td class="text-numeric text-end" data-role="total-results">{{ D::count($totals['results']) }}</td>
                                <td class="text-numeric text-end" data-role="total-cpr">{{ D::money($totals['costPerResultMicros'], $currency) }}</td>
                                <td class="text-numeric text-end" data-role="total-link-clicks">{{ D::integer($totals['linkClicks']) }}</td>
                                <td class="text-numeric text-end">{{ D::DASH }}</td>
                                <td class="text-numeric text-end" data-role="total-impressions">{{ D::integer($totals['impressions']) }}</td>
                                @if($metaCanManage)<td></td>@endif
                            </tr>
                        </tfoot>
                    </table>
                </div>

                @include('customer.business.ads.meta._data-pager', ['pagerRoute' => 'campaigns.index', 'noun' => 'campaigns'])

                <p class="text-caption text-muted mt-1 mb-0">
                    Amounts are in your Meta ad account currency ({{ $currency }}). Budgets are the ones set in Meta Ads, not your monthly target. Frequency is times each person saw the ad set in the last 7 days. {{ D::DASH }} means Meta did not report this figure at that level. Each row shows its own level's figures; ad sets and ads are not added to their campaign.
                    <a href="{{ route($prefix . 'ad-sets.index', [$workspaceUid, $businessUid, 'period' => $period->key]) }}">All ad sets</a> &middot;
                    <a href="{{ route($prefix . 'ads.index', [$workspaceUid, $businessUid, 'period' => $period->key]) }}">All ads</a>
                </p>
            @endif
        </x-card>

        @if($metaCanConnect && ! $metaCanManage)
            <p class="text-caption text-muted mt-1" data-role="reconnect-hint">Reconnect Meta to allow pause/resume.</p>
        @endif

        @if($metaCanManage)
            @include('customer.business.ads.meta._data-actions', ['rows' => $result->items, 'kind' => 'campaign', 'from' => 'campaigns', 'returnQuery' => $returnQuery, 'returnCampaign' => null])
            @include('customer.business.ads.meta._data-actions', ['rows' => array_merge(...array_values($tree['adSetsByCampaign'] ?: [[]])), 'kind' => 'ad_set', 'from' => 'campaigns', 'returnQuery' => $returnQuery, 'returnCampaign' => null])
            @include('customer.business.ads.meta._data-actions', ['rows' => array_merge(...array_values($tree['adsByAdSet'] ?: [[]])), 'kind' => 'ad', 'from' => 'campaigns', 'returnQuery' => $returnQuery, 'returnCampaign' => null])
        @endif
    @endif
@endsection

@if($ready)
    @section('page-script')
        @if($metaCanManage)
            @include('customer.business.ads._once-script')
        @endif
        <script>
            (function () {
                var table = document.querySelector('[data-tree-table]');
                if (!table) { return; }
                var rows = Array.prototype.slice.call(table.querySelectorAll('tbody tr'));
                function toggles() { return Array.prototype.slice.call(table.querySelectorAll('[data-tree-toggle]')); }
                function isOpen(id) {
                    var t = table.querySelector('[data-tree-toggle="' + id + '"]');
                    return !t || t.getAttribute('aria-expanded') === 'true';
                }
                function apply() {
                    rows.forEach(function (row) {
                        var parent = row.getAttribute('data-parent');
                        if (!parent) { return; }
                        var anc = row.getAttribute('data-ancestor');
                        row.hidden = !(isOpen(parent) && (!anc || isOpen(anc)));
                    });
                }
                table.addEventListener('click', function (e) {
                    var t = e.target.closest('[data-tree-toggle]');
                    if (!t) { return; }
                    t.setAttribute('aria-expanded', t.getAttribute('aria-expanded') === 'true' ? 'false' : 'true');
                    apply();
                });
                function setAll(open) {
                    toggles().forEach(function (t) { t.setAttribute('aria-expanded', open ? 'true' : 'false'); });
                    apply();
                }
                var ea = document.querySelector('[data-role="expand-all"]');
                var ca = document.querySelector('[data-role="collapse-all"]');
                if (ea) { ea.addEventListener('click', function () { setAll(true); }); }
                if (ca) { ca.addEventListener('click', function () { setAll(false); }); }
                apply();
            })();
        </script>
    @endsection
@endif
