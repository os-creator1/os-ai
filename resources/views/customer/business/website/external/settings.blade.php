@extends('customer.business.website.external.layout')

@section('external-content')
    @php
        use App\Library\ExternalSite\ExternalAuditPresenter as P;

        $active = $latest !== null && $latest->isActive();
        $offerRedirect = $connectionCrawl !== null && $connectionCrawl->connection_state === 'redirects_elsewhere' && $connectionCrawl->final_url;
    @endphp

    {{-- 1. Website connection --}}
    <x-card :padded="true" class="mb-2" data-role="address">
        <p class="text-label mb-1">Website connection</p>
        <form method="POST" action="{{ route('customer.workspaces.businesses.website.external.settings.update', [$workspaceUid, $businessUid]) }}" class="row g-1 align-items-end">
            @csrf
            <div class="col-md-8">
                <label class="form-label" for="settings-website-url">Current website address</label>
                <input class="form-control" id="settings-website-url" name="website_url" value="{{ old('website_url', $business->website_url) }}" placeholder="example.com" maxlength="2048" @disabled(! $canEditAddress)>
                @unless($canEditAddress)<p class="text-caption text-muted mb-0">Only the account owner can change the address.</p>@endunless
                @if($businessUrlPrefill !== '')<p class="text-caption text-muted mb-0">This is the website address saved in your business profile; changing it here changes it there too.</p>@endif
            </div>
            @if($canEditAddress)
                <div class="col-md-4"><button type="submit" class="btn btn-primary w-100" data-role="save-address">Save and verify address</button></div>
            @endif
        </form>

        <dl class="row mt-1 mb-0" data-role="connection">
            <dt class="col-sm-4">Normalised address</dt><dd class="col-sm-8" data-fact="normalized">{{ $normalizedUrl ?? 'No address saved' }}</dd>
            <dt class="col-sm-4">Effective address after redirects</dt>
            <dd class="col-sm-8" data-fact="effective">{{ $connectionCrawl?->final_url ?: 'Not checked yet' }}@if($connectionCrawl?->final_url && $normalizedUrl && rtrim($connectionCrawl->final_url, '/') !== rtrim($normalizedUrl, '/')) <span class="text-muted">(your address redirects here)</span>@endif</dd>
            <dt class="col-sm-4">Last connection result</dt>
            <dd class="col-sm-8" data-fact="connection"><x-badge :variant="$connection['variant']">{{ $connection['label'] }}</x-badge>
                @if($connectionCrawl?->connection_http_status) <span class="text-muted">HTTP {{ $connectionCrawl->connection_http_status }}</span>@endif
                <div class="text-caption text-muted">{{ $connection['detail'] }}@if($connectionCrawl?->finished_at) Checked {{ $connectionCrawl->finished_at->diffForHumans() }}.@endif</div></dd>
        </dl>

        <div class="d-flex flex-wrap gap-1 mt-1">
            <form method="POST" action="{{ route('customer.workspaces.businesses.website.external.settings.test', [$workspaceUid, $businessUid]) }}">@csrf<button type="submit" class="btn btn-sm btn-outline-secondary" data-role="test-connection">Test connection now</button></form>
            @if($offerRedirect && $canEditAddress)
                <form method="POST" action="{{ route('customer.workspaces.businesses.website.external.settings.update', [$workspaceUid, $businessUid]) }}">@csrf<input type="hidden" name="website_url" value="{{ $connectionCrawl->final_url }}"><button type="submit" class="btn btn-sm btn-outline-primary" data-role="use-redirect-target">Use {{ preg_replace('#^https?://#', '', rtrim($connectionCrawl->final_url, '/')) }} as my website address</button></form>
            @endif
        </div>
        <p class="text-caption text-muted mt-1 mb-0">MotionGrove reads the public pages anyone can see: it never signs in, never submits a form, never tries to get past bot protection and never changes anything on your website. It cannot edit your site; it shows what to change. Changing the address starts a new audit of the new address; the history of the old address is kept.</p>
    </x-card>

    {{-- 2. Audit controls --}}
    <x-card :padded="true" class="mb-2" data-role="checks">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-1 mb-1">
            <p class="text-label mb-0">Audit controls</p>
            @if($active)
                <form method="POST" action="{{ $cancelUrl }}">@csrf<button type="submit" class="btn btn-sm btn-outline-secondary" data-role="cancel-check">Cancel check</button></form>
            @else
                <form method="POST" action="{{ $crawlUrl }}">@csrf<button type="submit" class="btn btn-sm btn-primary" data-role="check-again">{{ $latest === null ? 'Start first audit' : 'Check again now' }}</button></form>
            @endif
        </div>
        <dl class="row mb-0">
            <dt class="col-sm-4">Crawl state</dt><dd class="col-sm-8" data-fact="state">{{ $latest === null ? 'Not started' : P::statusLabel($latest) }}@if($active) &middot; {{ P::stageLabel($latest->stage) }} ({{ $latest->progress_checked }} checked)@endif</dd>
            <dt class="col-sm-4">Latest check started / finished</dt><dd class="col-sm-8" data-fact="timing">@if($latest) {{ ($latest->started_at ?? $latest->created_at)?->toDayDateTimeString() }} / {{ $latest->finished_at?->toDayDateTimeString() ?? ($active ? 'running' : '—') }} @else — @endif</dd>
            <dt class="col-sm-4">Last completed audit</dt><dd class="col-sm-8" data-fact="last-report">{{ $report?->finished_at?->toDayDateTimeString() ?? 'None yet' }}</dd>
            <dt class="col-sm-4">Crawl budget</dt><dd class="col-sm-8" data-fact="budget">Up to {{ $budget }} pages per check, one request at a time. Larger sites are checked in part and the report says what was left out.</dd>
            <dt class="col-sm-4">Audit frequency</dt><dd class="col-sm-8" data-fact="cadence">Automatically about every {{ $cadenceDays }} days, when you change the address, and whenever you choose "Check again" (at most once every {{ $throttleMinutes }} minutes).</dd>
        </dl>
    </x-card>

    {{-- 3. History --}}
    <x-card :padded="true" class="mb-2" data-role="audit-history">
        <p class="text-label mb-1">Audit history</p>
        @forelse($history as $check)
            @php
                $rowChanges = $changes[$check->id] ?? null;
                $earlierAddress = $check->host !== null && ! app(\App\Library\ExternalSite\ExternalWebsiteReader::class)->isCurrentSite($business, $check);
            @endphp
            <p class="mb-50" data-role="check-row" data-status="{{ $check->status }}">
                {{ $check->created_at?->format('M j, Y H:i') }} &middot;
                <strong>{{ P::statusLabel($check) }}</strong>
                @if($check->hasResults()) &middot; {{ $check->pages_audited ?: $check->pages_fetched }} of {{ $check->pages_discovered }} addresses audited, {{ $check->critical_count }} critical, {{ $check->warning_count + $check->info_count }} other issue(s) @endif
                @if($rowChanges !== null) &middot; <span data-role="check-changes">{{ $rowChanges['new'] }} new, {{ $rowChanges['resolved'] }} resolved, {{ $rowChanges['unchanged'] }} unchanged</span>@endif
                @if($check->status === 'failed') &middot; {{ P::failure($check)['title'] }} @endif
                <span class="text-muted">&middot; {{ $check->host }}@if($earlierAddress) (earlier address)@endif &middot; {{ str_replace('_', ' ', $check->trigger) }}</span>
                @if($check->isEarlierFormat())<span class="text-muted">&middot; earlier audit format</span>@endif
            </p>
        @empty
            <p class="text-muted mb-0">No checks yet.</p>
        @endforelse
    </x-card>

    {{-- 4. Website management --}}
    <x-card :padded="true" class="mb-2" data-role="switch">
        <p class="text-label mb-1">Website management</p>
        <p class="text-muted">Stop monitoring, or build and host a website with MotionGrove instead. Your existing website is never touched, and your audit history is kept either way.</p>
        <div class="d-flex flex-wrap gap-1">
            <form method="POST" action="{{ route('customer.workspaces.businesses.website.mode.choose', [$workspaceUid, $businessUid]) }}">@csrf<input type="hidden" name="mode" value="hosted"><button class="btn btn-sm btn-outline-primary" type="submit" data-role="switch-hosted">Build a website with MotionGrove</button></form>
            <form method="POST" action="{{ route('customer.workspaces.businesses.website.external.stop', [$workspaceUid, $businessUid]) }}" onsubmit="return confirm('Stop checking this website? Your audit history is kept.');">@csrf<button class="btn btn-sm btn-flat-secondary" type="submit" data-role="stop-monitoring">Stop monitoring this website</button></form>
        </div>
    </x-card>
@endsection
