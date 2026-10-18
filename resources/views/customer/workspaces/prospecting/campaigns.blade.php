@extends('layouts/contentLayoutMaster')

@section('title', 'Outreach campaigns')

@section('content')
    <div class="row mb-2">
        <div class="col-12">
            <h4 class="mb-0">Outreach</h4>
        </div>
    </div>

    @include('customer.workspaces.prospecting._nav', ['prospectingActive' => 'campaigns'])

    <div class="row">
        <div class="col-md-4 mb-2">
            <x-card title="Create a campaign" :padded="true">
                <form method="post" action="{{ route('customer.workspaces.prospecting.campaigns.managed.store', $workspaceUid) }}">
                    @csrf
                    <div class="mb-1">
                        <label class="form-label" for="name">Name</label>
                        <input type="text" id="name" name="name" class="form-control @error('name') is-invalid @enderror" value="{{ old('name') }}">
                        @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="mb-1">
                        <label class="form-label" for="opening_message">First text</label>
                        <textarea id="opening_message" name="opening_message" class="form-control @error('opening_message') is-invalid @enderror" rows="4">{{ old('opening_message', $defaultOpener) }}</textarea>
                        @error('opening_message')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <p class="text-caption mb-0">The first text each prospect gets. It starts from your Message 1; you can change it here.</p>
                    </div>
                    <div class="mb-1">
                        <label class="form-label" for="context">Notes (only you see these)</label>
                        <textarea id="context" name="context" class="form-control" rows="2">{{ old('context') }}</textarea>
                    </div>
                    <x-button type="submit" variant="primary">Create draft campaign</x-button>
                </form>
            </x-card>
        </div>

        <div class="col-md-8 mb-2">
            <x-card title="Campaigns" :padded="true">
                @if($pausedForFunds)
                    <x-alert variant="warning" data-role="paused-for-funds">
                        <div class="d-flex justify-content-between align-items-center w-100 gap-2">
                            <span>Paused — add funds. Some texts are waiting for your balance.</span>
                            <form method="post" action="{{ route('customer.workspaces.prospecting.sending.resume', $workspaceUid) }}">
                                @csrf
                                <x-button type="submit" variant="primary" size="sm">Resume sending</x-button>
                            </form>
                        </div>
                    </x-alert>
                @endif

                @if($campaigns->isEmpty())
                    <x-empty-state icon="send" title="No campaigns yet"
                                    description="Create a draft campaign to start organizing prospects." />
                @else
                    <div class="table-responsive">
                        <table class="table" data-role="campaigns-table">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Prospects</th>
                                    <th>Sending number</th>
                                    <th>Status</th>
                                    <th>Created</th>
                                    <th>Replies</th>
                                    <th>Booked calls</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($campaigns as $campaign)
                                    @php $summary = $summaries[$campaign->id]; @endphp
                                    <tr data-campaign="{{ $campaign->uid }}">
                                        <td>{{ $campaign->name }}</td>
                                        <td>{{ $summary['prospects'] }}</td>
                                        <td>{{ $campaign->isManaged() ? ($sendingNumber ?? '—') : 'Own channel' }}</td>
                                        <td>
                                            <x-badge variant="neutral">{{ ucfirst($campaign->status->value) }}</x-badge>
                                            @if($summary['paused_for_funds'])
                                                <x-badge variant="warning">Paused — add funds</x-badge>
                                            @endif
                                        </td>
                                        <td>{{ $campaign->created_at?->format('M j, Y') }}</td>
                                        <td>{{ $summary['replies'] }}</td>
                                        <td>{{ $summary['booked'] }}</td>
                                        <td>
                                            <x-button variant="outline" size="sm"
                                                      :href="route('customer.workspaces.prospecting.campaigns.show', [$workspaceUid, $campaign->uid])">
                                                Manage
                                            </x-button>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
                <p class="text-caption mt-2 mb-0">
                    Campaigns created before Outreach use your own connected channel.
                    <a href="{{ route('customer.workspaces.prospecting.channels.index', $workspaceUid) }}">Manage connected channels</a>
                </p>
            </x-card>
        </div>
    </div>
@endsection
