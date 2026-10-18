@extends('layouts/contentLayoutMaster')

@section('title', 'Phone & Messaging')

@php
    $hasManagedNumber = ($managed['phoneNumber'] ?? null) !== null;
    $managedReady = ($managed['state'] ?? null) === 'ready';
    $managedIsDefault = $hasManagedNumber && ! $defaultIsByo;
@endphp

@section('content')
    <div class="row mb-2">
        <div class="col-12">
            <h4 class="mb-0">Phone &amp; Messaging</h4>
            <p class="text-caption mb-0">Choose how your agency sends texts: connect your own Telnyx account, or get a number through us. Numbers listed here belong to your agency, never to a client. Changing your sender only affects new messages — past conversations stay as they are.</p>
        </div>
    </div>

    @include('customer.workspaces.prospecting._nav', ['prospectingActive' => 'phone-messaging'])

    <div class="row">
        <div class="col-md-6 mb-2">
            <x-card title="Option A — Connect your Telnyx account" :padded="true">
                <p class="text-caption">Telnyx bills you directly; nothing is taken from your MotionGrove balance. Your access key is checked with Telnyx before it is saved, and is never shown again.</p>
                @php $telnyx = $channels->where('provider', \App\Models\SendingServer::TYPE_TELNYX); @endphp
                @forelse($telnyx as $channel)
                    @php
                        $state = $readiness[$channel->id] ?? ['ok' => false, 'message' => null];
                        $hasKey = trim((string) $channel->webhook_public_key) !== '';
                    @endphp
                    <div class="border rounded p-1 mb-1">
                        <div>
                            <strong>{{ $channel->sender_number }}</strong>
                            <x-badge variant="neutral">Your Telnyx account · billed by Telnyx</x-badge>
                            @if($channel->is_default_sender)
                                <x-badge variant="success">Current sender</x-badge>
                            @endif
                        </div>
                        <div class="mt-50">
                            @if($state['ok'])
                                <x-badge variant="success">Can send</x-badge>
                            @else
                                <x-badge variant="warning">Needs attention</x-badge>
                                <span class="text-caption">{{ $state['message'] }}</span>
                            @endif
                        </div>
                        <div class="mt-50">
                            @if(! $hasKey)
                                <x-badge variant="warning">Replies not set up</x-badge>
                                <span class="text-caption">Add your Telnyx webhook public key so replies can be received.</span>
                            @elseif($channel->webhook_verified_at)
                                <x-badge variant="success">Replies working</x-badge>
                            @else
                                <x-badge variant="neutral">Waiting for first event from Telnyx</x-badge>
                                <span class="text-caption">Set the webhook URL on the Manage page in your Telnyx messaging profile.</span>
                            @endif
                        </div>
                        <div class="d-flex flex-wrap gap-1 mt-1">
                            @if(! $channel->is_default_sender)
                                <form method="post" action="{{ route('customer.workspaces.prospecting.phone-messaging.sender', $workspaceUid) }}">
                                    @csrf
                                    <input type="hidden" name="sender" value="{{ $channel->uid }}">
                                    <x-button type="submit" variant="primary" size="sm">Use as my sender</x-button>
                                </form>
                            @endif
                            <x-button variant="outline" size="sm" :href="route('customer.workspaces.prospecting.channels.show', [$workspaceUid, $channel->uid])">Manage / change account</x-button>
                            @if(! $channel->is_default_sender)
                                <form method="post" action="{{ route('customer.workspaces.prospecting.channels.disconnect', [$workspaceUid, $channel->uid]) }}"
                                      onsubmit="return confirm('Disconnect this Telnyx account? Its saved credentials will be erased. Your Telnyx account and number are not changed.');">
                                    @csrf
                                    <x-button type="submit" variant="outline" size="sm">Disconnect</x-button>
                                </form>
                            @endif
                        </div>
                    </div>
                @empty
                    <p class="mb-1">No Telnyx account connected.</p>
                @endforelse
                <x-button variant="primary" size="sm" :href="route('customer.workspaces.prospecting.channels.connect', [$workspaceUid, 'Telnyx'])">Connect a Telnyx number</x-button>
            </x-card>
        </div>

        <div class="col-md-6 mb-2">
            <x-card title="Option B — Get a number through us" :padded="true">
                <p class="text-caption">Billed from your agency's own MotionGrove usage wallet. Number search, verification and purchase use the same flow as every business account.</p>
                @if($business === null)
                    <p class="mb-0">Your agency needs exactly one active business account of its own before it can get a number.</p>
                @else
                    @if($hasManagedNumber)
                        <div class="border rounded p-1 mb-1">
                            <div>
                                <strong>{{ $managed['phoneNumber'] }}</strong>
                                <x-badge variant="neutral">Bought through us · billed to your wallet</x-badge>
                                @if($managedIsDefault)
                                    <x-badge variant="success">Current sender</x-badge>
                                @endif
                            </div>
                            <div class="mt-50">
                                @if($managedReady)
                                    <x-badge variant="success">Can send</x-badge>
                                @else
                                    <x-badge variant="warning">Needs attention</x-badge>
                                    <span class="text-caption">Verification is not complete yet. Finish it in Text messaging.</span>
                                @endif
                            </div>
                            @if(! $managedIsDefault)
                                <form method="post" class="mt-1" action="{{ route('customer.workspaces.prospecting.phone-messaging.sender', $workspaceUid) }}">
                                    @csrf
                                    <input type="hidden" name="sender" value="managed">
                                    <x-button type="submit" variant="primary" size="sm">Use as my sender</x-button>
                                </form>
                            @endif
                        </div>
                    @else
                        <p class="mb-1">No number yet.</p>
                    @endif
                    <x-button variant="primary" size="sm" :href="route('customer.workspaces.businesses.text-messaging.show', [$workspaceUid, $business->uid])">
                        {{ $hasManagedNumber ? 'Manage number' : 'Get a number' }}
                    </x-button>
                @endif
            </x-card>
        </div>
    </div>
@endsection
