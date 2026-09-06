@extends('layouts/contentLayoutMaster')

@section('title', $prospect->company_name)

@section('content')
    <div class="row mb-2">
        <div class="col-12 d-flex justify-content-between align-items-center">
            <h4 class="mb-0">{{ $prospect->company_name }}</h4>
            <x-button variant="outline" size="sm" icon="arrow-left"
                      :href="route('customer.workspaces.prospecting.prospects.index', $workspaceUid)">
                Back
            </x-button>
        </div>
    </div>

    <div class="row">
        <div class="col-md-6 mb-2">
            <x-card title="Prospect" :padded="true">
                <dl class="row mb-0">
                    <dt class="col-sm-4">Contact</dt>
                    <dd class="col-sm-8">{{ $prospect->contact_name ?? '—' }}</dd>

                    <dt class="col-sm-4">Phone</dt>
                    <dd class="col-sm-8">{{ $prospect->phone }}</dd>

                    <dt class="col-sm-4">Email</dt>
                    <dd class="col-sm-8">{{ $prospect->email ?? '—' }}</dd>

                    <dt class="col-sm-4">Website</dt>
                    <dd class="col-sm-8">{{ $prospect->website ?? '—' }}</dd>

                    <dt class="col-sm-4">Source</dt>
                    <dd class="col-sm-8">{{ $prospect->source ?? '—' }}</dd>

                    <dt class="col-sm-4">Location</dt>
                    <dd class="col-sm-8">{{ $prospect->location ?? '—' }}</dd>

                    <dt class="col-sm-4">Status</dt>
                    <dd class="col-sm-8">
                        @if($prospect->status->value === 'active')
                            <x-badge variant="success">Active</x-badge>
                        @elseif($prospect->status->value === 'booked')
                            <x-badge variant="accent">Booked</x-badge>
                        @else
                            <x-badge variant="neutral">Stopped</x-badge>
                        @endif
                    </dd>
                </dl>

                @if($prospect->status->value === 'active')
                    <div class="d-flex gap-2 mt-3">
                        <form method="post" action="{{ route('customer.workspaces.prospecting.prospects.stop', [$workspaceUid, $prospect->uid]) }}">
                            @csrf
                            <x-button type="submit" variant="outline" size="sm">Stop</x-button>
                        </form>
                        <form method="post" action="{{ route('customer.workspaces.prospecting.prospects.mark-booked', [$workspaceUid, $prospect->uid]) }}">
                            @csrf
                            <x-button type="submit" variant="primary" size="sm">Mark booked</x-button>
                        </form>
                    </div>
                @endif
            </x-card>
        </div>

        <div class="col-md-6 mb-2">
            <x-card title="Campaign enrollments" :padded="true">
                @if($memberships->isEmpty())
                    <p class="text-caption mb-0">Not enrolled in any campaign yet.</p>
                @else
                    <ul class="list-unstyled mb-0">
                        @foreach($memberships as $member)
                            <li class="mb-1">
                                <a href="{{ route('customer.workspaces.prospecting.campaigns.show', [$workspaceUid, $member->campaign->uid]) }}">{{ $member->campaign->name }}</a>
                                <x-badge variant="neutral">Stage {{ $member->stage->value }}</x-badge>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-card>
        </div>
    </div>
@endsection
