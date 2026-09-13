@extends('layouts/contentLayoutMaster')

@section('title', 'Results')

@section('vendor-style')
    <link rel="stylesheet" href="{{ asset(mix('vendors/css/charts/apexcharts.css')) }}">
@endsection

@php
    use App\Enums\Automation\AutomationTriggerType;

    $m = $analytics->messages;
    $c = $analytics->contacts;
    $a = $analytics->automations;
    $n = static fn (int $value): string => number_format($value);
    $seriesUrl = route('customer.workspaces.businesses.analytics.series', array_merge([$workspaceUid, $businessUid], $range->queryParameters()));
    $overviewUrl = route('customer.workspaces.businesses.analytics.overview', [$workspaceUid, $businessUid]);
    $campaignsUrl = route('customer.workspaces.businesses.analytics.campaigns', array_merge([$workspaceUid, $businessUid], $range->queryParameters()));
    $resolvedCustomerContext = request()->attributes->get('customerContext');
    $accountNoun = $resolvedCustomerContext instanceof \App\Library\Navigation\CustomerContext ? $resolvedCustomerContext->accountNoun() : 'account';

    // Composed by the controller from Slice 2B's read seam, beside B5.
    $conversationsStarted = (int) $conversationsStarted;

    // Section visibility — a section renders only when it has something to
    // say for this period. Figures are never hidden to flatter the page:
    // the overview always shows its three figures, zeros included.
    $hasActivity = $c->newInRange > 0 || $m->inbound > 0 || $m->outbound > 0 || $m->api > 0 || $conversationsStarted > 0;
    $hasAutomationRuns = $a !== null && $a->executionsInRange > 0;
    $hasContacts = $c->totalNow > 0;
    $hasCampaigns = $analytics->campaigns->totalNow() > 0;
    $triggerLabel = static fn (string $trigger): string => AutomationTriggerType::tryFrom($trigger)?->label() ?? 'Other trigger';
@endphp

@section('content')
    {{-- The shared title bar is switched off here (the controller passes
         pageHeader => false) so its <h2> never precedes this page's <h1>.
         Both banners it normally carries are therefore rendered first; each
         renders nothing unless its session is active. --}}
    @include('auth.loggedAs')
    <x-view-as-banner />

    {{-- Everything in this section follows the chosen range, so the range
         control updates THIS section in place (window.AsyncRegion) and the
         address bar keeps the range. Without JavaScript it is an ordinary GET
         to the same URL. --}}
    <section id="business-results" aria-labelledby="results-heading" data-async-region="results" data-series-url="{{ $seriesUrl }}">
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
                    <x-alert variant="danger" icon="alert-circle" class="mb-2" data-role="validation-summary">
                        <ul class="mb-0 ps-3">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </x-alert>
                @endif
            </div>

            <div class="col-12">
                <x-card>
                    <h1 id="results-heading" class="h3 mb-0">Results</h1>
                    <p class="text-caption text-muted mb-2">{{ $analytics->business['name'] }}</p>
                    @include('customer.business.analytics._range', ['range' => $range, 'formAction' => $overviewUrl, 'businessName' => $analytics->business['name'], 'asyncRegion' => 'results'])
                </x-card>
            </div>

            @if ($analytics->coverage->shouldRender())
                <div class="col-12">
                    <x-alert variant="warning" icon="alert-triangle" class="mb-2" data-role="coverage-notice">
                        {{ $n($analytics->coverage->unattributedMessages) }} message record{{ $analytics->coverage->unattributedMessages === 1 ? '' : 's' }}
                        and {{ $n($analytics->coverage->unattributedContacts) }} contact record{{ $analytics->coverage->unattributedContacts === 1 ? '' : 's' }}
                        from before this account used Businesses could not be attributed to a Business and are not included in these figures.
                    </x-alert>
                </div>
            @endif
        </div>

        {{-- OVERVIEW — the local-Business outcomes this product can measure
             today: people added, conversations, and people writing in.
             Outgoing message volume is deliberately not here; it is
             operational health, shown under Messages, and a higher count is
             not a better result. --}}
        <section aria-labelledby="results-overview-heading" data-role="results-overview">
            <h2 id="results-overview-heading" class="text-section-heading mb-1">Overview</h2>

            <div class="row" data-role="stat-cards">
                <div class="col-md-4 col-sm-6 mb-2">
                    <x-card data-role="kpi-new-contacts">
                        <p class="text-label mb-1">New contacts</p>
                        <p class="h2 mb-0" data-role="kpi-new-contacts-value">{{ $n($c->newInRange) }}</p>
                        <p class="text-caption text-muted mb-0">Contacts added during this period.</p>
                    </x-card>
                </div>
                <div class="col-md-4 col-sm-6 mb-2">
                    <x-card data-role="kpi-new-conversations">
                        <p class="text-label mb-1">New conversations</p>
                        <p class="h2 mb-0" data-role="kpi-new-conversations-value">{{ $n($conversationsStarted) }}</p>
                        <p class="text-caption text-muted mb-0">Conversations started during this period.</p>
                    </x-card>
                </div>
                <div class="col-md-4 col-sm-6 mb-2">
                    <x-card data-role="kpi-messages-received">
                        <p class="text-label mb-1">Messages received</p>
                        <p class="h2 mb-0" data-role="kpi-messages-received-value">{{ $n($m->inbound) }}</p>
                        <p class="text-caption text-muted mb-0">Messages people sent to you.</p>
                    </x-card>
                </div>
            </div>

            @if ($hasActivity)
                <div class="row">
                    <div class="col-12 mb-2">
                        <x-card title="New contacts">
                            <p class="text-caption text-muted mb-1">Contacts added during this period.</p>
                            <div id="analytics-new-contacts" data-role="chart-contact-growth" role="img" aria-label="Chart of new contacts added during this period"></div>
                        </x-card>
                    </div>
                </div>
            @else
                <x-card class="mb-2">
                    <x-empty-state icon="bar-chart-2" title="Nothing to show for this period yet"
                                   description="New contacts, conversations and messages will appear here as they happen. Try a longer date range to see earlier activity." />
                </x-card>
            @endif
        </section>

        {{-- MESSAGES — moved to Settings -> Text messaging -> Delivery & usage
             (owner product decision: Results is for Business outcomes, not
             telecom plumbing). "Messages received" above stays here as a
             genuine outcome signal; the sent/failed/processing breakdown and
             the message-volume chart live only on the Delivery & usage page
             now. --}}

        {{-- AUTOMATIONS — only when automations actually ran in this period.
             Human outcome labels over the B4 ledger; trigger names come from
             AutomationTriggerType::label(), never the stored value. --}}
        @if ($hasAutomationRuns)
            <section aria-labelledby="results-automations-heading" class="mt-1">
                <h2 id="results-automations-heading" class="text-section-heading mb-1">Automations</h2>
                <x-card data-role="automations-panel">
                    <div class="row text-center mb-1">
                        <div class="col-4">
                            <p class="text-label mb-0">Runs</p>
                            <p class="h4 mb-0" data-role="automation-runs">{{ $n($a->executionsInRange) }}</p>
                        </div>
                        <div class="col-4">
                            <p class="text-label mb-0">Completed</p>
                            <p class="h4 mb-0" data-role="automation-completed">{{ $n($a->succeeded()) }}</p>
                        </div>
                        <div class="col-4">
                            <p class="text-label mb-0">Failed</p>
                            <p class="h4 mb-0" data-role="automation-failed">{{ $n($a->failed()) }}</p>
                        </div>
                    </div>
                    @if ($a->skipped() > 0 || $a->pending() > 0)
                        <p class="text-caption text-muted mb-1">
                            @if ($a->skipped() > 0){{ $n($a->skipped()) }} skipped on purpose, not a failure.@endif
                            @if ($a->pending() > 0){{ $n($a->pending()) }} waiting to run.@endif
                        </p>
                    @endif
                    @if ($a->byTrigger !== [])
                        <p class="text-caption text-muted mb-0" data-role="automation-triggers">
                            Started by:
                            {{ collect($a->byTrigger)->map(fn (int $count, string $trigger) => $triggerLabel($trigger) . ' (' . $n($count) . ')')->implode(' · ') }}
                        </p>
                    @endif
                </x-card>
            </section>
        @endif

        {{-- Supporting detail — each card only when it has data. --}}
        @if ($hasContacts || $hasCampaigns)
            <div class="row mt-1">
                @if ($hasContacts)
                    <div class="col-lg-6 mb-2">
                        <x-card title="Contacts" data-role="contacts-panel">
                            <dl class="row mb-0">
                                <dt class="col-8 fw-normal">All contacts</dt>
                                <dd class="col-4 text-end text-numeric mb-1">{{ $n($c->totalNow) }}</dd>
                                <dt class="col-8 fw-normal">Subscribed</dt>
                                <dd class="col-4 text-end text-numeric mb-1">{{ $n($c->subscribedNow) }}</dd>
                                <dt class="col-8 fw-normal">Unsubscribed</dt>
                                <dd class="col-4 text-end text-numeric mb-1">{{ $n($c->unsubscribedNow) }}</dd>
                                <dt class="col-8 fw-normal">Contact groups</dt>
                                <dd class="col-4 text-end text-numeric mb-0">{{ $n($c->groupCount) }}</dd>
                            </dl>
                            <p class="text-caption text-muted mb-0 mt-1">As of now, not only this period.</p>
                        </x-card>
                    </div>
                @endif

                {{-- Campaigns are supported in Messages → Campaigns; here they are
                     supporting detail only, never a headline figure. --}}
                @if ($hasCampaigns)
                    <div class="col-lg-6 mb-2">
                        <x-card title="Campaigns" data-role="campaigns-panel">
                            <x-slot:actions>
                                <x-button variant="outline" size="sm" icon="list" :href="$campaignsUrl">Campaign performance</x-button>
                            </x-slot:actions>
                            <x-table :headers="['Status', 'Campaigns']">
                                @foreach ($analytics->campaigns->statusSnapshot as $status => $count)
                                    <tr>
                                        <td><x-badge variant="neutral">{{ ucfirst($status) }}</x-badge></td>
                                        <td class="text-numeric">{{ $n($count) }}</td>
                                    </tr>
                                @endforeach
                            </x-table>
                            <p class="text-caption text-muted mb-0 mt-1">All campaigns as of now, not only this period.</p>
                        </x-card>
                    </div>
                @endif
            </div>
        @endif

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
                                        {{ \Carbon\CarbonImmutable::parse($analytics->advisor->lastSuccessfulRunAt)->setTimezone($analytics->business['timezone'])->format('M j, Y g:i A') }}
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

        {{-- Reserved: an Email section (Sent, Delivered, Opened, Clicked,
             Unsubscribed, Failed) belongs here once a canonical Business →
             contact email transport and event domain exists. Nothing is
             rendered for it until then. See
             docs/automation/RESULTS-CUSTOMER-EXPERIENCE-REDESIGN.md. --}}

        <div class="row">
            <div class="col-12 mb-2">
                <x-card title="Related">
                    <div class="d-flex flex-wrap gap-2">
                        <x-button variant="outline" size="sm" icon="credit-card" :href="route('customer.workspaces.businesses.usage-billing.show', [$workspaceUid, $businessUid])">Billing</x-button>
                        <x-button variant="outline" size="sm" icon="message-square" :href="route('customer.workspaces.businesses.conversations.index', [$workspaceUid, $businessUid])">Conversations</x-button>
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
            var charts = [];

            // The server groups the days (by day, week or month) and supplies
            // short axis labels plus the exact dates for each point. The axis
            // never carries a full date; the tooltip always does.
            //
            // Labels are thinned, never truncated or overlapped. Trim would
            // clip every label to one category's width ("Aug…"), so it is
            // off; instead the number of labels is set from the chart's own
            // width — about one per LABEL_SPACING_PX — so a phone shows three
            // readable dates and a desktop a dozen. Lines are straight: a
            // smoothed curve between two daily counts implies values that
            // were never measured.
            var LABEL_SPACING_PX = 110;

            function chartOptions(type, chart, series, colors, width) {
                var fitting = Math.max(2, Math.floor(width / LABEL_SPACING_PX));
                return {
                    chart: { type: type, height: 260, toolbar: { show: false }, zoom: { enabled: false }, fontFamily: 'inherit' },
                    colors: colors,
                    series: series,
                    grid: { borderColor: theme.chartGrid() },
                    xaxis: {
                        type: 'category',
                        categories: chart.labels,
                        tickAmount: chart.labels.length > fitting ? fitting : undefined,
                        labels: { style: { colors: theme.chartAxis() }, rotate: 0, rotateAlways: false, hideOverlappingLabels: true, trim: false },
                        tooltip: { enabled: false }
                    },
                    yaxis: { labels: { style: { colors: theme.chartAxis() } }, min: 0, forceNiceScale: true },
                    tooltip: {
                        theme: 'dark',
                        style: { fontSize: '12px' },
                        x: { formatter: function (value, opts) { return chart.tooltips[opts.dataPointIndex] || value; } }
                    },
                    dataLabels: { enabled: false },
                    stroke: { curve: 'straight', width: 2 },
                    fill: { opacity: type === 'area' ? 0.2 : 1 },
                    legend: { labels: { colors: theme.chartAxis() } }
                };
            }

            function mount(region, selector, build) {
                var el = region.querySelector(selector);
                if (el) {
                    var chart = new ApexCharts(el, build(el.clientWidth));
                    charts.push(chart);
                    chart.render();
                }
            }

            function render(region, series) {
                var contacts = series.new_contacts;
                mount(region, '[data-role="chart-contact-growth"]', function (width) {
                    return chartOptions('area', contacts, [{ name: 'New contacts', data: contacts.series.new_contacts }], [palette[0]], width);
                });

                // The message-volume chart moved to Settings -> Text
                // messaging -> Delivery & usage (Results cleanup); this
                // page's markup never carries that chart's container element
                // any more, so it is not mounted here. The /series JSON
                // endpoint still returns charts.messages unchanged — other
                // consumers (and the payload-shape guard below) depend on it.
            }

            // Mounted on load, and again whenever the range updates this page
            // in place — always from the series URL the CURRENT region carries,
            // so the charts can never show a different period from the figures.
            function mountCharts(region) {
                charts.forEach(function (chart) { chart.destroy(); });
                charts = [];

                if (!region || !region.dataset.seriesUrl) { return; }

                fetch(region.dataset.seriesUrl, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
                    .then(function (response) { return response.ok ? response.json() : Promise.reject(response.status); })
                    .then(function (payload) {
                        // Only a genuine series payload is charted; any error
                        // envelope (including a throttled request) is not.
                        if (!payload || !payload.charts || !payload.charts.new_contacts || !payload.charts.messages) { return Promise.reject('shape'); }
                        // Swapped again while this request was out: leave it.
                        if (!document.body.contains(region)) { return; }
                        return render(region, payload.charts);
                    })
                    .catch(function () {
                        region.querySelectorAll('[data-role^="chart-"]').forEach(function (el) {
                            el.innerHTML = '<p class="text-caption mb-0">Chart data is unavailable right now.</p>';
                        });
                    });
            }

            mountCharts(document.querySelector('[data-async-region="results"]'));

            document.addEventListener('async-region:updated', function (event) {
                if (event.detail && event.detail.name === 'results') {
                    mountCharts(event.target);
                }
            });
        })();
    </script>
@endsection
