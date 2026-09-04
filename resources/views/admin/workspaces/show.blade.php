@extends('layouts/contentLayoutMaster')

@section('title', $workspace->name)

@section('content')
    <section id="admin-workspace-show">
        <div class="row">
            <div class="col-12">
                <x-card title="{{ $workspace->name }}">
                    <dl class="row">
                        <dt class="col-sm-3">Uid</dt>
                        <dd class="col-sm-9">{{ $workspace->uid }}</dd>

                        <dt class="col-sm-3">Status</dt>
                        <dd class="col-sm-9">
                            @if ($workspace->is_active)
                                <x-badge variant="success">Active</x-badge>
                            @else
                                <x-badge variant="neutral">Inactive</x-badge>
                            @endif
                        </dd>

                        <dt class="col-sm-3">Owner</dt>
                        <dd class="col-sm-9">{{ $workspace->owner?->displayName() ?? 'Unknown' }}</dd>

                        <dt class="col-sm-3">Owner email</dt>
                        <dd class="col-sm-9">{{ $workspace->owner?->email ?? '—' }}</dd>

                        <dt class="col-sm-3">Created</dt>
                        <dd class="col-sm-9">{{ $workspace->created_at?->toDateTimeString() ?? '—' }}</dd>

                        <dt class="col-sm-3">Updated</dt>
                        <dd class="col-sm-9">{{ $workspace->updated_at?->toDateTimeString() ?? '—' }}</dd>
                    </dl>
                </x-card>
            </div>

            <div class="col-12">
                <x-card title="Businesses">
                    @if ($workspace->businesses->isEmpty())
                        <x-empty-state icon="inbox" title="No Businesses in this Workspace." />
                    @else
                        <x-table :headers="['Name', 'Uid', 'Business owner', 'Status', '']">
                            @foreach ($workspace->businesses as $business)
                                <tr>
                                    <td>{{ $business->name }}</td>
                                    <td>{{ $business->uid }}</td>
                                    <td>{{ $business->customer?->user?->displayName() ?? 'Unknown' }}</td>
                                    <td>{{ $business->status ? ucfirst($business->status->value) : '—' }}</td>
                                    <td>
                                        @can('view business')
                                            <a href="{{ route('admin.businesses.show', $business) }}">View</a>
                                        @endcan
                                    </td>
                                </tr>
                            @endforeach
                        </x-table>
                    @endif
                </x-card>
            </div>

            <div class="col-12">
                <x-card title="Memberships">
                    @if ($workspace->memberships->isEmpty())
                        <x-empty-state icon="inbox" title="This Workspace has no members." />
                    @else
                        <x-table :headers="['Name', 'Email', 'Role', 'Business access', 'Status']">
                            @foreach ($workspace->memberships as $membership)
                                <tr>
                                    <td>{{ $membership->user?->displayName() ?? 'Unknown' }}</td>
                                    <td>{{ $membership->user?->email ?? '—' }}</td>
                                    <td>{{ ucfirst($membership->role->value) }}</td>
                                    <td>
                                        @if ($membership->business_access_scope->value === 'all')
                                            All Businesses
                                        @elseif ($membership->assignedBusinesses->isEmpty())
                                            No Businesses assigned
                                        @else
                                            {{ $membership->assignedBusinesses->pluck('name')->implode(', ') }}
                                        @endif
                                    </td>
                                    <td>
                                        @if ($membership->is_active)
                                            <x-badge variant="success">Active</x-badge>
                                        @else
                                            <x-badge variant="neutral">Inactive</x-badge>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </x-table>
                    @endif
                </x-card>
            </div>
        </div>
    </section>

    @can('view workspace plans')
        <section id="admin-workspace-plan-entitlement">
            <div class="row">
                <div class="col-12">
                    @if (session('flash_success'))
                        <div class="alert alert-success">{{ session('flash_success') }}</div>
                    @endif

                    @if (session('flash_error'))
                        <div class="alert alert-danger">{{ session('flash_error') }}</div>
                    @endif
                </div>

                <div class="col-12">
                    <div class="card">
                        <div class="card-header">
                            <h4 class="card-title">Plan &amp; Entitlement</h4>
                        </div>
                        <div class="card-body">
                            <dl class="row">
                                <dt class="col-sm-3">Assigned</dt>
                                <dd class="col-sm-9">{{ $entitlementSummary->isAssigned ? 'Yes' : 'No' }}</dd>

                                @if ($entitlementSummary->isAssigned)
                                    <dt class="col-sm-3">Tier</dt>
                                    <dd class="col-sm-9">{{ $entitlementSummary->tierDisplayName }} ({{ $entitlementSummary->tier->value }})</dd>

                                    <dt class="col-sm-3">Status</dt>
                                    <dd class="col-sm-9">{{ ucfirst($entitlementSummary->status->value) }}</dd>

                                    <dt class="col-sm-3">Complimentary</dt>
                                    <dd class="col-sm-9">{{ $entitlementSummary->isComplimentary ? 'Yes' : 'No' }}</dd>

                                    <dt class="col-sm-3">Packaged features</dt>
                                    <dd class="col-sm-9">
                                        @if (empty($entitlementSummary->planFeatureKeys))
                                            None
                                        @else
                                            {{ implode(', ', $entitlementSummary->planFeatureKeys) }}
                                        @endif
                                    </dd>
                                @endif

                                <dt class="col-sm-3">Business slot capacity</dt>
                                <dd class="col-sm-9">
                                    {{ $entitlementSummary->capacity->currentBusinessCount }} used
                                    /
                                    @if ($entitlementSummary->capacity->unlimited)
                                        unlimited
                                    @elseif ($entitlementSummary->capacity->effectiveCapacity !== null)
                                        {{ $entitlementSummary->capacity->effectiveCapacity }}
                                    @else
                                        Unavailable ({{ $entitlementSummary->capacity->denialReason }})
                                    @endif
                                    (included {{ $entitlementSummary->capacity->includedSlots }}, additional {{ $entitlementSummary->capacity->additionalSlotsAllocated }})
                                </dd>

                                <dt class="col-sm-3">Workspace overrides</dt>
                                <dd class="col-sm-9">
                                    @if (empty($entitlementSummary->overrides))
                                        None
                                    @else
                                        @foreach ($entitlementSummary->overrides as $featureKey => $state)
                                            <span class="badge {{ $state->value === 'allow' ? 'badge-light-success' : 'badge-light-danger' }}">
                                                {{ $featureKey }}: {{ $state->value }}
                                            </span>
                                        @endforeach
                                    @endif
                                </dd>
                            </dl>

                            <hr>

                            <h5>Explain effective entitlement</h5>
                            <form method="GET" class="row g-2">
                                <div class="col-md-5">
                                    <select name="business_uid" class="form-select">
                                        @foreach ($workspace->businesses as $business)
                                            <option value="{{ $business->uid }}" @selected(($entitlementExplanation['business']->uid ?? null) === $business->uid)>{{ $business->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-md-5">
                                    <select name="feature_key" class="form-select">
                                        @foreach (\App\Enums\Entitlement\PlatformFeature::cases() as $feature)
                                            <option value="{{ $feature->value }}" @selected(($entitlementExplanation['feature']->value ?? null) === $feature->value)>{{ $feature->value }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <button type="submit" class="btn btn-outline-primary w-100">Explain</button>
                                </div>
                            </form>

                            @if ($entitlementExplanation !== null)
                                <div class="mt-2">
                                    <strong>{{ $entitlementExplanation['business']->name }} / {{ $entitlementExplanation['feature']->value }}:</strong>
                                    @if ($entitlementExplanation['decision']->allowed)
                                        <span class="badge badge-light-success">Allowed</span>
                                    @else
                                        <span class="badge badge-light-danger">Denied ({{ $entitlementExplanation['decision']->reason }})</span>
                                    @endif
                                </div>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        </section>
    @endcan

    @can('manage workspace plans')
        <section id="admin-workspace-plan-mutate">
            <div class="row">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">
                            <h4 class="card-title">Mutate Plan</h4>
                        </div>
                        <div class="card-body">
                            @unless ($entitlementSummary->isAssigned)
                                <h6>Assign first plan</h6>
                                <form method="POST" action="{{ route('admin.workspaces.plan.assign', $workspace) }}" class="row g-2 mb-3">
                                    @csrf
                                    <div class="col-md-3">
                                        <select name="tier" class="form-select" required>
                                            @foreach (\App\Enums\Entitlement\WorkspacePlanTier::cases() as $tier)
                                                <option value="{{ $tier->value }}">{{ ucfirst($tier->value) }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="col-md-4">
                                        <input type="text" name="reason" class="form-control" placeholder="Reason" required>
                                    </div>
                                    <div class="col-md-2">
                                        <select name="additional_business_slots" class="form-select">
                                            <option value="0">+0 slots</option>
                                            <option value="1">+1 slot</option>
                                            <option value="2">+2 slots</option>
                                        </select>
                                    </div>
                                    <div class="col-md-2 form-check mt-1">
                                        <input type="checkbox" class="form-check-input" id="assign-is-complimentary" name="is_complimentary" value="1">
                                        <label class="form-check-label" for="assign-is-complimentary">Complimentary</label>
                                    </div>
                                    <div class="col-md-1">
                                        <button type="submit" class="btn btn-outline-primary w-100">Assign</button>
                                    </div>
                                </form>
                            @else
                                <h6>Change plan</h6>
                                <form method="POST" action="{{ route('admin.workspaces.plan.change', $workspace) }}" class="row g-2 mb-3">
                                    @csrf
                                    <div class="col-md-3">
                                        <select name="tier" class="form-select" required>
                                            @foreach (\App\Enums\Entitlement\WorkspacePlanTier::cases() as $tier)
                                                <option value="{{ $tier->value }}" @selected($entitlementSummary->tier === $tier)>{{ ucfirst($tier->value) }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="col-md-4">
                                        <input type="text" name="reason" class="form-control" placeholder="Reason (optional)">
                                    </div>
                                    <div class="col-md-3">
                                        <select name="additional_business_slots" class="form-select">
                                            <option value="">Preserve current</option>
                                            <option value="0">Set to 0</option>
                                            <option value="1">Set to 1</option>
                                            <option value="2">Set to 2</option>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <button type="submit" class="btn btn-outline-primary w-100">Change</button>
                                    </div>
                                </form>

                                <h6>Change plan status</h6>
                                <form method="POST" action="{{ route('admin.workspaces.plan.status', $workspace) }}" class="row g-2 mb-3">
                                    @csrf
                                    <div class="col-md-3">
                                        <select name="status" class="form-select" required>
                                            @foreach (\App\Enums\Entitlement\WorkspacePlanAssignmentStatus::cases() as $status)
                                                <option value="{{ $status->value }}" @selected($entitlementSummary->status === $status)>{{ ucfirst($status->value) }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <input type="text" name="reason" class="form-control" placeholder="Reason" required>
                                    </div>
                                    <div class="col-md-3">
                                        <button type="submit" class="btn btn-outline-primary w-100">Change status</button>
                                    </div>
                                </form>

                                @if ($entitlementSummary->isComplimentary)
                                    <h6>Revoke complimentary status</h6>
                                    <form method="POST" action="{{ route('admin.workspaces.plan.complimentary.revoke', $workspace) }}" class="row g-2 mb-3">
                                        @csrf
                                        @method('DELETE')
                                        <div class="col-md-9">
                                            <input type="text" name="reason" class="form-control" placeholder="Reason (optional)">
                                        </div>
                                        <div class="col-md-3">
                                            <button type="submit" class="btn btn-outline-warning w-100">Revoke</button>
                                        </div>
                                    </form>
                                @else
                                    <h6>Grant complimentary status</h6>
                                    <form method="POST" action="{{ route('admin.workspaces.plan.complimentary.grant', $workspace) }}" class="row g-2 mb-3">
                                        @csrf
                                        <div class="col-md-9">
                                            <input type="text" name="reason" class="form-control" placeholder="Reason" required>
                                        </div>
                                        <div class="col-md-3">
                                            <button type="submit" class="btn btn-outline-primary w-100">Grant</button>
                                        </div>
                                    </form>
                                @endif

                                <h6>Update additional Business slots</h6>
                                <form method="POST" action="{{ route('admin.workspaces.plan.additional-slots', $workspace) }}" class="row g-2 mb-3">
                                    @csrf
                                    <div class="col-md-3">
                                        <select name="additional_business_slots" class="form-select" required>
                                            <option value="0">0</option>
                                            <option value="1">1</option>
                                            <option value="2">2</option>
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <input type="text" name="reason" class="form-control" placeholder="Reason (optional)">
                                    </div>
                                    <div class="col-md-3">
                                        <button type="submit" class="btn btn-outline-primary w-100">Update</button>
                                    </div>
                                </form>
                            @endunless

                            <hr>

                            <h6>Create or change a Workspace entitlement override</h6>
                            <form method="POST" action="{{ route('admin.workspaces.entitlement-overrides.store', $workspace) }}" class="row g-2 mb-3">
                                @csrf
                                <div class="col-md-3">
                                    <select name="feature_key" class="form-select" required>
                                        @foreach (\App\Enums\Entitlement\PlatformFeature::cases() as $feature)
                                            <option value="{{ $feature->value }}">{{ $feature->value }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <select name="state" class="form-select" required>
                                        @foreach (\App\Enums\Entitlement\WorkspaceEntitlementOverrideState::cases() as $state)
                                            <option value="{{ $state->value }}">{{ ucfirst($state->value) }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <input type="text" name="reason" class="form-control" placeholder="Reason" required>
                                </div>
                                <div class="col-md-3">
                                    <button type="submit" class="btn btn-outline-primary w-100">Save override</button>
                                </div>
                            </form>

                            @if (! empty($entitlementSummary->overrides))
                                <h6>Revert an override</h6>
                                @foreach ($entitlementSummary->overrides as $featureKey => $state)
                                    <form method="POST" action="{{ route('admin.workspaces.entitlement-overrides.revert', [$workspace, $featureKey]) }}" class="d-inline">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-sm btn-outline-secondary mb-1">Revert {{ $featureKey }} ({{ $state->value }})</button>
                                    </form>
                                @endforeach
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        </section>
    @endcan
@endsection
