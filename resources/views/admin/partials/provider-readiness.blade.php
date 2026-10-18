{{-- Provider readiness chips. Expects $readiness (list from PlatformProviderReadiness::all()). Never prints a value. --}}
<ul class="list-group list-group-flush" data-testid="provider-readiness">
    @foreach ($readiness as $p)
        <li class="list-group-item d-flex justify-content-between align-items-start" data-provider="{{ $p['key'] }}" data-state="{{ $p['state'] }}">
            <div>
                <strong>{{ $p['name'] }}</strong>
                <div class="small text-muted">{{ $p['purpose'] }}@if ($p['detail']) &middot; {{ $p['detail'] }}@endif</div>
            </div>
            <span class="badge badge-light-{{ ['not_configured' => 'secondary', 'connected' => 'success', 'attention' => 'warning'][$p['state']] }}">{{ $p['label'] }}</span>
        </li>
    @endforeach
</ul>
