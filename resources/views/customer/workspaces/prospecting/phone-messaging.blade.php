@extends('layouts/contentLayoutMaster')

@section('title', 'Phone & Messaging')

@section('content')
    <div class="row mb-2">
        <div class="col-12">
            <h4 class="mb-0">Phone &amp; Messaging</h4>
            <p class="text-caption mb-0">Choose how your agency sends texts: connect your own Telnyx account, or get a number through us. Numbers listed here belong to your agency, never to a client.</p>
        </div>
    </div>

    @include('customer.workspaces.prospecting._nav', ['prospectingActive' => 'phone-messaging'])

    <div class="row">
        <div class="col-md-6 mb-2">
            <x-card title="Option A — Connect your Telnyx account" :padded="true">
                <p class="text-caption">You pay Telnyx directly. Your access key is checked with Telnyx before the connection is marked active, and is never shown again after saving.</p>
                @php $telnyx = $channels->where('provider', \App\Models\SendingServer::TYPE_TELNYX); @endphp
                @forelse($telnyx as $channel)
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <span>{{ $channel->sender_number }}
                            <x-badge variant="neutral">Your Telnyx account</x-badge>
                            @if($channel->status === 'active')
                                <x-badge variant="success">Connected</x-badge>
                            @else
                                <x-badge variant="neutral">Disabled</x-badge>
                            @endif
                        </span>
                        <x-button variant="outline" size="sm" :href="route('customer.workspaces.prospecting.channels.show', [$workspaceUid, $channel->uid])">Manage / reconnect</x-button>
                    </div>
                @empty
                    <p class="mb-1">No Telnyx account connected.</p>
                @endforelse
                <x-button variant="primary" size="sm" :href="route('customer.workspaces.prospecting.channels.connect', [$workspaceUid, 'Telnyx'])">Connect Telnyx</x-button>
                <p class="text-caption mt-1 mb-0">Connected accounts currently power AI Prospecting campaigns. Outreach, Conversations and Automations send through Option B.</p>
            </x-card>
        </div>

        <div class="col-md-6 mb-2">
            <x-card title="Option B — Get a number through us" :padded="true">
                <p class="text-caption">Billed from your agency's own usage wallet. Number search, verification and purchase use the same flow as every business account.</p>
                @if($business === null)
                    <p class="mb-0">Your agency needs exactly one active business account of its own before it can get a number.</p>
                @else
                    @if(($managed['phoneNumber'] ?? null) !== null)
                        <div class="mb-1">{{ $managed['phoneNumber'] }}
                            <x-badge variant="neutral">Bought through us</x-badge>
                            @if($managed['state'] === 'ready')
                                <x-badge variant="success">Ready</x-badge>
                            @else
                                <x-badge variant="warning">Verification not complete</x-badge>
                            @endif
                        </div>
                    @else
                        <p class="mb-1">No number yet.</p>
                    @endif
                    <x-button variant="primary" size="sm" :href="route('customer.workspaces.businesses.text-messaging.show', [$workspaceUid, $business->uid])">
                        {{ ($managed['phoneNumber'] ?? null) !== null ? 'Manage number' : 'Get a number' }}
                    </x-button>
                @endif
            </x-card>
        </div>
    </div>
@endsection
