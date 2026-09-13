@extends('layouts/contentLayoutMaster')

{{--
    CRM Opportunities — customising one pipeline after it was copied from its
    template: rename it; add, rename, reorder and archive stages
    (CrmPipelineService). The New inquiry stage can be renamed but always stays
    first and cannot be archived. Archiving a stage with open opportunities
    requires choosing where they go.
--}}

@section('title', $pipeline->name . ' · Stages')

@section('content')
    @php
        $workspaceUid = request()->route('workspaceUid');
        $businessUid = request()->route('businessUid');
        $pipelineArgs = [$workspaceUid, $businessUid, $pipeline->uid];
        $lastIndex = $activeStages->count() - 1;
    @endphp

    <section id="crm-pipeline-settings" data-role="crm-pipeline-settings">
        <div class="mb-1">
            <a href="{{ route('customer.workspaces.businesses.crm.board', [$workspaceUid, $businessUid, 'pipeline' => $pipeline->uid]) }}">&larr; Back to {{ $pipeline->name }}</a>
        </div>

        <div class="row">
            <div class="col-12 col-xl-8">
                <x-card title="Pipeline">
                    <form method="POST" action="{{ route('customer.workspaces.businesses.crm.pipelines.update', $pipelineArgs) }}" class="d-flex gap-1 align-items-end" data-role="crm-pipeline-rename">
                        @csrf
                        <div class="flex-grow-1">
                            <label for="crm-pipeline-name" class="form-label text-label">Name</label>
                            <input id="crm-pipeline-name" name="name" class="form-control" maxlength="100" required value="{{ old('name', $pipeline->name) }}">
                        </div>
                        <x-button type="submit" variant="outline">Rename</x-button>
                    </form>
                </x-card>

                <x-card title="Stages" :padded="false">
                    <ol class="list-unstyled mb-0" data-role="crm-stage-list">
                        @foreach ($activeStages as $index => $stage)
                            @php
                                $stageArgs = [...$pipelineArgs, $stage->uid];
                                $open = (int) ($openCounts[$stage->id] ?? 0);
                                $destinations = $activeStages->reject(fn ($other) => $other->is($stage));
                            @endphp
                            <li class="p-2 border-bottom" data-role="crm-stage" data-stage="{{ $stage->uid }}" @if ($stage->semantic_key) data-semantic-key="{{ $stage->semantic_key }}" @endif>
                                <div class="d-flex flex-wrap gap-1 align-items-end">
                                    <form method="POST" action="{{ route('customer.workspaces.businesses.crm.stages.update', $stageArgs) }}" class="d-flex gap-1 align-items-end flex-grow-1">
                                        @csrf
                                        <div class="flex-grow-1">
                                            <label for="crm-stage-{{ $stage->uid }}" class="form-label text-label">
                                                Stage {{ $index + 1 }}
                                                @if ($stage->isNewInquiry())
                                                    <x-badge variant="accent" data-role="crm-start-stage">Start stage</x-badge>
                                                @endif
                                                <span class="text-caption text-muted">· {{ $open }} open</span>
                                            </label>
                                            <input id="crm-stage-{{ $stage->uid }}" name="name" class="form-control" maxlength="100" required value="{{ $stage->name }}">
                                        </div>
                                        <x-button type="submit" variant="outline" size="sm">Rename</x-button>
                                    </form>

                                    @unless ($stage->isNewInquiry())
                                        <div class="d-flex gap-50">
                                            @if ($index > 1 || ($index === 1 && ! $activeStages->first()->isNewInquiry()))
                                                <form method="POST" action="{{ route('customer.workspaces.businesses.crm.stages.move', $stageArgs) }}">
                                                    @csrf
                                                    <input type="hidden" name="direction" value="up">
                                                    <x-button type="submit" variant="ghost" size="sm" aria-label="Move {{ $stage->name }} earlier">&uarr;</x-button>
                                                </form>
                                            @endif
                                            @if ($index < $lastIndex)
                                                <form method="POST" action="{{ route('customer.workspaces.businesses.crm.stages.move', $stageArgs) }}">
                                                    @csrf
                                                    <input type="hidden" name="direction" value="down">
                                                    <x-button type="submit" variant="ghost" size="sm" aria-label="Move {{ $stage->name }} later">&darr;</x-button>
                                                </form>
                                            @endif
                                        </div>
                                    @endunless
                                </div>

                                @unless ($stage->isNewInquiry())
                                    <details class="mt-1" data-role="crm-stage-archive">
                                        <summary class="text-caption text-danger">Archive this stage</summary>
                                        <form method="POST" action="{{ route('customer.workspaces.businesses.crm.stages.archive', $stageArgs) }}" class="d-flex flex-wrap gap-1 align-items-end mt-1">
                                            @csrf
                                            @if ($open > 0)
                                                <div>
                                                    <label for="crm-archive-{{ $stage->uid }}" class="form-label text-label">Move its {{ $open }} open {{ \Illuminate\Support\Str::plural('opportunity', $open) }} to</label>
                                                    <select id="crm-archive-{{ $stage->uid }}" name="destination" class="form-select" required>
                                                        @foreach ($destinations as $destination)
                                                            <option value="{{ $destination->uid }}">{{ $destination->name }}</option>
                                                        @endforeach
                                                    </select>
                                                </div>
                                            @else
                                                <p class="text-caption mb-0">No open opportunities are in this stage. Won and lost ones keep it in their history.</p>
                                            @endif
                                            <x-button type="submit" variant="danger" size="sm">Archive stage</x-button>
                                        </form>
                                    </details>
                                @endunless
                            </li>
                        @endforeach
                    </ol>

                    <form method="POST" action="{{ route('customer.workspaces.businesses.crm.stages.store', $pipelineArgs) }}" class="d-flex gap-1 align-items-end p-2" data-role="crm-stage-add">
                        @csrf
                        <div class="flex-grow-1">
                            <label for="crm-new-stage" class="form-label text-label">Add a stage</label>
                            <input id="crm-new-stage" name="name" class="form-control" maxlength="100" required placeholder="e.g. Site visit booked">
                        </div>
                        <x-button type="submit" variant="primary">Add stage</x-button>
                    </form>
                </x-card>

                @if ($archivedStages->isNotEmpty())
                    <x-card title="Archived stages">
                        <ul class="mb-0" data-role="crm-archived-stages">
                            @foreach ($archivedStages as $stage)
                                <li>{{ $stage->name }} <span class="text-caption text-muted">· archived {{ $stage->archived_at->format('M j, Y') }}</span></li>
                            @endforeach
                        </ul>
                    </x-card>
                @endif
            </div>
        </div>
    </section>
@endsection
