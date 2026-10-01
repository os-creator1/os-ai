{{--
    Platform Owner / Admin V1 — "why can / can't this customer use the product
    right now". Renders CustomerAccountAccessResolver's own decision (the very
    object the customer-facing gate acts on) and the plan assignment's recorded
    lifecycle. Nothing here derives access; it only displays what the domain
    answered. Expects $support (WorkspaceSupportReader::forWorkspace()).
--}}
@php
    /** @var \App\Library\Entitlement\CustomerAccountAccessDecision $decision */
    $decision = $support['decision'];
    /** @var \App\Library\Entitlement\WorkspaceEntitlementSummary $summary */
    $summary = $support['summary'];
    $workspace = $support['workspace'];
@endphp

<x-card title="Account access">
    <dl class="row mb-0" data-testid="po-access">
        <dt class="col-sm-3">Customer access now</dt>
        <dd class="col-sm-9" data-testid="po-access-state">
            @if ($decision->isLocked())
                <x-badge variant="danger">Blocked — {{ str_replace('_', ' ', $decision->state->value) }}</x-badge>
            @else
                <x-badge variant="success">Usable</x-badge>
            @endif
        </dd>

        <dt class="col-sm-3">Reason</dt>
        <dd class="col-sm-9"><code data-testid="po-access-reason">{{ $decision->reason }}</code></dd>

        @if ($decision->heading)
            <dt class="col-sm-3">What the customer sees</dt>
            <dd class="col-sm-9">{{ $decision->heading }} — {{ $decision->message }}</dd>
        @endif

        @if ($decision->isInTrial())
            <dt class="col-sm-3">Trial ends</dt>
            <dd class="col-sm-9">{{ $decision->trialEndsAt->toDateTimeString() }}</dd>
        @endif

        @if ($decision->isInGracePeriod())
            <dt class="col-sm-3">Grace ends</dt>
            <dd class="col-sm-9">{{ $decision->graceEndsAt->toDateTimeString() }}</dd>
        @endif

        <dt class="col-sm-3">Plan assignment</dt>
        <dd class="col-sm-9">
            @if ($summary->isAssigned)
                {{ $summary->tierDisplayName }} ({{ $summary->tier?->value }}) — {{ $summary->status?->value }}
                @if ($summary->isComplimentary) <x-badge variant="accent">Complimentary</x-badge> @endif
            @else
                None. An unassigned Workspace is not blocked by the access gate (onboarding and plan selection own it); plan-gated features stay unavailable.
            @endif
        </dd>

        <dt class="col-sm-3">Recorded lifecycle</dt>
        <dd class="col-sm-9">
            Trial ends: {{ $summary->trialEndsAt?->toDateTimeString() ?? '—' }}
            · Grace started: {{ $summary->graceStartedAt?->toDateTimeString() ?? '—' }}
            · Locked at: {{ $summary->lockedAt?->toDateTimeString() ?? '—' }}
        </dd>
    </dl>

    @if ($support['mismatch'])
        <x-alert variant="warning" class="mt-2" data-testid="po-mismatch">{{ $support['mismatch'] }}</x-alert>
    @endif

    @if ($support['canRestoreAccess'])
        @can('manage workspace plans')
            <hr>
            <h5>Restore access</h5>
            <p class="text-muted mb-2">
                Clears the recorded Grace/Locked state in one step (the same operation a confirmed payment performs).
                Use it only when payment or entitlement has been verified. The action is audited with your name and the reason below.
            </p>
            <form method="POST" action="{{ route('admin.platform-owner.workspaces.restore-access', $workspace) }}" data-testid="po-restore-form">
                @csrf
                <div class="mb-2">
                    <label for="restore-reason" class="form-label">Reason (required)</label>
                    <textarea id="restore-reason" name="reason" class="form-control" rows="2" maxlength="1000" required>{{ old('reason') }}</textarea>
                    @error('reason') <div class="text-danger">{{ $message }}</div> @enderror
                </div>
                <div class="form-check mb-2">
                    <input type="checkbox" id="restore-confirm" name="confirm" value="1" class="form-check-input" required>
                    <label for="restore-confirm" class="form-check-label">I confirm this Workspace should be restored to full access.</label>
                    @error('confirm') <div class="text-danger">{{ $message }}</div> @enderror
                </div>
                <button type="submit" class="btn btn-primary">Restore access</button>
            </form>
        @endcan
    @elseif ($decision->isLocked() && str_starts_with($decision->reason, 'agency_'))
        <p class="text-muted mt-2 mb-0">This block comes from the managing Agency's own account, not from this Workspace. Nothing on this Workspace can clear it.</p>
    @elseif ($decision->isLocked())
        <p class="text-muted mt-2 mb-0">A plan-status block (inactive or suspended) is changed through the Plan &amp; Entitlement card on this page, not here.</p>
    @endif
</x-card>
