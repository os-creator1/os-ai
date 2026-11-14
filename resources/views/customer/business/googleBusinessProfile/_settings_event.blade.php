{{-- Audit-ledger event: display only safely stored, Business-scoped fields. --}}
@php
    $type = $operation->operation_type->value;
    $state = $operation->status->value;
    $failed = in_array($state, ['failed', 'unknown', 'deferred'], true);
    $neutral = in_array($type, ['disconnected', 'location_unbound', 'mirror_purged'], true);
    $title = match ($type) {
        'mirror_refreshed' => 'Listing details refreshed',
        'mirror_purged' => 'Cached listing details cleared',
        'accounts_enumerated' => 'Google accounts found',
        'locations_enumerated' => 'Google listings found',
        'location_bound' => 'Listing linked',
        'location_unbound' => 'Listing unlinked',
        'connect_initiated' => 'Sign-in started',
        'connect_completed' => 'Connected to Google',
        'connect_failed' => 'Sign-in failed',
        'token_refreshed' => 'Google access renewed',
        'disconnected' => 'Disconnected',
        'metrics_synced' => 'Google metrics updated',
        default => ucfirst(str_replace('_', ' ', $type)),
    };
    $icon = match ($type) {
        'mirror_refreshed', 'token_refreshed' => 'refresh-cw',
        'locations_enumerated', 'accounts_enumerated' => 'search',
        'location_bound', 'location_unbound' => 'link-2',
        'connect_completed' => 'check',
        'disconnected' => 'x',
        'connect_failed' => 'alert-triangle',
        'connect_initiated' => 'clock',
        'metrics_synced' => 'chart-no-axes-column',
        default => 'activity',
    };
@endphp
<div class="gbps-event" data-role="gbp-operation">
    <span class="gbps-event-icon {{ $failed ? 'is-warn' : ($neutral ? 'is-neutral' : '') }}" aria-hidden="true"><x-ds-icon :name="$icon" :size="15" /></span>
    <div class="gbps-event-main">
        <strong>{{ $title }}</strong>
        @if($operation->summary)
            <span>{{ $operation->summary }}</span>
        @endif
        @if($failed)
            <span>{{ ucfirst($state) }}@if($operation->failure_classification) &middot; {{ str_replace('_', ' ', $operation->failure_classification) }}@endif</span>
        @endif
    </div>
    <time class="gbps-event-time" datetime="{{ $operation->created_at?->toIso8601String() }}">{{ $operation->created_at?->format('g:i A') ?? '—' }}</time>
</div>
