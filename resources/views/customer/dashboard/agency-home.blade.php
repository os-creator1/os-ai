{{--
    Customer Experience Slice 4 §7 — the Agency Account Home: which client
    needs you, how many client accounts the plan allows, prospecting at a
    glance, and the account's plan and Agency-wide controls. Flags and counts
    only; a client's messages, contacts and results live in that client's own
    home, one click away.
--}}
@php use App\Library\Dashboard\DashboardSnapshot; @endphp

@if($dashboard->failed(DashboardSnapshot::BAND_CLIENTS))
    @include('customer.dashboard.band-failed', ['band' => 'clients', 'title' => 'Client accounts'])
@elseif($dashboard->has(DashboardSnapshot::BAND_CLIENTS))
    @php $clients = $dashboard->band(DashboardSnapshot::BAND_CLIENTS); @endphp
    <section class="mb-2" aria-labelledby="dashboard-clients-heading" data-band="clients">
        <x-card>
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-1 mb-1">
                <h2 class="h4 text-section-heading mb-0" id="dashboard-clients-heading">Client accounts</h2>
                @if($clients['manageUrl'])
                    <x-button variant="outline" size="sm" :href="$clients['manageUrl']" data-role="clients-manage">Manage client accounts</x-button>
                @endif
            </div>

            {{-- Wider screens: a table. --}}
            <div class="d-none d-md-block" data-role="clients-table">
                <x-table :headers="['Client account', 'Status', 'Needs attention', 'Open']" class="mb-0">
                    @foreach($clients['rows'] as $row)
                        <tr data-role="client-row">
                            <td class="fw-bolder">{{ $row['name'] }}</td>
                            <td>{{ $row['statusWord'] }}</td>
                            <td>
                                @forelse($row['flags'] as $flag)
                                    <div class="d-flex align-items-start gap-50 @unless($loop->last) mb-50 @endunless" data-role="client-flag" data-attention-type="{{ $flag['type']->value }}">
                                        <x-badge :variant="$flag['severity']->badgeVariant()">{{ $flag['severity']->word() }}</x-badge>
                                        <span>{{ $flag['text'] }}</span>
                                    </div>
                                @empty
                                    <span class="text-muted">Nothing needs attention</span>
                                @endforelse
                            </td>
                            <td>
                                @if($row['switch'] && $clients['switchUrl'])
                                    <form method="POST" action="{{ $clients['switchUrl'] }}">
                                        @csrf
                                        <input type="hidden" name="workspace" value="{{ $row['switch']['workspace'] }}">
                                        <input type="hidden" name="business" value="{{ $row['switch']['business'] }}">
                                        <x-button type="submit" variant="outline" size="sm">Open<span class="visually-hidden"> {{ $row['name'] }}</span></x-button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </x-table>
            </div>

            {{-- Narrow screens (375 px): the same rows, stacked — never a sideways-scrolling table. --}}
            <ul class="list-unstyled d-md-none mb-0" data-role="clients-stacked">
                @foreach($clients['rows'] as $row)
                    <li class="py-1 @unless($loop->last) border-bottom @endunless">
                        <h3 class="h6 mb-25">{{ $row['name'] }}</h3>
                        <p class="text-caption text-muted mb-50">{{ $row['statusWord'] }}</p>
                        @forelse($row['flags'] as $flag)
                            <div class="d-flex align-items-start gap-50 mb-50">
                                <x-badge :variant="$flag['severity']->badgeVariant()">{{ $flag['severity']->word() }}</x-badge>
                                <span>{{ $flag['text'] }}</span>
                            </div>
                        @empty
                            <p class="text-muted mb-50">Nothing needs attention</p>
                        @endforelse
                        @if($row['switch'] && $clients['switchUrl'])
                            <form method="POST" action="{{ $clients['switchUrl'] }}">
                                @csrf
                                <input type="hidden" name="workspace" value="{{ $row['switch']['workspace'] }}">
                                <input type="hidden" name="business" value="{{ $row['switch']['business'] }}">
                                <x-button type="submit" variant="outline" size="sm">Open<span class="visually-hidden"> {{ $row['name'] }}</span></x-button>
                            </form>
                        @endif
                    </li>
                @endforeach
            </ul>
        </x-card>
    </section>
@endif

@if($dashboard->failed(DashboardSnapshot::BAND_CAPACITY))
    @include('customer.dashboard.band-failed', ['band' => 'capacity', 'title' => 'Client account capacity'])
@elseif($dashboard->has(DashboardSnapshot::BAND_CAPACITY))
    @php $capacity = $dashboard->band(DashboardSnapshot::BAND_CAPACITY); @endphp
    <section class="mb-2" aria-labelledby="dashboard-capacity-heading" data-band="capacity">
        <x-card>
            <h2 class="h4 text-section-heading mb-1" id="dashboard-capacity-heading">Client account capacity</h2>
            <p class="h3 mb-25" data-role="capacity-figure">
                @if($capacity['unlimited'])
                    {{ $capacity['used'] }} in use
                @else
                    {{ $capacity['used'] }} of {{ $capacity['capacity'] }} in use
                @endif
            </p>
            <p class="mb-1" data-role="capacity-sentence">{{ $capacity['sentence'] }}</p>
            @if($capacity['manageUrl'])
                <x-button variant="outline" size="sm" :href="$capacity['manageUrl']">Manage capacity</x-button>
            @endif
        </x-card>
    </section>
@endif

@if($dashboard->failed(DashboardSnapshot::BAND_PROSPECTING))
    @include('customer.dashboard.band-failed', ['band' => 'prospecting', 'title' => 'Prospecting'])
@elseif($dashboard->has(DashboardSnapshot::BAND_PROSPECTING))
    @php $prospecting = $dashboard->band(DashboardSnapshot::BAND_PROSPECTING); @endphp
    <section class="mb-2" aria-labelledby="dashboard-prospecting-heading" data-band="prospecting">
        <x-card>
            <h2 class="h4 text-section-heading mb-1" id="dashboard-prospecting-heading">Prospecting</h2>
            <dl class="row mb-1">
                <dt class="col-6 col-md-4 text-label">Active campaigns</dt>
                <dd class="col-6 col-md-8" data-role="prospecting-campaigns">{{ number_format($prospecting['activeCampaigns']) }}</dd>
                <dt class="col-6 col-md-4 text-label">Prospects</dt>
                <dd class="col-6 col-md-8 mb-0" data-role="prospecting-prospects">{{ number_format($prospecting['prospects']) }}</dd>
            </dl>
            <x-button variant="outline" size="sm" :href="$prospecting['url']">Open prospecting</x-button>
        </x-card>
    </section>
@endif

@if($dashboard->failed(DashboardSnapshot::BAND_ACCOUNT))
    @include('customer.dashboard.band-failed', ['band' => 'account', 'title' => 'Plan and spending'])
@elseif($dashboard->has(DashboardSnapshot::BAND_ACCOUNT))
    @php $account = $dashboard->band(DashboardSnapshot::BAND_ACCOUNT); @endphp
    <section class="mb-2" aria-labelledby="dashboard-account-heading" data-band="account">
        <x-card>
            <h2 class="h4 text-section-heading mb-1" id="dashboard-account-heading">Plan and spending</h2>
            <dl class="row mb-0">
                <dt class="col-6 col-md-4 text-label">Plan</dt>
                <dd class="col-6 col-md-8" data-role="account-plan">{{ $account['plan'] ?? 'No plan assigned' }}</dd>
                @if($account['controls'] !== null)
                    @if($account['controls']['mixedCurrencies'])
                        <dt class="col-6 col-md-4 text-label">Spent this month</dt>
                        <dd class="col-6 col-md-8" data-role="account-spent">Client accounts use more than one currency, so no combined figure is shown.</dd>
                    @else
                        <dt class="col-6 col-md-4 text-label">Spent this month</dt>
                        <dd class="col-6 col-md-8" data-role="account-spent">{{ $account['controls']['spentThisPeriod'] }}</dd>
                        <dt class="col-6 col-md-4 text-label">Agency-wide monthly limit</dt>
                        <dd class="col-6 col-md-8" data-role="account-limit">{{ $account['controls']['spendLimit'] ?? 'No limit set' }}</dd>
                    @endif
                    <dt class="col-6 col-md-4 text-label">Paid activity</dt>
                    <dd class="col-6 col-md-8 mb-0" data-role="account-paused">{{ $account['controls']['paused'] ? 'Paused across the agency account' : 'Running' }}</dd>
                @endif
            </dl>
        </x-card>
    </section>
@endif

@if($dashboard->has(DashboardSnapshot::BAND_TEAM_ACCOUNT))
    @include('customer.dashboard.team-account', ['parent' => $dashboard->band(DashboardSnapshot::BAND_TEAM_ACCOUNT)])
@endif
