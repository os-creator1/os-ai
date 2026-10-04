@extends('layouts/contentLayoutMaster')

@section('title', 'Automation run')

@php
    $badge = fn (string $s) => match ($s) {
        'succeeded' => 'badge-light-success',
        'failed', 'cancelled', 'rejected' => 'badge-light-danger',
        'awaiting_approval', 'waiting' => 'badge-light-warning',
        default => 'badge-light-secondary',
    };
@endphp

@section('content')
    <section id="admin-platform-automation-run">
        <div class="d-flex justify-content-between align-items-start mb-2">
            <div>
                <h3 class="mb-25">{{ $run->automation->name }} <span class="text-muted fs-6">v{{ $run->version->version_number }}</span></h3>
                <span class="badge {{ $badge($run->state->value) }}" data-role="pa-run-state">{{ str_replace('_', ' ', ucfirst($run->state->value)) }}</span>
                @if ($run->safe_error)<span class="text-danger ms-1">{{ $run->safe_error }}</span>@endif
            </div>
            @if ($run->state->value === 'failed')
                <form method="POST" action="{{ route('admin.platform-automations.runs.retry', $run) }}">@csrf
                    <button class="btn btn-primary" data-role="pa-retry">Retry from the failed step</button></form>
            @endif
        </div>

        <div class="card">
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-sm-3">Trigger</dt><dd class="col-sm-9">{{ $run->trigger_type }}</dd>
                    <dt class="col-sm-3">Target</dt><dd class="col-sm-9">{{ ucfirst($run->target_type->value) }} #{{ $run->target_id }}
                        @if ($run->workspace_id) · Workspace #{{ $run->workspace_id }} @endif
                        @if ($run->business_id) · Business #{{ $run->business_id }} @endif</dd>
                    <dt class="col-sm-3">Occurrence</dt><dd class="col-sm-9"><code>{{ $run->occurrence_key }}</code></dd>
                    <dt class="col-sm-3">Started</dt><dd class="col-sm-9">{{ $run->started_at?->format('M j, Y g:i:s A') ?? '—' }}</dd>
                    <dt class="col-sm-3">Finished</dt><dd class="col-sm-9">{{ $run->finished_at?->format('M j, Y g:i:s A') ?? '—' }}</dd>
                </dl>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h5 class="card-title mb-0">Steps</h5></div>
            <div class="table-responsive">
                <table class="table" data-role="pa-steps">
                    <thead class="table-primary"><tr><th>#</th><th>Action</th><th>Safety</th><th>State</th><th>Attempts</th><th>Result / reference</th></tr></thead>
                    <tbody>
                    @foreach ($run->steps as $step)
                        <tr data-step="{{ $step->step_index }}">
                            <td>{{ $step->step_index + 1 }}</td>
                            <td>{{ $actions[$step->action_type]['label'] ?? $step->action_type }}
                                @if ($step->run_at && $step->state->value !== 'succeeded')<div class="small text-muted">due {{ $step->run_at->format('M j, g:i A') }}</div>@endif</td>
                            <td>{{ $step->safety_class->label() }}</td>
                            <td><span class="badge {{ $badge($step->state->value) }}">{{ str_replace('_', ' ', ucfirst($step->state->value)) }}</span>
                                @if ($step->safe_error)<div class="small text-danger">{{ $step->safe_error }}</div>@endif</td>
                            <td>{{ $step->attempts }}</td>
                            <td class="small">
                                @if ($step->operation_ref)<code>{{ $step->operation_ref }}</code>@endif
                                @if ($step->result){{ json_encode($step->result) }}@endif
                                @if ($step->decided_by_user_id)<div class="text-muted">decided by user #{{ $step->decided_by_user_id }} · {{ $step->decided_at?->format('M j, g:i A') }}</div>@endif
                            </td>
                        </tr>
                        @if ($step->state->value === 'awaiting_approval')
                            <tr>
                                <td colspan="6">
                                    <div class="alert alert-warning mb-0" data-role="pa-approval">
                                        <strong>Needs your approval.</strong>
                                        This step changes an account's state, billing or entitlements. It runs only if you approve, and then as you
                                        (the change is recorded in the audit trail under your name).
                                        <div class="mt-1 d-flex gap-1">
                                            <form method="POST" action="{{ route('admin.platform-automations.runs.approve', [$run, $step->step_index]) }}"
                                                  onsubmit="return confirm('Approve and run this step now?');">@csrf<button class="btn btn-sm btn-warning" data-role="pa-approve">Approve and run</button></form>
                                            <form method="POST" action="{{ route('admin.platform-automations.runs.reject', [$run, $step->step_index]) }}">@csrf<button class="btn btn-sm btn-outline-danger" data-role="pa-reject">Reject</button></form>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        @endif
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
        <a href="{{ route('admin.platform-automations.index', ['tab' => 'runs']) }}" class="btn btn-outline-secondary">Back to runs</a>
    </section>
@endsection
