{{--
    Home V2 — what the Growth Center knows beyond the one move: what is going
    well, the business health score (a secondary figure, with how it is worked
    out one click away) and the Advisor entry point. All of it is the Growth
    readers' own output, passed through unchanged.
--}}
<section class="mb-2" aria-labelledby="home-growth-insights-heading" data-band="growth_insights" data-role="home-growth-insights">
    <h2 class="visually-hidden" id="home-growth-insights-heading">How the business is doing</h2>

    @if($growth['positives'] !== [] || $growth['score'] !== null)
        <div class="row g-2">
            @if($growth['positives'] !== [])
                <div class="col-12 @if($growth['score'] !== null) col-md-8 @endif">
                    <x-card class="h-100">
                        <h3 class="h5 text-section-heading mb-1" data-role="growth-working-heading">What's working</h3>
                        <ul class="mb-0 ps-1" data-role="growth-working">
                            @foreach($growth['positives'] as $line)
                                <li>{{ $line }}</li>
                            @endforeach
                        </ul>
                    </x-card>
                </div>
            @endif

            @if($growth['score'] !== null)
                <div class="col-12 @if($growth['positives'] !== []) col-md-4 @endif">
                    <x-card class="h-100">
                        <h3 class="h5 text-section-heading mb-1">Business health</h3>
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
