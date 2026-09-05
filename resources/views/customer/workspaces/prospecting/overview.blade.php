@extends('layouts/contentLayoutMaster')

@section('title', 'Prospecting Overview')

@section('content')
    <div class="row mb-2">
        <div class="col-12">
            <h4 class="mb-0">Prospecting</h4>
            <p class="text-caption mb-0">Foundation data only — automatic AI outreach and sending are not yet implemented.</p>
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
@endsection
