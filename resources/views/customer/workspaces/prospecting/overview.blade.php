@extends('layouts/contentLayoutMaster')

@section('title', 'Prospecting Overview')

@section('content')
    <div class="row mb-2">
        <div class="col-12">
            <h4 class="mb-0">Prospecting</h4>
            <p class="text-caption mb-0">Real prospecting-local operational metrics only.</p>
        </div>
    </div>

    @include('customer.workspaces.prospecting._nav', ['prospectingActive' => 'overview'])

    <div class="row">
        <div class="col-md-3 col-sm-6 mb-2">
            <x-card :padded="true">
                <p class="text-caption mb-1">Total prospects</p>
                <h3 class="mb-0">{{ $totalProspects }}</h3>
            </x-card>
        </div>
        <div class="col-md-3 col-sm-6 mb-2">
            <x-card :padded="true">
                <p class="text-caption mb-1">Active campaigns</p>
                <h3 class="mb-0">{{ $activeCampaigns }}</h3>
            </x-card>
        </div>
        <div class="col-md-3 col-sm-6 mb-2">
            <x-card :padded="true">
                <p class="text-caption mb-1">Stopped / opted out</p>
                <h3 class="mb-0">{{ $stoppedProspects }}</h3>
            </x-card>
        </div>
        <div class="col-md-3 col-sm-6 mb-2">
            <x-card :padded="true">
                <p class="text-caption mb-1">Booked</p>
                <h3 class="mb-0">{{ $bookedProspects }}</h3>
            </x-card>
        </div>
    </div>

    <div class="row">
        <div class="col-md-3 col-sm-6 mb-2">
            <x-card :padded="true">
                <p class="text-caption mb-1">Initial messages sent</p>
                <h3 class="mb-0">{{ $initialMessagesSent }}</h3>
            </x-card>
        </div>
        <div class="col-md-3 col-sm-6 mb-2">
            <x-card :padded="true">
                <p class="text-caption mb-1">Inbound replies</p>
                <h3 class="mb-0">{{ $inboundReplies }}</h3>
            </x-card>
        </div>
        <div class="col-md-3 col-sm-6 mb-2">
            <x-card :padded="true">
                <p class="text-caption mb-1">Engaged (stage 2-5)</p>
                <h3 class="mb-0">{{ $engagedStageCount }}</h3>
            </x-card>
        </div>
        <div class="col-md-3 col-sm-6 mb-2">
            <x-card :padded="true">
                <p class="text-caption mb-1">Booking links sent</p>
                <h3 class="mb-0">{{ $bookingLinksSent }}</h3>
            </x-card>
        </div>
    </div>

    <div class="row">
        <div class="col-md-4 col-sm-6 mb-2">
            <x-card :padded="true">
                <p class="text-caption mb-1">Reply rate</p>
                <h3 class="mb-0">{{ $replyRate !== null ? $replyRate . '%' : '—' }}</h3>
            </x-card>
        </div>
        <div class="col-md-4 col-sm-6 mb-2">
            <x-card :padded="true">
                <p class="text-caption mb-1">Booked rate</p>
                <h3 class="mb-0">{{ $bookedRate !== null ? $bookedRate . '%' : '—' }}</h3>
            </x-card>
        </div>
        <div class="col-md-4 col-sm-6 mb-2">
            <x-card :padded="true">
                <p class="text-caption mb-1">Opt-out rate</p>
                <h3 class="mb-0">{{ $optOutRate !== null ? $optOutRate . '%' : '—' }}</h3>
            </x-card>
        </div>
    </div>
@endsection
