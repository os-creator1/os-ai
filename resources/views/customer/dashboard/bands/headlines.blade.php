{{--
    Customer Experience Slice 4 §4.1–§4.5 — the fixed last-30-days row. Every
    figure sits beside its comparison with the previous 30 days and an honest
    reading of it; volume is described, never praised. No range picker and no
    chart: Results holds the detail.
--}}
<section class="mb-2" aria-labelledby="dashboard-headlines-heading" data-band="headlines">
    <x-card>
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-1 mb-50">
            <h2 class="h4 text-section-heading mb-0" id="dashboard-headlines-heading">Business performance</h2>
            @if($headlines['resultsUrl'])
                <x-button variant="outline" size="sm" :href="$headlines['resultsUrl']" data-role="results-link">See full results</x-button>
            @endif
        </div>
        <p class="text-caption text-muted mb-2" data-role="headline-period">
            {{ $headlines['currentLabel'] }}, compared with the previous 30 days ({{ $headlines['previousLabel'] }}).
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
    </x-card>
</section>
