{{--
    Is my business growing? — three cards, one quiet revenue prompt, one short chart. Every figure is the existing Business
    performance band's own (the same B5 comparison, range logic and Business-local days Results uses); HomeV2Presenter only decides
    how it is shown: a dash for "nothing collected", "First month" when there is no earlier period to compare, "Same", a green ▲ n,
    an amber ▼ n, "Updating…" while an import runs. A source that failed says so; it is never shown as zero.

    The range control is the shared GET form (7 days / This month / 30 days / Custom) and the whole section is the existing
    `business-performance` async region, so changing the period updates only this section and the URL keeps the choice.
--}}
@php
    use App\Library\Dashboard\DashboardSnapshot;

    $band = $dashboard->has(DashboardSnapshot::BAND_HEADLINES) ? $dashboard->band(DashboardSnapshot::BAND_HEADLINES) : null;
@endphp
<section class="hv2-section home-performance" aria-labelledby="hv2-growth-title" data-band="headlines"@if($growth['failed'] || $band === null) data-band-state="failed"@endif data-role="home-growth" data-async-region="business-performance"@if($band !== null) data-range="{{ $band['range']->cacheKey() }}"@endif>
    <div class="hv2-growth__head">
        <h2 class="hv2-label" id="hv2-growth-title"><span class="hv2-num" aria-hidden="true">2</span>Is my business growing?</h2>
        @if($band !== null)
            <div data-role="performance-range" class="hv2-range">
                @include('customer.business.analytics._range', [
                    'range' => $band['range'],
                    'formAction' => $band['formAction'],
                    'asyncRegion' => 'business-performance',
                    'segmented' => true,
                ])
            </div>
        @endif
    </div>

    @if($growth['failed'] || $band === null)
        <div class="hv2-card hv2-quiet hv2-quiet--warn" data-role="growth-unavailable">
            <span class="hv2-check hv2-check--warn" aria-hidden="true">!</span>
            <div><strong>These figures are unavailable right now</strong><p class="hv2-muted mb-0">We couldn't read them, so nothing is shown rather than a wrong number. Refresh in a moment.</p></div>
        </div>
    @else
        <p class="hv2-muted mb-50" data-role="headline-period">{{ $band['currentLabel'] }}, compared with the {{ $band['previousRange']->days() }} days before it ({{ $band['previousLabel'] }}).</p>

        @if($band['rangeRejected'])
            <p class="hv2-muted" data-role="range-rejected">That date range can't be used, so this shows {{ strtolower($band['range']->label()) }} instead.</p>
        @endif

        <div class="hv2-kpis" data-role="kpis" style="--hv2-kpis: {{ max(1, count($growth['kpis'])) }}">
            @foreach($growth['kpis'] as $kpi)
                @php $tag = $kpi['url'] === null ? 'div' : 'a'; @endphp
                <{{ $tag }} class="hv2-kpi" @if($kpi['url'] !== null) href="{{ $kpi['url'] }}" @endif data-role="kpi" data-kpi="{{ $kpi['key'] }}"
                    data-headline="{{ ['bookings' => 'appointments_booked', 'contacts' => 'new_contacts', 'conversations' => 'new_conversations'][$kpi['key']] }}">
                    <span class="hv2-kpi__label">{{ $kpi['label'] }}@if($kpi['url'] !== null)<span class="hv2-kpi__arrow" aria-hidden="true">↗</span>@endif</span>
                    <span class="hv2-kpi__value" data-role="headline-figure">{{ $kpi['value'] }}</span>
                    @if($kpi['badge'] !== null)
                        <span class="hv2-badge hv2-badge--{{ $kpi['badge']['tone'] }}" data-role="kpi-badge" data-tone="{{ $kpi['badge']['tone'] }}" aria-label="{{ $kpi['badge']['sr'] }}">{{ $kpi['badge']['text'] }}</span>
                    @endif
                    @if($kpi['note'])<span class="hv2-kpi__note" data-role="kpi-note">{{ $kpi['note'] }}</span>@endif
                </{{ $tag }}>
            @endforeach
        </div>

        @if($growth['revenue'] !== null)
            <div class="hv2-revenue" data-role="revenue-prompt">
                <span><strong>Track revenue</strong> by connecting payments</span>
                <a href="{{ $growth['revenue']['url'] }}" data-role="revenue-connect">{{ $growth['revenue']['label'] }}</a>
            </div>
        @endif

        @if($growth['chart'] !== null)
            @include('customer.dashboard.home.v2._chart', ['chart' => $growth['chart']])
        @endif

        @include('customer.dashboard.home.v2._insight', ['headlines' => $band])
    @endif
</section>
