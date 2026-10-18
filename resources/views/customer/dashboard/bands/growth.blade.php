{{--
    Home = Growth Center. The first thing an owner sees: up to three things
    that need attention (plain title, one-line explanation, the factual context
    and ONE action), what is going well, and the business health score as a
    secondary figure. No confidence, impact, rule ids or fingerprints here —
    the detail page behind each item has the evidence.

    Every figure and sentence comes from the Growth readers (closed evidence +
    fixed rule copy); no AI writes any of it. "Ask Advisor" is a link to the
    existing, separately gated Advisor page.
--}}
<section class="mb-2" aria-labelledby="dashboard-growth-heading" data-band="growth">
    <x-card>
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-1 mb-1">
            <h2 class="h4 text-section-heading mb-0" id="dashboard-growth-heading">Needs your attention</h2>
            @if($growth['open_count'] > count($growth['items']))
                <a href="{{ $growth['urls']['all'] }}" data-role="growth-view-all">View all recommendations ({{ $growth['open_count'] }})</a>
            @elseif($growth['open_count'] > 0)
                <a href="{{ $growth['urls']['all'] }}" data-role="growth-view-all">View all recommendations</a>
            @endif
        </div>

        @if($growth['items'] === [])
            <p class="mb-0" data-role="growth-all-clear">You're in good shape. Nothing needs your attention right now.</p>
        @else
            <ul class="list-unstyled mb-0" data-role="growth-items">
                @foreach($growth['items'] as $item)
                    <li class="py-1 @if(! $loop->first) border-top @endif" data-role="growth-item">
                        <div class="d-flex flex-column flex-md-row align-items-md-center gap-1">
                            <div class="flex-grow-1">
                                <p class="fw-bold mb-25" data-role="growth-item-title">{{ $item['title'] }}</p>
                                @if(! empty($item['summary']))
                                    <p class="mb-25" data-role="growth-item-summary">{{ $item['summary'] }}</p>
                                @endif
                                <p class="mb-0 text-caption" data-role="growth-item-context">{{ $item['headline'] }}</p>
                            </div>
                            @if($item['action_url'] !== null)
                                <x-button size="sm" :href="$item['action_url']" data-role="growth-item-action">{{ $item['action_label'] }}</x-button>
                            @else
                                <x-button size="sm" variant="secondary" :href="$item['detail_url']" data-role="growth-item-action">See details</x-button>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif

        @if(! $growth['engine_enabled'])
            <p class="text-caption text-muted mt-1 mb-0" data-role="growth-engine-off">Recommendations will appear here once checks are switched on.</p>
        @endif
    </x-card>

    @if($growth['positives'] !== [] || $growth['score'] !== null)
        <div class="row mt-1">
            @if($growth['positives'] !== [])
                <div class="col-md-8 mb-1 mb-md-0">
                    <x-card class="h-100">
                        <h2 class="h5 text-section-heading mb-1" data-role="growth-working-heading">What's working</h2>
                        <ul class="mb-0 ps-1" data-role="growth-working">
                            @foreach($growth['positives'] as $line)
                                <li>{{ $line }}</li>
                            @endforeach
                        </ul>
                    </x-card>
                </div>
            @endif
            @if($growth['score'] !== null)
                <div class="@if($growth['positives'] !== []) col-md-4 @else col-12 @endif">
                    <x-card class="h-100">
                        <h2 class="h5 text-section-heading mb-1">Business health</h2>
                        <p class="mb-25" data-role="growth-health">
                            <span class="h3 mb-0" data-role="growth-health-score">{{ $growth['score'] }}</span>
                            @if($growth['delta'] !== null && $growth['delta'] !== 0)
                                <span class="text-muted" data-role="growth-health-delta">{{ $growth['delta'] > 0 ? '↑' : '↓' }}{{ abs($growth['delta']) }} in {{ $growth['days'] }} days</span>
                            @endif
                        </p>
                        <a href="{{ $growth['urls']['score'] }}" data-role="growth-health-link">How this is worked out</a>
                    </x-card>
                </div>
            @endif
        </div>
    @endif

    <p class="mt-1 mb-0">
        <a href="{{ $growth['urls']['advisor'] }}" data-role="growth-ask-advisor">Ask Advisor a question about your business</a>
    </p>
</section>
