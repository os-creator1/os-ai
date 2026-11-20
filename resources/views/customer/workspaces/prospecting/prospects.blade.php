@extends('layouts/contentLayoutMaster')

@section('title', 'Prospects')

@section('content')
@php
    $stageTone = fn ($label) => match ($label) {
        'Booked' => 'g',
        'Call asked' => 'a',
        'Booking link sent', 'Scheduling' => 'b',
        default => 'n',
    };
    $initials = fn ($p) => \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr(preg_replace('/[^\p{L}\p{N} ]/u', '', (string) ($p->contact_name ?: $p->company_name)) ?: '?', 0, 2));
    $campaignNames = collect($rows)->pluck('campaign')->filter(fn ($c) => $c !== '—')->unique()->sort()->values();
    $stageNames = collect($rows)->map(fn ($r) => $r['stage'] !== '—' ? $r['stage'] : $r['status']['label'])->unique()->sort()->values();
    $formatPhone = function (?string $digits) {
        $d = preg_replace('/\D/', '', (string) $digits);
        return (strlen($d) === 11 && $d[0] === '1')
            ? sprintf('+1 (%s) %s-%s', substr($d, 1, 3), substr($d, 4, 3), substr($d, 7))
            : ($d === '' ? '—' : '+' . $d);
    };
    $codes = ['+1' => 'US/CA', '+44' => 'UK', '+61' => 'AU', '+64' => 'NZ', '+353' => 'IE', '+49' => 'DE', '+33' => 'FR', '+34' => 'ES', '+39' => 'IT', '+31' => 'NL', '+370' => 'LT', '+52' => 'MX', '+55' => 'BR', '+91' => 'IN'];
    $cols = 'minmax(0,1.7fr) 150px minmax(0,1fr) 120px minmax(0,1.6fr) 40px';
@endphp
<div class="og page">
    @include('customer.workspaces.prospecting._nav', ['prospectingActive' => 'prospects', 'ogInside' => true, 'ogActions' => 'prospects'])

    <section class="card" aria-label="Prospects">
        <div class="filters" style="padding:14px 16px" data-role="prospect-filters">
            <label class="search"><svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="6.5"/><path d="M16 16l4.5 4.5"/></svg>
                <input type="search" id="og-q" placeholder="Search name, company or phone" aria-label="Search prospects" autocomplete="off"></label>
            <label class="sel"><span>Campaign</span>
                <select id="og-f-campaign" aria-label="Filter by campaign"><option value="">All</option>
                    @foreach($campaignNames as $c)<option value="{{ $c }}">{{ $c }}</option>@endforeach</select></label>
            <label class="sel"><span>Stage</span>
                <select id="og-f-stage" aria-label="Filter by stage"><option value="">Any</option>
                    @foreach($stageNames as $s)<option value="{{ $s }}">{{ $s }}</option>@endforeach</select></label>
        </div>

        @if($rows === [])
            <div class="empty" data-role="prospects-empty"><b>No prospects yet</b>Add a prospect to get started{{ ! empty($importUrl) ? ', or import contacts into a group and text the group from a campaign' : '' }}.</div>
        @else
            <div data-role="prospects-table">
                <div class="row h" style="grid-template-columns:{{ $cols }}" role="row"><span>Prospect</span><span>Phone</span><span>Campaign</span><span>Stage</span><span>Last message</span><span></span></div>
                @foreach($rows as $row)
                    @php
                        $prospect = $row['prospect'];
                        $stage = $row['stage'] !== '—' ? $row['stage'] : $row['status']['label'];
                        $show = route('customer.workspaces.prospecting.prospects.show', [$workspaceUid, $prospect->uid]);
                        $active = $prospect->status->value === 'active';
                    @endphp
                    <div class="row rsp" style="grid-template-columns:{{ $cols }}" data-prospect="{{ $prospect->uid }}"
                         data-campaign="{{ $row['campaign'] }}" data-stage="{{ $stage }}"
                         data-search="{{ \Illuminate\Support\Str::lower($prospect->contact_name . ' ' . $prospect->company_name . ' ' . $prospect->phone . ' ' . $formatPhone($prospect->phone)) }}">
                        <div class="who">
                            <span class="av" aria-hidden="true">{{ $initials($prospect) }}</span>
                            <div>
                                <a href="{{ $show }}"><b>{{ $prospect->contact_name ?: $prospect->company_name }}</b></a>
                                <span class="mut ell">{{ $prospect->company_name }}@if($prospect->source) · {{ $prospect->source }}@endif</span>
                            </div>
                        </div>
                        <span style="font-variant-numeric:tabular-nums">{{ $formatPhone($prospect->phone) }}</span>
                        <span style="color:var(--og-neutral)" class="ell">{{ $row['campaign'] }}</span>
                        <span class="pl {{ $stageTone($stage) }}" data-role="prospect-status" title="Status: {{ $row['status']['label'] }}">{{ $stage }}</span>
                        <span class="ell mut" style="font-size:13px">{{ $row['last_message'] !== '—' ? '“' . $row['last_message'] . '”' : '—' }}</span>
                        <details class="menu">
                            <summary class="ib" aria-label="Actions for {{ $prospect->contact_name ?: $prospect->company_name }}"><svg class="ic" viewBox="0 0 24 24"><circle cx="5" cy="12" r="1.3"/><circle cx="12" cy="12" r="1.3"/><circle cx="19" cy="12" r="1.3"/></svg></summary>
                            <div class="pop" role="menu">
                                <a role="menuitem" href="{{ $show }}">Open</a>
                                @if($active)
                                    <form method="post" action="{{ route('customer.workspaces.prospecting.prospects.mark-booked', [$workspaceUid, $prospect->uid]) }}">@csrf<button role="menuitem" type="submit">Mark booked</button></form>
                                    <form method="post" action="{{ route('customer.workspaces.prospecting.prospects.stop', [$workspaceUid, $prospect->uid]) }}" onsubmit="return confirm('Stop texting this prospect? They will not be texted again.');">@csrf<button role="menuitem" type="submit">Stop texting</button></form>
                                @endif
                            </div>
                        </details>
                    </div>
                @endforeach
                <div class="empty" id="og-none" hidden>No prospects match these filters.</div>
            </div>
            <div class="pager" id="og-count" data-role="prospect-count">{{ count($rows) }} {{ \Illuminate\Support\Str::plural('prospect', count($rows)) }}</div>
        @endif
    </section>

    {{-- Add prospect: only the phone is shown as the key field; company is required by the existing backend. --}}
    <dialog class="modal" id="og-add-prospect" aria-labelledby="og-add-title" data-reopen="{{ $errors->any() ? '1' : '0' }}">
        <form method="post" action="{{ route('customer.workspaces.prospecting.prospects.store', $workspaceUid) }}" class="sheet" novalidate>
            @csrf
            <div class="top"><h2 id="og-add-title">Add prospect</h2><span>Phone and company are required.</span></div>
            <div class="mid">
                <div class="fl">
                    <label for="phone">Phone</label>
                    <div class="phone">
                        <select name="country_code" aria-label="Country code">
                            @foreach($codes as $code => $label)<option value="{{ $code }}" @selected(old('country_code', '+1') === $code)>{{ $code }} {{ $label }}</option>@endforeach
                        </select>
                        <input type="tel" id="phone" name="phone" class="in @error('phone') bad @enderror" placeholder="(555) 123-4567" value="{{ old('phone') }}" inputmode="tel" autocomplete="off" @error('phone') aria-invalid="true" aria-describedby="phone-err" @enderror>
                    </div>
                    @error('phone')<span class="err" id="phone-err" role="alert">{{ $message }}</span>@enderror
                    @error('country_code')<span class="err" role="alert">{{ $message }}</span>@enderror
                </div>
                <div class="split">
                    <div class="fl">
                        <label for="company_name">Company</label>
                        <input type="text" id="company_name" name="company_name" class="in @error('company_name') bad @enderror" placeholder="e.g. Happy Paws Boarding" value="{{ old('company_name') }}">
                        @error('company_name')<span class="err" role="alert">{{ $message }}</span>@enderror
                    </div>
                    <div class="fl">
                        <label for="contact_name">Contact name</label>
                        <input type="text" id="contact_name" name="contact_name" class="in" value="{{ old('contact_name') }}">
                    </div>
                </div>
                <details class="more" @if($errors->has('email') || $errors->has('website') || $errors->has('source') || $errors->has('location')) open @endif>
                    <summary>More details — email, website, source, location<svg class="ic" viewBox="0 0 24 24" style="width:14px;height:14px"><path d="M7 10l5 5 5-5"/></svg></summary>
                    <div class="split" style="margin-top:6px">
                        <div class="fl"><label for="email">Email</label><input type="email" id="email" name="email" class="in @error('email') bad @enderror" value="{{ old('email') }}">@error('email')<span class="err">{{ $message }}</span>@enderror</div>
                        <div class="fl"><label for="website">Website</label><input type="text" id="website" name="website" class="in" value="{{ old('website') }}"></div>
                    </div>
                    <div class="split" style="margin-top:12px">
                        <div class="fl"><label for="source">Source</label><input type="text" id="source" name="source" class="in" value="{{ old('source') }}"></div>
                        <div class="fl"><label for="location">Location</label><input type="text" id="location" name="location" class="in" value="{{ old('location') }}"></div>
                    </div>
                </details>
            </div>
            <div class="foot">
                <button type="button" class="btn" data-og-close>Cancel</button>
                <button type="submit" class="btn pri">Add prospect</button>
            </div>
        </form>
    </dialog>
</div>
@endsection

@section('page-script')
<script>
(function () {
    var dlg = document.getElementById('og-add-prospect');
    if (dlg && dlg.dataset.reopen === '1' && dlg.showModal) { dlg.showModal(); }

    var q = document.getElementById('og-q'), fc = document.getElementById('og-f-campaign'), fs = document.getElementById('og-f-stage');
    if (!q) { return; }
    var rows = Array.prototype.slice.call(document.querySelectorAll('[data-prospect]'));
    var none = document.getElementById('og-none'), count = document.getElementById('og-count');
    function apply() {
        var term = q.value.trim().toLowerCase(), shown = 0;
        rows.forEach(function (r) {
            var ok = (!term || r.dataset.search.indexOf(term) !== -1)
                && (!fc.value || r.dataset.campaign === fc.value)
                && (!fs.value || r.dataset.stage === fs.value);
            r.hidden = !ok; if (ok) { shown++; }
        });
        if (none) { none.hidden = shown !== 0; }
        if (count) { count.textContent = shown + (shown === 1 ? ' prospect' : ' prospects'); }
    }
    [q, fc, fs].forEach(function (el) { el.addEventListener('input', apply); el.addEventListener('change', apply); });
})();
</script>
@endsection
