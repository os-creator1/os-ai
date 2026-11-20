@extends('layouts/contentLayoutMaster')

@section('title', 'Outreach campaigns')

@section('content')
@php
    $bucket = fn ($c) => match ($c->status->value) { 'active', 'paused' => 'active', 'draft' => 'draft', default => 'ended' };
    $counts = ['all' => $campaigns->count(), 'active' => $campaigns->filter(fn ($c) => $bucket($c) === 'active')->count(), 'draft' => $campaigns->filter(fn ($c) => $bucket($c) === 'draft')->count(), 'ended' => $campaigns->filter(fn ($c) => $bucket($c) === 'ended')->count()];
    $tone = fn ($v) => ['active' => 'g', 'paused' => 'a'][$v] ?? 'n';
    $cols = 'minmax(120px,1.6fr) 96px 84px 70px 56px 72px 40px';
    $mode = old('group_mode', $contactGroups->isEmpty() ? 'new' : 'existing');
    $drawerOpen = request()->boolean('new') || $errors->any() || $campaigns->isEmpty();
@endphp
<div class="og page">
    @include('customer.workspaces.prospecting._nav', ['prospectingActive' => 'campaigns', 'ogInside' => true, 'ogActions' => 'campaign'])

    @if($pausedForFunds)
        <div class="alertbar" data-role="paused-for-funds">
            <span>Paused — add funds. Some texts are waiting for your balance.</span>
            <form method="post" action="{{ route('customer.workspaces.prospecting.sending.resume', $workspaceUid) }}">
                @csrf
                <button type="submit" class="btn pri sm">Resume sending</button>
            </form>
        </div>
    @endif

    <div class="grid2">
        <section class="card" style="flex:3 1 640px;min-width:0" aria-label="Campaigns">
            <div class="filters" style="padding:14px 16px">
                <div class="seg" role="group" aria-label="Filter campaigns" data-role="campaign-filters">
                    <button type="button" class="on" data-filter="all" aria-pressed="true">All {{ $counts['all'] }}</button>
                    <button type="button" data-filter="active" aria-pressed="false">Active {{ $counts['active'] }}</button>
                    <button type="button" data-filter="draft" aria-pressed="false">Drafts {{ $counts['draft'] }}</button>
                    <button type="button" data-filter="ended" aria-pressed="false">Ended {{ $counts['ended'] }}</button>
                </div>
                <span class="grow"></span>
                @if($sendingNumber)<span class="mut">All from {{ $sendingNumber }}</span>@endif
            </div>

            @if($campaigns->isEmpty())
                <div class="empty" data-role="campaigns-empty"><b>No campaigns yet</b>Create a draft campaign to start texting a group of prospects.</div>
            @else
                <div data-role="campaigns-table">
                    <div class="row h" style="grid-template-columns:{{ $cols }}"><span>Campaign</span><span>Status</span><span>Prospects</span><span>Replies</span><span>Calls</span><span>Created</span><span></span></div>
                    @foreach($campaigns as $campaign)
                        @php
                            $summary = $summaries[$campaign->id];
                            $st = $campaign->status->value;
                            $show = route('customer.workspaces.prospecting.campaigns.show', [$workspaceUid, $campaign->uid]);
                        @endphp
                        <div class="row rsp" style="grid-template-columns:{{ $cols }}" data-campaign="{{ $campaign->uid }}" data-bucket="{{ $bucket($campaign) }}">
                            <a href="{{ $show }}" class="ell" title="{{ $campaign->name }}"><b style="font-weight:600">{{ $campaign->name }}</b>@unless($campaign->isManaged())<span class="mut" style="display:block">Own channel</span>@endunless</a>
                            <span style="display:flex;flex-direction:column;gap:4px;align-items:flex-start">
                                <span class="pl {{ $tone($st) }}">{{ ucfirst($st) }}</span>
                                @if($summary['paused_for_funds'])<span class="pl a">Paused — add funds</span>@endif
                            </span>
                            <span>{{ $summary['prospects'] }}</span>
                            <span>{{ $summary['replies'] }}</span>
                            <span>{{ $summary['booked'] }}</span>
                            <span class="mut">{{ $campaign->created_at?->format('M j') }}</span>
                            <details class="menu">
                                <summary class="ib" aria-label="Actions for {{ $campaign->name }}"><svg class="ic" viewBox="0 0 24 24"><circle cx="5" cy="12" r="1.3"/><circle cx="12" cy="12" r="1.3"/><circle cx="19" cy="12" r="1.3"/></svg></summary>
                                <div class="pop" role="menu">
                                    <a role="menuitem" href="{{ $show }}">{{ $st === 'draft' ? 'Open draft' : 'Open campaign' }}</a>
                                    @if(in_array($st, ['active', 'paused'], true))
                                        <form method="post" action="{{ route('customer.workspaces.prospecting.campaigns.status', [$workspaceUid, $campaign->uid]) }}">
                                            @csrf
                                            <input type="hidden" name="status" value="{{ $st === 'active' ? 'paused' : 'active' }}">
                                            <button role="menuitem" type="submit">{{ $st === 'active' ? 'Pause campaign' : 'Resume campaign' }}</button>
                                        </form>
                                    @endif
                                    @if($campaign->isManaged() && in_array($st, ['active', 'paused'], true))
                                        <form method="post" action="{{ route('customer.workspaces.prospecting.campaigns.end', [$workspaceUid, $campaign->uid]) }}"
                                              onsubmit="return confirm('Permanently end this campaign? No more automatic messages will be sent, and its recipients can be used in another campaign. Message history is preserved. This cannot be undone.');">
                                            @csrf
                                            <button role="menuitem" type="submit">End campaign</button>
                                        </form>
                                    @endif
                                </div>
                            </details>
                        </div>
                    @endforeach
                    <div class="empty" id="og-camp-none" hidden>No campaigns in this view.</div>
                </div>
            @endif
            <div class="pager">
                Campaigns created before Outreach use your own connected channel.
                <a class="link" href="{{ route('customer.workspaces.prospecting.channels.index', $workspaceUid) }}">Manage connected channels</a>
            </div>
        </section>

        {{-- New campaign panel --}}
        <section class="card drawer" id="og-new-campaign" aria-labelledby="og-nc-title" @unless($drawerOpen) hidden @endunless>
            <form method="post" action="{{ route('customer.workspaces.prospecting.campaigns.managed.store', $workspaceUid) }}" style="display:flex;flex-direction:column;gap:14px" id="og-nc-form">
                @csrf
                <div style="display:flex;align-items:center">
                    <b id="og-nc-title" style="flex:1;font-size:16px">New campaign</b>
                    <button type="button" class="ib" style="border:0" aria-label="Close" data-og-close="og-new-campaign"><svg class="ic" viewBox="0 0 24 24"><path d="M6 6l12 12M18 6L6 18"/></svg></button>
                </div>
                <div class="fl">
                    <label for="name">Name</label>
                    <input type="text" id="name" name="name" class="in @error('name') bad @enderror" placeholder="e.g. Dog boarding — Denver" value="{{ old('name') }}" maxlength="255">
                    @error('name')<span class="err" role="alert">{{ $message }}</span>@enderror
                </div>
                <div class="fl">
                    <label for="opening_message">First text</label>
                    <textarea id="opening_message" name="opening_message" class="in @error('opening_message') bad @enderror" rows="4">{{ old('opening_message', $defaultOpener) }}</textarea>
                    @error('opening_message')<span class="err" role="alert">{{ $message }}</span>@enderror
                    <span class="hint">From Message 1 of your script. Edit it just for this campaign.</span>
                </div>

                <fieldset class="fl" data-role="recipients-section" style="border:0;padding:0;margin:0">
                    <legend class="lab" style="margin-bottom:6px">Send to</legend>
                    @if(! $groupBusinessReady)
                        <div class="alertbar">Your Outreach business is not set up yet, so contact groups are unavailable.</div>
                    @endif
                    <div class="seg" style="align-self:flex-start">
                        <label><input type="radio" name="group_mode" id="group_mode_existing" value="existing" @checked($mode === 'existing')><span>Existing group</span></label>
                        <label><input type="radio" name="group_mode" id="group_mode_new" value="new" @checked($mode === 'new')><span>New group</span></label>
                    </div>
                    @error('group_mode')<span class="err" role="alert">{{ $message }}</span>@enderror

                    <div data-role="group-existing" @if($mode !== 'existing') hidden @endif>
                        <div class="picker" id="contact_group_uid" data-summary-url="{{ route('customer.workspaces.prospecting.recipient-groups.summary', $workspaceUid) }}">
                            <input type="search" class="in" data-role="group-search" placeholder="Search and pick a group" aria-label="Search groups" autocomplete="off">
                            <div class="opts" role="radiogroup" aria-label="Contact groups">
                                @forelse($contactGroups as $group)
                                    <label data-name="{{ \Illuminate\Support\Str::lower($group->name) }}">
                                        <input type="radio" name="contact_group_uid" value="{{ $group->uid }}" @checked(old('contact_group_uid') === $group->uid)>
                                        <b>{{ $group->name }}</b><span>{{ number_format($group->subscribers_count) }} {{ \Illuminate\Support\Str::plural('contact', $group->subscribers_count) }}</span>
                                    </label>
                                @empty
                                    <div class="mut" style="padding:12px">No contact groups yet — choose “New group”.</div>
                                @endforelse
                            </div>
                        </div>
                        @error('contact_group_uid')<span class="err" role="alert">{{ $message }}</span>@enderror
                    </div>

                    <div data-role="group-new" @if($mode !== 'new') hidden @endif>
                        <input type="text" name="new_group_name" id="new_group_name" class="in @error('new_group_name') bad @enderror" value="{{ old('new_group_name') }}" placeholder="New group name" maxlength="255" aria-label="New group name">
                        @error('new_group_name')<span class="err" role="alert">{{ $message }}</span>@enderror
                        <span class="hint">Creates an empty group in Contacts. Add contacts to it before you start the campaign.</span>
                    </div>
                    <div class="rsum" data-role="recipient-summary" hidden aria-live="polite"></div>
                </fieldset>

                <details class="more" @if(old('context')) open @endif>
                    <summary>Notes (only you see these)<svg class="ic" viewBox="0 0 24 24" style="width:14px;height:14px"><path d="M7 10l5 5 5-5"/></svg></summary>
                    <textarea id="context" name="context" class="in" rows="2">{{ old('context') }}</textarea>
                </details>

                <div style="display:flex;gap:10px;flex-wrap:wrap">
                    <button type="submit" name="intent" value="draft" class="btn out">Save draft</button>
                    <button type="submit" name="intent" value="start" class="btn pri">Start campaign</button>
                </div>
                <span class="hint">Nothing is sent from here. “Start campaign” saves the draft and opens it so you can check recipients and readiness before it begins.</span>
            </form>
        </section>
    </div>
</div>
@endsection

@section('page-script')
<script>
(function () {
    // List filters (All / Active / Drafts / Ended)
    var btns = document.querySelectorAll('[data-role="campaign-filters"] [data-filter]');
    var rows = document.querySelectorAll('[data-campaign]');
    var none = document.getElementById('og-camp-none');
    btns.forEach(function (b) {
        b.addEventListener('click', function () {
            var f = b.dataset.filter, shown = 0;
            btns.forEach(function (x) { var on = x === b; x.classList.toggle('on', on); x.setAttribute('aria-pressed', on ? 'true' : 'false'); });
            rows.forEach(function (r) { var ok = f === 'all' || r.dataset.bucket === f; r.hidden = !ok; if (ok) { shown++; } });
            if (none) { none.hidden = shown !== 0; }
        });
    });

    // Recipient picker: search, live summary (real counts via the existing summary endpoint)
    var picker = document.getElementById('contact_group_uid');
    if (!picker) { return; }
    var root = document.querySelector('[data-role="recipients-section"]');
    var existing = root.querySelector('[data-role="group-existing"]');
    var created = root.querySelector('[data-role="group-new"]');
    var summary = root.querySelector('[data-role="recipient-summary"]');
    var search = root.querySelector('[data-role="group-search"]');
    var token = 0;
    function esc(t) { var d = document.createElement('div'); d.textContent = t; return d.innerHTML; }
    function mode() { var m = root.querySelector('input[name="group_mode"]:checked'); return m ? m.value : 'existing'; }
    function chosen() { var c = picker.querySelector('input[name="contact_group_uid"]:checked'); return c ? c.value : ''; }
    function sync() {
        var m = mode();
        existing.hidden = m !== 'existing'; created.hidden = m !== 'new';
        if (m === 'new') {
            summary.hidden = false;
            summary.innerHTML = '<b>0</b> contacts in group (new, empty)<br><span class="mut">Add contacts to it before starting.</span>';
        } else { load(); }
    }
    function load() {
        var uid = chosen();
        if (!uid) { summary.hidden = true; return; }
        var my = ++token;
        summary.hidden = false; summary.textContent = 'Calculating…';
        fetch(picker.dataset.summaryUrl + '?group=' + encodeURIComponent(uid), {headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'}, credentials: 'same-origin'})
            .then(function (r) { if (!r.ok) { throw new Error(); } return r.json(); })
            .then(function (d) {
                if (my !== token) { return; }
                if (d.too_large) { summary.innerHTML = '<span class="err">This group is too large for one campaign. Split it into smaller groups.</span>'; return; }
                var why = d.reasons.map(function (x) { return '<li>' + esc(x.label) + ': ' + x.count + '</li>'; }).join('');
                summary.innerHTML = '<b>' + esc(d.name) + '</b><ul>'
                    + '<li data-role="summary-total">' + d.group_total + ' contacts in group</li>'
                    + '<li data-role="summary-eligible">' + d.eligible + ' eligible</li>'
                    + '<li data-role="summary-excluded">' + d.excluded + ' excluded</li></ul>'
                    + (why ? '<ul class="mut">' + why + '</ul>' : '');
            })
            .catch(function () { if (my === token) { summary.textContent = 'Could not calculate recipients.'; } });
    }
    root.querySelectorAll('input[name="group_mode"]').forEach(function (r) { r.addEventListener('change', sync); });
    picker.addEventListener('change', function (e) { if (e.target.name === 'contact_group_uid') { load(); } });
    search.addEventListener('input', function () {
        var q = search.value.trim().toLowerCase();
        picker.querySelectorAll('label[data-name]').forEach(function (l) { l.hidden = q !== '' && l.dataset.name.indexOf(q) === -1; });
    });
    sync();

    // "New campaign" header button opens the panel on this page.
    document.addEventListener('click', function (e) {
        var o = e.target.closest('[data-og-open="og-new-campaign"]');
        if (o) { var p = document.getElementById('og-new-campaign'); p.hidden = false; p.scrollIntoView({block: 'nearest'}); var n = document.getElementById('name'); if (n) { n.focus(); } }
    });
})();
</script>
@endsection
