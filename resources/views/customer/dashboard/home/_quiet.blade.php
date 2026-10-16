{{--
    Home V2 — "While it's quiet": practical next steps for a Business with nothing waiting on it.

    Each row exists only because HomeCommandCenter::quietSteps found its page open to THIS actor (permission and plan checked by
    HomeActionRouter) — a step the actor cannot use is absent, never disabled. A step with a Home drawer opens it in place, the
    same way the move's action does.
--}}
@php
    $icons = [
        'booking-type' => '<rect x="3" y="4" width="14" height="13" rx="2"/><path d="M3 8h14M7 2v4M13 2v4"/>',
        'form' => '<rect x="4" y="3" width="12" height="14" rx="2"/><path d="M7 8h6M7 11h6M7 14h3"/>',
        'contact' => '<circle cx="8" cy="7" r="3"/><path d="M2.5 16c.6-3 3-4.5 5.5-4.5M15 8v6M12 11h6"/>',
    ];
@endphp
<section class="h-100" aria-labelledby="home-quiet-heading" data-band="quiet_steps" data-role="home-quiet">
    <x-card class="h-100 home-panel">
        <h2 class="home-panel-title mb-0" id="home-quiet-heading">While it's quiet</h2>
        <p class="text-caption text-muted mb-1">A few ways to bring in your next contact.</p>
        <ul class="list-unstyled mb-0 home-rows" data-role="quiet-steps">
            @foreach($steps as $step)
                <li>
                    <a class="home-row" href="{{ $step['url'] }}" data-role="quiet-step" data-step="{{ $step['key'] }}"
                       @if($step['drawerUrl'] !== null) data-home-drawer="{{ $step['drawerUrl'] }}" data-drawer-title="{{ $step['title'] }}" @endif>
                        <span class="home-row-icon" aria-hidden="true"><svg viewBox="0 0 20 20" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">{!! $icons[$step['key']] ?? '' !!}</svg></span>
                        <span class="home-row-text"><strong>{{ $step['title'] }}</strong><span class="text-caption text-muted">{{ $step['text'] }}</span></span>
                        <span class="home-row-chevron" aria-hidden="true">›</span>
                    </a>
                </li>
            @endforeach
        </ul>
    </x-card>
</section>
