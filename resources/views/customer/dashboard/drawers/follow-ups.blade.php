{{--
    Home V2 drawer — Review customer follow-ups.

    READ: the conversations where the customer wrote last and nobody has
    answered (the same predicate and Location rule as the inbox and the Home
    count). EXECUTE: each reply posts to the inbox's OWN reply endpoint, so
    messaging readiness, funding, opt-out and idempotency are its, not ours.
    Nothing is sent until the customer presses Send on that row.
--}}
<div data-role="drawer-follow-ups">
    <p class="mb-1">
        These customers wrote last and haven't had an answer yet.
        @if($total > count($items))
            Showing the {{ count($items) }} that have waited longest of {{ number_format($total) }}.
        @endif
    </p>

    @if($items === [])
        <p class="mb-0" data-role="drawer-empty">Nobody is waiting for a reply right now.</p>
    @endif

    @foreach($items as $item)
        <form class="home-drawer-form border rounded p-1 mb-1" data-home-form="reply" action="{{ $item['replyUrl'] }}" method="POST" data-role="follow-up-item">
            @csrf
            <input type="hidden" name="idempotency_token" value="{{ $item['token'] }}">
            <div class="d-flex flex-wrap justify-content-between gap-50 mb-50">
                <strong data-role="follow-up-name">{{ $item['name'] }}</strong>
                <time class="text-caption text-muted" datetime="{{ $item['at']->toIso8601String() }}">{{ $item['at']->diffForHumans() }}</time>
            </div>
            @if($item['name'] !== $item['phone'])
                <p class="text-caption text-muted mb-50">{{ $item['phone'] }}</p>
            @endif
            <blockquote class="mb-1 ps-1 border-start" data-role="follow-up-snippet">{{ $item['snippet'] }}</blockquote>

            <label class="visually-hidden" for="reply-{{ $loop->index }}">Your reply to {{ $item['name'] }}</label>
            <textarea id="reply-{{ $loop->index }}" name="message" class="form-control mb-50" rows="3" maxlength="1600" required
                      placeholder="Write a reply — you review it before it is sent"></textarea>
            <x-button type="submit" size="sm" data-role="follow-up-send">Send reply</x-button>
        </form>
    @endforeach

    <p class="text-caption text-muted mb-0">Replies go out through your business's messaging, exactly as if sent from the inbox.</p>
</div>
