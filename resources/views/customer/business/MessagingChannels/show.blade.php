@extends('layouts/contentLayoutMaster')

@section('title', $providerLabel . ' connection')

@section('content')
    <div class="row mb-2">
        <div class="col-12 d-flex justify-content-between align-items-center">
            <h4 class="mb-0">{{ $providerLabel }}</h4>
            <x-button variant="outline" size="sm" icon="arrow-left"
                      :href="route('customer.workspaces.businesses.channels.index', [$workspaceUid, $businessUid])">
                Back
            </x-button>
        </div>
    </div>

    <div class="row">
        <div class="col-md-6 mb-2">
            <x-card title="Connection" :padded="true">
                <dl class="row mb-0">
                    <dt class="col-sm-4">Provider</dt>
                    <dd class="col-sm-8">{{ $providerLabel }}</dd>

                    <dt class="col-sm-4">Status</dt>
                    <dd class="col-sm-8">
                        @if($connection->status)
                            <x-badge variant="success">Enabled</x-badge>
                        @else
                            <x-badge variant="neutral">Disabled</x-badge>
                        @endif
                    </dd>

                    <dt class="col-sm-4">SMS</dt>
                    <dd class="col-sm-8">{{ $connection->sendingServer->plain ? 'Supported' : 'Not supported' }}</dd>

                    <dt class="col-sm-4">MMS</dt>
                    <dd class="col-sm-8">{{ $connection->sendingServer->mms ? 'Supported' : 'Not supported' }}</dd>

                    @if($inboundUrl)
                        <dt class="col-sm-4">Inbound webhook</dt>
                        <dd class="col-sm-8"><code>{{ $inboundUrl }}</code></dd>
                    @endif
                </dl>

                <div class="d-flex gap-2 mt-3">
                    @if($connection->status)
                        <form method="post" action="{{ route('customer.workspaces.businesses.channels.connections.disable', [$workspaceUid, $businessUid, $connection->uid]) }}">
                            @csrf
                            <x-button type="submit" variant="outline" size="sm">Disable</x-button>
                        </form>
                    @else
                        <form method="post" action="{{ route('customer.workspaces.businesses.channels.connections.enable', [$workspaceUid, $businessUid, $connection->uid]) }}">
                            @csrf
                            <x-button type="submit" variant="primary" size="sm">Enable</x-button>
                        </form>
                    @endif
                </div>
            </x-card>
        </div>

        <div class="col-md-6 mb-2">
            <x-card title="Credentials" :padded="true">
                @if($managed)
                    <x-empty-state icon="lock" title="Managed connection"
                                    description="This connection's credentials are managed elsewhere and can't be edited from this Business." />
                @else
                    <form method="post" action="{{ route('customer.workspaces.businesses.channels.connections.update', [$workspaceUid, $businessUid, $connection->uid]) }}">
                        @csrf
                        @method('PUT')

                        @foreach($fields as $key => $meta)
                            <div class="mb-1">
                                <label class="form-label" for="{{ $key }}">{{ $meta['label'] }}</label>
                                <input type="password" autocomplete="off" id="{{ $key }}" name="{{ $key }}"
                                       class="form-control @error($key) is-invalid @enderror"
                                       placeholder="Leave blank to keep current value">
                                @error($key)
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        @endforeach

                        <x-button type="submit" variant="primary">Save</x-button>
                    </form>
                @endif
            </x-card>
        </div>
    </div>

    <x-card title="Business sending identities" :padded="true">
        <div class="row">
            <div class="col-md-6">
                <p class="text-section-heading mb-1">Phone numbers</p>
                @if($phoneNumbers->isEmpty())
                    <p class="text-caption">None assigned to this Business yet.</p>
                @else
                    <ul class="list-unstyled mb-0">
                        @foreach($phoneNumbers as $number)
                            <li class="mb-1">{{ $number->number }} <x-badge variant="neutral">{{ ucfirst($number->status) }}</x-badge></li>
                        @endforeach
                    </ul>
                @endif
            </div>
            <div class="col-md-6">
                <p class="text-section-heading mb-1">Sender IDs</p>
                @if($senderIds->isEmpty())
                    <p class="text-caption">None assigned to this Business yet.</p>
                @else
                    <ul class="list-unstyled mb-0">
                        @foreach($senderIds as $senderId)
                            <li class="mb-1">{{ $senderId->sender_id }} <x-badge variant="neutral">{{ ucfirst($senderId->status) }}</x-badge></li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>
    </x-card>
@endsection
