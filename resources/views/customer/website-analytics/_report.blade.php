{{--
    The ONE analytics report body, shared by Agency → White Label → Analytics and Growth → Website → Analytics.
    Expects: $report (AnalyticsReport::build() or null), $mode ('agency'|'growth'), $filterAction, and
      agency: $campaignOptions, $landingLabel, $landingUrl
      growth: $hasForm, $hasBooking
    Every number below comes from the same filtered event set, so funnel, chart, pages, sources and activity always agree.
--}}
@php
    $t = $report['totals'] ?? [];
    $query = fn (array $over = []) => '?' . http_build_query(array_filter(array_merge(['range' => $report['range'] ?? null, 'landing' => $report['landing'] ?? null, 'campaign' => $report['campaign'] ?? null], $over), fn ($v) => $v !== null && $v !== ''));
    $fmt = fn ($n) => number_format((int) $n);
    $rate = fn ($v) => $v === null ? '—' : rtrim(rtrim(number_format($v, 1), '0'), '.') . '%';
    $isAgency = $mode === 'agency';
    $showCalendar = $isAgency || ! empty($hasBooking);
    $showForm = ! $isAgency && ! empty($hasForm);
    $peak = $report ? max(1, max(array_column($report['series'], 'visits') ?: [0])) : 1;
@endphp

<style>
    .wa { --wa-ink: var(--bs-body-color, #1f2937); --wa-muted: #6b7280; --wa-line: rgba(100,116,139,.22); --wa-bar: var(--bs-primary, #4f46e5); --wa-good: #16a34a; }
    .wa-card { background: var(--bs-card-bg, #fff); border: 1px solid var(--wa-line); border-radius: 12px; padding: 16px; margin-bottom: 16px; }
    .wa-card h3 { font-size: 1rem; margin: 0 0 12px; }
    .wa-head { display: flex; flex-wrap: wrap; gap: 12px; align-items: center; justify-content: space-between; margin-bottom: 16px; }
    .wa-ranges { display: inline-flex; flex-wrap: wrap; gap: 6px; }
    .wa-ranges a { padding: 6px 12px; border: 1px solid var(--wa-line); border-radius: 999px; text-decoration: none; color: var(--wa-ink); font-size: .875rem; }
    .wa-ranges a.is-active { background: var(--wa-bar); border-color: var(--wa-bar); color: #fff; }
    .wa-filters { display: flex; flex-wrap: wrap; gap: 12px; align-items: end; margin-bottom: 16px; }
    .wa-filters label { display: block; font-size: .75rem; color: var(--wa-muted); margin-bottom: 4px; }
    .wa-filters select { min-width: 200px; max-width: 100%; }
    .wa-notice { border-left: 4px solid #f59e0b; background: rgba(245,158,11,.1); padding: 10px 14px; border-radius: 6px; margin-bottom: 16px; font-size: .9rem; }
    .wa-kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px; margin-bottom: 16px; }
    .wa-kpi { border: 1px solid var(--wa-line); border-radius: 12px; padding: 14px; background: var(--bs-card-bg, #fff); }
    .wa-kpi .v { font-size: 1.6rem; font-weight: 700; line-height: 1.1; }
    .wa-kpi .l { font-size: .8rem; color: var(--wa-muted); margin-top: 4px; }
    .wa-kpi.is-goal .v { color: var(--wa-good); }
    .wa-funnel { display: grid; gap: 10px; }
    .wa-step { display: grid; grid-template-columns: 150px 1fr 64px; gap: 10px; align-items: center; font-size: .9rem; }
    .wa-track { background: rgba(100,116,139,.12); border-radius: 6px; height: 22px; overflow: hidden; }
    .wa-fill { background: var(--wa-bar); height: 100%; border-radius: 6px; min-width: 2px; }
    .wa-step:last-child .wa-fill { background: var(--wa-good); }
    .wa-step .n { text-align: right; font-weight: 600; }
    .wa-chart { display: flex; align-items: flex-end; gap: 2px; height: 140px; padding-top: 8px; border-bottom: 1px solid var(--wa-line); }
    .wa-bar { flex: 1 1 0; min-width: 2px; background: var(--wa-bar); opacity: .85; border-radius: 3px 3px 0 0; position: relative; }
    .wa-bar.has-outcome { background: var(--wa-good); opacity: 1; }
    .wa-bar.is-empty { background: rgba(100,116,139,.25); min-height: 2px; }
    .wa-bar.is-unmeasured { height: 100%; background: repeating-linear-gradient(45deg, rgba(100,116,139,.18) 0 3px, transparent 3px 6px); opacity: 1; border-radius: 0; }
    .wa-axis { display: flex; justify-content: space-between; font-size: .75rem; color: var(--wa-muted); margin-top: 4px; }
    .wa-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
    .wa table { width: 100%; border-collapse: collapse; font-size: .875rem; }
    .wa th { text-align: left; color: var(--wa-muted); font-weight: 600; font-size: .75rem; padding: 6px 8px; border-bottom: 1px solid var(--wa-line); }
    .wa td { padding: 8px; border-bottom: 1px solid var(--wa-line); vertical-align: top; word-break: break-word; }
    .wa td.num, .wa th.num { text-align: right; white-space: nowrap; }
    .wa-activity td:first-child { white-space: nowrap; color: var(--wa-muted); }
    .wa-pill { display: inline-block; padding: 1px 8px; border-radius: 999px; font-size: .75rem; background: rgba(79,70,229,.12); }
    .wa-pill.is-goal { background: rgba(22,163,74,.16); color: #166534; }
    .wa-foot { font-size: .8rem; color: var(--wa-muted); }
    .wa-foot li { margin-bottom: 4px; }
    @media (max-width: 767px) {
        .wa-grid { grid-template-columns: 1fr; }
        .wa-step { grid-template-columns: 1fr 56px; }
        .wa-step .lbl { grid-column: 1 / -1; font-weight: 600; }
        .wa-step .wa-track { grid-column: 1; }
        .wa-filters select { width: 100%; min-width: 0; }
        .wa-filters > div { flex: 1 1 100%; }
        .wa-table-scroll { overflow-x: auto; }
        .wa-kpi .v { font-size: 1.35rem; }
    }
</style>

<div class="wa" data-testid="website-analytics" data-mode="{{ $mode }}">
    @if ($report === null)
        <div class="wa-card" data-testid="analytics-not-started">
            <h3>No measurement yet</h3>
            <p class="mb-0">Analytics starts the first time your published @if ($isAgency) landing page @else website @endif is viewed by a visitor. Nothing has been measured so far, so there are no numbers to show — not even zeros.@if ($isAgency) Publish the page on the Landing Page tab, then paste its link into your next SMS campaign.@endif</p>
        </div>
    @else
        <div class="wa-head">
            <div>
                <div class="wa-ranges" role="group" aria-label="Date range" data-testid="analytics-ranges">
                    @foreach (\App\Library\WebsiteAnalytics\AnalyticsReport::RANGES as $key => $label)
                        <a href="{{ $filterAction . $query(['range' => $key]) }}" @class(['is-active' => $report['range'] === (string) $key]) @if ($report['range'] === (string) $key) aria-current="true" @endif data-range="{{ $key }}">{{ $label }}</a>
                    @endforeach
                </div>
            </div>
            <div class="wa-foot" data-testid="analytics-period">{{ $report['from_label'] }}@if ($report['from_label'] !== $report['to_label']) – {{ $report['to_label'] }}@endif · times in {{ str_replace('_', ' ', $report['timezone']) }}</div>
        </div>

        @if ($isAgency)
            <form method="get" action="{{ $filterAction }}" class="wa-filters" data-testid="analytics-filters">
                <input type="hidden" name="range" value="{{ $report['range'] }}">
                <div>
                    <label for="wa-landing">Landing page</label>
                    <select id="wa-landing" name="landing" class="form-select" onchange="this.form.submit()">
                        @forelse ($report['landings'] as $landing)
                            <option value="{{ $landing }}" @selected($report['landing'] === $landing)>{{ $landing }}</option>
                        @empty
                            <option value="">{{ $landingLabel }}</option>
                        @endforelse
                        @if (count($report['landings']) > 1)<option value="" @selected($report['landing'] === null)>All landing pages</option>@endif
                    </select>
                </div>
                <div>
                    <label for="wa-campaign">SMS campaign</label>
                    <select id="wa-campaign" name="campaign" class="form-select" onchange="this.form.submit()">
                        <option value="">All traffic</option>
                        <option value="direct" @selected($report['campaign'] === 'direct')>Direct / unknown only</option>
                        @foreach ($campaignOptions as $option)
                            <option value="{{ $option['token'] }}" @selected($report['campaign'] === $option['token'])>{{ $option['label'] }}</option>
                        @endforeach
                    </select>
                </div>
                <noscript><button class="btn btn-primary" type="submit">Apply</button></noscript>
            </form>
        @endif

        <div class="wa-notice" role="note" data-testid="collection-started">
            Measurement started <strong>{{ $report['collection_started_label'] }}</strong>.
            @if ($report['partially_measured'])
                Earlier dates in this range were <strong>not measured</strong> — they are not zero traffic.
            @endif
        </div>

        <div class="wa-kpis" data-testid="analytics-kpis">
            <div class="wa-kpi" data-metric="visitors"><div class="v">{{ $fmt($t['visitors']) }}</div><div class="l">Estimated visitors</div></div>
            <div class="wa-kpi" data-metric="visits"><div class="v">{{ $fmt($t['visits']) }}</div><div class="l">Visits</div></div>
            @unless ($isAgency)
                <div class="wa-kpi" data-metric="page_views"><div class="v">{{ $fmt($t['page_views']) }}</div><div class="l">Page views</div></div>
            @endunless
            @if ($showCalendar)
                <div class="wa-kpi" data-metric="calendar_opens"><div class="v">{{ $fmt($t['calendar_opens']) }}</div><div class="l">Opened the calendar</div></div>
                <div class="wa-kpi is-goal" data-metric="bookings"><div class="v">{{ $fmt($t['bookings']) }}</div><div class="l">Confirmed bookings</div></div>
            @endif
            @if ($showForm)
                <div class="wa-kpi" data-metric="form_opens"><div class="v">{{ $fmt($t['form_opens']) }}</div><div class="l">Opened the form (Check availability)</div></div>
                <div class="wa-kpi is-goal" data-metric="inquiries"><div class="v">{{ $fmt($t['inquiries']) }}</div><div class="l">Confirmed inquiries</div></div>
            @endif
            @if ($showCalendar)
                <div class="wa-kpi" data-metric="booking_rate"><div class="v">{{ $rate($t['booking_rate']) }}</div><div class="l">Visit-to-booking rate</div></div>
            @elseif ($showForm)
                <div class="wa-kpi" data-metric="inquiry_rate"><div class="v">{{ $rate($t['inquiry_rate']) }}</div><div class="l">Visit-to-inquiry rate</div></div>
            @endif
        </div>

        @if (! $isAgency && ! $showCalendar && ! $showForm)
            <div class="wa-notice" data-testid="no-goal-note">Your published site has no live lead form or booking calendar, so only traffic is measured here. Add a form on the Forms tab to measure inquiries.</div>
        @endif

        @if ($showCalendar || $showForm)
            @php
                $steps = [['Visits', $t['visits']]];
                if ($showCalendar) { $steps[] = ['Opened the calendar', $t['calendar_opens']]; $steps[] = ['Confirmed bookings', $t['bookings']]; }
                if ($showForm) { $steps[] = ['Opened the form', $t['form_opens']]; $steps[] = ['Confirmed inquiries', $t['inquiries']]; }
                $top = max(1, (int) $t['visits']);
            @endphp
            <div class="wa-card" data-testid="analytics-funnel">
                <h3>Funnel</h3>
                <div class="wa-funnel">
                    @foreach ($steps as [$label, $value])
                        <div class="wa-step"><span class="lbl">{{ $label }}</span><span class="wa-track"><span class="wa-fill" style="display:block;width:{{ min(100, round($value / $top * 100, 1)) }}%"></span></span><span class="n">{{ $fmt($value) }}</span></div>
                    @endforeach
                </div>
            </div>
        @endif

        <div class="wa-card" data-testid="analytics-chart">
            <h3>{{ $report['granularity'] === 'hour' ? 'Traffic by hour' : 'Traffic by day' }} <span class="wa-foot">· visits{{ ($showCalendar || $showForm) ? ' (green = a confirmed ' . ($showCalendar ? 'booking' : 'inquiry') . ')' : '' }}@if ($report['partially_measured']) · hatched = not measured yet @endif</span></h3>
            <div class="wa-chart" role="img" aria-label="Visits per {{ $report['granularity'] }}">
                @foreach ($report['series'] as $point)
                    @php($outcome = $point['bookings'] + $point['inquiries'])
                    @if (! $point['measured'])
                        <div class="wa-bar is-unmeasured" title="{{ $point['label'] }}: not measured (collection had not started)" data-measured="0"></div>
                    @else
                        <div @class(['wa-bar', 'is-empty' => $point['visits'] === 0, 'has-outcome' => $outcome > 0]) style="height:{{ $point['visits'] === 0 ? 2 : max(4, round($point['visits'] / $peak * 100)) }}%" title="{{ $point['label'] }}: {{ $point['visits'] }} {{ $point['visits'] === 1 ? 'visit' : 'visits' }}@if ($outcome > 0), {{ $outcome }} confirmed @endif" data-visits="{{ $point['visits'] }}" data-measured="1"></div>
                    @endif
                @endforeach
            </div>
            <div class="wa-axis"><span>{{ $report['series'][0]['label'] ?? '' }}</span><span>{{ $report['series'][count($report['series']) - 1]['label'] ?? '' }}</span></div>
        </div>

        <div class="wa-grid">
            <div class="wa-card" data-testid="analytics-pages">
                <h3>Top pages</h3>
                @if ($report['top_pages'])
                    <div class="wa-table-scroll"><table><thead><tr><th>Page</th><th class="num">Views</th><th class="num">Visitors</th></tr></thead><tbody>
                        @foreach ($report['top_pages'] as $row)<tr><td>{{ $row['path'] }}</td><td class="num">{{ $fmt($row['views']) }}</td><td class="num">{{ $fmt($row['visitors']) }}</td></tr>@endforeach
                    </tbody></table></div>
                @else
                    <p class="wa-foot mb-0">No page views in this range.</p>
                @endif
            </div>
            <div class="wa-card" data-testid="analytics-sources">
                <h3>{{ $isAgency ? 'Sources & SMS campaigns' : 'Sources' }}</h3>
                @if ($report['sources'])
                    <div class="wa-table-scroll"><table><thead><tr><th>Source</th><th class="num">Visits</th>@if ($showCalendar)<th class="num">Calendar</th><th class="num">Booked</th>@endif @if ($showForm)<th class="num">Form</th><th class="num">Inquiries</th>@endif</tr></thead><tbody>
                        @foreach ($report['sources'] as $row)
                            <tr><td>{{ $row['label'] }}</td><td class="num">{{ $fmt($row['visits']) }}</td>@if ($showCalendar)<td class="num">{{ $fmt($row['calendar_opens']) }}</td><td class="num">{{ $fmt($row['bookings']) }}</td>@endif @if ($showForm)<td class="num">{{ $fmt($row['form_opens']) }}</td><td class="num">{{ $fmt($row['inquiries']) }}</td>@endif</tr>
                        @endforeach
                    </tbody></table></div>
                @else
                    <p class="wa-foot mb-0">No visits in this range.</p>
                @endif
            </div>
        </div>

        <div class="wa-card wa-activity" data-testid="analytics-activity">
            <h3>Recent activity</h3>
            @if ($report['recent'])
                <div class="wa-table-scroll"><table><thead><tr><th>When</th><th>What</th><th>Source</th></tr></thead><tbody>
                    @foreach ($report['recent'] as $row)
                        <tr><td>{{ $row['at_label'] }}</td><td><span @class(['wa-pill', 'is-goal' => in_array($row['kind'], ['booking_confirmed', 'form_inquiry'], true)])>{{ $row['what'] }}</span> @if ($row['path'])<span class="wa-foot">{{ $row['path'] }}</span>@endif @if ($row['visitor'])<span class="wa-foot">· visitor …{{ $row['visitor'] }}</span>@endif</td><td>{{ $row['source'] }}</td></tr>
                    @endforeach
                </tbody></table></div>
            @else
                <p class="wa-foot mb-0">Nothing in this range yet.</p>
            @endif
        </div>

        <ul class="wa-foot" data-testid="analytics-notes">
            <li><strong>Estimated visitors</strong> count anonymous browsers, not people: someone on two devices, or after clearing their browser, counts twice. No names, phone numbers or e-mail addresses are ever recorded.</li>
            <li>A <strong>visit</strong> is one stretch of browsing; it ends after 30 minutes idle. People who decline cookies where consent is required, use Do Not Track / Global Privacy Control, or block scripts are not counted. Link previews and bots are ignored.</li>
            <li>A <strong>confirmed {{ $isAgency ? 'booking' : 'booking or inquiry' }}</strong> is counted only after it is stored, once per record, and only when the visit could be linked to this page.</li>
            <li>Traffic with no campaign link or referring site is shown as <strong>Direct / unknown</strong>.</li>
        </ul>
    @endif
</div>
