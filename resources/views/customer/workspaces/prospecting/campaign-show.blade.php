@extends('layouts/contentLayoutMaster')

@section('title', $campaign->name)

@section('content')
    <div class="row mb-2">
        <div class="col-12 d-flex justify-content-between align-items-center">
            <h4 class="mb-0">{{ $campaign->name }}</h4>
            <x-button variant="outline" size="sm" icon="arrow-left"
                      :href="route('customer.workspaces.prospecting.campaigns.index', $workspaceUid)">
                Back
            </x-button>
        </div>
    </div>

    <div class="row">
        <div class="col-md-6 mb-2">
            <x-card title="Campaign" :padded="true">
                <p class="text-caption">Status: <x-badge variant="neutral">{{ ucfirst($campaign->status->value) }}</x-badge></p>
                @if($campaign->context)
                    <p class="mb-2">{{ $campaign->context }}</p>
                @endif

                <form method="post" action="{{ route('customer.workspaces.prospecting.campaigns.status', [$workspaceUid, $campaign->uid]) }}" class="d-flex gap-2">
                    @csrf
                    <select name="status" class="form-select" style="max-width: 200px;">
                        <option value="draft" @selected($campaign->status->value === 'draft')>Draft</option>
                        <option value="active" @selected($campaign->status->value === 'active')>Active</option>
                        <option value="paused" @selected($campaign->status->value === 'paused')>Paused</option>
                    </select>
                    <x-button type="submit" variant="primary" size="sm">Update status</x-button>
                </form>
            </x-card>

            <x-card title="Enroll a prospect" :padded="true" class="mt-2">
                @if($enrollableProspects->isEmpty())
                    <p class="text-caption mb-0">No eligible prospects to enroll.</p>
                @else
                    <form method="post" action="{{ route('customer.workspaces.prospecting.campaigns.members.store', [$workspaceUid, $campaign->uid]) }}" class="d-flex gap-2">
                        @csrf
                        <select name="prospect_uid" class="form-select">
                            @foreach($enrollableProspects as $prospect)
                                <option value="{{ $prospect->uid }}">{{ $prospect->company_name }} ({{ $prospect->phone }})</option>
                            @endforeach
                        </select>
                        <x-button type="submit" variant="primary" size="sm">Enroll</x-button>
                    </form>
                @endif
            </x-card>
        </div>

        <div class="col-md-6 mb-2">
            <x-card title="Enrolled prospects" :padded="true">
                @if($members->isEmpty())
                    <p class="text-caption mb-0">No prospects enrolled yet.</p>
                @else
                    <ul class="list-unstyled mb-0">
                        @foreach($members as $member)
                            <li class="mb-1 d-flex justify-content-between align-items-center">
                                <a href="{{ route('customer.workspaces.prospecting.prospects.show', [$workspaceUid, $member->prospect->uid]) }}">{{ $member->prospect->company_name }}</a>
                                <x-badge variant="neutral">Stage {{ $member->stage->value }}</x-badge>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-card>
        </div>
    </div>
@endsection
