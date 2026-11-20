@extends('layouts/contentLayoutMaster')

@section('title', 'Outreach existing clients')
@section('page-heading', 'own')

@section('content')
@php
    $initials = fn ($n) => \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr(preg_replace('/[^\p{L}\p{N} ]/u', '', (string) $n) ?: '?', 0, 2));
    $formatPhone = function (?string $digits) {
        $d = preg_replace('/\D/', '', (string) $digits);
        return (strlen($d) === 11 && $d[0] === '1') ? sprintf('+1 (%s) %s-%s', substr($d, 1, 3), substr($d, 4, 3), substr($d, 7)) : ($d === '' ? '—' : '+' . $d);
    };
    $reasonLabel = ['manual' => 'Added by you', 'client_won' => 'Client won', 'csv_import' => 'Imported'];
    $codes = ['+1' => 'US/CA', '+44' => 'UK', '+61' => 'AU', '+64' => 'NZ', '+353' => 'IE', '+49' => 'DE', '+33' => 'FR', '+34' => 'ES', '+39' => 'IT', '+31' => 'NL', '+52' => 'MX', '+55' => 'BR', '+91' => 'IN'];
    $cols = 'minmax(0,1.8fr) 170px minmax(0,1.3fr) 110px 90px';
@endphp
<div class="og page">
    @include('customer.workspaces.prospecting._nav', ['prospectingActive' => 'prospects', 'ogInside' => true, 'ogActions' => 'clients', 'importUrl' => route('customer.workspaces.prospecting.prospects.import', $workspaceUid)])

    <div class="filters" style="justify-content:space-between">
        @include('customer.workspaces.prospecting._prospect-tabs', ['ptActive' => 'clients'])
    </div>

    <div class="ready ok" data-role="clients-protection" style="padding:18px 20px">
        <span class="bubble" style="width:44px;height:44px;border-radius:12px"><svg class="ic" viewBox="0 0 24 24" style="width:20px;height:20px" aria-hidden="true"><path d="M12 3l7 3v5c0 4.5-3 8-7 10-4-2-7-5.5-7-10V6l7-3z"/><path d="M9 12l2 2 4-4"/></svg></span>
        <div class="body"><b style="font-size:15px">Protected from outreach</b>
            <span class="mut">These numbers are skipped by every new and active campaign. Marking a prospect <b>Client won</b> adds them here automatically.</span></div>
    </div>

    <section class="card menu-card" aria-label="Existing clients">
        <form method="get" class="filters" style="padding:14px 16px" data-role="client-filters">
            <label class="search"><svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="6.5"/><path d="M16 16l4.5 4.5"/></svg>
                <input type="search" name="q" value="{{ $q }}" placeholder="Search clients or phone" aria-label="Search existing clients" autocomplete="off"></label>
            <label class="sel"><span>Added by</span>
                <select name="by" aria-label="Added by" onchange="this.form.submit()"><option value="anyone" @selected($by !== 'me')>Anyone</option><option value="me" @selected($by === 'me')>Me</option></select></label>
            <button class="sr" type="submit">Search</button>
        </form>

        @if($rows->count() === 0)
            <div class="empty" data-role="clients-empty"><b>{{ $q !== '' ? 'No clients match that search' : 'No existing clients yet' }}</b>Add a client, mark a prospect as Client won, or import a client list.</div>
        @else
            <div data-role="clients-table">
                <div class="row h" style="grid-template-columns:{{ $cols }}"><span>Business</span><span>Phone</span><span>How they got here</span><span>Added</span><span></span></div>
                @foreach($rows as $row)
                    <div class="row" style="grid-template-columns:{{ $cols }}" data-client="{{ $row->uid }}">
                        <div class="who"><span class="av g" aria-hidden="true">{{ $initials($row->business_name) }}</span><div><b>{{ $row->business_name ?: 'Unnamed business' }}</b></div></div>
                        <span style="font-variant-numeric:tabular-nums">{{ $formatPhone($row->phone) }}</span>
                        <span class="ell mut">{{ $row->source_label ?: ($reasonLabel[$row->reason] ?? $row->reason) }}</span>
                        <span class="mut">{{ $row->created_at?->format('M j') }}</span>
                        <div class="acts">
                            @if($clientsService->mayRemove($workspace, $row, $userId))
                                <details class="menu"><summary class="ib" aria-label="Actions for {{ $row->business_name ?: 'client' }}"><svg class="ic" viewBox="0 0 24 24"><circle cx="5" cy="12" r="1.3"/><circle cx="12" cy="12" r="1.3"/><circle cx="19" cy="12" r="1.3"/></svg></summary>
                                    <div class="pop" role="menu"><form method="post" action="{{ route('customer.workspaces.prospecting.prospects.clients.remove', [$workspaceUid, $row->uid]) }}"
                                          onsubmit="return confirm('Remove this existing client? Outreach may text this number again, unless they replied STOP.')">@csrf
                                        <button type="submit" role="menuitem">Remove from clients</button></form></div></details>
                            @else
                                <span class="ib" title="Only the workspace owner, or whoever added this client, can remove it." aria-label="Locked"><svg class="ic" viewBox="0 0 24 24"><rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 018 0v3"/></svg></span>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
            <div class="pager">{{ $rows->links() }}</div>
        @endif
    </section>

    <div class="notes">
        @if(count($reviewHints) > 0)
            <div class="note warn" data-role="similar-name-review">
                <svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16v.5"/></svg>
                <span><b>{{ count($reviewHints) }} look similar:</b>
                    @foreach($reviewHints as $h)“{{ $h['prospect'] }}” is like client “{{ $h['client'] }}” but has a different phone number. @endforeach
                    Review — similar names are never blocked automatically.</span>
            </div>
        @endif
        <div class="note" data-role="stop-note">
            <svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 018 0v3"/></svg>
            <span>People who replied <b>STOP</b> live on the Excluded list. Removing a client here never makes an opted-out number textable again.</span>
        </div>
    </div>

    <dialog class="og-dialog" id="og-add-client" aria-labelledby="og-add-client-title" data-reopen="{{ $errors->any() ? '1' : '0' }}">
        <form method="post" action="{{ route('customer.workspaces.prospecting.prospects.clients.store', $workspaceUid) }}" class="sheet" novalidate>
            @csrf
            <div class="top"><h2 id="og-add-client-title">Add existing client</h2><span>Outreach will never prospect this number.</span></div>
            <div class="mid">
                <div class="fl"><label for="client_name">Business name</label>
                    <input type="text" id="client_name" name="business_name" class="in @error('business_name') bad @enderror" value="{{ old('business_name') }}" autofocus>
                    @error('business_name')<span class="err" role="alert">{{ $message }}</span>@enderror</div>
                <div class="fl"><label for="client_phone">Phone</label>
                    <div class="phone"><select name="country_code" aria-label="Country code">@foreach($codes as $code => $label)<option value="{{ $code }}" @selected(old('country_code', '+1') === $code)>{{ $code }}</option>@endforeach</select>
                        <input type="tel" id="client_phone" name="phone" class="in @error('phone') bad @enderror" value="{{ old('phone') }}" inputmode="tel" placeholder="(555) 123-4567"></div>
                    @error('phone')<span class="err" role="alert">{{ $message }}</span>@enderror</div>
            </div>
            <div class="foot"><button type="button" class="btn" data-og-close>Cancel</button><button type="submit" class="btn pri">Add client</button></div>
        </form>
    </dialog>
</div>
@endsection

@section('page-script')
<script>
(function () { var d = document.getElementById('og-add-client'); if (d && d.dataset.reopen === '1' && d.showModal) { d.showModal(); } })();
</script>
@endsection
