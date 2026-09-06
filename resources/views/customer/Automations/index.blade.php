@extends('layouts/contentLayoutMaster')

@section('title', __('locale.menu.Automations'))

@section('content')
    <div class="row mb-2">
        <div class="col-12 d-flex justify-content-between align-items-center">
            <h4 class="mb-0">{{ __('locale.menu.Automations') }}</h4>
            <x-button variant="primary" size="sm" icon="plus"
                      :href="route('customer.workspaces.businesses.automations.create', [$workspaceUid, $businessUid])">
                Create Automation
            </x-button>
        </div>
    </div>

    <x-card :padded="false">
        @if($automations->count() === 0)
            <x-empty-state icon="cpu" title="No automations yet"
                            description="Create your first automation: when a trigger fires for a contact in this Business, one action runs." />
        @else
            <x-table :headers="[__('locale.labels.name'), 'Trigger', 'Action', __('locale.labels.status'), 'Last execution']">
                @foreach($automations as $automation)
                    <tr>
                        <td>
                            <a href="{{ route('customer.workspaces.businesses.automations.show', [$workspaceUid, $businessUid, $automation->uid]) }}">{{ $automation->name }}</a>
                        </td>
                        <td>{{ $automation->triggerSummary() }}</td>
                        <td>{{ $automation->actionSummary() }}</td>
                        <td>
                            @if($automation->status === \App\Models\Automation::STATUS_ACTIVE)
                                <x-badge variant="success">Active</x-badge>
                            @elseif($automation->status === \App\Models\Automation::STATUS_ERROR)
                                <x-badge variant="danger">Error</x-badge>
                            @else
                                <x-badge variant="neutral">Disabled</x-badge>
                            @endif
                        </td>
                        <td>
                            @if($automation->executions_max_created_at)
                                {{ \Illuminate\Support\Carbon::parse($automation->executions_max_created_at)->format('Y-m-d H:i') }}
                            @else
                                <span class="text-caption">Never</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </x-table>
        @endif
    </x-card>
@endsection
