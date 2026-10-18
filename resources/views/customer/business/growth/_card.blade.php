{{--
    One Opportunity card. $card is GrowthOpportunityPresenter::present().
    Everything here is escaped output of fixed copy + stored evidence figures.
    $compact hides the snooze/dismiss menus (used on the Overview).
--}}
@php
    $compact = $compact ?? false;
    $impactClass = 'impact-' . strtolower($card['impact']);
    $growthRoute = fn (string $name) => route('customer.workspaces.businesses.growth.' . $name, [$workspaceUid, $businessUid, $card['uid']]);
    $e = $card['evidence'];
    $isOpen = $card['state'] === 'open';
@endphp
<article class="gc-opp {{ $impactClass }} @if(! $isOpen) is-muted @endif" data-role="opportunity-card" data-rule="{{ $card['rule_key'] }}" data-state="{{ $card['state'] }}" data-impact="{{ strtolower($card['impact']) }}">
    <div class="gc-opp-meta">
        <span class="gc-pill {{ $impactClass }}" data-role="impact">{{ strtoupper($card['impact']) }} IMPACT</span>
        <span data-role="category">{{ $card['category_label'] }}</span>
        @if($card['location_name'])
            <span data-role="location"><x-ds-icon name="map-pin" size="12" /> {{ $card['location_name'] }}</span>
        @endif
        <span data-role="age">{{ $card['age_label'] }}</span>
        @if(! $isOpen)
            <span class="gc-pill @if($card['state'] === 'resolved') is-ok @endif" data-role="state">{{ $card['state_label'] }}@if($card['state'] === 'snoozed' && $card['snoozed_until']) until {{ $card['snoozed_until']->format('M j') }}@endif</span>
        @endif
    </div>

    <h3 class="gc-opp-headline"><a href="{{ $growthRoute('opportunities.show') }}" data-role="headline">{{ $card['headline'] }}</a></h3>

    <p class="gc-opp-why" data-role="why">{{ $card['why'] ?? $card['summary'] }}</p>

    @if(($e['value'] ?? null) || ($e['count'] ?? null) || ! empty($e['phrases']))
        <ul class="gc-facts" data-role="evidence">
            @isset($e['count'])<li><x-ds-icon name="list-checks" size="14" /> <span><strong>{{ $e['count'] }}</strong> {{ \Illuminate\Support\Str::plural('record', $e['count']) }}</span></li>@endisset
            @isset($e['value'])<li><x-ds-icon name="banknote" size="14" /> <span><strong>{{ $e['value'] }}</strong> at stake</span></li>@endisset
            @if(! empty($e['phrases']))<li><x-ds-icon name="hash" size="14" /> <span>{{ implode(', ', array_slice($e['phrases'], 0, 3)) }}</span></li>@endif
            <li><x-ds-icon name="shield-check" size="14" /> <span data-role="confidence">{{ $card['confidence'] }}</span></li>
        </ul>
    @endif

    @if($isOpen || $card['state'] === 'snoozed')
        <div class="gc-opp-actions">
            @if($card['action_url'])
                <x-button :href="$growthRoute('opportunities.go')" variant="primary" size="sm" icon="arrow-right" data-role="primary-action">{{ $card['action_label'] }}</x-button>
            @endif
            <x-button :href="$growthRoute('opportunities.show')" variant="secondary" size="sm" data-role="view-details">Details</x-button>

            @unless($compact)
                @include('customer.business.growth._menus', ['card' => $card])
            @endunless
        </div>
    @elseif($card['state'] === 'dismissed')
        <div class="gc-opp-actions">
            <form method="POST" action="{{ $growthRoute('opportunities.reopen') }}">@csrf
                <x-button type="submit" variant="secondary" size="sm" data-role="reopen">Reopen</x-button>
            </form>
        </div>
    @endif
</article>
