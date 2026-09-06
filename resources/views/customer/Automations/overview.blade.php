@extends('layouts/contentLayoutMaster')

@section('title', $automation->name)

@section('content')
    <div class="row mb-2">
        <div class="col-12 d-flex justify-content-between align-items-center">
            <h4 class="mb-0">{{ $automation->name }}</h4>
            <x-button variant="outline" size="sm" icon="arrow-left"
                      :href="route('customer.workspaces.businesses.automations.index', [$workspaceUid, $businessUid])">
                Back
            </x-button>
        </div>
    </div>

    <div class="row">
        <div class="col-md-5 mb-2">
            <x-card title="Definition" :padded="true">
                <dl class="row mb-0">
                    <dt class="col-sm-4">Status</dt>
                    <dd class="col-sm-8">
                        @if($automation->status === \App\Models\Automation::STATUS_ACTIVE)
                            <x-badge variant="success">Active</x-badge>
                        @elseif($automation->status === \App\Models\Automation::STATUS_ERROR)
                            <x-badge variant="danger">Error</x-badge>
                        @else
                            <x-badge variant="neutral">Disabled</x-badge>
                        @endif
                    </dd>

                    <dt class="col-sm-4">Trigger</dt>
                    <dd class="col-sm-8">{{ $automation->triggerSummary() }}</dd>

                    <dt class="col-sm-4">Action</dt>
                    <dd class="col-sm-8">{{ $automation->actionSummary() }}</dd>

                    <dt class="col-sm-4">Created</dt>
                    <dd class="col-sm-8">{{ $automation->created_at?->format('Y-m-d H:i') }}</dd>
                </dl>

                <div class="d-flex gap-2 mt-3 flex-wrap">
                    <x-button variant="outline" size="sm" icon="edit-3"
                              :href="route('customer.workspaces.businesses.automations.edit', [$workspaceUid, $businessUid, $automation->uid])">
                        Edit
                    </x-button>

                    @if($automation->status === \App\Models\Automation::STATUS_ACTIVE)
                        <form method="post" action="{{ route('customer.workspaces.businesses.automations.disable', [$workspaceUid, $businessUid, $automation->uid]) }}">
                            @csrf
                            <x-button type="submit" variant="outline" size="sm">Disable</x-button>
                        </form>
                    @else
                        <form method="post" action="{{ route('customer.workspaces.businesses.automations.enable', [$workspaceUid, $businessUid, $automation->uid]) }}">
                            @csrf
                            <x-button type="submit" variant="primary" size="sm">Enable</x-button>
                        </form>
                    @endif

                    <form method="post" action="{{ route('customer.workspaces.businesses.automations.destroy', [$workspaceUid, $businessUid, $automation->uid]) }}"
                          onsubmit="return confirm('Delete this automation and its execution history?');">
                        @csrf
                        <x-button type="submit" variant="outline" size="sm" icon="trash-2">Delete</x-button>
                    </form>
                </div>
            </x-card>
        </div>

        <div class="col-md-7 mb-2">
            <x-card title="Execution history" :padded="false">
                @if($executions->isEmpty())
                    <x-empty-state icon="clock" title="No executions yet"
                                    description="Each time this automation fires for a contact, the outcome is recorded here." />
                @else
                    <x-table :headers="['When', 'Contact', 'Trigger', __('locale.labels.status'), 'Result']">
                        @foreach($executions as $execution)
                            <tr>
                                <td>{{ $execution->created_at?->format('Y-m-d H:i') }}</td>
                                <td>{{ $execution->contact?->getFullName($execution->contact?->phone ? '…' . substr((string) $execution->contact->phone, -4) : '—') }}</td>
                                <td>{{ $execution->trigger_type?->label() }}</td>
                                <td><x-badge :variant="$execution->status->badgeVariant()">{{ $execution->status->label() }}</x-badge></td>
                                <td class="text-caption">{{ $execution->safe_result_summary ?? $execution->safe_error_summary ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </x-table>
                @endif
            </x-card>
        </div>
    </div>
@endsection
