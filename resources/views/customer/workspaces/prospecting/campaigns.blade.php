@extends('layouts/contentLayoutMaster')

@section('title', 'Prospecting Campaigns')

@section('content')
    <div class="row mb-2">
        <div class="col-12">
            <h4 class="mb-0">Prospecting</h4>
        </div>
    </div>

    @include('customer.workspaces.prospecting._nav', ['prospectingActive' => 'campaigns'])

    <div class="row">
        <div class="col-md-4 mb-2">
            <x-card title="Create a campaign" :padded="true">
                <form method="post" action="{{ route('customer.workspaces.prospecting.campaigns.store', $workspaceUid) }}">
                    @csrf
                    <div class="mb-1">
                        <label class="form-label" for="name">Name</label>
                        <input type="text" id="name" name="name" class="form-control @error('name') is-invalid @enderror" value="{{ old('name') }}">
                        @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="mb-1">
                        <label class="form-label" for="context">Campaign context</label>
                        <textarea id="context" name="context" class="form-control" rows="4">{{ old('context') }}</textarea>
                    </div>
                    <x-button type="submit" variant="primary">Create draft campaign</x-button>
                </form>
            </x-card>
        </div>

        <div class="col-md-8 mb-2">
            <x-card title="Campaigns" :padded="true">
                @if($campaigns->isEmpty())
                    <x-empty-state icon="send" title="No campaigns yet"
                                    description="Create a draft campaign to start organizing prospects." />
                @else
                    <div class="list-group list-group-flush">
                        @foreach($campaigns as $campaign)
                            <div class="list-group-item d-flex justify-content-between align-items-center px-0">
                                <div>
                                    <strong>{{ $campaign->name }}</strong>
                                    <x-badge variant="neutral">{{ ucfirst($campaign->status->value) }}</x-badge>
                                </div>
                                <x-button variant="outline" size="sm"
                                          :href="route('customer.workspaces.prospecting.campaigns.show', [$workspaceUid, $campaign->uid])">
                                    Manage
                                </x-button>
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-card>
        </div>
    </div>
@endsection
