@extends('layouts/contentLayoutMaster')

@section('title', 'Platform Owner')

@section('content')
    @php
        $o = $overview;
        $count = fn (array $bucket, string $key) => (int) ($bucket[$key] ?? 0);
    @endphp
    <section id="admin-platform-owner-overview">
        <div class="row">
            <div class="col-12">
                <p class="text-muted">
                    Counts of persisted state. Customer access for any single account is decided on its Workspace page,
                    by the same rule the customer-facing gate uses.
                </p>
            </div>

            <div class="col-12" data-testid="po-shortcuts">
                <x-card title="Go to">
                    <div class="d-flex flex-wrap gap-2">
                        <x-button variant="secondary" size="sm" :href="route('admin.workspaces.index')">Find a Workspace</x-button>
                        @can('view business')<x-button variant="secondary" size="sm" :href="route('admin.businesses.index')">Businesses</x-button>@endcan
                        <x-button variant="secondary" size="sm" :href="route('admin.platform-billing.index')">Billing &amp; Revenue</x-button>
                        @can('view workspace plans')<x-button variant="secondary" size="sm" :href="route('admin.workspace-plan-catalog.index')">Plan Catalog</x-button>@endcan
                        <x-button variant="secondary" size="sm" :href="route('admin.platform-owner.audit')">Audit Logs</x-button>
                    </div>
                    <p class="text-muted small mt-2 mb-0">To recover a customer's access, open their Workspace: the account state, subscription and the restore-access control are on that page.</p>
                </x-card>
            </div>

            <div class="col-md-4">
                <x-card title="Accounts">
                    <dl class="row mb-0">
                        <dt class="col-7">Workspaces</dt>
                        <dd class="col-5" data-testid="po-count-workspaces">{{ $o['workspaces']['total'] }}</dd>
                        <dt class="col-7">Active Workspaces</dt>
                        <dd class="col-5">{{ $o['workspaces']['active'] }}</dd>
                        <dt class="col-7">Businesses</dt>
                        <dd class="col-5" data-testid="po-count-businesses">{{ $o['businessesTotal'] }}</dd>
                        <dt class="col-7">Locations</dt>
                        <dd class="col-5" data-testid="po-count-locations">{{ $o['locationsTotal'] }}</dd>
                    </dl>
                </x-card>
            </div>

            <div class="col-md-4">
                <x-card title="Businesses by status">
                    <dl class="row mb-0" data-testid="po-business-status">
                        @foreach (\App\Enums\Business\BusinessStatus::cases() as $status)
                            <dt class="col-7">{{ ucfirst($status->value) }}</dt>
                            <dd class="col-5">{{ $count($o['businessesByStatus'], $status->value) }}</dd>
                        @endforeach
                    </dl>
                </x-card>
            </div>

            <div class="col-md-4">
                <x-card title="Plan assignments">
                    <dl class="row mb-0" data-testid="po-assignments">
                        @foreach (\App\Enums\Entitlement\WorkspacePlanAssignmentStatus::cases() as $status)
                            <dt class="col-7">{{ ucfirst($status->value) }}</dt>
                            <dd class="col-5" data-testid="po-assignment-{{ $status->value }}">{{ $count($o['assignmentsByStatus'], $status->value) }}</dd>
                        @endforeach
                        @foreach (\App\Enums\Entitlement\WorkspacePlanTier::cases() as $tier)
                            <dt class="col-7 text-muted">Tier: {{ ucfirst($tier->value) }}</dt>
                            <dd class="col-5 text-muted">{{ $count($o['assignmentsByTier'], $tier->value) }}</dd>
                        @endforeach
                    </dl>
                </x-card>
            </div>

            <div class="col-md-6">
                <x-card title="Recorded account lifecycle">
                    <dl class="row mb-0" data-testid="po-lifecycle">
                        <dt class="col-7">Trialing</dt>
                        <dd class="col-5">{{ $o['recordedLifecycle']['trial'] }}</dd>
                        <dt class="col-7">In grace period</dt>
                        <dd class="col-5">{{ $o['recordedLifecycle']['grace'] }}</dd>
                        <dt class="col-7">Locked</dt>
                        <dd class="col-5" data-testid="po-lifecycle-locked">{{ $o['recordedLifecycle']['locked'] }}</dd>
                    </dl>
                    <p class="text-muted small mt-2 mb-0">From the timestamps on the plan assignments. A grace window that has run out but not yet been swept is still counted as grace.</p>
                </x-card>
            </div>

            <div class="col-md-6">
                <x-card title="Platform subscriptions">
                    <dl class="row mb-0" data-testid="po-subscriptions">
                        <dt class="col-7">Granting access</dt>
                        <dd class="col-5" data-testid="po-subscriptions-granting">{{ $o['grantingSubscriptions'] }}</dd>
                        @foreach (\App\Enums\PlatformBilling\PlatformSubscriptionStatus::cases() as $status)
                            @if (($o['subscriptionsByStatus'][$status->value] ?? 0) > 0)
                                <dt class="col-7 text-muted">{{ str_replace('_', ' ', ucfirst($status->value)) }}</dt>
                                <dd class="col-5 text-muted">{{ $o['subscriptionsByStatus'][$status->value] }}</dd>
                            @endif
                        @endforeach
                    </dl>
                </x-card>
            </div>

            <div class="col-12">
                <x-card title="Needs attention">
                    <dl class="row mb-0" data-testid="po-attention">
                        <dt class="col-sm-4">Failed payment provider events</dt>
                        <dd class="col-sm-8">
                            {{ $o['attention']['failedProviderEvents'] }}
                            @if ($o['attention']['failedProviderEvents'] > 0) · <a href="{{ route('admin.provider-events.index') }}">Review</a> @endif
                        </dd>
                        <dt class="col-sm-4">Open messaging provisioning incidents</dt>
                        <dd class="col-sm-8">
                            {{ $o['attention']['unresolvedMessagingIncidents'] }}
                            @if ($o['attention']['unresolvedMessagingIncidents'] > 0) · <a href="{{ route('admin.messaging-provisioning-incidents.index') }}">Review</a> @endif
                        </dd>
                    </dl>
                </x-card>
            </div>

            <div class="col-12">
                <x-card title="Recently blocked Workspaces">
                    @if (empty($o['recentBlocked']))
                        <p class="mb-0 text-muted" data-testid="po-blocked-none">No Workspace is currently inactive, suspended or locked.</p>
                    @else
                        <x-table :headers="['Workspace', 'Owner', 'Recorded state', 'Since']">
                            @foreach ($o['recentBlocked'] as $row)
                                @php $assignment = $row['assignment']; @endphp
                                <tr data-testid="po-blocked-row">
                                    <td><a href="{{ route('admin.workspaces.show', $row['workspace']) }}">{{ $row['workspace']->name }}</a></td>
                                    <td>{{ $row['workspace']->owner?->email ?? '—' }}</td>
                                    <td>
                                        @if ($assignment->status !== \App\Enums\Entitlement\WorkspacePlanAssignmentStatus::Active)
                                            {{ ucfirst($assignment->status->value) }}
                                        @else
                                            Locked
                                        @endif
                                    </td>
                                    <td>{{ ($assignment->locked_at ?? $assignment->updated_at)?->toDateTimeString() }}</td>
                                </tr>
                            @endforeach
                        </x-table>
                    @endif
                </x-card>
            </div>

            <div class="col-12">
                @include('admin.platform-owner.partials.audit', ['rows' => $recentActions, 'actors' => $actors, 'title' => 'Recent admin activity', 'showWorkspace' => true])
                <p><a href="{{ route('admin.platform-owner.audit') }}">View the full audit log</a></p>
            </div>
        </div>
    </section>
@endsection
