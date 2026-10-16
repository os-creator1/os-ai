{{-- Home V2 §A — greeting and the one-sentence briefing (HomeBriefing; counts only). --}}
<section class="mb-2" aria-labelledby="home-greeting-title" data-role="home-greeting">
    <h2 class="home-greeting-title mb-25" id="home-greeting-title" data-role="home-salutation">
        {{ $home['greeting']['salutation'] }}@if($home['greeting']['name']), {{ $home['greeting']['name'] }}@endif
    </h2>
    <p class="mb-50 d-flex flex-wrap align-items-center gap-50" data-role="home-subline">
        <span>{{ $home['greeting']['subline'] }}</span>
        <span class="home-status" data-role="home-status" data-status="{{ $home['status']['key'] }}">{{ $home['status']['label'] }}</span>
    </p>

    @if($home['briefing']['unavailable'])
        <p class="home-briefing mb-0 text-caption" data-role="home-briefing" data-quiet="true">We couldn't read what changed just now. Everything else below is unaffected.</p>
    @else
        <p class="home-briefing mb-0" data-role="home-briefing" data-quiet="{{ $home['briefing']['quiet'] ? 'true' : 'false' }}">{{ $home['briefing']['text'] }}</p>
    @endif
</section>
