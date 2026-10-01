@extends('layouts/contentLayoutMaster')

{{--
    Implementation Contract 08A — the Agency-facing Clients list. Every row
    comes from an ACTIVE Contract 01 Agency<->Client relationship for this
    exact Agency Workspace (AgencyClientsController::index()) — never from
    Business-switcher or customer-context-switcher state. "Invite Client"
    posts straight to Contract 07's own existing send endpoint
    (customer.workspaces.client-invitations.store); this page never accepts
    an invitation on the client's behalf and never asks for a client
    password or payment details.
--}}

@section('title', 'Clients')

@section('content')
    <section id="agency-clients-index">
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">
            <h2 class="mb-0">Clients</h2>
        </div>

        <x-card title="Invite a client" class="mb-2">
            <p class="text-caption mb-3" data-role="invite-help">
                Send an invitation by email. The client sets up their own account and
                password when they accept — you never see or set it here.
            </p>
            <form method="POST" action="{{ route('customer.workspaces.client-invitations.store', $agencyWorkspace->uid) }}" data-role="invite-client-form">
                @csrf
                <div class="row">
                    <div class="col-12 col-md-5">
                        <x-input name="email" type="email" label="Client email" required />
                    </div>
                    <div class="col-12 col-md-5">
                        <x-input name="intended_business_name" label="Intended business name (optional)" />
                    </div>
                    <div class="col-12 col-md-2 d-flex align-items-start">
                        <x-button type="submit" variant="primary" class="mt-4" data-role="invite-client-submit">
                            Send invitation
                        </x-button>
                    </div>
                </div>
            </form>
        </x-card>

        <x-card title="Managed clients">
            @php
                $stateLabels = ['setup' => 'Waiting for setup', 'active' => 'Active business', 'inactive' => 'Inactive business'];
                $isFiltered = $search !== '' || $state !== '';
            @endphp
            <form method="GET" action="{{ route('customer.workspaces.clients.index', $agencyWorkspace->uid) }}" class="mb-3" data-role="clients-search-form">
                <div class="row align-items-end">
                    <div class="col-12 col-md-8">
                        <x-search-field id="clients-search" name="search" label="Search clients" :value="$search" placeholder="Search by name" />
                    </div>
                    <div class="col-12 col-md-4">
                        <label for="clients-state" class="form-label">Business</label>
                        <select id="clients-state" name="state" class="form-select" data-role="clients-state-filter" onchange="this.form.submit()">
                            <option value="">All clients</option>
                            @foreach ($stateFilters as $option)
                                <option value="{{ $option }}" @selected($state === $option)>{{ $stateLabels[$option] }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
            </form>

            @if ($clients->isEmpty())
                <x-empty-state
                    icon="users"
                    :title="$isFiltered ? 'No clients match your search' : 'No clients yet'"
                    :description="$isFiltered ? 'Try a different search term or filter.' : 'Invite your first client above to get started.'"
                    data-role="clients-empty-state"
                />
            @else
                <x-table :headers="['Client', 'Business', 'Account', 'Plan', 'Agency billing', 'Since', '']" data-role="clients-table">
                    @foreach ($clients as $client)
                        @php
                            // Newly-invited-client flow correction (review
                            // finding 2) — Draft and Inactive are both
                            // non-Active (ViewAsManager::startAgencyView()
                            // requires Active either way), but they are not
                            // the same fact: Draft means "the client has
                            // not finished their own setup yet", Inactive
                            // means the Business was active and is not any
                            // more. Each gets its own truthful label; only
                            // Draft ever says "waiting for client setup".
                            $clientBusinessActive = $client['business_status'] === \App\Enums\Business\BusinessStatus::Active->value;
                            $clientBusinessDraft = $client['business_status'] === \App\Enums\Business\BusinessStatus::Draft->value;
                        @endphp
                        <tr data-role="client-row" data-workspace-uid="{{ $client['workspace_uid'] }}">
                            <td>{{ $client['workspace_name'] }}</td>
                            <td>
                                @if ($client['business_name'] !== null)
                                    {{ $client['business_name'] }}
                                    @if ($clientBusinessDraft)
                                        <x-badge variant="warning" data-role="client-waiting-for-setup">Waiting for client setup</x-badge>
                                    @elseif (! $clientBusinessActive)
                                        <x-badge variant="secondary" data-role="client-business-inactive">Inactive</x-badge>
                                    @endif
                                @else
                                    <x-badge variant="warning" data-role="business-data-issue">
                                        {{ $client['business_count'] === 0 ? 'No business on file' : 'Multiple businesses' }}
                                    </x-badge>
                                @endif
                            </td>
                            <td data-role="client-account-state">
                                <x-badge :variant="$client['account']['variant']">{{ $client['account']['label'] }}</x-badge>
                            </td>
                            <td data-role="client-plan">{{ $client['plan_name'] ?? '—' }}</td>
                            <td data-role="client-agency-billing">
                                @if ($client['agency_subscription_status'] !== null)
                                    {{ $client['agency_subscription_plan'] }}
                                    <span class="text-caption">· {{ str_replace('_', ' ', $client['agency_subscription_status']->value) }}</span>
                                @else
                                    —
                                @endif
                            </td>
                            <td>{{ $client['established_at']?->format('M j, Y') }}</td>
                            <td class="text-end">
                                <div class="d-flex justify-content-end gap-1">
                                    <x-button
                                        variant="outline"
                                        size="sm"
                                        :href="route('customer.workspaces.clients.show', [$agencyWorkspace->uid, $client['workspace_uid']])"
                                        data-role="open-client"
                                    >
                                        Open
                                    </x-button>
                                    @if ($clientBusinessActive)
                                        <form method="POST" action="{{ route('customer.workspaces.clients.view-as', [$agencyWorkspace->uid, $client['workspace_uid']]) }}" data-role="view-as-form">
                                            @csrf
                                            <x-button type="submit" variant="ghost" size="sm" data-role="view-as-client">
                                                View As
                                            </x-button>
                                        </form>
                                    @elseif ($clientBusinessDraft)
                                        <x-badge variant="secondary" data-role="view-as-unavailable">Not ready</x-badge>
                                    @else
                                        <x-badge variant="secondary" data-role="view-as-unavailable">Unavailable</x-badge>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </x-table>
                <div class="mt-3" data-role="clients-pagination">{{ $clients->links() }}</div>
            @endif
        </x-card>
    </section>
@endsection
