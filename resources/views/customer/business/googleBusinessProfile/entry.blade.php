{{--
    GBP Slice A contract §25.1 / §17.1 — the bare /gbp Business chooser.
    Zero accessible shows an empty state, exactly one redirects through
    (so this view is never rendered for that case), several list them.
    Never guesses a Business.
--}}
@extends('layouts/contentLayoutMaster')

@php
    $resolvedCustomerContext = request()->attributes->get('customerContext');
    $accountNoun = $resolvedCustomerContext instanceof \App\Library\Navigation\CustomerContext ? $resolvedCustomerContext->accountNoun() : 'account';
@endphp

@section('title', 'Google Business Profile')

@section('content')
    <div class="row mb-2">
        <div class="col-12">
            <h4 class="mb-0">Google Business Profile</h4>
        </div>
    </div>

    @if(count($accessible) === 0)
        <x-card :padded="true">
            <x-empty-state icon="map-pin" title="No Business available yet"
                           description="Google Business Profile is organized by Business, and is included on the Growth and Agency plans. You don't have access to an eligible Business yet — ask an {{ $accountNoun }} owner to add you, or upgrade the plan." />
        </x-card>
    @else
        <x-card :padded="true">
            <p class="text-section-heading mb-2">Choose a Business to continue</p>
            <div class="list-group">
                @foreach($accessible as [$workspace, $business])
                    <a href="{{ route('customer.workspaces.businesses.gbp.index', [$workspace->uid, $business->uid]) }}"
                       class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
                        <span>
                            <strong>{{ $business->name }}</strong>
                            <span class="text-caption d-block">{{ $workspace->name }}</span>
                        </span>
                        <x-ds-icon name="chevron-right" size="18" />
                    </a>
                @endforeach
            </div>
        </x-card>
    @endif
@endsection
