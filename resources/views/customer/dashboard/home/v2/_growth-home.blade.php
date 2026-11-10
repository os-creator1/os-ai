{{--
    Growth Home — the approved "Variant B" command center (numbers first, red next move) over the SAME Home model
    (HomeV2Presenter::growthHome). It computes nothing, queries nothing and selects nothing:
      greeting + one compact performance card (Google visibility, New contacts, Bookings — labelled with the real period)
      → ONE Best Next Move in the red hero card (the description proposal is inline for add_description; every other move uses its own
        authorised link) with the other opportunities folded inside the card
      → Ask MotionGrove (the existing assistant, no AI on load) → MotionGrove at work (the existing recent-work feed)
      → right column: Getting your business ready (existing HomeSetupJourney) + Recent chats (the customer's own saved conversations).
    Anything unreadable is said to be unknown, never shown as a result. Core/Agency never render this.
--}}
@php
    $g = $v2['growthHome'];
    $needs = $v2['needs'];
    $period = $v2['growth']['period'] ?? null;
    $kpis = collect($v2['growth']['kpis'] ?? [])->filter(fn ($k) => in_array($k['key'], ['contacts', 'bookings'], true))->sortBy(fn ($k) => $k['key'] === 'contacts' ? 0 : 1)->values();
    $google = $g['google'];
    $labels = ['contacts' => 'New contacts', 'bookings' => 'Bookings'];
    $missing = array_diff_key($labels, $kpis->keyBy('key')->all());
    $activity = $g['activity'];
    $setup = $g['setup'];
    $greeting = $home['greeting']['salutation'] . ($greetingName ? ', ' . $greetingName : '');

    // Every row the existing sources record, in one list: work waiting on / under way for the customer first (undated), then dated activity.
    // The words are the existing source statuses only: nothing is "Running" unless the backend records it in progress, and nothing is
    // "Verified" here because no source on this page proves verification (Verified would need its own proof; none is invented).
    $statusView = [
        'approval' => ['Needs your approval', 'approval', 'needs'],
        'progress' => ['Running', 'running', 'running'],
        'failed' => ['Needs attention', 'attention', 'attention'],
        'completed' => ['Completed', 'done', 'done'],
        'recorded' => ['Recorded', 'done', 'done'],
    ];
    $rows = [];
    foreach ($g['currentWork'] as $row) { $rows[] = $row + ['dated' => false]; }
    foreach ($activity as $row) { $rows[] = $row + ['dated' => true]; }
    $linkWord = ['approval' => 'Review', 'progress' => 'View', 'failed' => 'See what happened', 'completed' => 'View', 'recorded' => 'View'];
    $icon = [
        'approval' => 'M4 20h4L19 9l-4-4L4 16zM13.5 6.5l4 4',
        'progress' => 'M4 12a8 8 0 0 1 14-5.3M18 3v4h-4M20 12a8 8 0 0 1-14 5.3M6 21v-4h4',
        'failed' => 'M12 4l9 16H3zM12 10v4M12 17h.01',
        'completed' => 'M5 12.5l4.5 4.5L19 7.5',
        'recorded' => 'M6 3.5h8l4 4v13H6zM14 3.5v4h4M9 12.5h6M9 16.5h6',
    ];
    $others = collect([$g['secondary']])->filter()->merge($g['more'])->values();
    $moveIcon = match (true) {
        ($needs['description'] ?? null) !== null => 'M4 20h4L19 9l-4-4L4 16zM13.5 6.5l4 4',
        ($needs['kind'] ?? '') === 'waiting' => 'M4 5h16v11H9l-5 4z',
        in_array($needs['moveKey'] ?? '', ['reach_setup'], true) => 'M12 3.5a8.5 8.5 0 1 0 0 17 8.5 8.5 0 0 0 0-17zM3.5 12h17M12 3.5c2.8 2.5 2.8 14.5 0 17M12 3.5c-2.8 2.5-2.8 14.5 0 17',
        default => 'M5 12h14M13 6l6 6-6 6',
    };
@endphp
@include('customer.dashboard.home.v2._growth-styles')
<div class="hv2-layout gh-root" data-role="growth-home">
    <div class="hv2-main">
        {{-- Greeting + numbers --}}
        <section class="gh-sec" aria-labelledby="gh-greeting" data-role="growth-performance">
            <div class="gh-greet" data-role="home-greeting">
                <h1 class="gh-h1" id="gh-greeting" data-role="home-salutation">{{ $greeting }}</h1>
                @if($period !== null)<span class="gh-period" data-role="headline-period">{{ $period['label'] }}</span>@endif
            </div>
            <div class="gh-metrics" data-role="kpis" style="--gh-cols: {{ max(1, ($google !== null ? 1 : 0) + $kpis->count() + ($v2['growth']['failed'] ? 0 : count($missing))) }}">
                @if($google !== null)
                    @php $gTag = $google['url'] ? 'a' : 'div'; $notTracking = $google['state'] === 'not_tracking'; @endphp
                    <{{ $gTag }} class="gh-met gh-met--lead" @if($google['url']) href="{{ $google['url'] }}" @endif data-role="google-visibility" data-state="{{ $google['state'] }}">
                        <span class="gh-met__k">Google visibility</span>
                        <span class="gh-met__state" data-role="google-visibility-state">{{ $notTracking ? 'Not tracking yet' : $google['label'] }}</span>
                        @if(! $notTracking && $google['detail'])<span class="gh-met__s">{{ $google['detail'] }}</span>@endif
                        @if($google['url'])<span class="gh-met__link" data-role="view-keywords">{{ $notTracking ? 'Choose keywords' : 'View keywords' }}<svg class="gh-ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg></span>@endif
                    </{{ $gTag }}>
                @endif
                @foreach($kpis as $kpi)
                    @php $tag = $kpi['url'] === null ? 'div' : 'a'; @endphp
                    <{{ $tag }} class="gh-met" @if($kpi['url'] !== null) href="{{ $kpi['url'] }}" @endif data-role="kpi" data-kpi="{{ $kpi['key'] }}">
                        <span class="gh-met__k">{{ $kpi['label'] }}</span>
                        <span class="gh-met__v" data-role="headline-figure">{{ $kpi['value'] }}</span>
                        {{-- A comparison appears only when the performance band proved one (its own wording); otherwise its honest note. --}}
                        @if($kpi['badge'] !== null)<span class="gh-met__s" data-role="kpi-badge" data-tone="{{ $kpi['badge']['tone'] }}">{{ $kpi['badge']['sr'] }}</span>@endif
                        @if($kpi['note'])<span class="gh-met__s" data-role="kpi-note">{{ $kpi['note'] }}</span>@endif
                    </{{ $tag }}>
                @endforeach
                @if(! $v2['growth']['failed'])
                    @foreach($missing as $key => $label)
                        <div class="gh-met" data-role="kpi" data-kpi="{{ $key }}" data-state="initial"><span class="gh-met__k">{{ $label }}</span><span class="gh-met__v">—</span><span class="gh-met__s">Nothing recorded yet</span></div>
                    @endforeach
                @endif
            </div>
            @if($v2['growth']['failed'])
                <p class="gh-quiet-note" data-role="growth-unavailable">Contacts and bookings are unavailable right now.</p>
            @endif
        </section>

        {{-- Your next best move --}}
        @if($needs['kind'] !== 'billing_strip')
        <section class="gh-sec" aria-labelledby="gh-next-title" data-band="next_best_move" data-role="growth-next" data-kind="{{ $needs['kind'] }}">
            <h2 class="gh-eyebrow" id="gh-next-title">Your next best move</h2>
            @if($needs['kind'] === 'caught_up' || $needs['kind'] === 'unavailable')
                @if($g['setupStep'] !== null && $needs['kind'] === 'caught_up')
                    <div class="gh-nbm" data-role="setup-step">
                        <span class="gh-nbm__deco gh-nbm__deco--a" aria-hidden="true"></span><span class="gh-nbm__deco gh-nbm__deco--b" aria-hidden="true"></span>
                        <div class="gh-nbm__head">
                            <span class="gh-nbm__tile" aria-hidden="true"><svg class="gh-ic gh-ic--lg" viewBox="0 0 24 24"><path d="M5 12h14M13 6l6 6-6 6"/></svg></span>
                            <div class="gh-nbm__text"><h3 class="gh-nbm__title">{{ $g['setupStep']['title'] }}</h3><p class="gh-nbm__sub">{{ $g['setupStep']['outcome'] }}</p></div>
                        </div>
                        @if(($g['setupStep']['action']['url'] ?? null) !== null)<div class="gh-nbm__actions"><a class="gh-btn gh-btn--light gh-btn--lg" href="{{ $g['setupStep']['action']['url'] }}" data-role="setup-step-action">{{ $g['setupStep']['action']['label'] }} <svg class="gh-ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg></a></div>@endif
                    </div>
                @else
                    <div class="gh-quiet{{ $needs['kind'] === 'unavailable' ? ' gh-quiet--warn' : '' }}" data-role="{{ $needs['kind'] === 'unavailable' ? 'needs-unavailable' : 'caught-up' }}">
                        <strong>{{ $needs['headline'] }}</strong>@if($needs['sub'])<p>{{ $needs['sub'] }}</p>@endif
                    </div>
                @endif
            @else
                <div class="gh-nbm" data-role="next-best-move" data-move="{{ $needs['moveKey'] ?? 'attention' }}">
                    <span class="gh-nbm__deco gh-nbm__deco--a" aria-hidden="true"></span><span class="gh-nbm__deco gh-nbm__deco--b" aria-hidden="true"></span>
                    <div class="gh-nbm__head">
                        <span class="gh-nbm__tile" aria-hidden="true"><svg class="gh-ic gh-ic--lg" viewBox="0 0 24 24"><path d="{{ $moveIcon }}"/></svg></span>
                        <div class="gh-nbm__text">
                            <h3 class="gh-nbm__title" data-role="next-best-move-headline">{{ $needs['headline'] }}</h3>
                            @if($needs['sub'])<p class="gh-nbm__sub" data-role="needs-sub">{{ $needs['sub'] }}</p>@endif
                            @php $pills = []; if (($needs['description'] ?? null) !== null) { if ($needs['description']['fromFacts']) { $pills[] = '✓ Drafted from your saved details'; } $pills[] = 'One click'; } elseif (! empty($needs['statusLabel'])) { $pills[] = $needs['statusLabel']; } @endphp
                            @if($pills !== [])<div class="gh-pills">@foreach($pills as $pill)<span class="gh-pill">{{ $pill }}</span>@endforeach</div>@endif
                            @if(($needs['evidence'] ?? []) !== [] || ($needs['why'] ?? []) !== [] || ($needs['impact'] ?? null) !== null)
                            <details class="gh-evidence" data-role="growth-evidence">
                                <summary>Why this?</summary>
                                <ul>
                                    @foreach(array_merge($needs['evidence'] ?? [], $needs['why'] ?? []) as $line)<li data-role="evidence-line">{{ $line }}</li>@endforeach
                                    @if(($needs['impact'] ?? null) !== null)<li data-role="evidence-impact">{{ is_array($needs['impact']) ? ($needs['impact']['text'] ?? '') : $needs['impact'] }}</li>@endif
                                </ul>
                                @if(($needs['confidence'] ?? null) !== null)<p>Confidence: {{ $needs['confidence'] }}</p>@endif
                            </details>
                            @endif
                        </div>
                    </div>
                    @if(($needs['description'] ?? null) !== null)
                        @include('customer.dashboard.home.v2._description-proposal', ['needs' => $needs, 'next' => $g['secondary']])
                    @elseif($needs['primary'] !== null)
                        <div class="gh-nbm__actions"><a class="gh-btn gh-btn--light gh-btn--lg" href="{{ $needs['primary']['url'] }}" data-role="next-best-move-action">{{ $needs['primary']['label'] }} <svg class="gh-ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg></a></div>
                    @endif
                    @if($others->isNotEmpty() || $g['moreUrl'] !== null)
                        <details class="gh-nbm__more" data-role="other-opportunities">
                            <summary>{{ $others->isEmpty() ? 'More recommendations' : $others->count() . ' more ' . ($others->count() === 1 ? 'opportunity' : 'opportunities') }}<svg class="gh-ic gh-chev" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg></summary>
                            <div class="gh-nbm__more-body">
                                @foreach($others as $item)
                                    <div class="gh-nbm__more-row" @if($loop->first && $item === $g['secondary']) data-role="secondary-opportunity" @else data-role="other-opportunity" @endif data-uid="{{ $item['uid'] }}"><span>{{ $item['title'] }}</span>@if($item['url'] !== null)<a href="{{ $item['url'] }}">Open</a>@endif</div>
                                @endforeach
                                @if($g['moreUrl'] !== null)<div class="gh-nbm__more-row"><span></span><a href="{{ $g['moreUrl'] }}" data-role="all-recommendations">See all recommendations</a></div>@endif
                            </div>
                        </details>
                    @endif
                </div>
            @endif
        </section>
        @endif

        {{-- Ask MotionGrove (the existing assistant; no model is called when Home loads) --}}
        @include('customer.dashboard.home._ask', ['home' => $home, 'v2Chips' => $v2['chips'], 'growthSkin' => true])

        {{-- MotionGrove at work --}}
        <section class="gh-sec" aria-labelledby="gh-work-title" data-role="growth-activity">
            <div class="gh-sh"><h2 class="gh-h2" id="gh-work-title">MotionGrove at work</h2>@if(! empty($g['workUrl']))<a href="{{ $g['workUrl'] }}" data-role="growth-work-link">Work history</a>@endif</div>
            <div class="gh-card gh-work" data-work>
                @if($g['activityFailed'])
                    <p class="gh-empty" data-role="activity-unavailable">We couldn't load recent activity just now.</p>
                @elseif($rows === [])
                    <p class="gh-empty" data-role="activity-empty">No activity yet. MotionGrove's work will appear here as it happens.</p>
                @else
                    <div class="gh-tabs" role="group" aria-label="Filter work">
                        @foreach(['all' => 'All', 'running' => 'Running', 'needs' => 'Needs approval', 'done' => 'Done', 'attention' => 'Attention'] as $key => $label)
                            <button type="button" class="gh-tab" data-filter="{{ $key }}" aria-pressed="{{ $loop->first ? 'true' : 'false' }}">{{ $label }}</button>
                        @endforeach
                    </div>
                    <div data-role="activity-items">
                    @foreach($rows as $row)
                        @php [$word, $tone, $bucket] = $statusView[$row['status']] ?? $statusView['recorded']; @endphp
                        <div class="gh-row" data-role="{{ $row['dated'] ? 'activity-item' : 'current-work-item' }}" data-status="{{ $row['status'] }}" data-bucket="{{ $bucket }}"@if($loop->index >= 5) hidden data-extra="1"@endif>
                            <span class="gh-row__ico gh-tone-{{ $tone }}" aria-hidden="true"><svg class="gh-ic" viewBox="0 0 24 24"><path d="{{ $icon[$row['status']] ?? $icon['recorded'] }}"/></svg></span>
                            <div class="gh-row__t">
                                <b>{{ $row['text'] }}</b>
                                <div class="gh-row__meta"><span class="gh-st gh-tone-{{ $tone }}"><i @if($row['status'] === 'progress') class="gh-live" @endif></i>{{ $word }}</span>@if($row['url'] !== null)<a href="{{ $row['url'] }}">{{ $linkWord[$row['status']] ?? 'View' }}</a>@endif</div>
                            </div>
                            <span class="gh-row__when">@if($row['at'] !== null)<time datetime="{{ $row['at']->format('c') }}">{{ $row['at']->diffForHumans() }}</time>@endif</span>
                        </div>
                    @endforeach
                    </div>
                    <p class="gh-empty gh-empty--filtered" data-role="work-filter-empty" hidden>Nothing here right now.</p>
                    <button type="button" class="gh-more" data-role="activity-more" @if(count($rows) <= 5) hidden @endif>Show {{ count($rows) - 5 }} more</button>
                @endif
            </div>
        </section>

        @php $leads = $g['leads'] ?? null; @endphp
        @if($leads !== null && ($leads['total'] > 0 || $leads['answered'] !== []))
        <section class="gh-sec" aria-labelledby="gh-waiting-title" data-role="growth-waiting">
            <h2 class="gh-h2" id="gh-waiting-title">Customers waiting for a reply</h2>
            <div class="gh-card gh-waiting">
                @if($leads['total'] > 0)
                    <p class="gh-quiet-note" data-role="waiting-count">
                        {{ number_format($leads['total']) }} {{ $leads['total'] === 1 ? 'customer is' : 'customers are' }} waiting.
                        @if($leads['total'] > count($leads['items'])) Showing the {{ count($leads['items']) }} who have waited longest. @endif
                    </p>
                    <ul data-role="waiting-items">
                        @foreach($leads['items'] as $lead)
                            <li data-role="waiting-item" data-uid="{{ $lead['uid'] }}">
                                <strong>{{ $lead['name'] }}</strong>
                                <span class="gh-quiet-note">· waiting <time datetime="{{ $lead['at']->toIso8601String() }}">{{ $lead['waiting'] }}</time></span>
                                @if($lead['prompt'] !== null)
                                    <button type="button" class="gh-btn gh-btn--line gh-btn--sm" data-role="prepare-reply" data-ask-prompt="{{ $lead['prompt'] }}">Prepare reply</button>
                                @endif
                                @if($leads['fullUrl'] !== null)
                                    <a href="{{ $leads['fullUrl'] }}"@if($leads['drawerUrl'] !== null) data-home-drawer="{{ $leads['drawerUrl'] }}" data-drawer-title="Reply to customers"@endif data-role="reply-manually">Reply yourself</a>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                    <p class="gh-quiet-note">Preparing a reply drafts it for you to review; nothing is sent until you press Send.</p>
                @endif
                @if($leads['answered'] !== [])
                    <ul>
                        @foreach($leads['answered'] as $done)
                            <li data-role="answered-item" data-status="{{ $done['status'] }}">
                                {{ $done['name'] }}
                                <span class="gh-quiet-note">· {{ $done['status'] === 'delivered' ? 'Reply delivered' : 'Reply submitted to your messaging provider' }} · <time datetime="{{ $done['at']->toIso8601String() }}">{{ $done['at']->diffForHumans() }}</time></span>
                            </li>
                        @endforeach
                    </ul>
                    <p class="gh-quiet-note">A reply is not a booking: MotionGrove does not know whether this customer booked.</p>
                @endif
            </div>
        </section>
        @endif

        {{-- A failed automation read is still visible on Growth Home; never silently hide a failed proof of all-clear. --}}
        @if($dashboard->failed(\App\Library\Dashboard\DashboardSnapshot::BAND_AUTOMATIONS))
            @include('customer.dashboard.band-failed', ['band' => 'automations', 'title' => 'Automations'])
        @endif
    </div>

    <aside class="hv2-side gh-side" aria-label="Setup and recent chats">
        @if($setup !== null)
            @php $nextStep = collect($setup['steps'])->firstWhere('key', $setup['nextKey']); @endphp
            <section class="gh-card gh-side-card" aria-labelledby="gh-setup-title" data-role="growth-setup">
                <div class="gh-side-head"><h2 id="gh-setup-title">Getting your business ready</h2><span data-role="growth-setup-count">{{ $setup['done'] }} of {{ $setup['total'] }}</span></div>
                <div class="gh-seg" style="--gh-seg: {{ $setup['total'] }}" aria-hidden="true">@foreach($setup['steps'] as $s)<span class="{{ $s['done'] ? 'is-done' : '' }}"></span>@endforeach</div>
                <details class="gh-checklist">
                    <summary>{{ $nextStep ? 'Next: ' . lcfirst($nextStep['title']) : 'Everything is set up' }}<svg class="gh-ic gh-chev" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg></summary>
                    <ul>
                        @foreach($setup['steps'] as $s)
                            <li class="{{ $s['done'] ? 'is-done' : '' }}">
                                @if($s['done'])<span class="gh-tick" aria-hidden="true"><svg class="gh-ic" viewBox="0 0 24 24"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg></span><span class="visually-hidden">Done: </span>@else<span class="gh-ring" aria-hidden="true"></span>@endif
                                @if(! $s['done'] && $s['url'] !== null)<a href="{{ $s['url'] }}">{{ $s['title'] }}</a>@else<span>{{ $s['title'] }}</span>@endif
                            </li>
                        @endforeach
                    </ul>
                </details>
            </section>
        @endif

        <section class="gh-card gh-side-card" aria-labelledby="gh-chats-title" data-role="recent-chats">
            <div class="gh-side-head"><h2 id="gh-chats-title">Recent chats</h2><a href="#ask" data-role="recent-chats-all" hidden>All</a></div>
            <div data-role="recent-chats-list"></div>
            <p class="gh-quiet-note" data-role="recent-chats-empty" hidden>No chats yet. Ask MotionGrove something and it will be kept here.</p>
            <p class="gh-quiet-note" data-role="recent-chats-error" hidden>We couldn't load your chats just now.</p>
        </section>
    </aside>
</div>
<script>
(function () {
    // "MotionGrove at work": filter chips and Show more over the rows the server already rendered. Presentation only; nothing is fetched.
    var box = document.querySelector('[data-role="growth-activity"] [data-work]');
    if (!box) { return; }
    var rows = [].slice.call(box.querySelectorAll('[data-bucket]')), tabs = [].slice.call(box.querySelectorAll('.gh-tab'));
    var more = box.querySelector('[data-role="activity-more"]'), none = box.querySelector('[data-role="work-filter-empty"]');
    var filter = 'all', expanded = false, LIMIT = 5;
    function paint() {
        var match = rows.filter(function (r) { return filter === 'all' || r.getAttribute('data-bucket') === filter; });
        rows.forEach(function (r) { r.hidden = true; });
        match.forEach(function (r, i) { r.hidden = !(expanded || i < LIMIT); });
        none.hidden = match.length > 0;
        var left = match.length - LIMIT;
        more.hidden = expanded || left <= 0;
        more.textContent = 'Show ' + left + ' more';
        tabs.forEach(function (t) { t.setAttribute('aria-pressed', t.getAttribute('data-filter') === filter ? 'true' : 'false'); });
    }
    tabs.forEach(function (t) { t.addEventListener('click', function () { filter = t.getAttribute('data-filter'); expanded = false; paint(); }); });
    more.addEventListener('click', function () { expanded = true; paint(); });
})();
</script>
