{{--
    Customer Experience Slice 1B — the View-as-client banner (contract §5.5
    "Banner", §17.2). Persistent and non-dismissible on every page while a
    view-as session is active: names the viewed client, the real actor,
    the expiry, and carries the Exit control. Announced to assistive
    technology through role="status".
--}}
@if(isset($customerContext) && $customerContext instanceof \App\Library\Navigation\CustomerContext && $customerContext->isViewingAsClient())
    @php
        $viewAs = $customerContext->viewAs;
        $now = \Carbon\CarbonImmutable::now();
        $endsAt = $viewAs->expiresAt->setTimezone(config('app.timezone', 'UTC'))->format('H:i');
    @endphp
    <div class="alert alert-warning customer-view-as-banner d-flex flex-wrap align-items-center gap-1 mb-2 p-1"
         role="status" aria-live="polite" data-role="view-as-banner">
        <x-ds-icon name="eye" size="18" aria-hidden="true" />
        <div class="flex-grow-1">
            <strong>Viewing {{ $viewAs->businessName }} as a client.</strong>
            You are still signed in as {{ $viewAs->actorDisplayName }}. Billing, funding, plan, staff, provider and delete actions are paused in this view.
            It ends automatically at {{ $endsAt }} ({{ $viewAs->minutesRemaining($now) }} min left).
        </div>
        <form method="POST" action="{{ route('customer.view-as.exit') }}" class="mb-0">
            @csrf
            <button type="submit" class="btn btn-sm btn-outline-secondary">Exit client view</button>
        </form>
    </div>
@endif
