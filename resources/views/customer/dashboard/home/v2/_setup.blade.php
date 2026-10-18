{{--
    The new-business Home: a short "Get set up" checklist beside Ask, and a quiet note that results come with activity.

    Every step is done ONLY because saved state says so (HomeV2Presenter::welcome): a published website, a connected mailbox, a priced
    catalog item, a booking type that can take a booking, an active automation. Nothing is done because a button was pressed, and a
    step this Business cannot do is simply absent. Exactly one step is highlighted.
--}}
@php
    $welcome = $v2['welcome'];
    // The Results page exists from day one (empty until activity), so it stays reachable for anyone allowed to see it.
    $resultsUrl = $dashboard->has(\App\Library\Dashboard\DashboardSnapshot::BAND_HEADLINES) ? ($dashboard->band(\App\Library\Dashboard\DashboardSnapshot::BAND_HEADLINES)['resultsUrl'] ?? null) : null;
@endphp
<section class="hv2-greeting" aria-labelledby="home-greeting-title" data-role="home-greeting">
    <h2 class="hv2-greeting__title" id="home-greeting-title" data-role="home-salutation">Welcome{{ $greetingName ? ', ' . $greetingName : '' }}</h2>
    <p class="hv2-greeting__sub" data-role="home-subline">A few steps and MotionGrove starts bringing in clients for you.</p>
</section>

<div class="hv2-layout hv2-layout--setup">
    <div class="hv2-main">
        <section class="hv2-card hv2-setup" aria-labelledby="hv2-setup-title" data-band="setup" data-role="home-setup">
            <div class="hv2-setup__head">
                <h3 class="hv2-setup__title" id="hv2-setup-title">Get set up</h3>
                <div class="hv2-progress" data-role="setup-progress">
                    <span class="hv2-progress__bar" aria-hidden="true"><span style="width: {{ $welcome['total'] > 0 ? round($welcome['done'] / $welcome['total'] * 100) : 0 }}%"></span></span>
                    <span>{{ $welcome['done'] }} of {{ $welcome['total'] }}</span>
                </div>
            </div>
            <ol class="hv2-steps" data-role="setup-steps">
                @foreach($welcome['steps'] as $step)
                    <li class="hv2-step {{ $step['done'] ? 'is-done' : '' }} {{ $welcome['nextKey'] === $step['key'] ? 'is-next' : '' }}" data-role="setup-step" data-step="{{ $step['key'] }}"
                        data-done="{{ $step['done'] ? 'true' : 'false' }}" data-next="{{ $welcome['nextKey'] === $step['key'] ? 'true' : 'false' }}">
                        <span class="hv2-step__mark" aria-hidden="true">{{ $step['done'] ? '✓' : $loop->iteration }}</span>
                        <span class="hv2-step__text">
                            <strong>{{ $step['title'] }}@if($step['done'])<span class="visually-hidden"> — done</span>@endif</strong>
                            @if($step['sub'] && ! $step['done'])<small>{{ $step['sub'] }}</small>@endif
                        </span>
                        @if(! $step['done'] && $step['url'] !== null)
                            <a class="btn btn-sm {{ $welcome['nextKey'] === $step['key'] ? 'btn-primary' : 'btn-outline-secondary' }}" href="{{ $step['url'] }}" data-role="setup-step-action">{{ $step['label'] }}</a>
                        @endif
                    </li>
                @endforeach
            </ol>
        </section>
    </div>

    <aside class="hv2-side" aria-label="Ask MotionGrove">
        @include('customer.dashboard.home._ask', ['home' => $home, 'v2Chips' => null, 'maxChips' => 2])
        <div class="hv2-card hv2-results-note" data-role="results-later">
            <strong>Your results will show here</strong>
            <p class="hv2-muted mb-0">Bookings, new contacts and what MotionGrove did for you, once your first clients come in.@if($resultsUrl) <a href="{{ $resultsUrl }}" data-role="results-link">See all analytics</a>@endif</p>
        </div>
    </aside>
</div>
