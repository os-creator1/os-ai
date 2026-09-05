@extends('layouts/contentLayoutMaster')

@section('title', 'Messaging Channels')

@section('content')
    <div class="row mb-2">
        <div class="col-12">
            <h4 class="mb-0">Messaging Channels</h4>
            <p class="text-caption mb-0">Connect a provider so this Business can send SMS and MMS through Outreach.</p>
        </div>
    </div>

    <div class="row">
        @foreach($providers as $provider)
            <div class="col-md-6 mb-2">
                <x-card :padded="true">
                    <div class="d-flex justify-content-between align-items-start mb-1">
                        <div>
                            <h5 class="mb-0">{{ $provider['label'] }}</h5>
                            <div class="mt-1">
                                <x-badge variant="neutral">SMS</x-badge>
                                <x-badge variant="neutral">MMS</x-badge>
                            </div>
                        </div>
                        @if($provider['connections']->isEmpty())
                            <x-button variant="primary" size="sm"
                                      :href="route('customer.workspaces.businesses.channels.connect', [$workspaceUid, $businessUid, $provider['type']])">
                                Connect
                            </x-button>
                        @endif
                    </div>

                    @if($provider['connections']->isEmpty())
                        <p class="text-caption mb-0">Not connected yet.</p>
                    @else
                        <div class="list-group list-group-flush">
                            @foreach($provider['connections'] as $connection)
                                <div class="list-group-item d-flex justify-content-between align-items-center px-0">
                                    <div>
                                        @if($connection->status)
                                            <x-badge variant="success">Enabled</x-badge>
                                        @else
                                            <x-badge variant="neutral">Disabled</x-badge>
                                        @endif
                                        <span class="text-caption ms-1">{{ $connection->created_at?->format('Y-m-d') }}</span>
                                    </div>
                                    <x-button variant="outline" size="sm"
                                              :href="route('customer.workspaces.businesses.channels.connections.show', [$workspaceUid, $businessUid, $connection->uid])">
                                        Manage
                                    </x-button>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </x-card>
            </div>
        @endforeach
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
