{{--
    Growth Home — one connected page over the SAME Home model (HomeV2Presenter::growthHome). It computes nothing, queries nothing and
    selects nothing: performance row (Google visibility from stored rank observations, New contacts, Bookings — the existing headline
    figures) → ONE Best Next Move (Needs-you's own, evidence collapsed) → one compact secondary → "MotionGrove at work" (the existing
    recent-work feed) → Ask MotionGrove. Anything unreadable is said to be unknown, never shown as a result. Core/Agency never render this.
--}}
@php
    $g = $v2['growthHome'];
    $needs = $v2['needs'];
    $kpis = collect($v2['growth']['kpis'] ?? [])->filter(fn ($k) => in_array($k['key'], ['contacts', 'bookings'], true))->sortBy(fn ($k) => $k['key'] === 'contacts' ? 0 : 1)->values();
    $google = $g['google'];
    $labels = ['contacts' => 'New contacts', 'bookings' => 'Bookings'];
    $missing = array_diff_key($labels, $kpis->keyBy('key')->all());
    $activity = $g['activity'];
    $words = ['approval' => 'Awaiting your approval', 'progress' => 'In progress', 'failed' => 'Needs attention', 'completed' => 'Completed', 'recorded' => 'Recorded'];
@endphp
<div class="hv2-layout gh-root" data-role="growth-home">
    <div class="hv2-main">
        <section class="hv2-section" aria-label="Performance" data-role="growth-performance">
            <div class="hv2-kpis" data-role="kpis" style="--hv2-kpis: {{ max(1, count($kpis) + ($google !== null ? 1 : 0)) }}">
                @if($google !== null)
                    <div class="hv2-kpi hv2-kpi--lead" data-role="google-visibility" data-state="{{ $google['state'] }}">
                        <span class="hv2-kpi__label">Google visibility</span>
                        <span class="hv2-kpi__value" data-role="google-visibility-state">{{ $google['label'] }}</span>
                        @if($google['detail'])<span class="hv2-kpi__note">{{ $google['detail'] }}</span>@endif
                        @if($google['url'])<a href="{{ $google['url'] }}" data-role="view-keywords">View keywords</a>@endif
                    </div>
                @endif
                @foreach($kpis as $kpi)
                    @php $tag = $kpi['url'] === null ? 'div' : 'a'; @endphp
                    <{{ $tag }} class="hv2-kpi" @if($kpi['url'] !== null) href="{{ $kpi['url'] }}" @endif data-role="kpi" data-kpi="{{ $kpi['key'] }}">
                        <span class="hv2-kpi__label">{{ $kpi['label'] }}</span>
                        <span class="hv2-kpi__value" data-role="headline-figure">{{ $kpi['value'] }}</span>
                        @if($kpi['badge'] !== null)<span class="hv2-badge hv2-badge--{{ $kpi['badge']['tone'] }}" data-role="kpi-badge" aria-label="{{ $kpi['badge']['sr'] }}">{{ $kpi['badge']['text'] }}</span>@endif
                        @if($kpi['note'])<span class="hv2-kpi__note" data-role="kpi-note">{{ $kpi['note'] }}</span>@endif
                    </{{ $tag }}>
                @endforeach
            </div>
            @if($missing !== [] && ! $v2['growth']['failed'])
                <div class="hv2-kpis" data-role="kpis-initial">
                    @foreach($missing as $key => $label)
                        <div class="hv2-kpi" data-role="kpi" data-kpi="{{ $key }}" data-state="initial"><span class="hv2-kpi__label">{{ $label }}</span><span class="hv2-kpi__value">—</span><span class="hv2-kpi__note">Nothing recorded yet</span></div>
                    @endforeach
                </div>
            @endif
            @if($v2['growth']['failed'])
                <p class="hv2-muted mb-0" data-role="growth-unavailable">Contacts and bookings are unavailable right now.</p>
            @endif
        </section>

        @if($needs['kind'] !== 'billing_strip')
        <section class="hv2-section" aria-labelledby="gh-next-title" data-band="next_best_move" data-role="growth-next" data-kind="{{ $needs['kind'] }}">
            <h2 class="hv2-label" id="gh-next-title">Best next move</h2>
            @if($needs['kind'] === 'caught_up' || $needs['kind'] === 'unavailable')
                @if($g['setupStep'] !== null && $needs['kind'] === 'caught_up')
                    <div class="hv2-card hv2-needs hv2-needs--single" data-role="setup-step">
                        <div class="hv2-needs__text"><h3 class="hv2-needs__title">{{ $g['setupStep']['title'] }}</h3><p class="hv2-muted mb-0">{{ $g['setupStep']['outcome'] }}</p></div>
                        @if(($g['setupStep']['action']['url'] ?? null) !== null)<div class="hv2-needs__actions"><a class="btn btn-primary hv2-btn" href="{{ $g['setupStep']['action']['url'] }}" data-role="setup-step-action">{{ $g['setupStep']['action']['label'] }} <span aria-hidden="true">→</span></a></div>@endif
                    </div>
                @else
                    <div class="hv2-card hv2-quiet{{ $needs['kind'] === 'unavailable' ? ' hv2-quiet--warn' : '' }}" data-role="{{ $needs['kind'] === 'unavailable' ? 'needs-unavailable' : 'caught-up' }}">
                        <div><strong>{{ $needs['headline'] }}</strong>@if($needs['sub'])<p class="hv2-muted mb-0">{{ $needs['sub'] }}</p>@endif</div>
                    </div>
                @endif
            @else
                <div class="hv2-card hv2-needs{{ $needs['kind'] === 'waiting' ? '' : ' hv2-needs--single' }}" data-role="next-best-move" data-move="{{ $needs['moveKey'] ?? 'attention' }}">
                    <div class="hv2-needs__text">
                        <h3 class="hv2-needs__title" data-role="next-best-move-headline">{{ $needs['headline'] }}</h3>
                        @if($needs['sub'])<p class="hv2-muted mb-0" data-role="needs-sub">{{ $needs['sub'] }}</p>@endif
                        @if(($needs['evidence'] ?? []) !== [] || ($needs['why'] ?? []) !== [] || ($needs['impact'] ?? null) !== null)
                        <details class="hv2-details gh-evidence" data-role="growth-evidence">
                            <summary>Why this?</summary>
                            <ul class="hv2-list">
                                @foreach(array_merge($needs['evidence'] ?? [], $needs['why'] ?? []) as $line)<li data-role="evidence-line">{{ $line }}</li>@endforeach
                                @if(($needs['impact'] ?? null) !== null)<li data-role="evidence-impact">{{ is_array($needs['impact']) ? ($needs['impact']['text'] ?? '') : $needs['impact'] }}</li>@endif
                            </ul>
                            @if(($needs['confidence'] ?? null) !== null)<p class="hv2-muted mb-0">Confidence: {{ $needs['confidence'] }}</p>@endif
                        </details>
                        @endif
                    </div>
                    @if($needs['primary'] !== null)
                        <div class="hv2-needs__actions"><a class="btn btn-primary hv2-btn" href="{{ $needs['primary']['url'] }}" data-role="next-best-move-action">{{ $needs['primary']['label'] }} <span aria-hidden="true">→</span></a></div>
                    @endif
                </div>
            @endif

            @if($g['secondary'] !== null)
                <p class="gh-secondary mb-0" data-role="secondary-opportunity" data-uid="{{ $g['secondary']['uid'] }}">
                    <span class="hv2-muted">Also worth doing:</span> {{ $g['secondary']['title'] }}
                    @if($g['secondary']['url'] !== null)<a href="{{ $g['secondary']['url'] }}">View</a>@endif
                </p>
            @endif
            @if($g['more'] !== [] || ($g['secondary'] !== null && $g['moreUrl'] !== null))
                <details class="hv2-details" data-role="other-opportunities">
                    <summary>Other recommendations</summary>
                    <ul class="hv2-list">
                        @foreach($g['more'] as $item)<li data-role="other-opportunity" data-uid="{{ $item['uid'] }}">{{ $item['title'] }}@if($item['url'] !== null) <a href="{{ $item['url'] }}">View</a>@endif</li>@endforeach
                    </ul>
                    @if($g['moreUrl'] !== null)<a href="{{ $g['moreUrl'] }}" data-role="all-recommendations">See all recommendations</a>@endif
                </details>
            @endif
        </section>
        @endif

        <section class="hv2-section" aria-labelledby="gh-work-title" data-role="growth-activity">
            <h2 class="hv2-label" id="gh-work-title">MotionGrove at work</h2>
            <div class="hv2-card">
                @if($g['activityFailed'])
                    <p class="hv2-muted mb-0" data-role="activity-unavailable">We couldn't load recent activity just now.</p>
                @elseif($activity === [] && $g['currentWork'] === [])
                    <p class="hv2-muted mb-0" data-role="activity-empty">No activity yet.</p>
                @else
                    @if($g['currentWork'] !== [])
                        <div data-role="current-work">
                            <p class="hv2-muted mb-0"><strong>Current work</strong></p>
                            <ul class="hv2-done">
                                @foreach($g['currentWork'] as $row)
                                    <li data-role="current-work-item" data-status="{{ $row['status'] }}">
                                        @if($row['url'] !== null)<a href="{{ $row['url'] }}">{{ $row['text'] }}</a>@else<span>{{ $row['text'] }}</span>@endif
                                        <span class="hv2-muted">· {{ $words[$row['status']] ?? '' }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                    @if($activity !== [])
                    <p class="hv2-muted mb-0"><strong>Recent activity</strong></p>
                    <ul class="hv2-done" data-role="activity-items">
                        @foreach($activity as $row)
                            <li data-role="activity-item" data-status="{{ $row['status'] }}"@if($loop->index >= 5) hidden data-extra="1"@endif>
                                @if($row['url'] !== null)<a href="{{ $row['url'] }}">{{ $row['text'] }}</a>@else<span>{{ $row['text'] }}</span>@endif
                                <span class="hv2-muted">· {{ $words[$row['status']] ?? '' }}@if($row['at'] !== null) · <time datetime="{{ $row['at']->format('c') }}">{{ $row['at']->diffForHumans() }}</time>@endif</span>
                            </li>
                        @endforeach
                    </ul>
                    @if(count($activity) > 5)
                        <button type="button" class="btn btn-link p-0" data-role="activity-more" onclick="this.parentNode.querySelectorAll('[data-extra]').forEach(function (e) { e.hidden = false; }); this.remove();">Show more</button>
                    @endif
                    @endif
                @endif
            </div>
        </section>

        {{-- A failed automation read is still visible on Growth Home; never silently hide a failed proof of all-clear. --}}
        @if($dashboard->failed(\App\Library\Dashboard\DashboardSnapshot::BAND_AUTOMATIONS))
            @include('customer.dashboard.band-failed', ['band' => 'automations', 'title' => 'Automations'])
        @endif

        @include('customer.dashboard.home._ask', ['home' => $home, 'v2Chips' => $v2['chips']])
    </div>
</div>
