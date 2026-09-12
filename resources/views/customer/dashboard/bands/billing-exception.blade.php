{{--
    Unified Business Home §2.2 row 0 / §5 (H-1) — billing on the Business Home
    is ONE compact strip, and only when the customer has something to do about
    it: what is wrong, what it means for the business, and the one place to fix
    it. Balance, spend, top-ups and invoices live in Settings → Billing.
--}}
<section class="mb-2" aria-labelledby="dashboard-billing-exception-heading" data-band="billing_exception">
    <x-alert :variant="$exception->severity->badgeVariant() === 'danger' ? 'danger' : 'warning'" class="mb-0">
        <div class="d-flex flex-column flex-md-row align-items-md-center gap-1"
             data-role="billing-exception" data-attention-type="{{ $exception->type->value }}" data-severity="{{ $exception->severity->value }}">
            <x-badge :variant="$exception->severity->badgeVariant()" data-role="billing-exception-severity">{{ $exception->severity->word() }}</x-badge>
            <div class="flex-grow-1">
                <h2 class="h6 text-label mb-25" id="dashboard-billing-exception-heading">Needs your attention</h2>
                <p class="mb-0" data-role="billing-exception-text">{{ $exception->text }}</p>
            </div>
            <x-button variant="outline" size="sm" :href="$exception->url" data-role="billing-exception-action">{{ $exception->actionLabel }}</x-button>
        </div>
    </x-alert>
</section>
