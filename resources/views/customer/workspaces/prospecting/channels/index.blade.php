@extends('layouts/contentLayoutMaster')

@php
    $resolvedCustomerContext = request()->attributes->get('customerContext');
    $accountNoun = $resolvedCustomerContext instanceof \App\Library\Navigation\CustomerContext ? $resolvedCustomerContext->accountNoun() : 'account';
@endphp

@section('title', 'Prospecting Channels')

@section('content')
    <div class="row mb-2">
        <div class="col-12">
            <h4 class="mb-0">Prospecting</h4>
            <p class="text-caption mb-0">Connect a dedicated Twilio or Telnyx number owned by this {{ $accountNoun }} — never a client Business's own connection.</p>
        </div>
    </div>

    @include('customer.workspaces.prospecting._nav', ['prospectingActive' => 'channels'])

    <div class="row mb-2">
        <div class="col-12 d-flex gap-2">
            <x-button variant="primary" size="sm" :href="route('customer.workspaces.prospecting.channels.connect', [$workspaceUid, 'Twilio'])">Connect Twilio</x-button>
            <x-button variant="primary" size="sm" :href="route('customer.workspaces.prospecting.channels.connect', [$workspaceUid, 'Telnyx'])">Connect Telnyx</x-button>
        </div>
    </div>

    <x-card title="Channels" :padded="true">
        @if($channels->isEmpty())
            <x-empty-state icon="link" title="No channels connected yet"
                            description="Connect a Twilio or Telnyx number to start sending prospecting campaigns." />
        @else
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Provider</th>
                            <th>Sender number</th>
                            <th>Status</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($channels as $channel)
                            <tr>
                                <td>{{ $channel->provider }}</td>
                                <td>{{ $channel->sender_number }}</td>
                                <td>
                                    @if($channel->status === 'active')
                                        <x-badge variant="success">Active</x-badge>
                                    @else
                                        <x-badge variant="neutral">Disabled</x-badge>
                                    @endif
                                </td>
                                <td>
                                    <x-button variant="outline" size="sm"
                                              :href="route('customer.workspaces.prospecting.channels.show', [$workspaceUid, $channel->uid])">
                                        Manage
                                    </x-button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-card>
@endsection
