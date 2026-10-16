{{--
    Home V2 §A — greeting, the one-sentence briefing (HomeBriefing; counts only) and the Business's one-line status.

    The page's single heading. The status pill is said only when the evidence supports it (HomeCommandCenter::status):
    "all caught up" is never claimed over an unreadable source.
--}}
<section class="home-greeting-row mb-2" aria-labelledby="home-greeting-title" data-role="home-greeting">
    <div class="home-greeting-text">
        <h2 class="home-greeting-title mb-25" id="home-greeting-title" data-role="home-salutation">
            {{ $home['greeting']['salutation'] }}@if($home['greeting']['name']), {{ $home['greeting']['name'] }}@endif
        </h2>

        @if($home['briefing']['unavailable'])
            <p class="home-briefing mb-0 text-caption" data-role="home-briefing" data-quiet="true">We couldn't read what changed just now. Everything else below is unaffected.</p>
        @else
            <p class="home-briefing mb-0" data-role="home-briefing" data-quiet="{{ $home['briefing']['quiet'] ? 'true' : 'false' }}">{{ $home['briefing']['text'] }}</p>
        @endif
    </div>

    <span class="home-status home-status-pill" data-role="home-status" data-status="{{ $home['status']['key'] }}" title="{{ $home['status']['label'] }}">
        <span class="home-status-dot" aria-hidden="true"></span>{{ $home['status']['pill'] }}
    </span>
</section>
