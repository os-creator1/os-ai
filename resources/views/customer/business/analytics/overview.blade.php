@extends('layouts/contentLayoutMaster')

@section('title', 'Analytics')

@section('vendor-style')
    <link rel="stylesheet" href="{{ asset(mix('vendors/css/charts/apexcharts.css')) }}">
@endsection

@php
    $m = $analytics->messages;
    $c = $analytics->contacts;
    $pct = static fn (?float $value): string => $value === null ? '—' : number_format($value, 1) . '%';
    $n = static fn (int $value): string => number_format($value);
    $seriesUrl = route('customer.workspaces.businesses.analytics.series', array_merge([$workspaceUid, $businessUid], $range->queryParameters()));
    $overviewUrl = route('customer.workspaces.businesses.analytics.overview', [$workspaceUid, $businessUid]);
    $campaignsUrl = route('customer.workspaces.businesses.analytics.campaigns', array_merge([$workspaceUid, $businessUid], $range->queryParameters()));
    $resolvedCustomerContext = request()->attributes->get('customerContext');
    $accountNoun = $resolvedCustomerContext instanceof \App\Library\Navigation\CustomerContext ? $resolvedCustomerContext->accountNoun() : 'account';
@endphp

@section('content')
    <section id="business-analytics">
        <div class="row">
            <div class="col-12">
                <a href="{{ route('customer.workspaces.show', $workspaceUid) }}" class="d-inline-flex align-items-center gap-1 transition-fast text-label mb-2">
                    <x-ds-icon name="arrow-left" size="16" />
                    Back to {{ ucfirst($accountNoun) }}
                </a>
            </div>

            <div class="col-12">
                {{-- Flash left by the legacy campaign actions, which redirect here now that the customer Reports product is gone. --}}
                @if (session('status'))
                    <x-alert variant="{{ match (session('status')) { 'success' => 'success', 'warning' => 'warning', 'info' => 'accent', default => 'danger' } }}" icon="alert-circle" class="mb-2" data-role="flash-message">
                        {{ session('message') }}
                    </x-alert>
                @endif
                @if ($errors->any())
                    <x-alert variant="danger" icon="alert-circle" class="mb-2">
                        <ul class="mb-0 ps-3">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </x-alert>
                @endif
            </div>

            {{-- 1. Header: Business identity, the grouping timezone, the range control --}}
            <div class="col-12">
                <x-card :title="$analytics->business['name'] . ' — Analytics'">
                    <p class="text-caption mb-2">
                        Figures are grouped by calendar day in this Business's timezone,
                        <strong>{{ $analytics->business['timezone'] }}</strong>.
                    </p>
                    @include('customer.business.analytics._range', ['range' => $range, 'formAction' => $overviewUrl])
                </x-card>
            </div>

            {{-- 3. Coverage notice (§3.2) --}}
            @if ($analytics->coverage->shouldRender())
                <div class="col-12">
                    <x-alert variant="warning" icon="alert-triangle" class="mb-2" data-role="coverage-notice">
                        {{ $n($analytics->coverage->unattributedMessages) }} message record{{ $analytics->coverage->unattributedMessages === 1 ? '' : 's' }}
                        and {{ $n($analytics->coverage->unattributedContacts) }} contact record{{ $analytics->coverage->unattributedContacts === 1 ? '' : 's' }}
                        created before Business tenancy could not be attributed to a Business and are not included in these figures.
                    </x-alert>
                </div>
            @endif
        </div>

        {{-- 4. Stat cards --}}
        <div class="row" data-role="stat-cards">
            <div class="col-xl-3 col-md-4 col-sm-6 mb-2">
                <x-card>
                    <p class="text-label mb-1">Total contacts <span class="text-caption">(as of now)</span></p>
                    <h3 class="mb-0 text-numeric">{{ $n($c->totalNow) }}</h3>
                </x-card>
            </div>
            <div class="col-xl-3 col-md-4 col-sm-6 mb-2">
                <x-card>
                    <p class="text-label mb-1">New contacts</p>
                    <h3 class="mb-0 text-numeric">{{ $n($c->newInRange) }}</h3>
                </x-card>
            </div>
            <div class="col-xl-3 col-md-4 col-sm-6 mb-2">
                <x-card>
                    <p class="text-label mb-1">Outbound messages</p>
                    <h3 class="mb-0 text-numeric">{{ $n($m->outbound) }}</h3>
                    <p class="text-caption mb-0">API messages, shown separately: {{ $n($m->api) }}</p>
                </x-card>
            </div>
            <div class="col-xl-3 col-md-4 col-sm-6 mb-2">
                <x-card>
                    <p class="text-label mb-1">Inbound messages</p>
                    <h3 class="mb-0 text-numeric">{{ $n($m->inbound) }}</h3>
                </x-card>
            </div>
            <div class="col-xl-3 col-md-4 col-sm-6 mb-2">
                <x-card>
                    <p class="text-label mb-1">Provider-accepted rate</p>
                    <h3 class="mb-0 text-numeric">{{ $pct($m->acceptedRate()) }}</h3>
                    <p class="text-caption mb-0">Accepted by the provider at send time, not handset delivery.</p>
                </x-card>
            </div>
            <div class="col-xl-3 col-md-4 col-sm-6 mb-2">
                <x-card>
                    <p class="text-label mb-1">Campaigns created</p>
                    <h3 class="mb-0 text-numeric">{{ $n($analytics->campaigns->createdInRange) }}</h3>
                </x-card>
            </div>
            @if ($analytics->advisor !== null)
                <div class="col-xl-3 col-md-4 col-sm-6 mb-2">
                    <x-card>
                        <p class="text-label mb-1">Open recommendations</p>
                        <h3 class="mb-0 text-numeric">{{ $n($analytics->advisor->openCurrent) }}</h3>
                        <p class="text-caption mb-0">AI Business Advisor recommendations that are open and current.</p>
                    </x-card>
                </div>
            @endif
        </div>

        {{-- 5. Charts --}}
        <div class="row">
            <div class="col-lg-6 mb-2">
                <x-card title="Contact growth">
                    <p class="text-caption mb-1">New contacts per day.</p>
                    <div id="analytics-contact-growth" data-role="chart-contact-growth"></div>
                </x-card>
            </div>
            <div class="col-lg-6 mb-2">
                <x-card title="Message volume by direction">
                    <p class="text-caption mb-1">Outbound, inbound and API messages per day.</p>
                    <div id="analytics-message-volume" data-role="chart-message-volume"></div>
                </x-card>
            </div>
        </div>

        {{-- 6. Message outcome breakdown (M4 / M5 / M6) --}}
        <div class="row">
            <div class="col-lg-6 mb-2">
                <x-card title="Outbound message outcomes" data-role="outcome-breakdown">
                    <x-table :headers="['Outcome', 'Messages', 'Share of outbound']">
                        <tr>
                            <td><x-badge variant="success">Provider accepted</x-badge></td>
                            <td class="text-numeric">{{ $n($m->accepted) }}</td>
                            <td class="text-numeric">{{ $pct($m->acceptedRate()) }}</td>
                        </tr>
                        <tr>
                            <td><x-badge variant="danger">Confirmed failed</x-badge></td>
                            <td class="text-numeric">{{ $n($m->confirmedFailed) }}</td>
                            <td class="text-numeric">{{ $pct($m->confirmedFailedRate()) }}</td>
                        </tr>
                        <tr>
                            <td><x-badge variant="warning">Unresolved / in flight</x-badge></td>
                            <td class="text-numeric">{{ $n($m->unresolved()) }}</td>
                            <td class="text-numeric">{{ $pct($m->unresolvedRate()) }}</td>
                        </tr>
                        <tr>
                            <td class="text-label">Outbound attempted</td>
                            <td class="text-numeric"><strong>{{ $n($m->outbound) }}</strong></td>
                            <td class="text-numeric">100%</td>
                        </tr>
                    </x-table>
                    <p class="text-caption mb-0 mt-1">
                        "Provider accepted" means the messaging provider accepted the message at send time; it is not handset delivery.
                        "Confirmed failed" covers Undelivered, Expired, Rejected, Failed and Skipped, where Skipped means the send was skipped, not that it failed downstream.
                        Everything else — in flight, provider-specific or unresolved statuses — is counted as unresolved.
                    </p>
                </x-card>
            </div>

            {{-- 7. Campaigns: C1/C2 summary + link to the paginated performance table --}}
            <div class="col-lg-6 mb-2">
                <x-card title="Campaigns">
                    <x-slot:actions>
                        <x-button variant="outline" size="sm" icon="list" :href="$campaignsUrl">Campaign performance</x-button>
                    </x-slot:actions>
                    <p class="text-caption mb-1">Created in the selected range: <strong class="text-numeric">{{ $n($analytics->campaigns->createdInRange) }}</strong></p>
                    <p class="text-label mb-1">Status distribution <span class="text-caption">(current snapshot of all {{ $n($analytics->campaigns->totalNow()) }} campaigns, not the selected period)</span></p>
                    @if ($analytics->campaigns->totalNow() === 0)
                        <x-empty-state icon="send" title="No campaigns yet" description="Campaigns sent from this Business will be summarized here." />
                    @else
                        <x-table :headers="['Status', 'Campaigns']">
                            @foreach ($analytics->campaigns->statusSnapshot as $status => $count)
                                <tr>
                                    <td><x-badge variant="neutral">{{ ucfirst($status) }}</x-badge></td>
                                    <td class="text-numeric">{{ $n($count) }}</td>
                                </tr>
                            @endforeach
                        </x-table>
                    @endif
                </x-card>
            </div>
        </div>

        <div class="row">
            {{-- Contacts snapshot (K4, K5) --}}
            <div class="col-lg-6 mb-2">
                <x-card title="Contacts">
                    <x-table :headers="['Measure', 'Value']">
                        <tr><td>Subscribed <span class="text-caption">(as of now)</span></td><td class="text-numeric">{{ $n($c->subscribedNow) }}</td></tr>
                        <tr><td>Unsubscribed <span class="text-caption">(as of now)</span></td><td class="text-numeric">{{ $n($c->unsubscribedNow) }}</td></tr>
                        <tr><td>Contact groups</td><td class="text-numeric">{{ $n($c->groupCount) }}</td></tr>
                    </x-table>
                </x-card>
            </div>

            {{-- 8. Automations panel (A1–A4), only when the ledger exists --}}
            @if ($analytics->automations !== null)
                @php $a = $analytics->automations; @endphp
                <div class="col-lg-6 mb-2">
                    <x-card title="Automations" data-role="automations-panel">
                        <p class="text-caption mb-1">Executions in the selected range: <strong class="text-numeric">{{ $n($a->executionsInRange) }}</strong></p>
                        <p class="text-label mb-1">Success rate <span class="text-caption">(succeeded ÷ succeeded + failed; pending and skipped are excluded)</span></p>
                        <h3 class="text-numeric">{{ $pct($a->successRate()) }}</h3>
                        <x-table :headers="['Status', 'Executions']">
                            @foreach (['succeeded' => 'success', 'failed' => 'danger', 'skipped' => 'warning', 'pending' => 'accent'] as $status => $variant)
                                <tr>
                                    <td><x-badge :variant="$variant">{{ ucfirst($status) }}</x-badge></td>
                                    <td class="text-numeric">{{ $n($a->byStatus[$status] ?? 0) }}</td>
                                </tr>
                            @endforeach
                        </x-table>
                        <p class="text-label mb-1 mt-2">By trigger</p>
                        @if ($a->byTrigger === [])
                            <p class="text-caption mb-0">No automation executions in this range.</p>
                        @else
                            <x-table :headers="['Trigger', 'Executions']">
                                @foreach ($a->byTrigger as $trigger => $count)
                                    <tr>
                                        <td>{{ ucwords(str_replace('_', ' ', $trigger)) }}</td>
                                        <td class="text-numeric">{{ $n($count) }}</td>
                                    </tr>
                                @endforeach
                            </x-table>
                        @endif
                    </x-card>
                </div>
            @endif
        </div>

        @if ($analytics->advisor !== null)
            <div class="row">
                <div class="col-lg-6 mb-2">
                    <x-card title="AI Business Advisor recommendations" data-role="advisor-panel">
                        <x-table :headers="['Measure', 'Value']">
                            <tr><td>Open recommendations <span class="text-caption">(as of now)</span></td><td class="text-numeric">{{ $n($analytics->advisor->openCurrent) }}</td></tr>
                            <tr><td>Completed in range</td><td class="text-numeric">{{ $n($analytics->advisor->completedInRange) }}</td></tr>
                            <tr><td>Dismissed in range</td><td class="text-numeric">{{ $n($analytics->advisor->dismissedInRange) }}</td></tr>
                            <tr>
                                <td>Last successful advisor run</td>
                                <td>
                                    @if ($analytics->advisor->lastSuccessfulRunAt)
                                        {{ \Carbon\CarbonImmutable::parse($analytics->advisor->lastSuccessfulRunAt)->setTimezone($analytics->business['timezone'])->format('Y-m-d H:i') }}
                                    @else
                                        <span class="text-caption">Never</span>
                                    @endif
                                </td>
                            </tr>
                        </x-table>
                        <p class="text-caption mb-0 mt-1">These are AI Business Advisor recommendations about the Business profile and presence; they carry no monetary value.</p>
                    </x-card>
                </div>
            </div>
        @endif

        {{-- 9. Cross-links, not duplicates --}}
        <div class="row">
            <div class="col-12 mb-2">
                <x-card title="Related">
                    <div class="d-flex flex-wrap gap-2">
                        <x-button variant="outline" size="sm" icon="credit-card" :href="route('customer.workspaces.businesses.usage-billing.show', [$workspaceUid, $businessUid])">Usage &amp; Billing</x-button>
                        <x-button variant="outline" size="sm" icon="message-square" :href="route('customer.chatbox.index')">Conversations</x-button>
                    </div>
                </x-card>
            </div>
        </div>
    </section>
@endsection

@section('vendor-script')
    <script src="{{ asset(mix('vendors/js/charts/apexcharts.min.js')) }}"></script>
@endsection

@section('page-script')
    <script>
        (function () {
            // Colours and grid come only from the shared token namespace
            // (window.PlatformTheme, resources/js/core/theme-tokens.js).
            var theme = window.PlatformTheme;
            var palette = theme.chartPalette();
            var seriesUrl = @json($seriesUrl);

            function baseOptions(type, height) {
                return {
                    chart: { type: type, height: height, toolbar: { show: false }, fontFamily: 'inherit' },
                    grid: { borderColor: theme.chartGrid() },
                    xaxis: { type: 'category', labels: { style: { colors: theme.chartAxis() }, rotate: -45, hideOverlappingLabels: true } },
                    yaxis: { labels: { style: { colors: theme.chartAxis() } }, min: 0, forceNiceScale: true },
                    tooltip: { theme: 'dark', style: { fontSize: '12px' } },
                    dataLabels: { enabled: false },
                    legend: { labels: { colors: theme.chartAxis() } }
                };
            }

            function render(payload) {
                var growth = Object.assign(baseOptions('area', 260), {
                    colors: [palette[0]],
                    series: [{ name: 'New contacts', data: payload.contact_growth.series.new_contacts }],
                    stroke: { curve: 'smooth', width: 2 },
                    fill: { opacity: 0.2 }
                });
                growth.xaxis.categories = payload.contact_growth.dates;
                new ApexCharts(document.querySelector('[data-role="chart-contact-growth"]'), growth).render();

                var volume = Object.assign(baseOptions('bar', 260), {
                    colors: [palette[0], palette[1], theme.chartNeutral()],
                    series: [
                        { name: 'Outbound', data: payload.message_volume.series.outgoing },
                        { name: 'Inbound', data: payload.message_volume.series.incoming },
                        { name: 'API', data: payload.message_volume.series.api }
                    ],
                    plotOptions: { bar: { columnWidth: '60%' } }
                });
                volume.chart.stacked = true;
                volume.xaxis.categories = payload.message_volume.dates;
                new ApexCharts(document.querySelector('[data-role="chart-message-volume"]'), volume).render();
            }

            fetch(seriesUrl, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
                .then(function (response) { return response.ok ? response.json() : Promise.reject(response.status); })
                .then(function (payload) {
                    // Only a genuine series payload is charted; any error
                    // envelope (including a throttled request) is not.
                    if (!payload || !payload.contact_growth || !payload.message_volume) { return Promise.reject('shape'); }
                    return render(payload);
                })
                .catch(function () {
                    document.querySelectorAll('[data-role^="chart-"]').forEach(function (el) {
                        el.innerHTML = '<p class="text-caption mb-0">Chart data is unavailable right now.</p>';
                    });
                });
        })();
    </script>
@endsection
