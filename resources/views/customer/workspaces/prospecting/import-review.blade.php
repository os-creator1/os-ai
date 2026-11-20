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
            <div><b>{{ $batch->filename ?? 'CSV file' }}</b>
                <div class="mut">{{ $summary['rows'] ?? 0 }} rows @if(! empty($summary['truncated'])) · only the first 5,000 rows were read @endif</div></div>
            <div class="kpis" data-role="import-counts">
                <div class="k5"><b>{{ $summary['ready'] ?? 0 }}</b><span>ready</span></div>
                <div class="k5"><b>{{ $outcomes['duplicate'] ?? 0 }}</b><span>duplicates</span></div>
                <div class="k5"><b>{{ ($outcomes['missing_phone'] ?? 0) + ($outcomes['invalid_phone'] ?? 0) }}</b><span>no usable phone</span></div>
                <div class="k5"><b>{{ ($outcomes['excluded'] ?? 0) + ($outcomes['stopped'] ?? 0) }}</b><span>protected</span></div>
            </div>
        </div>

        @if($imported)
            <div class="flash" role="status" style="margin:16px 24px">This file has been imported. Nothing further can be changed.</div>
        @endif

        <form method="post" action="{{ route('customer.workspaces.prospecting.prospects.import.map', [$workspaceUid, $batch->uid]) }}" class="imp-body">
            @csrf
            <div class="imp-map">
                <h3>Your columns → saved as</h3>
                <p class="mut">We matched {{ count(array_filter($mapping)) }} of {{ count($headers) }} columns automatically. Change any match if it is wrong. Original values are always kept.</p>
                <div class="row h" style="grid-template-columns:minmax(0,1fr) 220px minmax(0,1fr)" role="row"><span>Your column</span><span>Saved as</span><span>Example</span></div>
                @foreach($headers as $i => $header)
                    @php $field = $mapping[$header] ?? null; $via = $detail[$header]['via'] ?? 'none'; $example = collect($samples)->pluck($header)->filter(fn ($v) => $v !== null && $v !== '')->first(); @endphp
                    <div class="row" style="grid-template-columns:minmax(0,1fr) 220px minmax(0,1fr)" data-column="{{ $i }}">
                        <code class="ell" title="{{ $header }}">{{ $header }}</code>
                        <select name="mapping[{{ $i }}]" class="in" style="height:38px;font-size:13.5px" aria-label="Field for column {{ $header }}" @disabled($imported)>
                            <option value="">Ignore this column</option>
                            @foreach($fieldOptions as $key => $label)<option value="{{ $key }}" @selected($field === $key)>{{ $label }}</option>@endforeach
                        </select>
                        <span class="mut ell" title="{{ $example }}">{{ $example ?? '—' }}@if($via === 'duplicate') <span class="pl a">Duplicate match</span>@elseif($via === 'content') <span class="pl b">Detected from values</span>@endif</span>
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
                    <div class="qr"><span><b>{{ $info['count'] }}</b> {{ $issueText[$code][0] ?? $code }}<small>{{ $issueText[$code][1] ?? '' }} Rows: {{ implode(', ', $info['rows'] ?? []) }}{{ ($info['count'] ?? 0) > count($info['rows'] ?? []) ? '…' : '' }}</small></span></div>
                @empty
                    <p class="mut">No issues found.</p>
                @endforelse
                <div class="qr"><span>Checked against your existing clients and the STOP list<small>{{ ($outcomes['excluded'] ?? 0) }} existing-client and {{ ($outcomes['stopped'] ?? 0) }} STOP matches are skipped.</small></span></div>
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
        <form method="post" action="{{ route('customer.workspaces.prospecting.prospects.import.commit', [$workspaceUid, $batch->uid]) }}" class="foot imp-foot" data-role="import-commit">
            @csrf
            @if(! $isClients)
                <label class="sel"><span>Add to</span>
                    <select name="campaign_uid" aria-label="Destination">
                        <option value="">Prospect list only — no campaign</option>
                        @foreach($draftCampaigns as $c)<option value="{{ $c->uid }}">Draft campaign · {{ $c->name }}</option>@endforeach
                    </select></label>
                <span class="mut" title="Contact groups are filled from Contacts; Outreach reads them through the recipient bridge.">Contact groups: use Contacts import (unchanged).</span>
            @endif
            <span class="grow"></span>
            <a class="btn" href="{{ route('customer.workspaces.prospecting.prospects.index', $workspaceUid) }}">Cancel</a>
            <button type="submit" class="btn pri" @disabled(($summary['ready'] ?? 0) === 0 && ! $isClients) data-role="import-confirm">
                {{ $isClients ? 'Import ' . ($summary['ready'] ?? 0) . ' clients' : 'Import ' . ($summary['ready'] ?? 0) . ' prospects' }}</button>
        </form>
        @endunless
    </section>
</div>
@endsection
