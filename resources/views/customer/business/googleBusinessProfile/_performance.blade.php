{{--
    Google performance (Performance Insights V1). READ ONLY and loaded ONLY on request: nothing here is fetched by a page view,
    and nothing Google sends is stored, cached or logged — the numbers exist in this response alone.

    Every value is Google's own count for the dates shown. A figure Google did not report for every day of a period is
    "Not available", never zero, and a change is shown only when both periods are complete. Google search-term counts are
    impressions (how often the business appeared for a search), NOT ranking positions. Escaped Blade output only.
--}}
@php
    $fmtDate = fn ($date) => \Carbon\Carbon::parse($date)->format('j M Y');
    $fmtNumber = fn ($value) => number_format((int) $value);
@endphp

<x-card :padded="false" class="gbp-card mb-2" data-role="gbp-performance">
    <div class="gbp-card-body">
        <div class="gbp-diff-head">
            <div>
                <p class="gbp-eyebrow mb-25">On Google</p>
                <h2 class="h5 mb-25">Google performance</h2>
                <p class="text-caption mb-0">How people found and contacted your business through Google Search and Maps.</p>
            </div>
            @if (! empty($canManageGoogle))
                <form method="POST" action="{{ route('customer.workspaces.businesses.gbp.performance', [$workspaceUid, $businessUid]) }}">
                    @csrf
                    <button type="submit" class="btn btn-outline-primary">{{ isset($performance) ? 'Refresh Google performance' : 'Load Google performance' }}</button>
                </form>
            @endif
        </div>

        @if (! isset($performance))
            <p class="text-caption mb-0">
                Not loaded yet. This reads your numbers from Google when you ask; nothing is stored here, so it is loaded again each time.
            </p>
        @else
            @foreach ($performance as $entry)
                @if (count($performance) > 1 && $entry['location'])
                    <h3 class="h6 mt-2 mb-1">{{ $entry['location'] }}</h3>
                @endif

                @if ($entry['error'])
                    <x-alert variant="warning" class="mb-1">{{ $entry['error'] }}</x-alert>
                    @continue
                @endif

                @php($data = $entry['data'])
                <p class="text-caption mb-2">
                    Last 28 days reported by Google: <strong>{{ $fmtDate($data['period']['start']) }} &ndash; {{ $fmtDate($data['period']['end']) }}</strong>
                    (compared with {{ $fmtDate($data['period']['previous_start']) }} &ndash; {{ $fmtDate($data['period']['previous_end']) }}).
                    Google reports a few days late. Loaded {{ $data['fetched_at']->format('j M Y, H:i') }} UTC.
                </p>

                <div class="gbp-perf-grid">
                    @foreach ($data['kpis'] as $kpi)
                        <div class="gbp-perf-kpi" data-kpi="{{ $kpi['key'] }}">
                            <span class="gbp-eyebrow">{{ $kpi['label'] }}</span>
                            @if ($kpi['current'] === null)
                                <span class="gbp-perf-value gbp-unset">Not available</span>
                                <span class="text-caption">Google has not reported every day of this period.</span>
                            @else
                                <span class="gbp-perf-value">{{ $fmtNumber($kpi['current']) }}</span>
                                @if ($kpi['comparable'])
                                    <span class="text-caption" data-role="change">
                                        @if ($kpi['delta'] === 0)
                                            No change
                                        @else
                                            {{ $kpi['delta'] > 0 ? '▲ +' : '▼ −' }}{{ $fmtNumber(abs($kpi['delta'])) }}@if ($kpi['percent'] !== null) ({{ $kpi['percent'] > 0 ? '+' : '' }}{{ $kpi['percent'] }}%)@endif
                                        @endif
                                        vs previous 28 days
                                    </span>
                                @else
                                    <span class="text-caption">No comparison: the previous period is incomplete.</span>
                                @endif
                            @endif
                        </div>
                    @endforeach
                </div>
                @if (collect($data['kpis'])->every(fn ($kpi) => $kpi['current'] === 0))
                    <p class="text-caption mt-1 mb-0">Google reported no activity for this period. A new or quiet profile can show zero.</p>
                @endif

                <h3 class="h6 mt-3 mb-25">Search terms people used to find you on Google</h3>
                @if ($data['keywords']['error'])
                    <x-alert variant="warning" class="mb-1">Search terms could not be loaded: {{ $data['keywords']['error'] }}</x-alert>
                @elseif ($data['keywords']['month'] === null)
                    <p class="text-caption mb-1">Google returned no search terms for the last two months. Google only lists terms for profiles with enough searches.</p>
                @else
                    <p class="text-caption mb-1">{{ \Carbon\Carbon::parse($data['keywords']['month'] . '-01')->format('F Y') }}, the latest complete month with data.</p>
                    <ul class="gbp-perf-terms" data-role="gbp-search-terms">
                        @foreach ($data['keywords']['items'] as $term)
                            <li>
                                <span class="gbp-break">{{ $term['term'] }}</span>
                                <span class="text-caption">
                                    @if ($term['value'] !== null)
                                        {{ $fmtNumber($term['value']) }} impressions
                                    @else
                                        fewer than {{ $fmtNumber($term['threshold']) }} impressions
                                    @endif
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endif
                <p class="text-caption mb-0">
                    These counts are <strong>impressions</strong>: how often your business appeared for that search on Google. They are not ranking positions.
                    Google shows &ldquo;fewer than N&rdquo; when a term was too rare to report an exact number.
                </p>
            @endforeach
        @endif
    </div>
</x-card>

<style>
    .gbp-perf-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: var(--space-3); }
    @media (min-width: 992px) { .gbp-perf-grid { grid-template-columns: repeat(4, minmax(0, 1fr)); } }
    .gbp-perf-kpi { display: flex; flex-direction: column; gap: var(--space-1); padding: var(--space-3); border: 1px solid var(--color-border-neutral); border-radius: var(--radius-lg); background: var(--color-surface-secondary); }
    .gbp-perf-value { font-size: 1.5rem; font-weight: 600; color: var(--color-text-primary); line-height: 1.2; }
    .gbp-perf-terms { list-style: none; margin: 0 0 var(--space-2); padding: 0; }
    .gbp-perf-terms li { display: flex; flex-wrap: wrap; justify-content: space-between; gap: var(--space-2); padding: var(--space-2) 0; border-bottom: 1px solid var(--color-border-neutral); }
</style>
