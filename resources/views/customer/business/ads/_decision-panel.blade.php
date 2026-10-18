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
@include('customer.business.ads._dashboard-styles')
@php
    $panelTone = $primary !== null ? $tone($primary['decision']) : 'neutral';
@endphp

<section class="decision-panel tone-{{ $panelTone }}" data-role="decision-panel" aria-labelledby="decision-kicker">
    <p class="decision-kicker" id="decision-kicker">What should you do now?</p>

    @if($primary !== null)
        @php
            $d = $primary['decision'];
        @endphp
        <div class="decision-grid">
            <div>
                <div class="d-flex align-items-center flex-wrap gap-1" data-role="decision-primary" data-state="{{ $d->state->value }}">
                    <x-badge :variant="$tone($d)" class="decision-state" data-role="decision-state">{{ strtoupper($d->state->label()) }}</x-badge>
                    <span class="text-caption" data-role="decision-goal">{{ $d->purposeName }}</span>
                </div>

                <h2 class="decision-headline" data-role="decision-headline">{{ $d->headline }}</h2>

                <div class="decision-reasons">
                    @foreach($d->reasons as $reason)
                        <p data-role="decision-reason">{{ $reason }}</p>
                    @endforeach
                </div>

                @if($primary['cta'] !== null)
                    <div class="decision-cta" data-role="decision-cta">
                        @if($primary['cta']['url'])
                            <a href="{{ $primary['cta']['url'] }}" class="btn btn-primary d-inline-flex align-items-center gap-1"
                               @if($primary['cta']['external']) target="_blank" rel="noopener noreferrer" @endif>{{ $primary['cta']['label'] }}
                                <x-ds-icon :name="$primary['cta']['external'] ? 'external-link' : 'arrow-right'" size="15" aria-hidden="true" /></a>
                        @else
                            <p class="mb-0 text-caption" data-role="decision-cta-instruction">Next step: {{ strtolower($primary['cta']['label']) }} in your ad account.</p>
                        @endif
                    </div>
                @endif

                @foreach($d->notes as $note)
                    <p class="decision-note" data-role="decision-note">{{ $note }}</p>
                @endforeach
            </div>

            <aside class="decision-side" aria-label="Evidence and next review">
                @if($d->evidence !== [])
                    <p class="decision-kicker mb-50">Evidence</p>
                    <ul class="decision-evidence">
                        @foreach(array_slice($d->evidence, 0, 4) as $row)
                            <li><span>{{ $row['label'] }}</span><span>{{ $row['value'] }}</span></li>
                        @endforeach
                    </ul>
                @endif
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
            </aside>
        </div>

        <details data-role="decision-why">
            <summary>Why am I seeing this?</summary>
            <dl class="row mt-1 mb-0">
                @foreach($d->evidence as $row)
                    <dt class="col-sm-6">{{ $row['label'] }}</dt>
                    <dd class="col-sm-6" data-evidence="{{ \Illuminate\Support\Str::slug($row['label']) }}">{{ $row['value'] }}</dd>
                @endforeach
            </dl>
            <p class="text-caption mb-0">A deterministic rule produced this from your own numbers. It is not an AI opinion and it does not change unless the numbers do.</p>
        </details>

        @if($others !== [])
            <p class="decision-others-title">Your other goals</p>
        @endif
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
        <div class="decision-reasons">
            <p data-role="decision-reason">Ads Manager shows numbers. MotionGrove can tell you what to do next, but only once it knows the goal these ads serve (for example, enrolling students), where those leads go, and what a good result costs you.</p>
        </div>
        @if($goalsUrl)
            <div class="decision-cta"><a href="{{ $goalsUrl }}" class="btn btn-primary" data-role="decision-cta">Set up a goal</a></div>
        @endif
    @else
        <h2 class="decision-headline" data-role="decision-headline">Assign a goal to your campaigns.</h2>
        <div class="decision-reasons">
            <p data-role="decision-reason">You have goals set up, but none of this provider's campaigns is assigned to one, so MotionGrove cannot judge them yet.</p>
        </div>
        @if($goalsUrl)
            <div class="decision-cta"><a href="{{ $goalsUrl }}" class="btn btn-primary" data-role="decision-cta">Assign campaigns to a goal</a></div>
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
    <div class="outcome-kpis" data-role="outcome-kpis">
        @foreach($primary['decision']->kpis as $kpi)
            <div class="outcome-kpi" data-kpi="{{ \Illuminate\Support\Str::slug($kpi['label']) }}">
                <p class="label">{{ $kpi['label'] }}</p>
                <p class="value">{{ $kpi['value'] }}</p>
                @if($kpi['note'])
                    <p class="note">{{ $kpi['note'] }}</p>
                @endif
            </div>
        @endforeach
    </div>
    <p class="text-caption mb-2" data-role="outcome-kpi-caption">Business outcomes for {{ $primary['decision']->purposeName }}. The provider's own figures follow below.</p>
@endif
@endif
