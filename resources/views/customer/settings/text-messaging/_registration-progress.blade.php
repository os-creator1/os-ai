{{--
    The two persistent registration stages, derived only from saved carrier
    state (RegistrationProgress). Inputs: $progress, $workspaceUid, $businessUid.
--}}
@php
    $variants = ['verified' => 'success', 'approved' => 'success', 'processing' => 'info', 'unconfirmed' => 'info', 'overdue' => 'warning', 'under_review' => 'info', 'ready' => 'info', 'needs_attention' => 'danger', 'issue' => 'warning', 'not_started' => 'neutral'];
    $stages = [['title' => 'Business verification', 'role' => 'brand', 'stage' => $progress['brand']], ['title' => 'Messaging campaign', 'role' => 'campaign', 'stage' => $progress['campaign']]];
@endphp
<x-card :padded="true" class="mb-2" data-role="registration-progress">
    <div class="progress mb-2" style="height: 6px;" role="progressbar" aria-valuenow="{{ $progress['percent'] }}" aria-valuemin="0" aria-valuemax="100" aria-label="Registration progress">
        <div class="progress-bar" style="width: {{ $progress['percent'] }}%"></div>
    </div>
    @foreach($stages as $i => $item)
        <div class="mb-2" data-role="stage-{{ $item['role'] }}" data-state="{{ $item['stage']['key'] }}">
            <div class="d-flex flex-wrap align-items-center gap-1">
                <strong>{{ $i + 1 }}. {{ $item['title'] }}</strong>
                <x-badge variant="{{ $variants[$item['stage']['key']] ?? 'neutral' }}">{{ $item['stage']['label'] }}</x-badge>
            </div>
            <p class="text-caption text-muted mb-0">{{ $item['stage']['detail'] }}@if($item['role'] === 'brand' && $progress['tcr_brand_id']) <span data-role="tcr-brand-id">(Carrier brand ID {{ $progress['tcr_brand_id'] }})</span>@endif</p>
        </div>
    @endforeach
    <div class="d-flex flex-wrap align-items-center gap-2">
        <form method="post" action="{{ route('customer.workspaces.businesses.text-messaging.registration.refresh', [$workspaceUid, $businessUid]) }}" class="m-0">
            @csrf
            <x-button type="submit" variant="secondary" data-role="refresh-status">Refresh status</x-button>
        </form>
        <span class="text-caption text-muted" data-role="last-checked">
            @if($progress['last_checked_at'])
                Last carrier status check: {{ $progress['last_checked_at']->diffForHumans() }}
            @else
                Not checked with the carrier yet. We check periodically, and you can refresh at any time.
            @endif
        </span>
    </div>
</x-card>
