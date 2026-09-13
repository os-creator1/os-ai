@extends('layouts/contentLayoutMaster')

{{--
    Automations V2 (contract §13.3, V2-D) — the workflow list.

    V2-E does not exist yet (contract §16: "D can build against fixtures
    while E builds the real controllers"), so this view depends on nothing
    but the props below — no route() call, no named route. Whatever
    controller V2-E builds renders this exact view with these exact props.

    Props:
      string $workspaceUid
      string $businessUid
      string $basePath          e.g. "/workspaces/{uid}/businesses/{uid}/automations/workflows"
      \Illuminate\Pagination\LengthAwarePaginator|iterable $workflows
          Each row: object|array with ->uid, ->name, ->status (one of
          draft|published|paused|archived), ->updated_at (Carbon|string|null).
          Only mechanically true canonical V2 lifecycle states are ever
          shown here — no conversion rate, no leads, no revenue, nothing
          this list cannot prove from the row itself.
--}}

@section('title', __('automations.v2.list.heading'))

@section('page-style')
    <link rel="stylesheet" href="{{ asset(mix('css/base/pages/automations-workflow-builder.css')) }}">
@endsection

@section('content')
    <div class="row mb-2" data-role="wf-list-header">
        <div class="col-12 d-flex justify-content-between align-items-center flex-wrap gap-2">
            <h4 class="mb-0">{{ __('automations.v2.list.heading') }}</h4>
            <x-button variant="primary" size="sm" icon="plus" href="{{ $basePath }}/new" data-role="wf-new-workflow">
                {{ __('automations.v2.list.new_workflow') }}
            </x-button>
        </div>
    </div>

    <x-card :padded="false">
        @php($rows = is_array($workflows) ? $workflows : (method_exists($workflows, 'items') ? $workflows->items() : iterator_to_array($workflows)))
        @if (count($rows) === 0)
            <x-empty-state icon="git-branch" :title="__('automations.v2.list.empty_title')" :description="__('automations.v2.list.empty_description')">
                <x-slot:action>
                    <x-button variant="primary" size="sm" icon="plus" href="{{ $basePath }}/new">
                        {{ __('automations.v2.list.new_workflow') }}
                    </x-button>
                </x-slot:action>
            </x-empty-state>
        @else
            <x-table :headers="[__('automations.v2.list.column_name'), __('automations.v2.list.column_status'), __('automations.v2.list.column_updated'), '']" data-role="wf-list-table">
                @foreach ($rows as $workflow)
                    @php($status = is_object($workflow->status ?? null) ? $workflow->status->value : ($workflow->status ?? 'draft'))
                    <tr data-role="wf-list-row" data-workflow-uid="{{ $workflow->uid }}">
                        <td>
                            <a href="{{ $basePath }}/{{ $workflow->uid }}" data-role="wf-list-open">{{ $workflow->name }}</a>
                        </td>
                        <td>
                            @switch($status)
                                @case('published')
                                    <x-badge variant="success">{{ __('automations.v2.list.status_published') }}</x-badge>
                                    @break
                                @case('paused')
                                    <x-badge variant="warning">{{ __('automations.v2.list.status_paused') }}</x-badge>
                                    @break
                                @case('archived')
                                    <x-badge variant="neutral">{{ __('automations.v2.list.status_archived') }}</x-badge>
                                    @break
                                @default
                                    <x-badge variant="neutral">{{ __('automations.v2.list.status_draft') }}</x-badge>
                            @endswitch
                        </td>
                        <td>
                            @php($updatedAt = $workflow->updated_at ?? null)
                            @if ($updatedAt)
                                {{ \Illuminate\Support\Carbon::parse($updatedAt)->format('Y-m-d H:i') }}
                            @else
                                <span class="text-caption">—</span>
                            @endif
                        </td>
                        <td class="text-end">
                            <x-button variant="outline" size="sm" href="{{ $basePath }}/{{ $workflow->uid }}">
                                {{ __('automations.v2.list.open') }}
                            </x-button>
                        </td>
                    </tr>
                @endforeach
            </x-table>
            @if (is_object($workflows) && method_exists($workflows, 'links'))
                <div class="p-3">
                    {{ $workflows->links() }}
                </div>
            @endif
        @endif
    </x-card>
@endsection
