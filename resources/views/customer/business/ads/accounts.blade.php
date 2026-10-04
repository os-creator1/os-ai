{{--
    Google Ads Module V1 (contract 23 §3) — choose the Google Ads account.

    The list is derived SERVER-SIDE on every render and re-derived on the POST.
    Nothing is ever auto-selected: each selectable account has its own
    explicit "Use this account" form that posts ONLY the customer id. Manager
    accounts are shown but cannot be chosen. A failure to list accounts is a
    calm message with a retry link, never a stack trace.
--}}
@extends('layouts/contentLayoutMaster')

@section('title', 'Choose Google Ads account')

@section('content')
    @include('customer.business.ads._header', [
        'title' => 'Choose your Google Ads account',
        'subtitle' => 'Pick the advertising account whose results you want to see here.',
        'showFreshness' => false,
    ])

    <x-flash-alert class="mb-2" />
    @if($errors->any())
        <x-alert variant="danger" icon="alert-circle" class="mb-2" data-role="validation-summary">
            <ul class="mb-0 ps-3">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </x-alert>
    @endif

    @if($loadError !== null)
        <x-card :padded="true" data-role="accounts-load-error">
            <x-empty-state icon="cloud-off" title="We could not load your Google Ads accounts" :description="$loadError">
                <x-slot:action>
                    <x-button variant="outline" icon="refresh-cw" :href="route('customer.workspaces.businesses.ads.accounts', [$workspaceUid, $businessUid])">Try again</x-button>
                </x-slot:action>
            </x-empty-state>
        </x-card>
    @elseif(count($candidates) === 0)
        <x-card :padded="true" data-role="accounts-none">
            <x-empty-state icon="inbox" title="No Google Ads accounts found"
                           description="The Google account you connected does not have access to any Google Ads account we can use. Disconnect and connect again with the Google account that manages your ads." />
        </x-card>
    @else
        @if($account !== null)
            <x-alert variant="neutral" icon="info" role="status" class="mb-2" data-role="change-warning">
                An account is already selected. Choosing a different account replaces the figures shown here, and your budget and cost targets are cleared.
            </x-alert>
        @endif

        <x-card :padded="false" data-role="accounts-list">
            <div class="list-group list-group-flush">
                @foreach($candidates as $candidate)
                    <div class="list-group-item d-flex justify-content-between align-items-center flex-wrap gap-1"
                         data-role="account-candidate" data-customer-id="{{ $candidate['customerId'] }}">
                        <div>
                            <strong>{{ $candidate['name'] ?? 'Unnamed account' }}</strong>
                            <span class="text-caption d-block">
                                {{ $candidate['idDisplay'] }}
                                @if(! $candidate['isManager'])
                                    &middot; {{ $candidate['currency'] }} &middot; {{ $candidate['timeZone'] }}
                                @endif
                            </span>
                            <span class="d-block mt-50">
                                @if($candidate['isManager'])
                                    <x-badge variant="neutral">Manager account</x-badge>
                                @endif
                                @if($candidate['isTest'])
                                    <x-badge variant="accent">Test account</x-badge>
                                @endif
                                @if($selectedCustomerId === $candidate['customerId'])
                                    <x-badge variant="success">Currently selected</x-badge>
                                @endif
                            </span>
                        </div>
                        <div>
                            @if($candidate['selectable'])
                                <form method="POST" action="{{ route('customer.workspaces.businesses.ads.accounts.select', [$workspaceUid, $businessUid]) }}">
                                    @csrf
                                    <input type="hidden" name="customer_id" value="{{ $candidate['customerId'] }}">
                                    <x-button type="submit" variant="{{ $selectedCustomerId === $candidate['customerId'] ? 'outline' : 'primary' }}" size="sm">
                                        {{ $selectedCustomerId === $candidate['customerId'] ? 'Keep this account' : 'Use this account' }}
                                    </x-button>
                                </form>
                            @else
                                <span class="text-caption" data-role="manager-note">Manager accounts cannot be chosen. Choose an account under it.</span>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </x-card>
    @endif
@endsection
