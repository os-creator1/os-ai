@extends('customer.business.website.external.layout')

@section('external-content')
    @php
        use App\Library\ExternalSite\ExternalAuditPresenter as P;
        use App\Models\ExternalSitePlanItem as Tracked;

        $detailRoute = fn (int $id): string => route('customer.workspaces.businesses.website.external.page', [$workspaceUid, $businessUid, $id]);
        $sectionTitles = [
            'problem' => ['Confirmed technical problems', 'Things the crawler observed that are broken, contradictory or blocking. Fix these first.'],
            'improvement' => ['SEO improvements', 'Worthwhile changes to what search engines read on your pages. These improve how clearly your pages are described; they do not promise rankings.'],
            'opportunity' => ['Growth opportunities', 'Suggestions that could help customers find and contact you. They are ideas, not defects, and are labelled where they are an interpretation.'],
        ];
    @endphp

    @if($report === null || $plan === null)
        <section class="xw-card xw-card-pad" data-role="plan-empty">
            <p class="xw-p" style="margin:0">
                @if($earlier !== null) Your last check used an earlier version of the audit, so there is no improvement plan from it. Check again to get one.
                @else Your improvement plan is built from your first audit. Start an audit to see it. @endif
            </p>
        </section>
    @else
        @php
            $allItems = array_merge($plan['sections']['problem'], $plan['sections']['improvement'], $plan['sections']['opportunity']);
            $total = count($allItems);
            $doneCount = count(array_filter($allItems, fn ($i) => in_array($i['tracking']['status'], [Tracked::DONE], true)));
            $progressCount = count(array_filter($allItems, fn ($i) => in_array($i['tracking']['status'], [Tracked::IN_PROGRESS, Tracked::WAITING], true)));
            $effortText = ['quick' => 'quick change', 'moderate' => 'moderate change', 'technical' => 'technical change'];
            $copyIcon = '<svg class="xi" viewBox="0 0 24 24"><rect x="8" y="8" width="12" height="12" rx="2"/><path d="M16 8V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h2"/></svg>';
        @endphp

        <section class="xw-card xw-plan-top" data-role="plan-intro">
            <div class="xw-plan-top-main">
                <h2 class="xw-sr">Website improvement plan</h2>
                <div>
                    <b data-role="plan-progress">{{ $doneCount }} of {{ $total }} done</b>
                    <span class="xw-mut">@if($progressCount > 0)&middot; {{ $progressCount }} in progress @endif&middot; from the audit of {{ $report->finished_at?->toFormattedDateString() }}</span>
                </div>
                @if($total > 0)
                    <div class="xw-progress" role="img" aria-label="{{ $doneCount }} of {{ $total }} improvements done">
                        @for($n = 0; $n < $total; $n++)
                            <span style="flex:1" class="{{ $n < $doneCount ? 'is-done' : ($n < $doneCount + $progressCount ? '' : 'is-empty') }}"></span>
                        @endfor
                    </div>
                @endif
                <span class="xw-mut">{{ $plan['counts']['problems'] }} confirmed problem(s) &middot; {{ $plan['counts']['improvements'] }} SEO improvement(s) &middot; {{ $plan['counts']['opportunities'] }} growth opportunit{{ $plan['counts']['opportunities'] === 1 ? 'y' : 'ies' }}. MotionGrove cannot change your website: every item says what to change and how, for your own editor or developer. Priorities come from what was observed and how important the page is (home, contact or booking pages first). No traffic, ranking or revenue is predicted. Mark an item <em>done</em> once you change it, then check again: it is confirmed only when the next complete check no longer finds it.</span>
                @if($plan['start_here'] !== [])
                    <div class="xw-mut" data-role="start-here"><strong>Start here:</strong>
                        @foreach($plan['start_here'] as $item)<span data-role="start-item">@if(! $loop->first), @endif<a class="xw-lk xw-lk-sm" href="#plan-{{ $item['rule_key'] }}">{{ $item['title'] }}</a></span>@endforeach
                    </div>
                @endif
            </div>
            <button type="button" class="xw-btn xw-btn-line" data-role="copy-plan" data-copy="{{ $plan['full_text'] }}" onclick="navigator.clipboard && navigator.clipboard.writeText(this.dataset.copy); this.textContent='Plan copied';">{!! $copyIcon !!}Copy the whole plan</button>
        </section>

        @if($report->status === 'partial')
            <div class="xw-banner is-warn" role="status">This plan covers only what was checked. {{ P::finishReason($report->finish_reason, $report->coverage) }}</div>
        @endif

        @foreach($sectionTitles as $kind => [$title, $blurb])
            @if($plan['sections'][$kind] !== [])
                <div class="xw-sect" data-role="plan-section" data-kind="{{ $kind }}" style="margin-top:{{ $loop->first ? 0 : 8 }}px">
                    <b>{{ $title }}</b>
                    <span class="xw-mut">{{ count($plan['sections'][$kind]) }} &middot; {{ $blurb }}</span>
                </div>

                @foreach($plan['sections'][$kind] as $item)
                    @php
                        [$kindText, $kindTone] = P::kindTag($item['kind']);
                        $st = $item['tracking']['status'];
                        $isOpen = in_array($st, [Tracked::IN_PROGRESS, Tracked::WAITING], true) || ($item['rule_key'] === ($allItems[0]['rule_key'] ?? null) && $doneCount === 0 && $progressCount === 0);
                        $formId = 'trk-'.$item['rule_key'];
                        $where = $item['site_level'] ? 'Whole site' : $item['page_count'].' '.($item['page_count'] === 1 ? 'page' : 'pages');
                    @endphp
                    <section class="xw-card xw-card-flat" id="plan-{{ $item['rule_key'] }}" data-role="plan-item" data-rule="{{ $item['rule_key'] }}" data-priority="{{ $item['priority'] }}" data-status="{{ $st }}">
                        <details class="xw-det" @if($isOpen) open @endif>
                            <summary>
                                <span class="xw-state is-{{ $st }}" aria-hidden="true">@if($st === Tracked::DONE)<svg class="xi" viewBox="0 0 24 24"><path d="M5 12.500l4.500 4.500L19 7.500"/></svg>@endif</span>
                                <span class="xw-det-title">
                                    <span class="xw-rw-title">
                                        <b data-role="plan-title">{{ $item['title'] }}</b>
                                        <span class="xw-tg {{ $kindTone }}" title="{{ $item['kind_label'] }}">{{ $kindText }}</span>
                                        @if($item['priority'] !== 'info' && $item['priority'] !== 'low')<span class="xw-tg {{ P::toneClass(P::priorityVariant($item['priority'])) }}">{{ $item['priority_label'] }}</span>@endif
                                        @if($item['confidence'] === 'heuristic')<span class="xw-tg xw-t-low" title="An interpretation of what was observed">Interpretation</span>@endif
                                    </span>
                                    <span class="xw-det-sub">{{ $where }} &middot; {{ $effortText[$item['effort']] ?? 'change' }} &middot; {{ $item['category_label'] }}</span>
                                </span>
                                <label class="xw-sel xw-sel-status is-{{ $st }}" for="st-{{ $item['rule_key'] }}" onclick="event.stopPropagation()">
                                    <select form="{{ $formId }}" id="st-{{ $item['rule_key'] }}" name="status" aria-label="Your progress" onchange="this.form.requestSubmit ? this.form.requestSubmit() : this.form.submit()">
                                        @foreach($statuses as $value => $label)<option value="{{ $value }}" @selected($st === $value)>{{ $label }}</option>@endforeach
                                    </select><svg class="xi" viewBox="0 0 24 24" style="right:10px"><path d="M7 10l5 5 5-5"/></svg>
                                </label>
                                <svg class="xi xw-chev" viewBox="0 0 24 24"><path d="M7 10l5 5 5-5"/></svg>
                            </summary>

                            <div class="xw-det-body">
                                <div class="xw-twocol">
                                    <div class="xw-change">
                                        <span class="xw-eyebrow">Change</span>
                                        <span class="xw-p-body" data-role="proposed-change"><strong>Proposed change.</strong> {{ $item['proposal'] }}</span>
                                        <span class="xw-p-body" data-role="business-relevance"><strong>Why it matters for your business.</strong> {{ $item['relevance'] }}</span>
                                        <div class="xw-change-rows" data-role="plan-urls">
                                            @foreach(array_slice($item['urls'], 0, 12) as $u)
                                                <div class="xw-change-row">
                                                    @if($u['page_id'])<a class="xw-code" href="{{ $detailRoute($u['page_id']) }}">{{ $u['path'] }}</a>@else <span class="xw-code" style="font-weight:600">Whole website</span> @endif
                                                    <span class="xw-mut">{{ $u['observed'] }}</span>
                                                    @if($u['page_id'])<a class="xw-lk xw-lk-sm" href="{{ $u['url'] }}" target="_blank" rel="noopener noreferrer">View page<svg class="xi" viewBox="0 0 24 24" style="width:12px;height:12px"><path d="M14 4h6v6M20 4l-9 9M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/></svg></a>@endif
                                                </div>
                                            @endforeach
                                            @if(count($item['urls']) > 12)<span class="xw-mut">…and {{ count($item['urls']) - 12 }} more (see the <a class="xw-lk xw-lk-sm" href="{{ $auditUrl }}?rule={{ $item['rule_key'] }}#rule-{{ $item['rule_key'] }}">audit</a>).</span>@endif
                                        </div>
                                    </div>
                                    <div class="xw-steps">
                                        <span class="xw-eyebrow">Steps</span>
                                        <ol data-role="plan-steps">@foreach($item['steps'] as $step)<li>{{ $step }}</li>@endforeach</ol>
                                        <p class="xw-mut" style="margin:10px 0 0" data-role="plan-verify"><strong>How we will verify it.</strong> {{ $item['verify'] }}</p>
                                    </div>
                                </div>

                                {{-- the owner's own tracking --}}
                                <form id="{{ $formId }}" method="POST" action="{{ route('customer.workspaces.businesses.website.external.plan.update', [$workspaceUid, $businessUid, $item['item_key']]) }}" class="xw-stack" style="gap:14px" data-role="tracking-form">
                                    @csrf
                                    <input type="hidden" name="rule_key" value="{{ $item['rule_key'] }}">
                                    <div class="xw-field">
                                        <label for="nt-{{ $item['rule_key'] }}">Note <span>(optional)</span></label>
                                        <input class="xw-in xw-in-sm" id="nt-{{ $item['rule_key'] }}" name="note" maxlength="500" value="{{ $item['tracking']['note'] }}" placeholder="e.g. Sent to my developer">
                                    </div>
                                    @if($item['tracking']['still_present_after_done'])
                                        <p class="xw-warnline" data-role="still-present">You marked this done, but the latest check still found it. If you changed the site recently, check again.</p>
                                    @endif
                                    <div class="xw-actions" data-role="help">
                                        @if($st !== Tracked::DONE)
                                            <button type="submit" class="xw-btn xw-btn-primary" data-role="mark-done" onclick="document.getElementById('st-{{ $item['rule_key'] }}').value='{{ Tracked::DONE }}'"><svg class="xi" viewBox="0 0 24 24"><path d="M5 12.500l4.500 4.500L19 7.500"/></svg>Mark as done</button>
                                        @endif
                                        <button type="submit" class="xw-btn xw-btn-line" data-role="save-tracking">Save progress</button>
                                        <button type="button" class="xw-btn xw-btn-line" data-role="copy-brief" data-copy="{{ $item['brief'] }}" onclick="navigator.clipboard && navigator.clipboard.writeText(this.dataset.copy); this.textContent='Copied for your developer';">{!! $copyIcon !!}Copy a brief for my web developer</button>
                                        @if($item['mailto'])<a class="xw-btn xw-btn-line" data-role="email-help" href="{{ $item['mailto'] }}">Email this to my support contact</a>@endif
                                        <span class="xw-mut">Confirmed done when the next check no longer finds it.</span>
                                    </div>
                                </form>
                            </div>
                        </details>
                    </section>
                @endforeach
            @endif
        @endforeach

        @if($plan['observations'] !== [])
            <div class="xw-sect" data-role="plan-section" data-kind="info" style="margin-top:8px"><b>Observations (nothing to fix on your site)</b></div>
            @foreach($plan['observations'] as $obs)
                <section class="xw-card xw-card-pad xw-stack" data-role="observation" data-rule="{{ $obs['rule_key'] }}">
                    <h3 class="xw-h">{{ $obs['title'] }} <span class="xw-mut">&middot; {{ $obs['page_count'] }} {{ $obs['page_count'] === 1 ? 'page' : 'pages' }}</span></h3>
                    <p class="xw-p">{{ $obs['what'] }}</p>
                    <p class="xw-mut">{{ $obs['why'] }} <a class="xw-lk xw-lk-sm" href="{{ $auditUrl }}?rule={{ $obs['rule_key'] }}#rule-{{ $obs['rule_key'] }}">See the pages</a>.</p>
                </section>
            @endforeach
        @endif

        @if($plan['verified'] !== [])
            <section class="xw-card xw-card-pad xw-stack" data-role="verified-fixes">
                <h2 class="xw-h">Changes you made that the latest check confirms</h2>
                <ul style="margin:0;padding-left:18px;font-size:14px">@foreach($plan['verified'] as $v)<li data-role="verified">{{ $v['title'] }} @if($v['verified'])<span class="xw-tg xw-t-ok">Confirmed fixed</span>@else <span class="xw-tg xw-t-low">Not found; waiting for a complete check to confirm</span>@endif</li>@endforeach</ul>
            </section>
        @endif

        @if($plan['counts']['items'] === 0)
            <section class="xw-card xw-card-pad" data-role="plan-clean"><p class="xw-p" style="margin:0">There is nothing to change in this audit.</p></section>
        @endif
    @endif

    <script>document.addEventListener('DOMContentLoaded', function () { var h = window.location.hash; if (h && /^#plan-[a-z0-9_]+$/i.test(h)) { var n = document.querySelector(h + ' details'); if (n) { n.open = true; n.scrollIntoView(); } } });</script>
@endsection
