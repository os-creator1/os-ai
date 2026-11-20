@php
    $f = $filters;
    $opt = fn ($v, $cur) => $v == $cur ? 'selected' : '';
    $action = $action ?? route('customer.workspaces.prospecting.analytics.index', $workspaceUid);
    $hidden = $hidden ?? [];
@endphp
<form method="get" action="{{ $action }}" class="afilters" data-role="analytics-filters" aria-label="Analytics filters" data-autosubmit>
    @foreach($hidden as $hk => $hv)<input type="hidden" name="{{ $hk }}" value="{{ $hv }}">@endforeach
    <div class="filters">
        <label class="sel"><span>Campaign</span><select name="campaign"><option value="">All</option>@foreach($options['campaigns'] as $c)<option value="{{ $c->uid }}" {{ $opt($c->uid, $f->campaignUid) }}>{{ $c->name }}</option>@endforeach</select></label>
        <label class="sel"><span>Period</span><select name="period" aria-label="Period">@foreach(\App\Library\AgencyOutreach\Analytics\AnalyticsFilters::PERIODS as $k => $l)<option value="{{ $k }}" {{ $opt($k, $f->period) }}>{{ $l }}</option>@endforeach<option value="custom" {{ $opt('custom', $f->period) }}>Custom…</option></select></label>
        <label class="sel"><span>Business type</span><select name="type"><option value="">All</option>@foreach($options['types'] as $t)<option value="{{ $t }}" {{ $opt($t, $f->businessType) }}>{{ $t }}</option>@endforeach</select></label>
        <label class="sel"><span>Message</span><select name="step"><option value="">All</option>@foreach($options['steps'] ?? [] as $s)<option value="{{ $s['key'] }}" {{ $opt($s['key'], $f->stepKey) }}>{{ $s['label'] }}</option>@endforeach</select></label>
    </div>
    <details class="more afilters-more" @if($f->geo || $f->from || $f->to || $f->rating || $f->reviews || $f->website || $f->batchUid || $f->outcome || $f->channelId || $f->scriptVersion) open @endif>
        <summary class="btn out sm"><svg class="ic" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg>More filters</summary>
        <div class="filters card pad" style="margin-top:8px">
            <label class="sel"><span>From</span><input type="date" name="from" value="{{ $f->from }}" aria-label="From date"></label>
            <label class="sel"><span>To</span><input type="date" name="to" value="{{ $f->to }}" aria-label="To date"></label>
            <label class="sel"><span>State</span><select name="geo"><option value="">Any</option>@foreach($options['geos'] as $g)<option value="{{ $g }}" {{ $opt($g, $f->geo) }}>{{ $g }}</option>@endforeach</select></label>
            <label class="sel"><span>Rating</span><select name="rating"><option value="">Any</option>@foreach(array_keys(\App\Library\AgencyOutreach\Analytics\AnalyticsFilters::RATING_BANDS) as $k)<option value="{{ $k }}" {{ $opt($k, $f->rating) }}>★ {{ $k }}</option>@endforeach</select></label>
            <label class="sel"><span>Reviews</span><select name="reviews"><option value="">Any</option>@foreach(array_keys(\App\Library\AgencyOutreach\Analytics\AnalyticsFilters::REVIEW_BANDS) as $k)<option value="{{ $k }}" {{ $opt($k, $f->reviews) }}>{{ $k }}</option>@endforeach</select></label>
            <label class="sel"><span>Website</span><select name="website"><option value="">Any</option><option value="yes" {{ $opt('yes', $f->website) }}>Has website</option><option value="no" {{ $opt('no', $f->website) }}>No website</option><option value="unknown" {{ $opt('unknown', $f->website) }}>Unknown</option></select></label>
            <label class="sel"><span>Source</span><select name="batch"><option value="">Any</option>@foreach($options['batches'] as $b)<option value="{{ $b->uid }}" {{ $opt($b->uid, $f->batchUid) }}>{{ $b->filename }}</option>@endforeach</select></label>
            <label class="sel"><span>Outcome</span><select name="outcome"><option value="">Any</option>@foreach(['no_response' => 'No response', 'replied' => 'Replied', 'interested' => 'Interested', 'rejected' => 'Rejected', 'opted_out' => 'Opted out', 'booked' => 'Call booked', 'won' => 'Client won'] as $k => $l)<option value="{{ $k }}" {{ $opt($k, $f->outcome) }}>{{ $l }}</option>@endforeach</select></label>
            <label class="sel"><span>Sending number</span><select name="number"><option value="">Any</option>@foreach($options['channels'] as $ch)<option value="{{ $ch->id }}" {{ $opt($ch->id, $f->channelId) }}>{{ $ch->sender_number }}</option>@endforeach</select></label>
            <label class="sel"><span>Script version</span><select name="script"><option value="">Any</option>@foreach($options['versions'] as $v)<option value="{{ $v }}" {{ $opt($v, $f->scriptVersion) }}>v{{ $v }}</option>@endforeach</select></label>
            <button type="submit" class="btn pri sm">Apply filters</button>
        </div>
    </details>
    <div class="filters" style="margin-top:8px">
        <button type="submit" class="sr">Apply filters</button>
        @if($f->active())<a class="btn out sm" href="{{ $action }}@if(! empty($hidden)){{ '?' . http_build_query($hidden) }}@endif" data-role="reset-filters">Reset filters</a>@endif
        @if(! empty($showExport))<span class="grow"></span><a class="btn out sm" data-role="export-csv-inline" href="{{ route('customer.workspaces.prospecting.analytics.export', $workspaceUid) }}?{{ http_build_query($f->toQuery()) }}">Export CSV</a>@endif
    </div>
</form>
<script>
(function () {
    document.querySelectorAll('form[data-autosubmit]').forEach(function (f) {
        f.querySelectorAll(':scope > .filters select').forEach(function (el) { el.addEventListener('change', function () { f.submit(); }); });
    });
})();
</script>
