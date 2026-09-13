{{--
    Unified Business Home §2.5 (H-3) — Business performance.

    The period is the customer's own choice, through the SAME range control
    Results uses, and the three figures are the only canonical ones: new
    contacts, new conversations and messages received. Each sits beside its
    comparison with the equal-length period immediately before it, described
    and never praised — a rise in any of them is a factual change, not a win.

    The chart is fetched by the browser from B5's own series endpoint for the
    same range: this page renders no series of its own. "See details" opens
    Results on that same period.
--}}
<section class="mb-2" aria-labelledby="dashboard-headlines-heading" data-band="headlines" data-range="{{ $headlines['range']->cacheKey() }}">
    <x-card>
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-1 mb-50">
            <h2 class="h4 text-section-heading mb-0" id="dashboard-headlines-heading">Business performance</h2>
            @if($headlines['resultsUrl'])
                <x-button variant="outline" size="sm" :href="$headlines['resultsUrl']" data-role="results-link">See details</x-button>
            @endif
        </div>

        <div class="mb-1" data-role="performance-range">
            @include('customer.business.analytics._range', [
                'range' => $headlines['range'],
                'formAction' => $headlines['formAction'],
            ])
        </div>

        @if($headlines['rangeRejected'])
            <p class="text-caption mb-1" data-role="range-rejected">
                That date range can't be used, so this shows {{ strtolower($headlines['range']->label()) }} instead.
            </p>
        @endif

        <p class="text-caption text-muted mb-2" data-role="headline-period">
            {{ $headlines['currentLabel'] }}, compared with the {{ $headlines['previousRange']->days() }} days before it ({{ $headlines['previousLabel'] }}).
        </p>

        <div class="row g-2">
            @foreach($headlines['items'] as $headline)
                <div class="col-12 col-sm-6 col-xl-4">
                    <article class="border rounded p-1 h-100" data-role="headline" data-headline="{{ $headline->key }}"
                             data-trend="{{ $headline->comparison->trend->value }}" data-polarity="{{ $headline->polarity->value }}"
                             @if($headline->judgement !== null) data-judgement="{{ $headline->judgement }}" @endif>
                        <h3 class="h6 text-label mb-50">{{ $headline->label }}</h3>
                        <p class="h2 mb-0" data-role="headline-figure">{{ $headline->figure }}</p>
                        <p class="text-caption text-muted mb-50">{{ $headline->figureCaption }}</p>
                        <p class="mb-50" data-role="headline-comparison">{{ $headline->comparisonSentence }}</p>
                        @if($headline->judgementWord() !== null)
                            <x-badge :variant="$headline->judgementVariant()" class="mb-50" data-role="headline-judgement">{{ $headline->judgementWord() }}</x-badge>
                        @endif
                        <p class="text-caption mb-0" data-role="headline-interpretation">{{ $headline->interpretation }}</p>
                    </article>
                </div>
            @endforeach
        </div>

        @if($headlines['seriesUrl'])
            <div class="mt-2">
                <h3 class="h6 text-label mb-50" id="dashboard-contacts-chart-heading">New contacts</h3>
                <div data-role="chart-new-contacts" data-series-url="{{ $headlines['seriesUrl'] }}"
                     aria-labelledby="dashboard-contacts-chart-heading" role="img" style="min-height: 180px;">
                    <p class="text-caption text-muted mb-0" data-role="chart-placeholder">Loading the chart…</p>
                </div>
            </div>
        @endif
    </x-card>
</section>
