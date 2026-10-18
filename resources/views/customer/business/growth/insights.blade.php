{{--
    Growth Center — Insights: the Daily Brief plus what's working and what
    changed. The same canonical truth as the Overview, presented as a brief.
--}}
@extends('layouts/contentLayoutMaster')

@section('title', 'Growth Center — Insights')

@section('page-style')
    @include('customer.business.growth._styles')
@endsection

@section('content')
    @include('customer.business.growth._header')

    <div class="gc">
        <section class="gc-panel mb-2" data-role="daily-brief">
            <div class="gc-section-head">
                <div>
                    <h2 class="gc-section-title">Today's brief</h2>
                    <p class="gc-section-sub">{{ now()->format('l, F j') }}</p>
                </div>
                <a class="gc-link" href="{{ route('customer.workspaces.businesses.growth.brief', [$workspaceUid, $businessUid]) }}" data-role="open-brief">Open as a page</a>
            </div>
            @include('customer.business.growth._brief_body')
        </section>

        @if($canSeeScore)
            <div class="gc-cols">
                <section class="gc-panel" data-role="whats-working">
                    <h2 class="gc-panel-title">What's working</h2>
                    @if($brief['positives'] !== [])
                        <ul class="gc-bullets">@foreach($brief['positives'] as $line)<li><x-ds-icon name="check-circle" size="16" class="gc-up" /><span>{{ $line }}</span></li>@endforeach</ul>
                    @else
                        <p class="gc-empty-line">Good news will show up here as your numbers settle.</p>
                    @endif
                </section>
                <section class="gc-panel" data-role="recent-changes">
                    <h2 class="gc-panel-title">What changed</h2>
                    @if($movement !== null || $brief['changes'] !== [])
                        <ul class="gc-bullets">
                            @if($movement !== null)<li><x-ds-icon :name="$movement['delta'] >= 0 ? 'trending-up' : 'trending-down'" size="16" class="{{ $movement['delta'] >= 0 ? 'gc-up' : 'gc-down' }}" /><span>{{ $movement['sentence'] }}</span></li>@endif
                            @foreach($brief['changes'] as $change)<li><x-ds-icon :name="$change['kind'] === 'up' ? 'arrow-up-right' : 'arrow-down-right'" size="16" class="{{ $change['kind'] === 'up' ? 'gc-up' : 'gc-down' }}" /><span>{{ $change['text'] }}</span></li>@endforeach
                        </ul>
                    @else
                        <p class="gc-empty-line">Changes are compared once there are a couple of weeks of history.</p>
                    @endif
                </section>
            </div>
        @endif
    </div>
@endsection
