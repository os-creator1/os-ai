{{--
    Google Ads Module V1 — Search terms: what people searched, what it cost and
    what is wasting money.

    Cached data only. Search terms, keywords and campaign names are customer /
    provider data: escaped. Potential waste is amber information, never red.
    "Add negative" opens a SERVER-RENDERED confirmation (it posts the local
    row id and campaign uid only, never a Google id, and never the term text:
    the server reads the text from its own cached row). "Ignore" is a local
    classification and changes nothing in Google Ads.
--}}
@extends('layouts/contentLayoutMaster')

@section('title', 'Ads search terms')

@php
    use App\Library\GoogleAds\GoogleAdsDisplay as D;
    use App\Library\GoogleAds\Reporting\GoogleAdsSearchTermClass as TermClass;

    $ready = $adsState === 'ready' && $result !== null;
    $currency = $account?->currency_code;
    $prefix = 'customer.workspaces.businesses.ads.';
    $keep = [];

    if ($ready) {
        $keep = array_filter(['class' => $classFilter, 'campaign' => $campaignFilter], fn ($v) => $v !== null);
    }

    $tabs = [[null, 'All', 'all']];
    foreach (TermClass::cases() as $case) {
        $tabs[] = [$case->value, D::termClassLabel($case), $case->value];
    }
@endphp

@section('content')
    @include('customer.business.ads._header', ['title' => 'Search terms', 'subtitle' => 'What people searched before clicking your ads, and what it cost.'])

    <x-flash-alert class="mb-2" />

    @if(! $ready)
        @include('customer.business.ads._empty-state')
    @else
        @include('customer.business.ads._period-selector', ['periodRoute' => 'search-terms.index', 'keep' => array_merge($keep, ['sort' => $sort, 'dir' => $direction])])

        @if($waste !== null)
            <x-card :padded="true" class="mb-2" data-role="waste-summary">
                <h2 class="text-section-heading mb-1">Money that may be wasted</h2>
                <p class="mb-1" data-role="waste-summary-text">
                    {{ D::money($waste->spendMicros, $currency) }} spent across {{ number_format($waste->termCount) }} search {{ $waste->termCount === 1 ? 'term' : 'terms' }} with no conversions.
                </p>
                @if(($waste->alreadyExcludedCount ?? 0) > 0)
                    <p class="text-caption text-muted mb-1" data-role="waste-already-excluded">
                        {{ number_format($waste->alreadyExcludedCount) }} more {{ $waste->alreadyExcludedCount === 1 ? 'term is' : 'terms are' }} already excluded and not counted.
                    </p>
                @endif
                <a href="{{ route($prefix . 'search-terms.index', array_merge([$workspaceUid, $businessUid], array_filter(['campaign' => $campaignFilter]), ['period' => $period->key, 'class' => 'potential_waste'])) }}" data-role="review-waste">Review these terms</a>
            </x-card>
        @endif

        <div class="d-flex gap-1 flex-nowrap overflow-auto pb-50 mb-1" data-role="class-tabs" aria-label="Filter by classification">
            @foreach($tabs as [$value, $label, $countKey])
                <a href="{{ route($prefix . 'search-terms.index', array_merge([$workspaceUid, $businessUid], array_filter(['campaign' => $campaignFilter]), array_filter(['class' => $value]), ['period' => $period->key])) }}"
                   class="btn btn-sm text-nowrap {{ $classFilter === $value ? 'btn-primary' : 'btn-outline-secondary' }}" data-class-tab="{{ $countKey }}">
                    {{ $label }} <span class="text-numeric" data-role="class-count">{{ number_format($counts[$countKey] ?? 0) }}</span>
                </a>
            @endforeach
        </div>

        <form method="GET" action="{{ route($prefix . 'search-terms.index', [$workspaceUid, $businessUid]) }}" class="row g-1 align-items-end mb-2" data-role="term-filters">
            <input type="hidden" name="period" value="{{ $period->key }}">
            @if($classFilter !== null)<input type="hidden" name="class" value="{{ $classFilter }}">@endif
            <div class="col-8 col-md-5">
                <label for="term-campaign" class="form-label text-label">Campaign</label>
                <select name="campaign" id="term-campaign" class="form-select form-select-sm">
                    <option value="">All campaigns</option>
                    @foreach($campaignOptions as $uid => $name)
                        <option value="{{ $uid }}" @selected($campaignFilter === $uid)>{{ $name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-4 col-md-2">
                <button type="submit" class="btn btn-sm btn-outline-secondary w-100" data-role="apply-filters">Apply</button>
            </div>
        </form>

        <x-card :padded="true">
            @if($result->total === 0)
                <x-empty-state icon="search" title="No search terms to show"
                               :description="$classFilter !== null || $campaignFilter !== null ? 'No search terms match this filter.' : 'No search-term data yet. It appears here after your next Google Ads update.'" data-role="no-search-terms" />
            @else
                <div class="table-responsive" data-role="search-terms-table-wrap">
                    <table class="table ds-table align-middle" data-role="search-terms-table">
                        @include('customer.business.ads._table-head', [
                            'sortRoute' => 'search-terms.index',
                            'keep' => $keep,
                            'columns' => array_values(array_filter([
                                ['term', 'Search term', false],
                                [null, 'Campaign', false],
                                [null, 'Keyword', false],
                                ['spend', 'Spend', true],
                                ['clicks', 'Clicks', true],
                                ['conversions', 'Conversions', true],
                                ['cpl', 'Cost per conversion', true],
                                [null, 'State', false],
                                $adsCanManage ? [null, 'Actions', false] : null,
                            ])),
                        ])
                        <tbody>
                            @foreach($result->items as $row)
                                @php
                                    $lower = mb_strtolower(trim($row->term));
                                    $isPending = ($pendingNegatives['c|' . $row->internal['campaign_id'] . '|' . $lower] ?? false)
                                        || ($pendingNegatives['g|' . $row->internal['ad_group_id'] . '|' . $lower] ?? false);
                                    $excluded = $row->classification === TermClass::Excluded;
                                    $ignored = $row->classification === TermClass::Ignored;
                                @endphp
                                <tr data-role="search-term-row" data-class="{{ $row->classification->value }}">
                                    <td data-role="term-text">{{ $row->term }}</td>
                                    <td>
                                        <a href="{{ route($prefix . 'campaigns.show', [$workspaceUid, $businessUid, $row->campaignUid, 'period' => $period->key]) }}">{{ $row->campaignName }}</a>
                                        <span class="d-block text-caption text-muted">{{ $row->adGroupName }}</span>
                                    </td>
                                    <td>
                                        @if($row->matchedKeywordText !== null)
                                            {{ $row->matchedKeywordText }}
                                            @if($row->matchedKeywordMatchType !== null)<span class="d-block text-caption text-muted">{{ D::matchLabel($row->matchedKeywordMatchType) }}</span>@endif
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td class="text-numeric text-end">{{ D::money($row->totals->spendMicros, $currency) }}</td>
                                    <td class="text-numeric text-end">{{ D::integer($row->totals->clicks) }}</td>
                                    <td class="text-numeric text-end">{{ D::count($row->totals->conversionsDisplay()) }}</td>
                                    <td class="text-numeric text-end">{{ D::money($row->cplMicros(), $currency) }}</td>
                                    <td>
                                        <x-badge :variant="D::termClassVariant($row->classification)" data-role="term-state">{{ D::termClassLabel($row->classification) }}</x-badge>
                                        @if($row->alreadyNegative && ! $excluded)
                                            <span class="d-block text-caption text-muted" data-role="already-negative">Already has a negative keyword</span>
                                        @endif
                                        @if($isPending)
                                            <x-badge variant="warning" data-role="pending-confirmation">Pending confirmation</x-badge>
                                        @endif
                                    </td>
                                    @if($adsCanManage)
                                        <td class="text-nowrap">
                                            <div class="d-flex gap-1">
                                                @if(! $excluded && ! $row->alreadyNegative)
                                                    <form method="POST" action="{{ route($prefix . 'search-terms.negative.preview', [$workspaceUid, $businessUid]) }}">
                                                        @csrf
                                                        <input type="hidden" name="search_term_id" value="{{ $row->internal['search_term_id'] }}">
                                                        <input type="hidden" name="campaign" value="{{ $row->campaignUid }}">
                                                        <input type="hidden" name="from" value="search-terms">
                                                        @if(! empty($returnQuery))<input type="hidden" name="q" value="{{ http_build_query($returnQuery) }}">@endif
                                                        <button type="submit" class="btn btn-sm btn-outline-secondary" data-role="add-negative">Add negative</button>
                                                    </form>
                                                @endif
                                                @if(! $excluded)
                                                    <form method="POST" action="{{ route($prefix . ($ignored ? 'search-terms.unignore' : 'search-terms.ignore'), [$workspaceUid, $businessUid]) }}" data-ads-once>
                                                        @csrf
                                                        <input type="hidden" name="search_term_id" value="{{ $row->internal['search_term_id'] }}">
                                                        <input type="hidden" name="campaign" value="{{ $row->campaignUid }}">
                                                        <input type="hidden" name="from" value="search-terms">
                                                        @if(! empty($returnQuery))<input type="hidden" name="q" value="{{ http_build_query($returnQuery) }}">@endif
                                                        <button type="submit" class="btn btn-sm btn-flat-secondary" data-role="{{ $ignored ? 'unignore-term' : 'ignore-term' }}">{{ $ignored ? 'Un-ignore' : 'Ignore' }}</button>
                                                    </form>
                                                @endif
                                            </div>
                                        </td>
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @if($result->lastPage > 1)
                    <nav class="d-flex justify-content-between align-items-center mt-1" aria-label="Pagination" data-role="pagination">
                        <span class="text-caption">Page {{ $result->page }} of {{ $result->lastPage }} &middot; {{ number_format($result->total) }} search terms</span>
                        <span class="d-flex gap-1">
                            @if($result->page > 1)
                                <a class="btn btn-sm btn-outline-secondary" rel="prev" href="{{ route($prefix . 'search-terms.index', array_merge([$workspaceUid, $businessUid], $keep, ['period' => $period->key, 'sort' => $sort, 'dir' => $direction, 'page' => $result->page - 1])) }}">Previous</a>
                            @endif
                            @if($result->page < $result->lastPage)
                                <a class="btn btn-sm btn-outline-secondary" rel="next" href="{{ route($prefix . 'search-terms.index', array_merge([$workspaceUid, $businessUid], $keep, ['period' => $period->key, 'sort' => $sort, 'dir' => $direction, 'page' => $result->page + 1])) }}">Next</a>
                            @endif
                        </span>
                    </nav>
                @endif

                <p class="text-caption text-muted mt-1 mb-0">
                    "Potential waste" means spend with no conversions in Google Ads. It is a prompt to look, not a verdict. A term is never excluded without your confirmation.
                </p>
            @endif
        </x-card>
    @endif
@endsection

@if($ready && $adsCanManage)
    @section('page-script')
        @include('customer.business.ads._once-script')
    @endsection
@endif
