{{--
    Rank history chart — plain inline SVG, no chart library. Rank 1 (best) is at
    the TOP. Lines join only consecutive found checks; a check where we were not
    found is a hollow marker on the baseline and breaks the line. No
    interpolation across missing checks. @param array $chart SeoRankChart::build()
--}}
@if(! $chart['has_data'])
    <p class="text-muted mb-0" data-role="chart-empty">No completed checks yet.</p>
@else
    <svg viewBox="0 0 {{ $chart['width'] }} {{ $chart['height'] }}" class="w-100" role="img" aria-label="{{ $label }} over time" data-role="rank-chart">
        @foreach($chart['ticks'] as $tick)
            <line x1="40" x2="{{ $chart['width'] - 14 }}" y1="{{ $tick['y'] }}" y2="{{ $tick['y'] }}" stroke="currentColor" stroke-opacity="0.12"/>
            <text x="34" y="{{ $tick['y'] + 4 }}" text-anchor="end" font-size="11" fill="currentColor" fill-opacity="0.6">{{ $tick['label'] }}</text>
        @endforeach
        @foreach($chart['segments'] as $d)
            <path d="{{ $d }}" fill="none" stroke="#7367f0" stroke-width="2" stroke-linejoin="round" data-role="chart-segment"/>
        @endforeach
        @foreach($chart['points'] as $p)
            <circle cx="{{ $p['x'] }}" cy="{{ $p['y'] }}" r="3.5" fill="#7367f0" data-role="chart-point" data-position="{{ $p['position'] }}"><title>{{ $p['label'] }}</title></circle>
        @endforeach
        @foreach($chart['gaps'] as $g)
            <circle cx="{{ $g['x'] }}" cy="{{ $g['y'] }}" r="3.5" fill="none" stroke="currentColor" stroke-opacity="0.5" data-role="chart-gap"><title>{{ $g['label'] }}</title></circle>
        @endforeach
        <text x="40" y="{{ $chart['height'] - 8 }}" font-size="11" fill="currentColor" fill-opacity="0.6">{{ $chart['x_start'] }}</text>
        <text x="{{ $chart['width'] - 14 }}" y="{{ $chart['height'] - 8 }}" text-anchor="end" font-size="11" fill="currentColor" fill-opacity="0.6">{{ $chart['x_end'] }}</text>
    </svg>
    <p class="text-caption mb-0">Higher on the chart is better. Hollow markers are checks where the result was not found.</p>
@endif
