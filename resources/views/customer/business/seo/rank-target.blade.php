{{--
    Keyword rank detail — SEO KEYWORD RANK TRACKING V1.

    Provider rank observations and Google Search Console metrics are two
    different facts from two different sources and are shown in separate,
    labelled blocks; they are never averaged, merged or substituted.
    Escaped Blade output only.
--}}
@extends('layouts/contentLayoutMaster')

@section('title', $keyword->phrase . ' · Search keywords')

@php
    use App\Enums\Seo\SeoKeywordCoverageStatus;
    use App\Enums\Seo\SeoRankObservationStatus;

    $dash = '—';
    $indexUrl = route('customer.workspaces.businesses.seo.keywords.index', [$workspaceUid, $businessUid]);
    $firstTracked = $target->tracked_since;
    $fmt = fn ($o) => $o === null ? null : ($o->status === SeoRankObservationStatus::Found ? '#' . $o->position : ($o->status === SeoRankObservationStatus::NotMatched ? 'Not matched' : 'Not found'));
@endphp

@section('content')
    <div class="mb-1">
        <a href="{{ $indexUrl }}" class="text-caption">← Search keywords</a>
    </div>

    <div class="d-flex justify-content-between align-items-start flex-wrap gap-1 mb-2">
        <div>
            <h4 class="mb-25" data-role="detail-keyword">{{ $keyword->phrase }}</h4>
            <p class="text-caption mb-0" data-role="detail-location">{{ $locationLabel }} · Google · {{ $target->device }}</p>
        </div>
        <div class="d-flex gap-1 flex-wrap">
            @can('manage_seo')
                @if($target->isTracking())
                    <form method="POST" action="{{ route('customer.workspaces.businesses.seo.rank-targets.check', [$workspaceUid, $businessUid, $target->uid]) }}">
                        @csrf
                        <button class="btn btn-primary" type="submit" data-role="check-now">Check now</button>
                    </form>
                    <form method="POST" action="{{ route('customer.workspaces.businesses.seo.rank-targets.stop', [$workspaceUid, $businessUid, $target->uid]) }}">
                        @csrf
                        <button class="btn btn-outline-secondary" type="submit" data-role="rank-stop">Stop tracking</button>
                    </form>
                @else
                    <form method="POST" action="{{ route('customer.workspaces.businesses.seo.rank-targets.restart', [$workspaceUid, $businessUid, $target->uid]) }}">
                        @csrf
                        <button class="btn btn-outline-primary" type="submit" data-role="rank-restart">Start tracking</button>
                    </form>
                @endif
            @endcan
        </div>
    </div>

    <x-flash-alert class="mb-2" />

    @if($unavailable)
        <x-alert variant="neutral" class="mb-2" data-role="rank-unavailable-notice">Rank checks are not available right now, so no checks will run. Your existing results stay visible.</x-alert>
    @endif

    @if($pausedBySpend)
        <x-alert variant="warning" class="mb-2" data-role="rank-paused-notice">Rank checks paused until your usage period resets. Your latest results stay visible.</x-alert>
    @endif

    <div class="row g-1 mb-2" data-section="rank-facts">
        <div class="col-6 col-lg-3"><x-card :padded="true" class="h-100"><p class="text-caption mb-25">Current organic position</p>
            <div class="h4 mb-0" data-role="current-organic">{{ $fmt($organic['current']) ?? $dash }}</div></x-card></div>
        <div class="col-6 col-lg-3"><x-card :padded="true" class="h-100"><p class="text-caption mb-25">Current local position</p>
            <div class="h4 mb-0" data-role="current-local">{{ $local['current'] === null ? $dash : ($local['current']->status === SeoRankObservationStatus::Found ? 'Local #' . $local['current']->position : $fmt($local['current'])) }}</div></x-card></div>
        <div class="col-6 col-lg-3"><x-card :padded="true" class="h-100"><p class="text-caption mb-25">Change (organic)</p>
            <div class="h4 mb-0">@include('customer.business.seo._rank-change', ['change' => $organicChange])</div></x-card></div>
        <div class="col-6 col-lg-3"><x-card :padded="true" class="h-100"><p class="text-caption mb-25">Previous organic</p>
            <div class="h4 mb-0" data-role="previous-organic">{{ $fmt($organic['previous']) ?? $dash }}</div></x-card></div>
        <div class="col-6 col-lg-3"><x-card :padded="true" class="h-100"><p class="text-caption mb-25">Best organic</p>
            <div class="h4 mb-0" data-role="best-organic">{{ $organic['best'] === null ? $dash : '#' . $organic['best'] }}</div></x-card></div>
        <div class="col-6 col-lg-3"><x-card :padded="true" class="h-100"><p class="text-caption mb-25">Best local</p>
            <div class="h4 mb-0" data-role="best-local">{{ $local['best'] === null ? $dash : 'Local #' . $local['best'] }}</div></x-card></div>
        <div class="col-6 col-lg-3"><x-card :padded="true" class="h-100"><p class="text-caption mb-25">First tracked</p>
            <div class="h5 mb-0" data-role="first-tracked">{{ $firstTracked ? $firstTracked->format('M j, Y') : $dash }}</div></x-card></div>
        <div class="col-6 col-lg-3"><x-card :padded="true" class="h-100"><p class="text-caption mb-25">Last checked</p>
            <div class="h5 mb-0" data-role="last-checked">{{ $target->last_checked_at ? $target->last_checked_at->format('M j, Y g:i A') . ' UTC' : $dash }}</div></x-card></div>
    </div>

    <div class="row g-1 mb-2" data-section="rank-history">
        <div class="col-lg-6">
            <x-card :padded="true" class="h-100">
                <p class="text-section-heading mb-1">Organic position</p>
                @include('customer.business.seo._rank-chart', ['chart' => $organicChart, 'label' => 'Organic position'])
            </x-card>
        </div>
        <div class="col-lg-6">
            <x-card :padded="true" class="h-100">
                <p class="text-section-heading mb-1">Local position</p>
                @include('customer.business.seo._rank-chart', ['chart' => $localChart, 'label' => 'Local position'])
            </x-card>
        </div>
    </div>

    <div class="row g-1 mb-2">
        <div class="col-lg-6">
            <x-card :padded="true" class="h-100" data-section="website-coverage">
                <p class="text-section-heading mb-1">Website coverage</p>
                @if($coverage !== null)
                    <p class="mb-25" data-role="keyword-coverage" data-status="{{ $coverage->status->value }}">{{ $coverage->status->label() }}</p>
                    @if($coverage->status === SeoKeywordCoverageStatus::Covered)
                        <p class="text-caption mb-0" data-role="keyword-coverage-detail">
                            Found in {{ $coverage->titlePages }} {{ $coverage->titlePages === 1 ? 'page title' : 'page titles' }},
                            {{ $coverage->descriptionPages }} {{ $coverage->descriptionPages === 1 ? 'meta description' : 'meta descriptions' }} and
                            {{ $coverage->bodyPages }} {{ $coverage->bodyPages === 1 ? 'page body' : 'page bodies' }}.
                        </p>
                    @endif
                @else
                    <p class="text-muted mb-0">{{ $dash }}</p>
                @endif
                <p class="text-caption mb-0 mt-50">Coverage is a content check. It is independent of rank.</p>
            </x-card>
        </div>
        <div class="col-lg-6">
            @if($searchConsole !== null)
                <x-card :padded="true" class="h-100" data-section="search-console">
                    <p class="text-section-heading mb-1">Google Search Console</p>
                    <p class="text-caption mb-50">A separate source from the rank tracker above, as of {{ $searchConsole['as_of'] }}.</p>
                    <dl class="row mb-0">
                        <dt class="col-6">Clicks</dt><dd class="col-6" data-role="gsc-clicks">{{ number_format($searchConsole['clicks']) }}</dd>
                        <dt class="col-6">Impressions</dt><dd class="col-6" data-role="gsc-impressions">{{ number_format($searchConsole['impressions']) }}</dd>
                        <dt class="col-6">Average position</dt><dd class="col-6" data-role="gsc-position">{{ $searchConsole['average_position'] === null ? $dash : number_format($searchConsole['average_position'], 1) }}</dd>
                    </dl>
                </x-card>
            @endif
        </div>
    </div>

    <x-card :padded="false" class="mb-2" data-section="recent-checks">
        <div class="p-2 pb-1"><p class="text-section-heading mb-0">Recent checks</p></div>
        @if($recent->isEmpty())
            <div class="px-2 pb-2"><p class="text-muted mb-0">No completed checks yet.</p></div>
        @else
            <x-table :headers="['Checked', 'Type', 'Result', 'Landing page']">
                @foreach($recent as $o)
                    <tr data-role="recent-check">
                        <td>{{ $o->checked_at->format('M j, Y g:i A') }} UTC</td>
                        <td>{{ $o->check_type->value === 'local' ? 'Local' : 'Organic' }}</td>
                        <td>
                            @include('customer.business.seo._rank-badge', ['obs' => $o, 'kind' => $o->check_type->value, 'state' => 'active'])
                        </td>
                        <td>{{ $o->result_path ?? $dash }}</td>
                    </tr>
                @endforeach
            </x-table>
        @endif
    </x-card>
@endsection
