{{--
    GBP Slice A contract §25.8 / §27 — connection settings plus the recent
    operations panel.

    The operations panel is the customer-visible change log. Slice A makes
    NO change to a Google account, so Google's "notify the end-client
    within 48 hours" obligation is not triggered today — but the panel is
    built now so a future mutation slice inherits it rather than having to
    add it (contract §27).

    Granted scopes are DISPLAYED. Tokens are not: BusinessGoogleConnection
    hides refresh_token_encrypted, and no token, authorization code or raw
    state is available to this view at all.
--}}
@extends('layouts/contentLayoutMaster')

@section('title', 'Google connection settings')

@section('content')
    <div class="row mb-2">
        <div class="col-12 d-flex justify-content-between align-items-center flex-wrap">
            <h4 class="mb-0">Google connection settings</h4>
            <a href="{{ route('customer.workspaces.businesses.gbp.index', [$workspaceUid, $businessUid]) }}" class="text-caption">Back</a>
        </div>
    </div>

    @if(session('message'))
        <x-alert :variant="session('status') === 'error' ? 'danger' : 'success'" class="mb-2">
            {{ session('message') }}
        </x-alert>
    @endif

    <x-card :padded="true" class="mb-2">
        <p class="text-section-heading mb-1">Connection</p>

        @if($connection)
            <dl class="row mb-0">
                <dt class="col-sm-4">Status</dt>
                <dd class="col-sm-8">{{ ucfirst($connection->state->value) }}</dd>

                <dt class="col-sm-4">Google account</dt>
                <dd class="col-sm-8">{{ $connection->google_account_email ?? 'Not recorded' }}</dd>

                <dt class="col-sm-4">Granted access</dt>
                <dd class="col-sm-8">
                    {{ $connection->granted_scopes ?? 'None' }}
                    <span class="text-caption d-block">
                        Google offers only this one Business Profile permission. This platform uses it for reading only.
                    </span>
                </dd>

                <dt class="col-sm-4">Connected</dt>
                <dd class="col-sm-8">{{ $connection->connected_at?->toDayDateTimeString() ?? '—' }}</dd>

                <dt class="col-sm-4">Last refreshed</dt>
                <dd class="col-sm-8">{{ $connection->last_refreshed_at?->toDayDateTimeString() ?? '—' }}</dd>
            </dl>
        @else
            <p class="mb-0 text-caption">No Google account is connected for this business.</p>
        @endif
    </x-card>

    @if($binding)
        <x-card :padded="true" class="mb-2">
            <p class="text-section-heading mb-1">Linked location</p>
            <dl class="row mb-2">
                <dt class="col-sm-4">Google location</dt>
                <dd class="col-sm-8">{{ $binding->provider_location_resource_name }}</dd>

                <dt class="col-sm-4">Google account</dt>
                <dd class="col-sm-8">{{ $binding->provider_account_resource_name }}</dd>

                <dt class="col-sm-4">Platform location</dt>
                <dd class="col-sm-8">{{ $location?->name ?? '—' }}</dd>
            </dl>

            @can('manage_google_business_profile')
                <form method="POST" action="{{ route('customer.workspaces.businesses.gbp.unbind', [$workspaceUid, $businessUid]) }}">
                    @csrf
                    <input type="hidden" name="binding_uid" value="{{ $binding->uid }}">
                    <button type="submit" class="btn btn-outline-secondary">Unlink this location</button>
                </form>
            @endcan
        </x-card>
    @endif

    @if($connection && $connection->state->value !== 'disconnected')
        <x-card :padded="true" class="mb-2">
            <p class="text-section-heading mb-1">Disconnect</p>
            <p class="text-caption mb-2">
                Disconnecting deletes the stored authorization and every linked location for this business. It cannot be undone &mdash;
                reconnecting requires going through Google's permission screen again. You can also remove this platform's access
                from your Google Account settings.
            </p>
            @can('manage_google_business_profile')
                <form method="POST" action="{{ route('customer.workspaces.businesses.gbp.disconnect', [$workspaceUid, $businessUid]) }}">
                    @csrf
                    <button type="submit" class="btn btn-outline-danger">Disconnect Google account</button>
                </form>
            @else
                <p class="text-caption mb-0">You do not have permission to manage this connection.</p>
            @endcan
        </x-card>
    @endif

    <x-card :padded="true">
        <p class="text-section-heading mb-1">Recent activity</p>

        @if($operations->isEmpty())
            <p class="text-caption mb-0">No Google activity recorded yet.</p>
        @else
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th scope="col">When</th>
                            <th scope="col">Operation</th>
                            <th scope="col">Detail</th>
                            <th scope="col">Result</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($operations as $operation)
                            <tr>
                                <td>{{ $operation->created_at?->toDayDateTimeString() }}</td>
                                <td>{{ str_replace('_', ' ', $operation->operation_type->value) }}</td>
                                <td>{{ $operation->summary ?? '—' }}</td>
                                <td>
                                    {{ ucfirst($operation->status->value) }}
                                    @if($operation->failure_classification)
                                        <span class="text-caption d-block">{{ str_replace('_', ' ', $operation->failure_classification) }}</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-card>
@endsection
