@php
    $prospectingActive = $prospectingActive ?? null;
    $ogInside = $ogInside ?? false;
    $ogActions = $ogActions ?? null; // 'campaign' | 'prospects' | null
    $needsPhoneSetup = false;
    if ($prospectingActive !== 'phone-messaging') {
        $navWorkspace = \App\Models\Workspace::query()->where('uid', $workspaceUid)->first();
        $needsPhoneSetup = $navWorkspace !== null && app(\App\Library\AgencyOutreach\PhoneSetupHint::class)->needed($navWorkspace);
    }
    $ogTabs = [
        'overview' => ['Overview', route('customer.workspaces.prospecting.overview', $workspaceUid)],
        'prospects' => ['Prospects', route('customer.workspaces.prospecting.prospects.index', $workspaceUid)],
        'conversations' => ['Conversations', route('customer.workspaces.prospecting.conversations.index', $workspaceUid)],
        'campaigns' => ['Campaigns', route('customer.workspaces.prospecting.campaigns.index', $workspaceUid)],
        'analytics' => ['Analytics', route('customer.workspaces.prospecting.analytics.index', $workspaceUid)],
        'script' => ['Script', route('customer.workspaces.prospecting.script.show', $workspaceUid)],
        'phone-messaging' => ['Phone & texting', route('customer.workspaces.prospecting.phone-messaging', $workspaceUid)],
    ];
@endphp

@once
    <link rel="stylesheet" href="{{ asset('css/outreach/outreach.css') }}?v=4">
@endonce

@unless($ogInside)<div class="og hd">@endunless

<div class="head" data-role="outreach-header">
    <div>
        <h4 class="mb-0">Outreach</h4>
        <p>Find customers, automate conversations and measure results.</p>
    </div>
    @if($ogActions === 'campaign')
        <a class="btn pri" data-role="new-campaign" href="{{ route('customer.workspaces.prospecting.campaigns.index', $workspaceUid) }}?new=1"
           @if($prospectingActive === 'campaigns') data-og-open="og-new-campaign" @endif>
            <svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>New campaign
        </a>
    @elseif($ogActions === 'analytics')
        @if(! empty($exportUrl))
            <a class="btn out" data-role="export-csv" href="{{ $exportUrl }}">
                <svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 4v11M7 10l5 5 5-5M5 20h14"/></svg>Export
            </a>
        @endif
    @elseif($ogActions === 'clients')
        @if(! empty($importUrl))
            <a class="btn out" data-role="import-prospects" href="{{ $importUrl }}">
                <svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 20V9M7 14l5-5 5 5M5 4h14"/></svg>Import CSV
            </a>
        @endif
        <button type="button" class="btn pri" data-role="add-client-open" data-og-open="og-add-client">
            <svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>Add client
        </button>
    @elseif($ogActions === 'prospects')
        @if(! empty($importUrl))
            <a class="btn out" data-role="import-prospects" href="{{ $importUrl }}">
                <svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 20V9M7 14l5-5 5 5M5 4h14"/></svg>Import CSV
            </a>
        @endif
        <button type="button" class="btn pri" data-role="add-prospect-open" data-og-open="og-add-prospect">
            <svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>Add prospect
        </button>
    @endif
</div>

<nav class="tabs" data-role="outreach-tabs" aria-label="Outreach sections">
    @foreach($ogTabs as $key => [$label, $url])
        <a class="tab @if($prospectingActive === $key) on @endif" href="{{ $url }}" @if($prospectingActive === $key) aria-current="page" @endif>{{ $label }}</a>
    @endforeach
</nav>

@if($needsPhoneSetup)
    <div class="alertbar" data-role="outreach-phone-setup">
        <span>Texting isn't set up yet. Connect your own Telnyx account or get a number through us.</span>
        <a class="btn pri sm" href="{{ route('customer.workspaces.prospecting.phone-messaging', $workspaceUid) }}">Set up phone</a>
    </div>
@endif

{{-- Flash messages (flash_success / flash_error) are surfaced by the global PageToasts layer. --}}

@unless($ogInside)</div>@endunless

@once
<script>
(function () {
    // Open/close native <dialog> or toggle a drawer by id: [data-og-open="id"] / [data-og-close].
    document.addEventListener('click', function (e) {
        var opener = e.target.closest('[data-og-open]');
        if (opener) {
            var el = document.getElementById(opener.getAttribute('data-og-open'));
            if (!el) { return; }
            e.preventDefault();
            if (el.showModal) { el.showModal(); } else { el.hidden = false; }
            var f = el.querySelector('[autofocus], input:not([type=hidden]), textarea, select');
            if (f) { f.focus(); }
            return;
        }
        var closer = e.target.closest('[data-og-close]');
        if (closer) {
            var t = closer.closest('dialog') || document.getElementById(closer.getAttribute('data-og-close'));
            if (t) { e.preventDefault(); if (t.close) { t.close(); } else { t.hidden = true; } }
        }
    });
    // Close row menus when clicking elsewhere.
    document.addEventListener('click', function (e) {
        document.querySelectorAll('details.menu[open]').forEach(function (d) { if (!d.contains(e.target)) { d.open = false; } });
    });
})();
</script>
@endonce
