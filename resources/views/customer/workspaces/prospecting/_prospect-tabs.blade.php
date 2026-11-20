@php
    $ptActive = $ptActive ?? 'all';
    $ptTabs = [
        'all' => ['All prospects', route('customer.workspaces.prospecting.prospects.index', $workspaceUid), $counts['all'] ?? null],
        'clients' => ['Existing clients', route('customer.workspaces.prospecting.prospects.clients', $workspaceUid), $counts['clients'] ?? null],
        'excluded' => ['Excluded', route('customer.workspaces.prospecting.prospects.excluded', $workspaceUid), $counts['excluded'] ?? null],
    ];
@endphp
<div class="seg" role="tablist" aria-label="Prospect lists" data-role="prospect-subtabs">
    @foreach($ptTabs as $key => [$label, $url, $n])
        <a href="{{ $url }}" role="tab" @if($ptActive === $key) class="on" aria-selected="true" aria-current="page" @else aria-selected="false" @endif>{{ $label }}@if($n !== null) <span class="mut" style="margin-left:6px">{{ $n }}</span>@endif</a>
    @endforeach
</div>
