{{--
    "What needs attention" — the SECONDARY panel under the decision. It lists
    exceptional provider facts only (the provider's own recommendation /
    attention items, a budget overrun, a missing result type). It never
    overrides the decision panel: when the decision itself found a tracking,
    funnel or action problem, an empty list is NOT reported as "all fine".

    Expects: $attention (?array{items, count}; null = not available on this
    plan), $extraItems (list of {title, text, url, label}), $decisionState
    (?AdsDecisionState of the primary goal), $providerName, $upgradeNote (?string).
--}}
@php
    use App\Library\Ads\Decisions\AdsDecisionState;

    $items = $attention['items'] ?? [];
    $total = $attention['count'] ?? count($items);
    $extraItems = $extraItems ?? [];
    $decisionFlagsProblem = $decisionState !== null && in_array($decisionState, [AdsDecisionState::CheckTracking, AdsDecisionState::FixTheFunnel, AdsDecisionState::Act], true);
    // No judged goal yet (no goal, or no campaign assigned): the account may look calm, but nothing is judged.
    $goalSetupPending = $decisionState === null;
@endphp

<section class="cx-card" data-role="attention">
    <div class="cx-card-head is-plain">
        <h2 class="cx-card-title">What needs attention</h2>
        @if($total + count($extraItems) > 0)
            <span class="cx-pill is-warning">{{ $total + count($extraItems) }}</span>
        @endif
    </div>
    <div class="cx-card-body">
        @if($items === [] && $extraItems === [])
            @if($decisionFlagsProblem)
                <div class="cx-callout is-warning" data-role="attention-none">
                    <x-ds-icon name="alert-circle" size="15" aria-hidden="true" />
                    <span class="cx-callout-text">No {{ $providerName }} account issues found. The recommendation above still needs your attention first.</span>
                </div>
            @elseif($goalSetupPending)
                <p class="text-caption mb-0" data-role="attention-none">No {{ $providerName }} account issues found. Finish the goal setup above so MotionGrove can judge whether these ads are working.</p>
            @elseif($attention === null)
                <p class="text-caption mb-0" data-role="attention-none">{{ $upgradeNote ?? 'Nothing stands out right now.' }}</p>
            @else
                <div class="cx-callout is-success" data-role="attention-none">
                    <x-ds-icon name="check" size="15" aria-hidden="true" />
                    <span class="cx-callout-text">Nothing stands out right now.</span>
                </div>
            @endif
        @else
            <ul class="ads-attention">
                @foreach($extraItems as $extra)
                    <li data-role="attention-setup">
                        <strong>{{ $extra['title'] }}</strong>
                        <span class="text-caption">{{ $extra['text'] }}</span>
                        @if($extra['url'])
                            <a href="{{ $extra['url'] }}" class="text-caption">{{ $extra['label'] }}</a>
                        @endif
                    </li>
                @endforeach
                @foreach($items as $item)
                    <li data-role="attention-item" data-tone="{{ $item['tone'] }}">
                        <strong>{{ $item['title'] }}</strong>
                        @foreach($item['evidence_lines'] as $line)
                            <span class="text-caption">{{ $line }}</span>
                        @endforeach
                        @if($item['url'])
                            <a href="{{ $item['url'] }}" class="text-caption">{{ $item['action_label'] }}</a>
                        @endif
                    </li>
                @endforeach
            </ul>
            @if($total > count($items))
                <p class="text-caption mb-0 mt-1">Showing the {{ count($items) }} biggest of {{ $total }}.</p>
            @endif
        @endif
    </div>
</section>
