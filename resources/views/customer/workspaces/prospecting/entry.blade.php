@extends('layouts/contentLayoutMaster')

@php
    $resolvedCustomerContext = request()->attributes->get('customerContext');
    $accountNoun = $resolvedCustomerContext instanceof \App\Library\Navigation\CustomerContext ? $resolvedCustomerContext->accountNoun() : 'account';
@endphp

@section('title', 'Outreach')

@section('content')
    <div class="row mb-2">
        <div class="col-12">
            <h4 class="mb-0">Outreach</h4>
        </div>
    </div>

    @if(count($accessible) === 0)
        <x-card :padded="true">
            <x-empty-state icon="target" title="Outreach is not available to you yet"
                            description="Outreach comes with the Agency plan and is run by the owner or an admin of the Agency account. You do not have owner or admin access to an Agency account that includes it." />
        </x-card>
    @else
        <x-card :padded="true">
            <p class="text-section-heading mb-2">Choose an {{ $accountNoun }} to continue</p>
            <div class="list-group">
                @foreach($accessible as $workspace)
                    <a href="{{ route('customer.workspaces.prospecting.overview', $workspace->uid) }}"
                       class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
                        <strong>{{ $workspace->name }}</strong>
                        <x-ds-icon name="chevron-right" size="18" />
                    </a>
                @endforeach
            </div>
        </x-card>
    @endif
@endsection
