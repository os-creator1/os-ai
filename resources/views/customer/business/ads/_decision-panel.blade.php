{{--
    Acquisition Purpose + Ads Decisioning V1 — "What should you do now?".

    THE PRIMARY ELEMENT of every provider page. It sits directly under the page
    navigation and ABOVE the period controls and KPIs: the owner reads what to
    do, why, what NOT to change, when enough evidence will exist to look again
    and where to click, before any provider metric.

    `$decisionView` is built by AdsDecisionPresenter from deterministic facts
    (AdsDecisionEngine). Nothing here is AI-written. A call to action is a real
    link or it is not drawn: there is no placeholder button. Every string is
    escaped. Colour is never the only carrier: the verdict is printed in words.
--}}
@php
    $view = $decisionView ?? null;
    $primary = $view['primary'] ?? null;
    $others = $view['others'] ?? [];
    $unassigned = $view['unassigned'] ?? [];
    $goalsUrl = $view['goalsUrl'] ?? null;
    $tone = static fn ($decision) => $decision->state->tone();
@endphp

@if($view !== null)
<style>
    .decision-panel { background: var(--color-surface, #fff); border: 1px solid var(--color-border, #e7e2d9); border-radius: var(--radius-lg, 12px); padding: 1.25rem 1.5rem; }
    .decision-panel .decision-kicker { letter-spacing: .08em; text-transform: uppercase; font-size: .75rem; color: var(--color-text-muted, #6b6b6b); margin: 0 0 .5rem; }
    .decision-panel .decision-state { font-weight: 700; letter-spacing: .04em; }
    .decision-panel .decision-headline { font-size: 1.25rem; line-height: 1.35; margin: .5rem 0 .75rem; color: var(--color-text-primary, #1c1c1c); }
    .decision-panel .decision-meta { border-top: 1px solid var(--color-border-subtle, #eee9df); margin-top: .75rem; padding-top: .75rem; }
    .decision-panel dl { margin: 0; }
    .decision-panel dt { font-weight: 600; color: var(--color-text-secondary, #444); }
    .decision-panel dd { margin: 0 0 .5rem; }
    .decision-panel details summary { cursor: pointer; font-weight: 600; }
    .decision-other { border-top: 1px solid var(--color-border-subtle, #eee9df); padding: .75rem 0 0; margin-top: .75rem; }
    @media (max-width: 575.98px) { .decision-panel { padding: 1rem; } .decision-panel .decision-headline { font-size: 1.1rem; } }
</style>

<section class="decision-panel mb-2" data-role="decision-panel" aria-labelledby="decision-kicker">
    <p class="decision-kicker" id="decision-kicker">What should you do now?</p>

    @if($primary !== null)
        @php
            $d = $primary['decision'];
        @endphp
        <div class="d-flex align-items-center flex-wrap gap-1" data-role="decision-primary" data-state="{{ $d->state->value }}">
            <x-badge :variant="$tone($d)" class="decision-state" data-role="decision-state">{{ strtoupper($d->state->label()) }}</x-badge>
            <span class="text-muted" data-role="decision-goal">{{ $d->purposeName }}</span>
        </div>

        <h2 class="decision-headline" data-role="decision-headline">{{ $d->headline }}</h2>

        @foreach($d->reasons as $reason)
            <p class="mb-50" data-role="decision-reason">{{ $reason }}</p>
        @endforeach

        <div class="decision-meta">
            <dl>
                @if($d->doNotChange)
                    <dt>What not to change</dt>
                    <dd data-role="decision-do-not-change">{{ $d->doNotChange }}</dd>
                @endif
                @if($d->nextReview)
                    <dt>Next review</dt>
                    <dd data-role="decision-next-review">{{ $d->nextReview }}</dd>
                @endif
            </dl>

            @foreach($d->notes as $note)
                <p class="text-caption text-muted mb-50" data-role="decision-note">{{ $note }}</p>
            @endforeach

            @if($primary['cta'] !== null)
                <div class="mt-1" data-role="decision-cta">
                    @if($primary['cta']['url'])
                        <a href="{{ $primary['cta']['url'] }}" class="btn btn-primary"
                           @if($primary['cta']['external']) target="_blank" rel="noopener noreferrer" @endif>{{ $primary['cta']['label'] }}</a>
                    @else
                        <p class="mb-0 text-muted" data-role="decision-cta-instruction">Next step: {{ strtolower($primary['cta']['label']) }} in your ad account.</p>
                    @endif
                </div>
            @endif
        </div>

        <details class="mt-1" data-role="decision-why">
            <summary>Why am I seeing this?</summary>
            <dl class="row mt-1 mb-0">
                @foreach($d->evidence as $row)
                    <dt class="col-sm-6">{{ $row['label'] }}</dt>
                    <dd class="col-sm-6" data-evidence="{{ \Illuminate\Support\Str::slug($row['label']) }}">{{ $row['value'] }}</dd>
                @endforeach
            </dl>
            <p class="text-caption text-muted mb-0">A deterministic rule produced this from your own numbers. It is not an AI opinion and it does not change unless the numbers do.</p>
        </details>

        @foreach($others as $other)
            @php
                $od = $other['decision'];
            @endphp
            <div class="decision-other" data-role="decision-other" data-state="{{ $od->state->value }}">
                <div class="d-flex align-items-center flex-wrap gap-1">
                    <x-badge :variant="$tone($od)">{{ strtoupper($od->state->label()) }}</x-badge>
                    <strong>{{ $od->purposeName }}</strong>
                </div>
                <p class="mb-50 mt-50">{{ $od->headline }}</p>
                @if($other['cta'] !== null && $other['cta']['url'])
                    <a href="{{ $other['cta']['url'] }}" @if($other['cta']['external']) target="_blank" rel="noopener noreferrer" @endif>{{ $other['cta']['label'] }}</a>
                @endif
            </div>
        @endforeach
    @elseif(! ($view['hasGoals'] ?? false))
        <h2 class="decision-headline" data-role="decision-headline">Tell MotionGrove what these ads are for.</h2>
        <p class="mb-1" data-role="decision-reason">Ads Manager shows numbers. MotionGrove can tell you what to do next, but only once it knows the goal these ads serve (for example, enrolling students), where those leads go, and what a good result costs you.</p>
        @if($goalsUrl)
            <a href="{{ $goalsUrl }}" class="btn btn-primary" data-role="decision-cta">Set up a goal</a>
        @endif
    @else
        <h2 class="decision-headline" data-role="decision-headline">Assign a goal to your campaigns.</h2>
        <p class="mb-1" data-role="decision-reason">You have goals set up, but none of this provider's campaigns is assigned to one, so MotionGrove cannot judge them yet.</p>
        @if($goalsUrl)
            <a href="{{ $goalsUrl }}" class="btn btn-primary" data-role="decision-cta">Assign campaigns to a goal</a>
        @endif
    @endif

    @if($unassigned !== [])
        <div class="decision-other" data-role="decision-unassigned">
            <p class="mb-50"><strong>Assign a goal so MotionGrove can judge {{ count($unassigned) === 1 ? 'this campaign' : 'these campaigns' }} properly.</strong></p>
            <ul class="mb-50 ps-2">
                @foreach($unassigned as $campaign)
                    <li>{{ $campaign['name'] }}</li>
                @endforeach
            </ul>
            @if($goalsUrl)
                <a href="{{ $goalsUrl }}">Assign a goal</a>
            @endif
        </div>
    @endif
</section>

{{-- The Business-outcome KPI row of the primary goal: spend, qualified leads, cost per qualified lead, outcomes, cost per outcome. A dash is "not enough data", never zero. --}}
@if($primary !== null && $primary['decision']->kpis !== [])
    <div class="row" data-role="outcome-kpis">
        @foreach($primary['decision']->kpis as $kpi)
            <div class="col-6 col-lg mb-2">
                <x-card class="h-100" data-kpi="{{ \Illuminate\Support\Str::slug($kpi['label']) }}">
                    <p class="text-label mb-1">{{ $kpi['label'] }}</p>
                    <p class="h3 mb-0">{{ $kpi['value'] }}</p>
                    @if($kpi['note'])
                        <p class="text-caption text-muted mb-0">{{ $kpi['note'] }}</p>
                    @endif
                </x-card>
            </div>
        @endforeach
    </div>
    <p class="text-caption text-muted mb-2" data-role="outcome-kpi-caption">Business outcomes for {{ $primary['decision']->purposeName }}. The provider's own figures follow below.</p>
@endif
@endif
