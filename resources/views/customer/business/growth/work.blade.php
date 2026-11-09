{{--
    Growth Center — Work history. One record of Growth work in one vocabulary (GrowthWorkStage), built only from the Opportunity and its
    latest action execution. "Verified" means the approved change was re-read from the saved record; it is never a business result.
--}}
@extends('layouts/contentLayoutMaster')

@section('title', 'Growth work history')

@section('page-style')
    @include('customer.business.growth._styles')
@endsection

@section('content')
    @include('customer.business.growth._header')

    <div class="gc" data-surface="growth-work">
        <p class="gc-subtitle" data-role="work-explainer">What MotionGrove has recommended, what you approved, what ran and what was confirmed. A finished action shows that a change was made, not that enquiries or bookings changed.</p>

        @php $c = $connected ?? ['booking' => null, 'replies' => null, 'activity' => null]; @endphp

        @if($rows === [])
            <div class="gc-panel" data-role="work-empty">
                <p class="gc-empty-line mb-0">Nothing has been approved or completed yet. When you approve a recommendation it appears here with its status.</p>
            </div>
        @else
            <ul class="gc-list" data-role="work-rows">
                @foreach($rows as $row)
                    <li class="gc-panel mb-1" data-role="work-row" data-uid="{{ $row['uid'] }}" data-stage="{{ $row['stage']['key'] }}">
                        <div class="gc-opp-meta mb-50">
                            <span class="gc-pill @if(in_array($row['stage']['key'], ['verified', 'completed'], true)) is-ok @endif" data-role="work-stage">{{ $row['stage']['label'] }}</span>
                            <span>{{ $row['category'] }}</span>
                            @if($row['at'])<time datetime="{{ $row['at']->toIso8601String() }}">{{ $row['at']->diffForHumans() }}</time>@endif
                        </div>
                        <a class="gc-link" href="{{ $row['url'] }}" data-role="work-headline">{{ $row['headline'] }}</a>
                        @if($row['stage']['note'])<p class="mb-0 mt-50" data-role="work-note">{{ $row['stage']['note'] }}</p>@endif
                        @if($row['result'])<p class="mb-0 mt-25 text-muted" data-role="work-result">{{ $row['result'] }}</p>@endif
                    </li>
                @endforeach
            </ul>
            {{ $paginator->links() }}
        @endif

        @if($c['booking'] !== null)
            <section class="gc-panel mb-1" id="booking" data-role="work-booking">
                <h3 class="gc-panel-title">Booking setup</h3>
                @if($c['booking']['unavailable'])
                    <p class="mb-0" data-role="work-unavailable">We couldn't check your booking setup just now.</p>
                @elseif($c['booking']['activeTypeCount'] === 0)
                    <p class="mb-50" data-role="booking-status" data-state="needs_setup"><strong>Needs setup</strong> — no booking type is active, so customers cannot book online.</p>
                    @if($c['booking']['createUrl'])<a class="gc-link" href="{{ $c['booking']['createUrl'] }}" data-role="booking-create">Create a booking type</a>@endif
                @else
                    <p class="mb-50" data-role="booking-status" data-state="{{ $c['booking']['problems'] === [] ? 'ready' : 'needs_setup' }}">
                        <strong>{{ $c['booking']['problems'] === [] ? 'Ready' : 'Needs setup' }}</strong> —
                        {{ $c['booking']['readyCount'] }} of {{ $c['booking']['evaluated'] }} active booking {{ $c['booking']['evaluated'] === 1 ? 'type is' : 'types are' }} ready to take bookings, as of this check.
                    </p>
                    @foreach($c['booking']['problems'] as $problem)
                        <p class="mb-25" data-role="booking-problem">{{ $problem['name'] }}: {{ $problem['reason'] }}
                            @if($problem['fixUrl'])<a class="gc-link" href="{{ $problem['fixUrl'] }}" data-role="booking-fix">Fix this</a>@else<span class="text-muted">Ask someone with calendar access to fix this.</span>@endif
                        </p>
                    @endforeach
                @endif
                <p class="text-muted mb-0 mt-50">After you fix a booking type, <a href="#booking" onclick="window.location.reload(); return false;" data-role="booking-recheck">check again</a>: this reads your setup live. Ready does not mean customers have booked.</p>
            </section>
        @endif

        @if($c['replies'] !== null)
            <section class="gc-panel mb-1" data-role="work-replies">
                <h3 class="gc-panel-title">Customer replies</h3>
                @if($c['replies']['unavailable'])
                    <p class="mb-0" data-role="work-unavailable">We couldn't read your conversations just now.</p>
                @else
                    <p class="mb-50" data-role="replies-waiting">{{ $c['replies']['waiting'] === 0 ? 'Nobody is waiting for a reply.' : number_format($c['replies']['waiting']) . ($c['replies']['waiting'] === 1 ? ' customer is' : ' customers are') . ' waiting for a reply.' }}
                        @if($c['replies']['waiting'] > 0)<a class="gc-link" href="{{ $c['replies']['inboxUrl'] }}" data-role="replies-open">Review and reply</a>@endif</p>
                    @foreach($c['replies']['answered'] as $done)
                        <p class="mb-25" data-role="reply-row" data-status="{{ $done['status'] }}">{{ $done['name'] }} — {{ $done['status'] === 'delivered' ? 'Reply delivered' : 'Reply submitted to your messaging provider' }} · <time datetime="{{ $done['at']->toIso8601String() }}">{{ $done['at']->diffForHumans() }}</time></p>
                    @endforeach
                    <p class="text-muted mb-0 mt-50">A reply is not a booking: MotionGrove does not know whether a customer booked.</p>
                @endif
            </section>
        @endif

        @if($c['activity'] !== null)
            <section class="gc-panel mb-1" data-role="work-seo">
                <h3 class="gc-panel-title">Website and SEO activity</h3>
                @if($c['activity']['unavailable'])
                    <p class="mb-0" data-role="work-unavailable">We couldn't read your website activity just now.</p>
                @else
                    @forelse($c['activity']['items'] as $item)
                        <p class="mb-25" data-role="seo-row">
                            @if($item['url'])<a class="gc-link" href="{{ $item['url'] }}">{{ $item['text'] }}</a>@else{{ $item['text'] }}@endif
                            · <span class="text-muted">Recorded</span> · <time datetime="{{ $item['at']->toIso8601String() }}">{{ $item['at']->diffForHumans() }}</time>
                        </p>
                    @empty
                        <p class="mb-50" data-role="seo-empty">No audits, publishes or rank checks recorded yet.</p>
                    @endforelse
                    <p class="text-muted mb-0 mt-50">Whether a specific title or description fix is drafted, published or verified is shown on <a class="gc-link" href="{{ $c['activity']['auditUrl'] }}" data-role="seo-audit-link">your SEO audit</a>. Recorded activity is not a ranking or traffic result.</p>
                @endif
            </section>
        @endif
    </div>
@endsection
