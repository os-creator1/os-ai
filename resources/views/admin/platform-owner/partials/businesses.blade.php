{{--
    Platform Owner / Admin V1 — one row per Business in the Workspace, with the
    state a support person needs on a single line: lifecycle, Locations,
    whether customer access is granted (the Workspace's canonical decision),
    payer/wallet status and provider connection STATUS. Provider rows come
    from ProviderConnectionStatusReader, which never selects a credential
    column. Expects $support.
--}}
@php
    $decision = $support['decision'];
    $label = fn ($value) => $value === null ? '—' : ucfirst(str_replace('_', ' ', is_object($value) ? $value->value : (string) $value));
@endphp

<x-card title="Businesses">
    @if (empty($support['businesses']))
        <x-empty-state icon="inbox" title="No Businesses in this Workspace." />
    @else
        <x-table :headers="['Business', 'Status', 'Locations', 'Customer access', 'Payer / wallet', 'Providers']">
            @foreach ($support['businesses'] as $row)
                @php
                    $business = $row['business'];
                    $stripe = $row['stripe'];
                    $email = $row['email'];
                    $google = $row['google'];
                @endphp
                <tr data-testid="po-business-row">
                    <td>
                        @can('view business')
                            <a href="{{ route('admin.businesses.show', $business) }}">{{ $business->name }}</a>
                        @else
                            {{ $business->name }}
                        @endcan
                        <div class="text-muted small">{{ $business->uid }}</div>
                        <div class="text-muted small">Owner: {{ $business->customer?->user?->displayName() ?? 'Unknown' }}</div>
                        @if ($row['agencyManaged']) <x-badge variant="accent">Agency-managed</x-badge> @endif
                    </td>
                    <td>{{ $label($business->status) }}</td>
                    <td>
                        {{ $row['locations']['active'] }} active
                        @if ($row['locations']['archived'] > 0) · {{ $row['locations']['archived'] }} archived @endif
                        @if ($business->primaryLocation)
                            <div class="text-muted small">Primary: {{ collect([$business->primaryLocation->name, $business->primaryLocation->city, $business->primaryLocation->region])->filter()->implode(', ') }}</div>
                        @endif
                    </td>
                    <td>
                        @if ($row['accessGranted'])
                            <x-badge variant="success">Granted</x-badge>
                        @else
                            <x-badge variant="danger">Denied</x-badge>
                        @endif
                        <div class="text-muted small"><code>{{ $decision->reason }}</code></div>
                    </td>
                    <td>
                        {{ $row['payer'] ? $label($row['payer']->payer_type) : 'No payer assignment' }}
                        <div class="text-muted small">
                            Wallet: {{ $row['wallet'] ? $label($row['wallet']->billing_status) : 'none' }}
                            @if ($row['wallet']?->paid_activity_paused_at) · paid activity paused @endif
                        </div>
                    </td>
                    <td>
                        <div data-testid="po-provider-stripe">
                            Stripe:
                            @if ($stripe)
                                {{ $label($stripe->status) }}
                                <span class="text-muted small"><code>{{ $stripe->stripe_account_id }}</code>@if ($stripe->requirements_disabled_reason) · {{ $stripe->requirements_disabled_reason }} @endif</span>
                            @else
                                not connected
                            @endif
                        </div>
                        <div data-testid="po-provider-email">
                            Email:
                            @if ($email)
                                {{ $label($email->state) }} ({{ $label($email->provider) }})
                                <span class="text-muted small">{{ $email->mailbox_email }}@if ($email->failure_classification) · {{ $email->failure_classification }} @endif</span>
                            @else
                                not connected
                            @endif
                        </div>
                        <div data-testid="po-provider-google">
                            Google Business Profile:
                            @if ($google)
                                {{ $label($google->state) }}
                                @if ($google->failure_classification) <span class="text-muted small">· {{ $google->failure_classification }}</span> @endif
                            @else
                                not connected
                            @endif
                        </div>
                    </td>
                </tr>
            @endforeach
        </x-table>

        @if ($support['businessesTruncated'])
            <p class="text-muted mt-2 mb-0">Showing the first {{ \App\Library\PlatformOwner\WorkspaceSupportReader::MAX_BUSINESSES }} Businesses.</p>
        @endif

        @if (collect($support['businesses'])->contains(fn ($row) => $row['wallet'] !== null || $row['payer'] !== null))
            <p class="text-muted mt-2 mb-0">Balances, ledgers and funding live on each Business's Usage Billing page.</p>
        @endif
    @endif
</x-card>

@php
    $calendarRows = collect($support['calendar'])->flatten(1);
@endphp
<x-card title="Calendar connections">
    @if ($calendarRows->isEmpty())
        <p class="mb-0 text-muted" data-testid="po-calendar-none">No member of this Workspace has a calendar connected.</p>
    @else
        <x-table :headers="['Member', 'Provider', 'State', 'Last synced', 'Sync failures']">
            @foreach ($calendarRows as $connection)
                @php $member = $support['memberships']->firstWhere('user_id', $connection->user_id)?->user ?? ($support['workspace']->owner_user_id === $connection->user_id ? $support['workspace']->owner : null); @endphp
                <tr data-testid="po-calendar-row">
                    <td>{{ $member?->displayName() ?? 'User #' . $connection->user_id }}<div class="text-muted small">{{ $connection->external_account_email }}</div></td>
                    <td>{{ $label($connection->provider) }}</td>
                    <td>{{ $label($connection->state) }}@if ($connection->failure_classification) <div class="text-muted small">{{ $connection->failure_classification }}</div> @endif</td>
                    <td>{{ $connection->last_synced_at?->toDateTimeString() ?? '—' }}</td>
                    <td>{{ $connection->sync_failure_count }}@if ($connection->last_sync_failure_at) <div class="text-muted small">last {{ $connection->last_sync_failure_at->toDateTimeString() }}</div> @endif</td>
                </tr>
            @endforeach
        </x-table>
    @endif
</x-card>
