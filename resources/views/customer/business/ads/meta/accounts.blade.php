{{--
    Meta Ads Module V1 (contract 24 §4) — choose the Meta ad account.

    The list is derived SERVER-SIDE on every render and re-derived on the POST.
    NOTHING is ever auto-selected, even when there is exactly one account: each
    selectable account has its own explicit "Use this account" form that posts
    ONLY the account id. Accounts that are not active in Meta are shown with
    their status and cannot be chosen. A failure to list accounts is a calm
    message with a retry link. Account names are customer data: escaped.
--}}
@extends('layouts/contentLayoutMaster')

@section('title', 'Choose Meta ad account')

@section('content')
    @include('customer.business.ads._header', [
        'title' => 'Choose your Meta ad account',
        'subtitle' => 'Pick the ad account whose results you want to see here.',
        'showFreshness' => false,
        'provider' => 'meta',
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
            <x-empty-state icon="cloud-off" title="We could not load your Meta ad accounts" :description="$loadError">
                <x-slot:action>
                    <x-button variant="outline" icon="refresh-cw" :href="route('customer.workspaces.businesses.ads.meta.accounts', [$workspaceUid, $businessUid])">Try again</x-button>
                </x-slot:action>
            </x-empty-state>
        </x-card>
    @elseif(count($candidates) === 0)
        <x-card :padded="true" data-role="accounts-none">
            <x-empty-state icon="inbox" title="No Meta ad accounts found"
                           description="The Meta account you connected does not have access to any ad account we can use. Disconnect and connect again with the Meta account that manages your ads." />
        </x-card>
    @else
        @if($account !== null)
            <x-alert variant="neutral" icon="info" role="status" class="mb-2" data-role="change-warning">
                An ad account is already selected. Choosing a different account replaces the figures shown here, and your budget target, cost target and result type are cleared.
            </x-alert>
        @endif

        <x-card :padded="false" data-role="accounts-list">
            <div class="list-group list-group-flush">
                @foreach($candidates as $candidate)
                    <div class="list-group-item d-flex justify-content-between align-items-center flex-wrap gap-1"
                         data-role="account-candidate" data-selectable="{{ $candidate['selectable'] ? '1' : '0' }}">
                        <div>
                            <strong>{{ $candidate['name'] ?? 'Unnamed account' }}</strong>
                            <span class="text-caption d-block">{{ $candidate['currency'] }} &middot; {{ $candidate['timeZone'] }}</span>
                            <span class="d-block mt-50">
                                @if(! $candidate['selectable'])
                                    <x-badge variant="warning" data-role="account-status">{{ $candidate['statusLabel'] }}</x-badge>
                                @endif
                                @if($selectedAccountId === $candidate['accountId'])
                                    <x-badge variant="success">Currently selected</x-badge>
                                @endif
                            </span>
                        </div>
                        <div>
                            @if($candidate['selectable'])
                                <form method="POST" action="{{ route('customer.workspaces.businesses.ads.meta.accounts.select', [$workspaceUid, $businessUid]) }}">
                                    @csrf
                                    <input type="hidden" name="account_id" value="{{ $candidate['accountId'] }}">
                                    <x-button type="submit" variant="{{ $selectedAccountId === $candidate['accountId'] ? 'outline' : 'primary' }}" size="sm">
                                        {{ $selectedAccountId === $candidate['accountId'] ? 'Keep this account' : 'Use this account' }}
                                    </x-button>
                                </form>
                            @else
                                <span class="text-caption" data-role="not-selectable-note">This account is not active in Meta, so it cannot be chosen.</span>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </x-card>
    @endif
@endsection
