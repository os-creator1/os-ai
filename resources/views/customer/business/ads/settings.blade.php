{{--
    Google Ads Module V1 — Settings: connection status, the selected account,
    the Business's own monthly budget target and target cost per conversion
    (planning values stored here, NOT changes made in Google Ads), data sync
    status with the manual refresh, and disconnect.

    No token, authorization code, client secret, developer token or raw
    provider payload is available to this view, so none can be rendered. Every
    state-changing control is a CSRF-protected POST shown only to someone with
    manage_google_ads.
--}}
@extends('layouts/contentLayoutMaster')

@section('title', 'Ads settings')

@php
    use App\Library\GoogleAds\GoogleAdsMoney;
    use App\Library\GoogleAds\GoogleAdsCustomerId;
    use App\Library\GoogleAds\Sync\GoogleAdsFreshnessState;

    $connectionLabel = match ($connection?->state->value) {
        'active' => 'Connected',
        'pending' => 'Waiting for Google to confirm',
        'revoked' => 'Google revoked access: reconnect to continue',
        'disconnected' => 'Disconnected',
        default => 'Not connected',
    };
    $connectionVariant = match ($connection?->state->value) {
        'active' => 'success',
        'revoked' => 'warning',
        default => 'neutral',
    };
    $customerId = $account !== null ? GoogleAdsCustomerId::normalize($account->customer_id) : null;
    $customerIdDisplay = $customerId !== null ? substr($customerId, 0, 3) . '-' . substr($customerId, 3, 3) . '-' . substr($customerId, 6) : null;
@endphp

@section('content')
    @include('customer.business.ads._header', ['title' => 'Ads settings', 'subtitle' => 'Your Google Ads connection, targets and data updates.'])

    <x-flash-alert class="mb-2" />

    <x-card :padded="true" class="mb-2" data-section="connection">
        <p class="text-section-heading mb-1">Connection</p>
        <dl class="row mb-0">
            <dt class="col-sm-4">Status</dt>
            <dd class="col-sm-8" data-role="connection-state"><x-badge :variant="$connectionVariant">{{ $connectionLabel }}</x-badge></dd>

            @if($connection !== null && $connection->isActive())
                <dt class="col-sm-4">Google account</dt>
                <dd class="col-sm-8" data-role="connection-email">{{ $connection->google_account_email ?? 'Connected' }}</dd>

                <dt class="col-sm-4">Connected</dt>
                <dd class="col-sm-8">{{ $connection->connected_at?->toDayDateTimeString() ?? '—' }}</dd>
            @endif
        </dl>

        @if($connection === null || ! $connection->isActive())
            <div class="mt-2">
                @if($adsCanManage)
                    <form method="POST" action="{{ route('customer.workspaces.businesses.ads.connect', [$workspaceUid, $businessUid]) }}">
                        @csrf
                        <x-button type="submit" icon="link" data-role="connect-google-ads">Connect Google Ads</x-button>
                    </form>
                @else
                    <p class="text-caption mb-0">Ask the owner of this account to connect Google Ads.</p>
                @endif
            </div>
        @endif
    </x-card>

    @if($account !== null)
        <x-card :padded="true" class="mb-2" data-section="account">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-1 mb-1">
                <p class="text-section-heading mb-0">Google Ads account</p>
                @if($adsCanManage)
                    <x-button variant="outline" size="sm" icon="list-checks" :href="route('customer.workspaces.businesses.ads.accounts', [$workspaceUid, $businessUid])">Change account</x-button>
                @endif
            </div>
            <dl class="row mb-0">
                <dt class="col-sm-4">Account</dt>
                <dd class="col-sm-8" data-role="account-name">{{ $account->descriptive_name ?? 'Unnamed account' }}</dd>

                <dt class="col-sm-4">Account ID</dt>
                <dd class="col-sm-8" data-role="account-id">{{ $customerIdDisplay ?? $account->customer_id }}</dd>

                <dt class="col-sm-4">Currency</dt>
                <dd class="col-sm-8" data-role="account-currency">{{ $account->currency_code }}</dd>

                <dt class="col-sm-4">Time zone</dt>
                <dd class="col-sm-8" data-role="account-timezone">{{ $account->time_zone }}</dd>

                @if($account->is_test_account)
                    <dt class="col-sm-4">Type</dt>
                    <dd class="col-sm-8"><x-badge variant="accent">Test account</x-badge></dd>
                @endif
            </dl>
        </x-card>

        <x-card :padded="true" class="mb-2" data-section="targets">
            <p class="text-section-heading mb-1">Targets</p>
            <p class="text-caption mb-2">
                These are your own planning figures, in {{ $account->currency_code }}. They are used to show whether you are on pace and on target.
                They do not change anything in Google Ads, where each campaign has its own daily budget.
            </p>

            @if($errors->any())
                <x-alert variant="danger" icon="alert-circle" class="mb-2" data-role="validation-summary">
                    <ul class="mb-0 ps-3">
                        @foreach($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </x-alert>
            @endif

            <form method="POST" action="{{ route('customer.workspaces.businesses.ads.settings.update', [$workspaceUid, $businessUid]) }}" data-role="targets-form">
                @csrf
                <div class="row">
                    <div class="col-md-6">
                        <label for="monthly_budget_target" class="form-label text-label">Monthly budget target ({{ $account->currency_code }})</label>
                        <input type="text" inputmode="decimal" autocomplete="off" class="form-control mb-1 @error('monthly_budget_target') is-invalid @enderror"
                               name="monthly_budget_target" id="monthly_budget_target" placeholder="For example 250"
                               value="{{ old('monthly_budget_target', $monthlyTargetInput) }}" @cannot('manage_google_ads') disabled @endcannot>
                        <div class="form-text text-caption mb-2">What you plan to spend on ads each month. Leave blank for no target.</div>
                    </div>
                    <div class="col-md-6">
                        <label for="target_cpl" class="form-label text-label">Target cost per conversion ({{ $account->currency_code }})</label>
                        <input type="text" inputmode="decimal" autocomplete="off" class="form-control mb-1 @error('target_cpl') is-invalid @enderror"
                               name="target_cpl" id="target_cpl" placeholder="For example 25"
                               value="{{ old('target_cpl', $cplTargetInput) }}" @cannot('manage_google_ads') disabled @endcannot>
                        <div class="form-text text-caption mb-2">What you would like each Google conversion to cost. Leave blank for no target.</div>
                    </div>
                </div>
                @can('manage_google_ads')
                    <x-button type="submit" icon="check" data-role="save-targets">Save targets</x-button>
                @else
                    <p class="text-caption mb-0">You do not have permission to change these targets.</p>
                @endcan
            </form>
        </x-card>

        <x-card :padded="true" class="mb-2" data-section="sync">
            <p class="text-section-heading mb-1">Data updates</p>
            <dl class="row mb-2">
                <dt class="col-sm-4">Last successful update</dt>
                <dd class="col-sm-8" data-role="sync-last">{{ $freshness->lastSuccessfulSyncAt?->diffForHumans() ?? 'Not yet' }}</dd>

                <dt class="col-sm-4">Data through</dt>
                <dd class="col-sm-8" data-role="sync-through">{{ $freshness->dataThroughDate?->format('M j, Y') ?? '—' }}</dd>

                <dt class="col-sm-4">Status</dt>
                <dd class="col-sm-8" data-role="sync-state">
                    @if($freshness->state === GoogleAdsFreshnessState::Running)
                        Refreshing now
                    @elseif($freshness->lastFailureLabel)
                        Latest refresh failed: {{ $freshness->lastFailureLabel }}
                    @elseif($freshness->state === GoogleAdsFreshnessState::NeverSynced)
                        Waiting for the first update
                    @else
                        Up to date
                    @endif
                </dd>
            </dl>
            <p class="text-caption mb-2">Your figures update automatically every day. You can ask for an extra update, at most about once an hour.</p>
            @can('manage_google_ads')
                <form method="POST" action="{{ route('customer.workspaces.businesses.ads.refresh', [$workspaceUid, $businessUid]) }}">
                    @csrf
                    <x-button type="submit" variant="outline" icon="refresh-cw" data-role="refresh-now">Refresh now</x-button>
                </form>
            @endcan
        </x-card>
    @endif

    @if($connection !== null && $connection->state->value !== 'disconnected')
        <x-card :padded="true" class="mb-2" data-section="disconnect">
            <p class="text-section-heading mb-1">Disconnect</p>
            <p class="text-caption mb-2">
                Disconnecting destroys the stored Google authorization and stops all updates. Your earlier figures are kept.
                Reconnecting means going through Google's permission screen again. You can also remove this platform's access from your Google Account settings.
            </p>
            @can('manage_google_ads')
                <form method="POST" action="{{ route('customer.workspaces.businesses.ads.disconnect', [$workspaceUid, $businessUid]) }}"
                      onsubmit="return confirm('Disconnect Google Ads? Updates will stop until you connect again.');">
                    @csrf
                    <x-button type="submit" variant="danger" size="sm" data-role="disconnect-google-ads">Disconnect Google Ads</x-button>
                </form>
            @else
                <p class="text-caption mb-0">You do not have permission to manage this connection.</p>
            @endcan
        </x-card>
    @endif
@endsection
