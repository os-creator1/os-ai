@extends('customer.business.website.external.layout')

@section('external-content')
    @php
        use App\Library\ExternalSite\ExternalAuditPresenter as P;

        $active = $latest !== null && $latest->isActive();
        $offerRedirect = $connectionCrawl !== null && $connectionCrawl->connection_state === 'redirects_elsewhere' && $connectionCrawl->final_url;
    @endphp

    @php
        $reachTone = ['success' => '', 'warning' => 'is-warn', 'danger' => 'is-bad'][$connection['variant']] ?? 'is-neutral';
        $cadenceText = 'Every '.$cadenceDays.' days';
    @endphp

    <div class="xw-narrow" style="display:flex;flex-direction:column;gap:18px">
        {{-- 1. Website connection --}}
        <section class="xw-card xw-card-pad-lg xw-stack" style="gap:14px" data-role="address">
            <h2 class="xw-h xw-h-lg">Website connection</h2>
            <form method="POST" action="{{ route('customer.workspaces.businesses.website.external.settings.update', [$workspaceUid, $businessUid]) }}" class="xw-addr">
                @csrf
                <label class="xw-sr" for="settings-website-url">Current website address</label>
                <input class="xw-in" id="settings-website-url" name="website_url" value="{{ old('website_url', $business->website_url) }}" placeholder="example.com" maxlength="2048" @disabled(! $canEditAddress)>
                @if($canEditAddress)
                    <button type="submit" class="xw-btn xw-btn-line" data-role="save-address" aria-label="Save and verify address">Save &amp; verify</button>
                @endif
            </form>
            @unless($canEditAddress)<span class="xw-mut">Only the account owner can change the address.</span>@endunless

            <div class="xw-reach {{ $reachTone }}" data-role="reachability">
                <span class="xw-reach-ic" aria-hidden="true">
                    @if($reachTone === '')<svg class="xi" viewBox="0 0 24 24"><path d="M5 12.500l4.500 4.500L19 7.500"/></svg>
                    @else<svg class="xi" viewBox="0 0 24 24"><path d="M12 8v5M12 16.500h.01"/></svg>@endif
                </span>
                <div class="xw-reach-text">
                    <b>{{ $connection['label'] }}</b>
                    <span class="xw-mut">{{ $connection['detail'] }}@if($connectionCrawl?->finished_at) Checked {{ $connectionCrawl->finished_at->diffForHumans() }}.@endif</span>
                </div>
                <form method="POST" action="{{ route('customer.workspaces.businesses.website.external.settings.test', [$workspaceUid, $businessUid]) }}">@csrf<button type="submit" class="xw-lk" data-role="test-connection" aria-label="Test connection now">Test now</button></form>
            </div>

            <dl class="xw-facts" data-role="connection">
                <dt>Normalised address</dt><dd data-fact="normalized">{{ $normalizedUrl ?? 'No address saved' }}</dd>
                <dt>Effective address after redirects</dt>
                <dd data-fact="effective">{{ $connectionCrawl?->final_url ?: 'Not checked yet' }}@if($connectionCrawl?->final_url && $normalizedUrl && rtrim($connectionCrawl->final_url, '/') !== rtrim($normalizedUrl, '/')) <span class="xw-faint">(your address redirects here)</span>@endif</dd>
                <dt>Last connection result</dt>
                <dd data-fact="connection">{{ $connection['label'] }}@if($connectionCrawl?->connection_http_status) &middot; HTTP {{ $connectionCrawl->connection_http_status }}@endif</dd>
            </dl>

            @if($offerRedirect && $canEditAddress)
                <form method="POST" action="{{ route('customer.workspaces.businesses.website.external.settings.update', [$workspaceUid, $businessUid]) }}" class="xw-actions">@csrf<input type="hidden" name="website_url" value="{{ $connectionCrawl->final_url }}"><button type="submit" class="xw-btn xw-btn-line" data-role="use-redirect-target">Use {{ preg_replace('#^https?://#', '', rtrim($connectionCrawl->final_url, '/')) }} as my website address</button></form>
            @endif

            <span class="xw-mut">
                Also updates your business profile. Changing it starts a new audit &mdash; old history is kept.
                @if($businessUrlPrefill !== '')This is the website address saved in your business profile; changing it here changes it there too.@endif
            </span>
        </section>

        {{-- 2. Audit controls --}}
        <section class="xw-card xw-card-pad-lg xw-stack" style="gap:14px" data-role="checks">
            <div class="xw-rw-title" style="justify-content:space-between">
                <h2 class="xw-h xw-h-lg" style="flex:1">Audit controls</h2>
                @if($active)
                    <form method="POST" action="{{ $cancelUrl }}" style="margin:0">@csrf<button type="submit" class="xw-btn xw-btn-line xw-btn-sm" data-role="cancel-check">Cancel check</button></form>
                @else
                    <form method="POST" action="{{ $crawlUrl }}" style="margin:0">@csrf<button type="submit" class="xw-btn xw-btn-line xw-btn-sm" data-role="check-again"><svg class="xi" viewBox="0 0 24 24"><path d="M20 11a8 8 0 0 0-14.500-4.500L4 8M4 4v4h4M4 13a8 8 0 0 0 14.500 4.500L20 16M20 20v-4h-4"/></svg>{{ $latest === null ? 'Start first audit' : 'Check again now' }}</button></form>
                @endif
            </div>

            <div class="xw-kpi-grid">
                <div class="xw-kpi"><span>Audit frequency</span><b data-fact="cadence-short">{{ $cadenceText }}</b><small data-fact="cadence">Automatically about every {{ $cadenceDays }} days, when you change the address, and whenever you choose "Check again" (at most once every {{ $throttleMinutes }} minutes).</small></div>
                <div class="xw-kpi"><span>Last completed audit</span><b data-fact="last-report">{{ $report?->finished_at?->format('M j, g:i A') ?? 'None yet' }}</b><small>@if($report?->finished_at){{ $report->finished_at->diffForHumans() }}@else Nothing has finished yet @endif</small></div>
                <div class="xw-kpi"><span>Crawl budget</span><b>{{ $budget }} pages</b><small data-fact="budget">Up to {{ $budget }} pages per check, one request at a time. Larger sites are checked in part and the report says what was left out.</small></div>
                <div class="xw-kpi"><span>Crawl state</span><b data-fact="state">{{ $latest === null ? 'Not started' : P::statusLabel($latest) }}</b><small>@if($active){{ P::stageLabel($latest->stage) }} ({{ $latest->progress_checked }} checked)@elseif($latest) <span data-fact="timing">{{ ($latest->started_at ?? $latest->created_at)?->toDayDateTimeString() }} / {{ $latest->finished_at?->toDayDateTimeString() ?? '—' }}</span>@else No check yet @endif</small></div>
            </div>

            {{-- 3. History --}}
            <div data-role="audit-history">
                <span class="xw-eyebrow" style="display:block;margin-bottom:6px">Audit history</span>
                <div class="xw-hist-list">
                    @forelse($history as $check)
                        @php
                            $rowChanges = $changes[$check->id] ?? null;
                            $earlierAddress = $check->host !== null && ! app(\App\Library\ExternalSite\ExternalWebsiteReader::class)->isCurrentSite($business, $check);
                        @endphp
                        <div class="xw-nm" data-role="check-row" data-status="{{ $check->status }}">
                            <span class="xw-tg {{ P::toneClass(P::statusVariant($check)) }}">{{ P::statusLabel($check) }}</span>
                            <div class="xw-nm-body">
                                <span>{{ $check->created_at?->format('M j, g:i A') }} &middot; {{ str_replace('_', ' ', $check->trigger) }}</span>
                                <small>{{ $check->host }}@if($earlierAddress) (earlier address)@endif
                                    @if($check->status === 'failed') &middot; {{ P::failure($check)['title'] }} @endif
                                    @if($check->isEarlierFormat()) &middot; earlier audit format @endif
                                    @if($rowChanges !== null) &middot; <span data-role="check-changes">{{ $rowChanges['new'] }} new, {{ $rowChanges['resolved'] }} resolved, {{ $rowChanges['unchanged'] }} unchanged</span>@endif
                                </small>
                            </div>
                            @if($check->hasResults())<span class="xw-nm-end">{{ $check->pages_audited ?: $check->pages_fetched }} of {{ $check->pages_discovered }} addresses audited &middot; {{ $check->critical_count }} critical, {{ $check->warning_count + $check->info_count }} other issue(s)</span>@endif
                        </div>
                    @empty
                        <p class="xw-mut" style="margin:0">No checks yet.</p>
                    @endforelse
                </div>
            </div>
        </section>

        <div class="xw-privacy">
            <svg class="xi" viewBox="0 0 24 24"><rect x="5" y="10.500" width="14" height="10" rx="2"/><path d="M8 10.500V8a4 4 0 0 1 8 0v2.500"/></svg>
            <span>MotionGrove reads the public pages anyone can see: it never signs in, never submits a form, never tries to get past bot protection and never changes anything on your website. It cannot edit your site; it shows what to change. Changing the address starts a new audit of the new address; the history of the old address is kept.</span>
        </div>

        {{-- 4. Website management --}}
        <section class="xw-card xw-host" data-role="switch">
            <div class="xw-host-text">
                <b>Want MotionGrove to host your site?</b>
                <span class="xw-mut">Build one from our templates. Your current site isn't touched and audit history is kept either way.</span>
            </div>
            <form method="POST" action="{{ route('customer.workspaces.businesses.website.mode.choose', [$workspaceUid, $businessUid]) }}">@csrf<input type="hidden" name="mode" value="hosted"><button class="xw-btn xw-btn-primary" type="submit" data-role="switch-hosted" aria-label="Build a website with MotionGrove">Build a website</button></form>
        </section>

        <form method="POST" action="{{ route('customer.workspaces.businesses.website.external.stop', [$workspaceUid, $businessUid]) }}" class="xw-stop-form" onsubmit="return confirm('Stop checking this website? Your audit history is kept.');">@csrf<button class="xw-stop" type="submit" data-role="stop-monitoring">Stop monitoring this website</button></form>
    </div>
@endsection
