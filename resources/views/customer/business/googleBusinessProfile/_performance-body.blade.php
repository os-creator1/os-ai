{{-- The inner part of the Google performance card: also returned on its own for the automatic and Refresh reads. Escaped output only. --}}
@php
    $fmtDate = fn ($date) => \Carbon\Carbon::parse($date)->format('j M Y');
    $fmtShort = fn ($date) => \Carbon\Carbon::parse($date)->format('j M');
    $fmtNumber = fn ($value) => number_format((int) $value);
    $seriesColors = ['#B4514A', '#C9B8B0', '#647C78', '#4E79A7'];
@endphp
@foreach ($performance as $entry)
    @if (count($performance) > 1 && $entry['location'])
        <h3 class="gbp-perf-loc">{{ $entry['location'] }}</h3>
    @endif

    @php $data = $entry['data']; @endphp

    @if ($data === null)
        @if ($entry['error'])
            <p class="gbp-perf-msg is-warn">
                Google performance could not be loaded: {{ $entry['error'] }}
                @if (! empty($entry['failed_at'])) (tried {{ $entry['failed_at']->diffForHumans() }}) @endif
            </p>
        @elseif (! empty($performanceAuto))
            <p class="gbp-perf-msg" role="status" data-role="gbp-performance-loading">Loading your Google performance&hellip;</p>
        @else
            <p class="gbp-perf-msg">No Google performance has been read yet. It is read daily, and when someone who manages the connection opens this page.</p>
        @endif
        @continue
    @endif

    <div class="gbp-perf-meta" data-role="gbp-performance-status">
        @if ($data['fetched_at']->isToday())
            <span class="gbp-pill">Updated today</span>
        @else
            <span class="gbp-pill is-warn">Older data</span>
        @endif
        <span>Last successful refresh: <strong>{{ $data['fetched_at']->format('j M Y, H:i') }} UTC</strong> ({{ $data['fetched_at']->diffForHumans() }}).</span>
    </div>
    @if ($entry['error'])
        <p class="gbp-perf-msg is-warn" style="padding-bottom:0">
            The latest refresh failed: {{ $entry['error'] }} The numbers below are from the last successful read, not current.
        </p>
    @endif
    <div class="gbp-perf-meta" style="padding-top:4px">
        <span>
            Reporting period, as reported by Google: <strong>{{ $fmtDate($data['period']['start']) }} &ndash; {{ $fmtDate($data['period']['end']) }}</strong>
            (compared with {{ $fmtDate($data['period']['previous_start']) }} &ndash; {{ $fmtDate($data['period']['previous_end']) }}).
            Google reports a few days late.
        </span>
    </div>

    <div class="gbp-kpis">
        @foreach ($data['kpis'] as $kpi)
            <div class="gbp-kp" data-kpi="{{ $kpi['key'] }}">
                <span class="l">{{ $kpi['label'] }}</span>
                @if ($kpi['current'] === null)
                    <b class="is-na">Not available</b>
                    <span class="dl">Google has not reported every day of this period.</span>
                @else
                    <b>{{ $fmtNumber($kpi['current']) }}</b>
                    @if ($kpi['comparable'])
                        @if ($kpi['delta'] === 0)
                            <span class="dl" data-role="change">No change</span>
                        @else
                            <span class="dl {{ $kpi['delta'] > 0 ? 'is-up' : 'is-down' }}" data-role="change">
                                {{ $kpi['delta'] > 0 ? '▲ +' : '▼ −' }}{{ $fmtNumber(abs($kpi['delta'])) }}@if ($kpi['percent'] !== null) ({{ $kpi['percent'] > 0 ? '+' : '' }}{{ $kpi['percent'] }}%)@endif vs {{ $fmtNumber($kpi['previous']) }}
                            </span>
                        @endif
                    @else
                        <span class="dl">No comparison: the previous period is incomplete.</span>
                    @endif
                @endif
            </div>
        @endforeach
    </div>

    @if (collect($data['kpis'])->every(fn ($kpi) => $kpi['current'] === 0))
        <p class="gbp-perf-msg" style="padding-bottom:0">Google reported no activity for this period. A new or quiet profile can show zero.</p>
    @endif

    {{-- Chart: Google's own totals for the two reported periods (no daily series is read, so nothing between them is drawn). --}}
    @php
        $plot = collect($data['kpis'])->filter(fn ($kpi) => $kpi['current'] !== null && $kpi['comparable'] && $kpi['previous'] !== null)->values();
        $rawMax = max(1, (int) $plot->map(fn ($kpi) => max((int) $kpi['current'], (int) $kpi['previous']))->max());
        $mag = 10 ** (int) floor(log10($rawMax));
        $niceMax = $mag;
        foreach ([1, 2, 5, 10] as $m) { if ($m * $mag >= $rawMax) { $niceMax = $m * $mag; break; } }
        $yAt = fn ($value) => 170 - ($value / $niceMax) * 140;
        $xPrev = 150; $xCur = 490;
    @endphp
    @if ($plot->isNotEmpty())
        <div class="gbp-chart" data-role="gbp-chart">
            <div class="gbp-legend">
                @foreach ($plot as $i => $kpi)
                    <span><i style="background: {{ $seriesColors[$i % 4] }}"></i>{{ $kpi['label'] }}</span>
                @endforeach
            </div>
            <svg viewBox="0 0 620 200" role="img" aria-label="Google totals for the previous period and this period: @foreach ($plot as $kpi){{ $kpi['label'] }} {{ $fmtNumber($kpi['previous']) }} then {{ $fmtNumber($kpi['current']) }}@if (! $loop->last); @endif @endforeach">
                @foreach ([0, 0.5, 1] as $tick)
                    <line class="gl" x1="40" x2="600" y1="{{ $yAt($niceMax * $tick) }}" y2="{{ $yAt($niceMax * $tick) }}"></line>
                    <text class="ax" x="32" y="{{ $yAt($niceMax * $tick) + 4 }}" text-anchor="end">{{ $fmtNumber($niceMax * $tick) }}</text>
                @endforeach
                @foreach ($plot as $i => $kpi)
                    @php $color = $seriesColors[$i % 4]; @endphp
                    <path d="M{{ $xPrev }} {{ $yAt((int) $kpi['previous']) }} L{{ $xCur }} {{ $yAt((int) $kpi['current']) }}" fill="none" stroke="{{ $color }}" stroke-width="2.5" stroke-linecap="round"></path>
                    <circle cx="{{ $xPrev }}" cy="{{ $yAt((int) $kpi['previous']) }}" r="4" fill="#FFFFFF" stroke="{{ $color }}" stroke-width="2.5"></circle>
                    <circle cx="{{ $xCur }}" cy="{{ $yAt((int) $kpi['current']) }}" r="4" fill="#FFFFFF" stroke="{{ $color }}" stroke-width="2.5"></circle>
                @endforeach
                @php
                    $labelYs = [];
                    foreach ($plot as $i => $kpi) { $labelYs[$i] = $yAt((int) $kpi['current']) + 4; }
                    arsort($labelYs);
                    $lastY = 1000;
                    foreach ($labelYs as $i => $y) { if ($lastY - $y < 13) { $y = $lastY - 13; } $labelYs[$i] = $y; $lastY = $y; }
                @endphp
                @foreach ($plot as $i => $kpi)
                    <text class="pv" x="{{ $xCur + 12 }}" y="{{ $labelYs[$i] }}" fill="{{ $seriesColors[$i % 4] === '#C9B8B0' ? '#8F7F78' : $seriesColors[$i % 4] }}">{{ $fmtNumber($kpi['current']) }}</text>
                @endforeach
                <text class="ax" x="{{ $xPrev }}" y="192" text-anchor="middle">{{ $fmtShort($data['period']['previous_start']) }} – {{ $fmtShort($data['period']['previous_end']) }}</text>
                <text class="ax" x="{{ $xCur }}" y="192" text-anchor="middle">{{ $fmtShort($data['period']['start']) }} – {{ $fmtShort($data['period']['end']) }}</text>
            </svg>
            <p class="gbp-chart-note">Two reported periods, joined by a line. Google returned period totals, not a daily or monthly series, so nothing between them is drawn.</p>
        </div>
    @endif

    <div class="gbp-terms">
        <h3>Search terms people used to find you on Google</h3>
        @if ($data['keywords']['error'])
            <p class="gbp-perf-msg is-warn" style="padding:0 0 8px">Search terms could not be loaded: {{ $data['keywords']['error'] }}</p>
        @elseif ($data['keywords']['month'] === null)
            <p>Google returned no search terms for the last two months. Google only lists terms for profiles with enough searches.</p>
        @else
            <p>{{ \Carbon\Carbon::parse($data['keywords']['month'] . '-01')->format('F Y') }}, the latest complete month with data.</p>
            <ul class="gbp-term-list" data-role="gbp-search-terms">
                @foreach ($data['keywords']['items'] as $term)
                    <li>
                        <span class="gbp-break">{{ $term['term'] }}</span>
                        <span>
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
        <p>
            These counts are <strong>impressions</strong>: how often your business appeared for that search on Google. They are not ranking positions.
            Google shows &ldquo;fewer than N&rdquo; when a term was too rare to report an exact number.
        </p>
    </div>
@endforeach
