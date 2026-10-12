{{--
    Home V2 §B — the secondary queue: other recommendations, behind the one
    move. Same Growth engine items, nothing re-scored; they never take the
    primary slot.
--}}
<section class="mb-2" aria-labelledby="home-queue-heading" data-band="growth" data-role="home-queue">
    <x-card>
        <div class="d-flex flex-wrap align-items-baseline justify-content-between gap-1 mb-1">
            <h2 class="h5 text-section-heading mb-0" id="home-queue-heading">
                @if($emphasis) Ways to grow from here @else Also worth a look @endif
            </h2>
            @if($home['queueUrl'])
                <a href="{{ $home['queueUrl'] }}" class="text-caption" data-role="growth-view-all">See all recommendations @if($home['queueCount'] > 0)({{ $home['queueCount'] }})@endif</a>
            @endif
        </div>
        <ul class="list-unstyled mb-0" data-role="growth-items">
            @foreach($home['queue'] as $item)
                <li class="d-flex flex-column flex-md-row align-items-md-center gap-1 py-50 @unless($loop->first) border-top @endunless" data-role="growth-item">
                    <div class="flex-grow-1">
                        <p class="fw-bold mb-0" data-role="growth-item-title">{{ $item['title'] }}</p>
                        <p class="text-caption text-muted mb-0">{{ $item['context'] }}@if($item['awaitingApproval']) · Waiting for your approval @endif</p>
                    </div>
                    <a href="{{ $item['url'] }}" class="btn btn-sm btn-outline-secondary" data-role="growth-item-action">See details</a>
                </li>
            @endforeach
        </ul>
    </x-card>
</section>
