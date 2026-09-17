@extends('layouts/contentLayoutMaster')

{{--
    Implementation Contract 08A — a single Client's detail page. Every
    fact here comes from the existing canonical records this Agency
    Workspace already has an ACTIVE Contract 01 relationship for
    (AgencyClientsController::show()) — no analytics dashboard, no
    financial data, no AgencyRebill/SaaS Plan/White Label surface.
--}}

@section('title', $clientWorkspace->name)

@section('content')
    <section id="agency-client-detail">
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">
            <div>
                <a href="{{ route('customer.workspaces.clients.index', $agencyWorkspace->uid) }}" class="text-caption d-inline-block mb-1">&larr; Back to Clients</a>
                <h2 class="mb-0" data-role="client-workspace-name">{{ $clientWorkspace->name }}</h2>
            </div>
            <form method="POST" action="{{ route('customer.workspaces.clients.view-as', [$agencyWorkspace->uid, $clientWorkspace->uid]) }}" data-role="view-as-form">
                @csrf
                <x-button type="submit" variant="primary" data-role="view-as-client">View As this client</x-button>
            </form>
        </div>

        <div class="row">
            <div class="col-12 col-lg-8">
                <x-card title="Client business">
                    @if ($business !== null)
                        <dl class="row mb-0" data-role="client-business-facts">
                            <dt class="col-sm-4">Business name</dt>
                            <dd class="col-sm-8" data-role="business-name">{{ $business->name }}</dd>

                            <dt class="col-sm-4">Locations</dt>
                            <dd class="col-sm-8" data-role="location-count">{{ $locationCount }}</dd>

                            <dt class="col-sm-4">Primary location</dt>
                            <dd class="col-sm-8" data-role="primary-location">
                                {{ $primaryLocation?->name ?? $primaryLocation?->address_line_1 ?? 'Not set' }}
                            </dd>
                        </dl>
                    @else
                        <x-alert variant="warning" data-role="business-data-integrity-warning">
                            @if ($businessCount === 0)
                                This client has no Business on file. This is a data-integrity
                                issue outside this screen's scope to repair.
                            @else
                                This client has {{ $businessCount }} Businesses on file, more
                                than the one this screen expects. This is a data-integrity
                                issue outside this screen's scope to repair.
                            @endif
                        </x-alert>
                    @endif
                </x-card>
            </div>

            <div class="col-12 col-lg-4">
                <x-card title="Relationship">
                    <dl class="row mb-0" data-role="relationship-facts">
                        <dt class="col-sm-5">Status</dt>
                        <dd class="col-sm-7">
                            <x-badge variant="success" data-role="relationship-status">{{ ucfirst($relationship->status->value) }}</x-badge>
                        </dd>

                        <dt class="col-sm-5">Managed since</dt>
                        <dd class="col-sm-7" data-role="established-at">{{ $relationship->established_at?->format('M j, Y') }}</dd>
                    </dl>
                </x-card>

                <x-card title="Account access">
                    <p class="mb-0" data-role="account-access-state">
                        @if ($accessDecision->isLocked())
                            <x-badge variant="danger">{{ $accessDecision->heading ?? 'Locked' }}</x-badge>
                        @else
                            <x-badge variant="success">Usable</x-badge>
                        @endif
                    </p>
                </x-card>
            </div>
        </div>
    </section>
@endsection
