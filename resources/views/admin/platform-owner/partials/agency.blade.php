{{--
    Platform Owner / Admin V1 — READ-ONLY Agency facts, only where the data
    already exists (relationship, managing Agency, client plan/subscription).
    No Agency mutation exists on this surface. Expects $support.
--}}
@php
    $agency = $support['agency'];
@endphp

@if ($agency !== null)
    <x-card title="Agency">
        <dl class="row mb-0" data-testid="po-agency">
            @if ($agency['relationship'])
                <dt class="col-sm-3">Agency-managed</dt>
                <dd class="col-sm-9">Yes</dd>

                <dt class="col-sm-3">Managing Agency</dt>
                <dd class="col-sm-9">
                    @if ($agency['agencyWorkspace'])
                        <a href="{{ route('admin.workspaces.show', $agency['agencyWorkspace']) }}">{{ $agency['agencyWorkspace']->name }}</a>
                    @else
                        —
                    @endif
                </dd>

                <dt class="col-sm-3">Relationship</dt>
                <dd class="col-sm-9">{{ $agency['relationship']->status?->value ?? '—' }} since {{ $agency['relationship']->established_at?->toDateTimeString() ?? '—' }}</dd>

                <dt class="col-sm-3">Agency SaaS plan</dt>
                <dd class="col-sm-9">
                    @if ($agency['subscription'])
                        {{ $agency['subscription']->plan?->name ?? '—' }} — {{ $agency['subscription']->status?->value ?? '—' }}
                    @else
                        No client subscription recorded.
                    @endif
                </dd>
            @endif

            @if ($agency['managedClients'] > 0)
                <dt class="col-sm-3">Manages</dt>
                <dd class="col-sm-9">{{ $agency['managedClients'] }} client {{ \Illuminate\Support\Str::plural('Workspace', $agency['managedClients']) }}</dd>
            @endif
        </dl>
    </x-card>
@endif
