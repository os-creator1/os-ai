@extends('layouts/contentLayoutMaster')

@section('title', 'Outreach import review')
@section('page-heading', 'own')

@section('content')
@php
    $isClients = $batch->kind === 'existing_clients';
    $outcomes = $summary['outcomes'] ?? [];
    $issues = $summary['issues'] ?? [];
    $headers = (array) $batch->headers;
    $mapping = (array) $batch->mapping;
    $detail = (array) ($summary['detail'] ?? []);
    $imported = $batch->status === 'imported';
    $issueText = [
        'missing_phone' => ['rows have no phone', 'They cannot be texted, so they are skipped.'],
        'invalid_phone' => ['phone numbers are not valid', 'Skipped. Check the country or fix the file.'],
        'needs_region' => ['numbers have no country code', 'Choose a country below to confirm how to read them.'],
        'social_as_website' => ['"websites" are social pages', 'Saved as a social link, not a website.'],
        'address_in_hours' => ['rows have an address in the hours column', 'We moved it to Address.'],
        'invalid_rating' => ['ratings are outside 0–5', 'Rating left blank for those rows.'],
        'invalid_review_count' => ['review counts are not numbers', 'Left blank for those rows.'],
        'ambiguous_location' => ['rows have an unclear city / state', 'Kept as the full address only.'],
        'similar_client' => ['look like an existing client', 'Different phone — review, never blocked automatically.'],
        'missing_name' => ['rows have no business name', 'The phone number is used as the name.'],
    ];
    $fieldOptions = \App\Library\AgencyOutreach\Import\ProspectFieldMapper::FIELDS;
@endphp
<div class="og page">
    @include('customer.workspaces.prospecting._nav', ['prospectingActive' => 'prospects', 'ogInside' => true])

    <section class="card imp" data-role="import-review">
        <div class="imp-head"><h2>{{ $isClients ? 'Import existing clients' : 'Import prospects' }}</h2>@include('customer.workspaces.prospecting._import-stepper')</div>

        <div class="imp-file">
            <span class="csvb" aria-hidden="true">CSV</span>
            <div class="grow"><b>{{ $batch->filename ?? 'CSV file' }}</b>
                <div class="mut">{{ $summary['rows'] ?? 0 }} rows @if(! empty($summary['truncated'])) · only the first 5,000 rows were read @endif</div></div>
            <div class="imp-stats" data-role="import-counts">
                <div><b class="t-g">{{ $summary['ready'] ?? 0 }}</b><span>ready</span></div>
                <div><b class="t-a">{{ $outcomes['duplicate'] ?? 0 }}</b><span>duplicates</span></div>
                <div><b class="t-r">{{ ($outcomes['missing_phone'] ?? 0) + ($outcomes['invalid_phone'] ?? 0) }}</b><span>no usable phone</span></div>
                <div><b>{{ ($outcomes['excluded'] ?? 0) + ($outcomes['stopped'] ?? 0) }}</b><span>protected</span></div>
            </div>
        </div>

        @if($imported)
            <div class="flash" role="status" style="margin:16px 24px">This file has been imported. Nothing further can be changed.</div>
        @endif

        <form method="post" action="{{ route('customer.workspaces.prospecting.prospects.import.map', [$workspaceUid, $batch->uid]) }}" class="imp-body">
            @csrf
            <div class="imp-map">
                <div class="imp-mh"><div><h3>We matched {{ count(array_filter($mapping)) }} of {{ count($headers) }} columns</h3>
                    <p class="mut">Change any match if it's wrong. Original values are kept.</p></div>
                    @if(count(array_filter($mapping)) === count($headers))<span class="pl g">✓ Auto-matched</span>@endif</div>
                <div class="row h imp-cols" role="row"><span>Your column</span><span></span><span>Saved as</span><span>Example</span><span></span></div>
                @foreach($headers as $i => $header)
                    @php $field = $mapping[$header] ?? null; $cfgx = $batch->extra_fields[$header] ?? null; $via = $detail[$header]['via'] ?? 'none'; $example = collect($samples)->pluck($header)->filter(fn ($v) => $v !== null && $v !== '')->first(); @endphp
                    @php $flag = $via === 'duplicate' || ($field === 'website' && ! empty($summary['issues']['social_as_website'])) || ($field === 'opening_hours' && ! empty($summary['issues']['address_in_hours'])); @endphp
                    <div class="row imp-cols" data-column="{{ $i }}">
                        <code class="ell" title="{{ $header }}">{{ $header }}</code>
                        <svg class="ic arrow" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
                        <select name="mapping[{{ $i }}]" class="in" style="height:40px;font-size:14px" aria-label="Field for column {{ $header }}" @disabled($imported)>
                            <option value="">Ignore this column</option>
                            @foreach($fieldOptions as $key => $label)<option value="{{ $key }}" @selected($field === $key)>{{ $label }}</option>@endforeach
                            @if(! $isClients && $business !== null)
                                @php $cfg = $batch->extra_fields[$header] ?? null; @endphp
                                <optgroup label="Custom fields">
                                    <option value="cf_new" @selected(($cfg['action'] ?? null) === 'new')>Create custom field “{{ \Illuminate\Support\Str::limit($header, 40) }}”</option>
                                    @foreach($definitions as $def)<option value="cf:{{ $def->uid }}" @selected(($cfg['action'] ?? null) === 'existing' && ($cfg['definition_uid'] ?? null) === $def->uid)>Use “{{ $def->label }}”</option>@endforeach
                                </optgroup>
                            @endif
                        </select>
                        <span class="mut ell" title="{{ $example }}">{{ $example ?? '—' }}@if($via === 'duplicate') <span class="pl a">Duplicate match</span>@elseif($via === 'content') <span class="pl b">Detected from values</span>@endif</span>
                        @php $off = $field === null && $cfgx === null; @endphp
                        <span class="ck {{ $off ? 'off' : ($flag ? 'warn' : 'ok') }}" title="{{ $off ? 'Ignored' : ($flag ? 'Worth a look' : 'Matched') }}" aria-label="{{ $off ? 'Ignored' : ($flag ? 'Worth a look' : 'Matched') }}">{{ $off ? '–' : ($flag ? '!' : '✓') }}</span>
                    </div>
                @endforeach
                <div class="filters" style="padding:14px 20px">
                    <label class="sel"><span>Numbers without a country code are in</span>
                        <select name="region" aria-label="Country for numbers without a country code" @disabled($imported)>
                            <option value="">— not set (those rows are held back) —</option>
                            @foreach($regions as $code => $label)<option value="{{ $code }}" @selected($batch->region === $code)>{{ $label }}</option>@endforeach
                        </select></label>
                    @unless($imported)<button class="btn out sm" type="submit" data-role="apply-mapping">Apply changes</button>@endunless
                </div>
            </div>

            <aside class="imp-look" data-role="import-issues">
                <h3>Worth a look</h3>
                @forelse($issues as $code => $info)
                    <div class="qr"><span class="qi {{ in_array($code, ['missing_phone', 'invalid_phone', 'needs_region', 'invalid_rating', 'invalid_review_count'], true) ? 'r' : 'a' }}" aria-hidden="true">!</span>
                        <span class="qt"><b>{{ $info['count'] }} {{ $issueText[$code][0] ?? $code }}</b><small>{{ $issueText[$code][1] ?? '' }} Rows: {{ implode(', ', $info['rows'] ?? []) }}{{ ($info['count'] ?? 0) > count($info['rows'] ?? []) ? '…' : '' }}</small></span></div>
                @empty
                    <p class="mut">No issues found.</p>
                @endforelse
                <div class="qr chk"><span class="qi ok" aria-hidden="true">✓</span><span class="qt"><small>Checked against your existing clients and the STOP list — {{ ($outcomes['excluded'] ?? 0) + ($outcomes['stopped'] ?? 0) }} matches ({{ ($outcomes['excluded'] ?? 0) }} client, {{ ($outcomes['stopped'] ?? 0) }} STOP), skipped.</small></span></div>
                <div class="filters" role="group" aria-label="Show rows">
                    @foreach(array_merge(['' => 'All'], \App\Library\AgencyOutreach\Import\ProspectImporter::OUTCOME_LABELS) as $key => $label)
                        @if($key === '' || ($outcomes[$key] ?? 0) > 0)
                            <a class="pl {{ $outcome === $key ? 'r' : 'n' }}" href="{{ route('customer.workspaces.prospecting.prospects.import.review', [$workspaceUid, $batch->uid]) }}{{ $key !== '' ? '?outcome=' . $key : '' }}">{{ $label }}@if($key !== '') {{ $outcomes[$key] }}@endif</a>
                        @endif
                    @endforeach
                </div>
            </aside>
        </form>

        <div class="imp-rows" data-role="import-rows">
            <div class="row h" style="grid-template-columns:60px minmax(0,1.4fr) 150px 140px minmax(0,1.2fr)"><span>Row</span><span>Business</span><span>Phone</span><span>Result</span><span>Notes</span></div>
            @foreach($rows as $r)
                <div class="row" style="grid-template-columns:60px minmax(0,1.4fr) 150px 140px minmax(0,1.2fr)">
                    <span class="mut">{{ $r->row_number }}</span>
                    <span class="ell">{{ $r->normalized['business_name'] ?? '—' }}</span>
                    <span style="font-variant-numeric:tabular-nums">{{ $r->phone ? '+' . $r->phone : '—' }}</span>
                    <span class="pl {{ in_array($r->outcome, ['new', 'existing'], true) ? 'g' : (in_array($r->outcome, ['excluded', 'stopped'], true) ? 'r' : 'a') }}">{{ \App\Library\AgencyOutreach\Import\ProspectImporter::OUTCOME_LABELS[$r->outcome] ?? $r->outcome }}</span>
                    <span class="mut ell">{{ collect($r->issues)->map(fn ($i) => $issueText[$i][0] ?? $i)->implode('; ') }}</span>
                </div>
            @endforeach
            @if($rows->isEmpty())<div class="empty">No rows in this view.</div>@endif
            <div class="pager">Showing the first {{ $rows->count() }} rows of this view. All original rows stay stored for diagnostics.</div>
        </div>

        @unless($imported)
        @if(! $isClients && count($fieldPlan) > 0)
            <div class="imp-plan" data-role="field-plan">
                <h3>Custom fields on each contact</h3>
                <p class="mut">Saved as real Custom Fields you can see and edit in the Contact profile. Existing fields are reused; nothing existing is overwritten.</p>
                @foreach($fieldPlan as $f)
                    <div class="qr"><span class="pl {{ $f['action'] === 'create' ? 'b' : ($f['action'] === 'reuse' ? 'g' : 'a') }}">{{ ['create' => 'Will create', 'reuse' => 'Will reuse', 'archived' => 'Archived — skipped', 'conflict' => 'Conflict — skipped', 'limit' => 'Limit — skipped'][$f['action']] }}</span>
                        <span><b>{{ $f['label'] }}</b> <small class="mut">{{ str_replace('_', ' ', $f['type']) }} · {{ $f['source'] }}</small>@if($f['note'])<br><small class="mut">{{ $f['note'] }}</small>@endif</span></div>
                @endforeach
            </div>
        @endif
        <form method="post" action="{{ route('customer.workspaces.prospecting.prospects.import.commit', [$workspaceUid, $batch->uid]) }}" class="foot imp-foot" data-role="import-commit">
            @csrf
            @if(! $isClients)
                <div class="fl" style="flex:1 1 320px">
                    <label for="contact_group_uid">Save prospects to</label>
                    <select class="in" id="contact_group_uid" name="contact_group_uid">
                        <option value="">Create a new contact group</option>
                        @foreach($groups as $uid => $name)
                            <option value="{{ $uid }}" @selected(old('contact_group_uid') === $uid)>{{ $name }}</option>
                        @endforeach
                    </select>
                    @error('contact_group_uid')<span class="err" role="alert">{{ $message }}</span>@enderror
                    <div id="new-group-name">
                        <label for="group_name">New contact group name</label>
                        <input type="text" id="group_name" name="group_name" class="in @error('group_name') bad @enderror" maxlength="255" value="{{ old('group_name', $defaultGroupName) }}" data-role="group-name">
                        @error('group_name')<span class="err" role="alert">{{ $message }}</span>@enderror
                    </div>
                    <span class="hint"><b>{{ $summary['ready'] ?? 0 }}</b> prospects will be saved to this one group, ready to pick for a campaign. Nothing is sent.</span>
                </div>
            @else
                <span class="imp-dest"><b>{{ $summary['ready'] ?? 0 }}</b> clients will be added</span>
            @endif
            <span class="grow"></span>
            <a class="btn" href="{{ route('customer.workspaces.prospecting.prospects.index', $workspaceUid) }}">Back</a>
            <button type="submit" class="btn pri" @disabled(($summary['ready'] ?? 0) === 0 && ! $isClients) data-role="import-confirm">
                {{ $isClients ? 'Import ' . ($summary['ready'] ?? 0) . ' clients' : 'Import ' . ($summary['ready'] ?? 0) . ' prospects' }}</button>
        </form>
        @endunless
    </section>
</div>
@endsection

@section('page-script')
<script>
    const destination = document.getElementById('contact_group_uid');
    if (destination) {
        const newGroup = document.getElementById('new-group-name');
        const updateDestination = () => {
            newGroup.hidden = destination.value !== '';
            document.getElementById('group_name').required = !newGroup.hidden;
        };
        destination.addEventListener('change', updateDestination);
        updateDestination();
    }
</script>
@endsection
