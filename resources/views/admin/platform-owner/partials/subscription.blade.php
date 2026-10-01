{{--
    Platform Owner / Admin V1 — the persisted lane-A PlatformSubscription,
    shown BESIDE the access decision. Entitlement is never inferred from it;
    "grants access" is PlatformSubscriptionStatus::grantsAccess(). No provider
    call is made to render this. Expects $support.
--}}
@php
    /** @var \App\Models\PlatformSubscription|null $subscription */
    $subscription = $support['subscription'];
@endphp

<x-card title="Platform subscription">
    @if ($subscription === null)
        <p class="mb-0 text-muted" data-testid="po-subscription-none">No platform subscription is recorded for this Workspace (it may be complimentary, assigned by an administrator, or not yet subscribed).</p>
    @else
        <dl class="row mb-0" data-testid="po-subscription">
            <dt class="col-sm-3">Provider status</dt>
            <dd class="col-sm-9">{{ $subscription->status?->value ?? '—' }}</dd>

            <dt class="col-sm-3">Grants access</dt>
            <dd class="col-sm-9" data-testid="po-subscription-grants">
                @if ($subscription->status?->grantsAccess())
                    <x-badge variant="success">Yes</x-badge>
                @else
                    <x-badge variant="danger">No</x-badge>
                @endif
            </dd>

            <dt class="col-sm-3">Tier</dt>
            <dd class="col-sm-9">{{ $subscription->catalog?->display_name ?? '—' }}</dd>

            <dt class="col-sm-3">Provider customer</dt>
            <dd class="col-sm-9"><code>{{ $subscription->provider_customer_id ?? '—' }}</code></dd>

            <dt class="col-sm-3">Provider subscription</dt>
            <dd class="col-sm-9"><code>{{ $subscription->provider_subscription_id ?? '—' }}</code></dd>

            <dt class="col-sm-3">Current period</dt>
            <dd class="col-sm-9">{{ $subscription->current_period_start?->toDateTimeString() ?? '—' }} → {{ $subscription->current_period_end?->toDateTimeString() ?? '—' }}</dd>

            <dt class="col-sm-3">Trial ends</dt>
            <dd class="col-sm-9">{{ $subscription->trial_ends_at?->toDateTimeString() ?? '—' }}</dd>

            <dt class="col-sm-3">Cancellation</dt>
            <dd class="col-sm-9">
                @if ($subscription->cancel_at_period_end) Cancels at period end · @endif
                Canceled: {{ $subscription->canceled_at?->toDateTimeString() ?? '—' }}
                · Ended: {{ $subscription->ended_at?->toDateTimeString() ?? '—' }}
            </dd>

            <dt class="col-sm-3">Last provider event</dt>
            <dd class="col-sm-9">{{ $subscription->last_event_at?->toDateTimeString() ?? '—' }} @if ($subscription->last_reason) ({{ $subscription->last_reason }}) @endif</dd>

            <dt class="col-sm-3">Last updated</dt>
            <dd class="col-sm-9">{{ $subscription->updated_at?->toDateTimeString() ?? '—' }}</dd>
        </dl>
        <p class="text-muted mt-2 mb-0">Read from the stored record; Stripe is not called. The plan assignment, not this status, decides access.</p>
    @endif
</x-card>
