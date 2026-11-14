{{-- The inner part of the Google performance card: also returned on its own for the automatic and Refresh reads. Escaped output only. --}}
@php
    $fmtDate = fn ($date) => \Carbon\Carbon::parse($date)->format('j M Y');
    $fmtNumber = fn ($value) => number_format((int) $value);
@endphp
            @foreach ($performance as $entry)
                @if (count($performance) > 1 && $entry['location'])
                    <h3 class="h6 mt-2 mb-1">{{ $entry['location'] }}</h3>
                @endif

                @php($data = $entry['data'])

                @if ($data === null)
                    @if ($entry['error'])
                        <x-alert variant="warning" class="mb-1">
                            Google performance could not be loaded: {{ $entry['error'] }}
                            @if (! empty($entry['failed_at'])) (tried {{ $entry['failed_at']->diffForHumans() }}) @endif
                        </x-alert>
                    @elseif (! empty($performanceAuto))
                        <p class="text-caption mb-0" role="status" data-role="gbp-performance-loading">Loading your Google performance&hellip;</p>
                    @else
                        <p class="text-caption mb-0">No Google performance has been read yet. It is read daily, and when someone who manages the connection opens this page.</p>
                    @endif
                    @continue
                @endif

                <p class="text-caption mb-2" data-role="gbp-performance-status">
                    @if ($data['fetched_at']->isToday())
                        <x-badge variant="success">Updated today</x-badge>
                    @else
                        <x-badge variant="warning">Older data</x-badge>
                    @endif
                    Last successful refresh: <strong>{{ $data['fetched_at']->format('j M Y, H:i') }} UTC</strong>
                    ({{ $data['fetched_at']->diffForHumans() }}).
                </p>
                @if ($entry['error'])
                    <x-alert variant="warning" class="mb-1">
                        The latest refresh failed: {{ $entry['error'] }} The numbers below are from the last successful read, not current.
                    </x-alert>
                @endif
                <p class="text-caption mb-2">
                    Reporting period, as reported by Google: <strong>{{ $fmtDate($data['period']['start']) }} &ndash; {{ $fmtDate($data['period']['end']) }}</strong>
                    (compared with {{ $fmtDate($data['period']['previous_start']) }} &ndash; {{ $fmtDate($data['period']['previous_end']) }}).
                    Google reports a few days late.
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
                {{-- Simple trend: Google's own totals for the two periods, drawn as bars. Only metrics with both periods complete. --}}
                @php
                    $trend = collect($data['kpis'])->filter(fn ($kpi) => $kpi['current'] !== null && $kpi['comparable'] && isset($kpi['previous']) && $kpi['previous'] !== null);
                    $trendMax = max(1, (int) $trend->map(fn ($kpi) => max((int) $kpi['current'], (int) $kpi['previous']))->max());
                @endphp
                @if ($trend->isNotEmpty())
                    <div class="gbp-trend" data-role="gbp-trend" role="img" aria-label="Previous period compared with current period for each Google metric">
                        @foreach ($trend as $kpi)
                            <div class="gbp-trend-row">
                                <span>{{ $kpi['label'] }}</span>
                                <span class="gbp-trend-bars">
                                    <span class="gbp-trend-bar"><span style="width: {{ round(max((int) $kpi['current'], 0) / $trendMax * 100) }}%"></span><span class="text-caption">{{ $fmtNumber($kpi['current']) }}</span></span>
                                    <span class="gbp-trend-bar is-prev"><span style="width: {{ round(max((int) $kpi['previous'], 0) / $trendMax * 100) }}%"></span><span class="text-caption">{{ $fmtNumber($kpi['previous']) }}</span></span>
                                </span>
                            </div>
                        @endforeach
                        <div class="gbp-trend-legend text-caption"><span>Top bar: this period</span><span>Bottom bar: previous period</span></div>
                    </div>
                @endif
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
