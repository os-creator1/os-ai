{{--
    The Agency Account Home — Customer Experience Slice 4 §7, reshaped by
    Unified Home §3.1 (A-1): which client needs you, how each client did over
    the selected period, the outreach truth table, and — only when they need
    an action — client-account capacity and an account billing problem.

    A client's own messages, results and recommendations stay in that client's
    Business Home, one click away; no Agency portfolio figure ever follows the
    actor into it. Nothing on this page is generated: every number is a
    persisted fact, and there is no AI call anywhere in this branch.
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

@if($dashboard->failed(DashboardSnapshot::BAND_CROSS_CLIENT))
    @include('customer.dashboard.band-failed', ['band' => 'cross_client', 'title' => 'Client performance'])
@elseif($dashboard->has(DashboardSnapshot::BAND_CROSS_CLIENT))
    @php $performance = $dashboard->band(DashboardSnapshot::BAND_CROSS_CLIENT); @endphp
    <section class="mb-2" aria-labelledby="dashboard-cross-client-heading" data-band="cross_client">
        <x-card>
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-1 mb-25">
                <h2 class="h4 text-section-heading mb-0" id="dashboard-cross-client-heading">Client performance</h2>
                <nav class="d-flex flex-wrap gap-50" aria-label="Period" data-role="cross-client-periods">
                    @foreach($performance['periods'] as $period)
                        <x-button size="sm" :variant="$period['selected'] ? 'primary' : 'outline'" :href="$period['url']" data-role="cross-client-period" data-period="{{ $period['value'] }}" :aria-current="$period['selected'] ? 'true' : null">{{ $period['label'] }}</x-button>
                    @endforeach
                </nav>
            </div>
            <p class="text-caption text-muted mb-1" data-role="cross-client-span">{{ $performance['rangeLabel'] }} · {{ $performance['spanLabel'] }}</p>

            {{-- Wider screens: a table. --}}
            <div class="d-none d-md-block" data-role="cross-client-table">
                <x-table :headers="['Client account', 'New contacts', 'New conversations']" class="mb-0">
                    @foreach($performance['rows'] as $row)
                        <tr data-role="cross-client-row">
                            <td class="fw-bolder">{{ $row['name'] }}</td>
                            <td data-role="cross-client-contacts">{{ number_format($row['newContacts']) }}</td>
                            <td data-role="cross-client-conversations">{{ number_format($row['newConversations']) }}</td>
                        </tr>
                    @endforeach
                    <tr data-role="cross-client-total">
                        <td class="fw-bolder">All client accounts</td>
                        <td class="fw-bolder">{{ number_format($performance['totals']['newContacts']) }}</td>
                        <td class="fw-bolder">{{ number_format($performance['totals']['newConversations']) }}</td>
                    </tr>
                </x-table>
            </div>

            {{-- Narrow screens (375 px): the same rows, stacked. --}}
            <ul class="list-unstyled d-md-none mb-0" data-role="cross-client-stacked">
                @foreach($performance['rows'] as $row)
                    <li class="py-1 border-bottom">
                        <h3 class="h6 mb-25">{{ $row['name'] }}</h3>
                        <p class="mb-0">{{ number_format($row['newContacts']) }} new contacts</p>
                        <p class="mb-0">{{ number_format($row['newConversations']) }} new conversations</p>
                    </li>
                @endforeach
                <li class="py-1">
                    <h3 class="h6 mb-25">All client accounts</h3>
                    <p class="mb-0">{{ number_format($performance['totals']['newContacts']) }} new contacts</p>
                    <p class="mb-0">{{ number_format($performance['totals']['newConversations']) }} new conversations</p>
                </li>
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
    @include('customer.dashboard.band-failed', ['band' => 'prospecting', 'title' => 'Outreach'])
@elseif($dashboard->has(DashboardSnapshot::BAND_PROSPECTING))
    @php $prospecting = $dashboard->band(DashboardSnapshot::BAND_PROSPECTING); @endphp
    <section class="mb-2" aria-labelledby="dashboard-prospecting-heading" data-band="prospecting">
        <x-card>
            <h2 class="h4 text-section-heading mb-25" id="dashboard-prospecting-heading">Outreach</h2>
            <p class="text-caption text-muted mb-1" data-role="prospecting-range">{{ $prospecting['rangeLabel'] }}</p>
            <dl class="row mb-1">
                <dt class="col-6 col-md-4 text-label">Prospects contacted</dt>
                <dd class="col-6 col-md-8" data-role="prospecting-contacted">{{ number_format($prospecting['contacted']) }}</dd>
                <dt class="col-6 col-md-4 text-label">Replies</dt>
                <dd class="col-6 col-md-8" data-role="prospecting-replies">{{ number_format($prospecting['replies']) }}</dd>
                <dt class="col-6 col-md-4 text-label">Booked calls</dt>
                <dd class="col-6 col-md-8" data-role="prospecting-booked">{{ number_format($prospecting['booked']) }}</dd>
                <dt class="col-6 col-md-4 text-label">Failed sends</dt>
                <dd class="col-6 col-md-8" data-role="prospecting-failures">{{ number_format($prospecting['failures']) }}</dd>
            </dl>
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
    @include('customer.dashboard.band-failed', ['band' => 'account', 'title' => 'Account billing'])
@elseif($dashboard->has(DashboardSnapshot::BAND_ACCOUNT))
    @php $account = $dashboard->band(DashboardSnapshot::BAND_ACCOUNT); @endphp
    <section class="mb-2" aria-labelledby="dashboard-account-heading" data-band="account">
        <x-card>
            <h2 class="h4 text-section-heading mb-1" id="dashboard-account-heading">Account billing</h2>
            <p class="mb-1" data-role="account-paused">{{ $account['sentence'] }}</p>
            @if($account['manageUrl'])
                <x-button variant="outline" size="sm" :href="$account['manageUrl']">Open account settings</x-button>
            @endif
        </x-card>
    </section>
@endif

@if($dashboard->has(DashboardSnapshot::BAND_TEAM_ACCOUNT))
    @include('customer.dashboard.team-account', ['parent' => $dashboard->band(DashboardSnapshot::BAND_TEAM_ACCOUNT)])
@endif
