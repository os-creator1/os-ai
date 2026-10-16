{{--
    The one "How online payments work" panel for the Stripe Connect page. It is
    rendered for every connection state (not connected, onboarding, restricted,
    ready) so the posture copy is never duplicated or allowed to drift.

    Each line states a fact the backend enforces: payments settle in the
    Business's own connected account (StripeConnectManager creates it, no
    platform balance is involved), the Business is the merchant of record, and
    a connection is always a NEW account — an existing one cannot be linked.
    Lane B has no relationship with MotionGrove subscription billing.
--}}
@props(['business'])

<section class="sr-card pc-how" data-role="posture-note" aria-labelledby="pc-how-title">
    <div class="sr-card__body">
        <h2 class="pc-how__title" id="pc-how-title">How online payments work</h2>
        <ul class="pc-how__grid">
            <li>
                <span class="sr-tile sr-tile--neutral" aria-hidden="true"><x-ds-icon name="arrow-right" size="14" /></span>
                <p>Money goes <strong>straight to <span class="pc-wrap">{{ $business->name }}</span>'s Stripe account</strong>. MotionGrove never holds it.</p>
            </li>
            <li>
                <span class="sr-tile sr-tile--neutral" aria-hidden="true"><x-ds-icon name="file-text" size="14" /></span>
                <p><span class="pc-wrap">{{ $business->name }}</span> is the <strong>merchant of record</strong> and pays Stripe's fees.</p>
            </li>
            <li>
                <span class="sr-tile sr-tile--neutral" aria-hidden="true"><x-ds-icon name="plus" size="14" /></span>
                <p>Connecting <strong>creates a new Stripe account</strong>. An existing one can't be linked here.</p>
            </li>
            <li>
                <span class="sr-tile sr-tile--neutral" aria-hidden="true"><x-ds-icon name="credit-card" size="14" /></span>
                <p>Online payments are <strong>separate from your MotionGrove subscription billing</strong>.</p>
            </li>
        </ul>
    </div>
</section>
