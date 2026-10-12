{{--
    Home V2 drawer — Review an ads recommendation.

    The Ads Decision Engine reaches Home only as Growth recommendations (its
    persisted decision, with its own evidence), so this shows exactly those:
    the recommendation, why, what to expect, and the evidence. It changes NOTHING
    — MotionGrove never edits a campaign or budget on its own — and the action
    is the provider page, where any change is made and confirmed by the owner.
--}}
<div data-role="drawer-ads">
    @if($cards === [])
        <p class="mb-1" data-role="drawer-empty">There is no ads recommendation right now. That usually means either no ads are connected, or nothing has enough results yet to justify a change.</p>
        @if($action['fullUrl'])
            <a class="btn btn-sm btn-outline-primary" href="{{ $action['fullUrl'] }}">Open Ads</a>
        @endif
    @else
        <div class="home-drawer-result mb-1" data-kind="info">
            <strong>What changes:</strong> nothing automatically. These are recommendations to review — budgets and campaigns are only changed by you, in the Ads module.
        </div>

        @foreach($cards as $card)
            <article class="border rounded p-1 mb-1" data-role="ads-card">
                <h3 class="h6 mb-50" data-role="ads-card-title">{{ $card['title'] }}</h3>
                @if(! empty($card['summary']))<p class="mb-50">{{ $card['summary'] }}</p>@endif
                <p class="text-caption mb-50">{{ $card['headline'] }}</p>
                @if(! empty($card['why']))<p class="mb-50"><strong>Why:</strong> {{ $card['why'] }}</p>@endif
                @if(! empty($card['expected']))<p class="mb-50"><strong>What to expect:</strong> {{ $card['expected'] }}</p>@endif
                <p class="text-caption text-muted mb-1">{{ $card['impact'] }} impact · {{ $card['confidence'] }}@if($card['age_label']) · {{ $card['age_label'] }}@endif</p>
                <div class="d-flex flex-wrap gap-50">
                    @if($card['action_url'])
                        <a class="btn btn-sm btn-primary" href="{{ $card['action_url'] }}">{{ $card['action_label'] }}</a>
                    @endif
                    <a class="btn btn-sm btn-outline-secondary" href="{{ $card['detail_url'] }}">See the evidence</a>
                </div>
            </article>
        @endforeach
    @endif
</div>
