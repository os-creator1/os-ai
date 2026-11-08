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
    </div>
@endsection
