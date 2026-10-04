{{--
    Meta Ads Module V1 (contract 24 §3/§5.2) — Settings: connection and token
    status, the selected ad account, the Business's own monthly budget target,
    target cost per result and the RESULT TYPE (planning values stored here,
    NOT changes made in Meta), data sync status with the manual refresh, and
    disconnect.

    No token, authorization code, app secret or raw provider payload is
    available to this view, so none can be rendered. The Meta ad account id is
    never shown. Every state-changing control is a CSRF-protected POST shown
    only to someone with manage_meta_ads.
--}}
@extends('layouts/contentLayoutMaster')

@section('title', 'Meta Ads settings')

@php
    use App\Library\MetaAds\Sync\MetaAdsFreshnessState;

    $state = $connection?->state->value;
    $connectionLabel = match ($state) {
        'active' => 'Connected',
        'pending' => 'Waiting for Meta to confirm',
        'expired' => 'Expired: reconnect to continue',
        'revoked' => 'Meta revoked access: reconnect to continue',
        'disconnected' => 'Disconnected',
        default => 'Not connected',
    };
    $connectionVariant = match ($state) {
        'active' => 'success',
        'expired', 'revoked' => 'warning',
        default => 'neutral',
    };
    $connectRoute = route('customer.workspaces.businesses.ads.meta.connect', [$workspaceUid, $businessUid]);
    $reauthDue = $tokenStatus !== null && $tokenStatus->needsReauthSoon;
    $readOnly = $connection !== null && $connection->isActive() && ! $metaCanManage && $metaCanConnect;
    $currentType = old('result_action_type', $account?->result_action_type);
@endphp

@section('content')
    @include('customer.business.ads._header', [
        'title' => 'Meta Ads settings',
        'subtitle' => 'Your Meta connection, targets and data updates.',
        'provider' => 'meta',
    ])

    <x-flash-alert class="mb-2" />

    <x-card :padded="true" class="mb-2" data-section="connection">
        <p class="text-section-heading mb-1">Connection</p>
        <dl class="row mb-0">
            <dt class="col-sm-4">Status</dt>
            <dd class="col-sm-8" data-role="connection-state"><x-badge :variant="$connectionVariant">{{ $connectionLabel }}</x-badge></dd>

            @if($connection !== null && $connection->isActive())
                <dt class="col-sm-4">Meta account</dt>
                <dd class="col-sm-8" data-role="connection-user">{{ $connection->meta_user_name ?: 'Connected' }}</dd>

                <dt class="col-sm-4">Connected</dt>
                <dd class="col-sm-8">{{ $connection->connected_at?->toDayDateTimeString() ?? '—' }}</dd>

                <dt class="col-sm-4">Access expires</dt>
                <dd class="col-sm-8" data-role="token-expires">
                    {{ $tokenStatus?->expiresAt?->format('M j, Y') ?? 'Unknown' }}
                    @if($tokenStatus?->daysLeft !== null)
                        <span class="text-muted">({{ $tokenStatus->daysLeft }} {{ $tokenStatus->daysLeft === 1 ? 'day' : 'days' }} left)</span>
                    @endif
                </dd>
            @endif
        </dl>

        @if($connection !== null && $connection->isActive())
            @if($reauthDue)
                <x-alert variant="warning" icon="alert-triangle" role="status" class="mt-2 mb-0" data-role="reconnect-warning">
                    Reconnect Meta before {{ $tokenStatus->expiresAt?->format('M j, Y') ?? 'your access expires' }}. Meta does not renew access automatically, so updates stop when it expires.
                </x-alert>
            @endif
            @if($readOnly)
                <x-alert variant="neutral" icon="info" role="status" class="mt-2 mb-0" data-role="read-only-note">
                    This connection can read your figures but was not allowed to manage ads. Reconnect Meta to allow pause and resume.
                </x-alert>
            @endif
            @if($metaCanConnect && ($reauthDue || $readOnly))
                <div class="mt-2">
                    <form method="POST" action="{{ $connectRoute }}">
                        @csrf
                        <x-button type="submit" icon="link" data-role="reconnect-meta-ads">Reconnect Meta</x-button>
                    </form>
                </div>
            @endif
        @else
            <div class="mt-2">
                @if($metaCanConnect)
                    <form method="POST" action="{{ $connectRoute }}">
                        @csrf
                        <x-button type="submit" icon="link" data-role="{{ in_array($state, ['expired', 'revoked'], true) ? 'reconnect-meta-ads' : 'connect-meta-ads' }}">{{ in_array($state, ['expired', 'revoked'], true) ? 'Reconnect Meta' : 'Connect Meta' }}</x-button>
                    </form>
                @else
                    <p class="text-caption mb-0">Ask the owner of this account to connect Meta.</p>
                @endif
            </div>
        @endif
    </x-card>

    @if($account !== null)
        <x-card :padded="true" class="mb-2" data-section="account">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-1 mb-1">
                <p class="text-section-heading mb-0">Meta ad account</p>
                @if($metaCanConnect)
                    <x-button variant="outline" size="sm" icon="list-checks" :href="route('customer.workspaces.businesses.ads.meta.accounts', [$workspaceUid, $businessUid])">Change account</x-button>
                @endif
            </div>
            <dl class="row mb-0">
                <dt class="col-sm-4">Account</dt>
                <dd class="col-sm-8" data-role="account-name">{{ $account->name ?? 'Unnamed account' }}</dd>

                <dt class="col-sm-4">Currency</dt>
                <dd class="col-sm-8" data-role="account-currency">{{ $account->currency_code }}</dd>

                <dt class="col-sm-4">Time zone</dt>
                <dd class="col-sm-8" data-role="account-timezone">{{ $account->time_zone }}</dd>
            </dl>
        </x-card>

        <x-card :padded="true" class="mb-2" data-section="targets">
            <p class="text-section-heading mb-1">Targets and results</p>
            <p class="text-caption mb-2">
                These are your own planning figures, in {{ $account->currency_code }}. They are used to show whether you are on pace and on target.
                They do not change anything in Meta Ads Manager, where each campaign has its own budget.
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

            <form method="POST" action="{{ route('customer.workspaces.businesses.ads.meta.settings.update', [$workspaceUid, $businessUid]) }}" data-role="settings-form">
                @csrf
                <div class="row">
                    <div class="col-md-6">
                        <label for="monthly_budget_target" class="form-label text-label">Monthly budget target ({{ $account->currency_code }})</label>
                        <input type="text" inputmode="decimal" autocomplete="off" class="form-control mb-1 @error('monthly_budget_target') is-invalid @enderror"
                               name="monthly_budget_target" id="monthly_budget_target" placeholder="For example 250"
                               value="{{ old('monthly_budget_target', $monthlyTargetInput) }}" @if(! $metaCanAct) disabled @endif>
                        <div class="form-text text-caption mb-2">What you plan to spend on Meta ads each month. Leave blank for no target.</div>
                    </div>
                    <div class="col-md-6">
                        <label for="target_cost_per_result" class="form-label text-label">Target cost per result ({{ $account->currency_code }})</label>
                        <input type="text" inputmode="decimal" autocomplete="off" class="form-control mb-1 @error('target_cost_per_result') is-invalid @enderror"
                               name="target_cost_per_result" id="target_cost_per_result" placeholder="For example 25"
                               value="{{ old('target_cost_per_result', $cprTargetInput) }}" @if(! $metaCanAct) disabled @endif>
                        <div class="form-text text-caption mb-2">What you would like each result to cost. Leave blank for no target.</div>
                    </div>
                    <div class="col-md-6">
                        <label for="result_action_type" class="form-label text-label">What counts as a result</label>
                        <select class="form-select mb-1 @error('result_action_type') is-invalid @enderror" name="result_action_type" id="result_action_type" @if(! $metaCanAct) disabled @endif>
                            <option value="" @selected($currentType === null || $currentType === '')>Not chosen yet</option>
                            @foreach($resultTypes as $key => $label)
                                <option value="{{ $key }}" @selected($currentType === $key)>{{ $label }}</option>
                            @endforeach
                        </select>
                        <div class="form-text text-caption mb-2">Meta reports many kinds of actions. Choose the one that matters to you; results and cost per result are shown for that type only. Changing it only changes which stored figures are shown.</div>
                    </div>
                </div>
                @if($metaCanAct)
                    <x-button type="submit" icon="check" data-role="save-settings">Save settings</x-button>
                @else
                    <p class="text-caption mb-0">{{ $metaViewingAsClient ? 'Settings cannot be changed while viewing a client account.' : 'You do not have permission to change these settings.' }}</p>
                @endif
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
                    @if($freshness->state === MetaAdsFreshnessState::Running)
                        Refreshing now
                    @elseif($freshness->lastFailureLabel)
                        Latest refresh failed: {{ $freshness->lastFailureLabel }}
                    @elseif($freshness->state === MetaAdsFreshnessState::NeverSynced)
                        Waiting for the first update
                    @else
                        Up to date
                    @endif
                </dd>
            </dl>
            <p class="text-caption mb-2">Your figures update automatically every day. You can ask for an extra update, at most about once an hour.</p>
            @if($metaCanAct)
                <form method="POST" action="{{ route('customer.workspaces.businesses.ads.meta.refresh', [$workspaceUid, $businessUid]) }}">
                    @csrf
                    <x-button type="submit" variant="outline" icon="refresh-cw" data-role="refresh-now">Refresh now</x-button>
                </form>
            @endif
        </x-card>
    @endif

    @if($connection !== null && $connection->state->value !== 'disconnected')
        <x-card :padded="true" class="mb-2" data-section="disconnect">
            <p class="text-section-heading mb-1">Disconnect</p>
            <p class="text-caption mb-2">
                Disconnecting destroys the stored Meta authorization and stops all updates. Your earlier figures are kept.
                Reconnecting means going through Meta's permission screen again. You can also remove this platform's access in your Meta account settings.
            </p>
            @if($metaCanAct)
                <form method="POST" action="{{ route('customer.workspaces.businesses.ads.meta.disconnect', [$workspaceUid, $businessUid]) }}"
                      onsubmit="return confirm('Disconnect Meta? Updates will stop until you connect again.');">
                    @csrf
                    <x-button type="submit" variant="danger" size="sm" data-role="disconnect-meta-ads">Disconnect Meta</x-button>
                </form>
            @else
                <p class="text-caption mb-0">{{ $metaViewingAsClient ? 'The connection cannot be changed while viewing a client account.' : 'You do not have permission to manage this connection.' }}</p>
            @endif
        </x-card>
    @endif
@endsection
