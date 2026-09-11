{{--
    Customer Experience Slice 4 §4 band 4 — spend and account health, shown
    to the payer side only (BillingProfileManager::actorManagesPayerControls())
    and never while viewing as a client. Figures are the wallet's own, already
    formatted; nothing is recomputed here.
--}}
<section class="mb-2" aria-labelledby="dashboard-spend-heading" data-band="spend">
    <x-card>
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-1 mb-1">
            <h2 class="h4 text-section-heading mb-0" id="dashboard-spend-heading">Spend and billing</h2>
            @if($spend['billingUrl'])
                <x-button variant="outline" size="sm" :href="$spend['billingUrl']" data-role="billing-link">Open billing</x-button>
            @endif
        </div>
        @if(! $spend['configured'])
            <p class="mb-0">Usage billing is not set up for this business yet.</p>
        @else
            <dl class="row mb-0">
                <dt class="col-6 col-md-4 text-label">Billing status</dt>
                <dd class="col-6 col-md-8" data-role="spend-status">{{ $spend['status'] }}@if($spend['paused']) · Paid activity paused @endif</dd>

                <dt class="col-6 col-md-4 text-label">Available balance</dt>
                <dd class="col-6 col-md-8" data-role="spend-available">{{ $spend['available'] }}</dd>

                @if($spend['outstanding'] !== null)
                    <dt class="col-6 col-md-4 text-label">Outstanding balance</dt>
                    <dd class="col-6 col-md-8" data-role="spend-outstanding">{{ $spend['outstanding'] }}</dd>
                @endif

                <dt class="col-6 col-md-4 text-label">Spent this month</dt>
                <dd class="col-6 col-md-8" data-role="spend-period">{{ $spend['spentThisPeriod'] }}</dd>

                <dt class="col-6 col-md-4 text-label">Monthly spending limit</dt>
                <dd class="col-6 col-md-8" data-role="spend-limit">{{ $spend['monthlyLimit'] ?? 'No limit set' }}</dd>

                <dt class="col-6 col-md-4 text-label">Automatic top-up</dt>
                <dd class="col-6 col-md-8 mb-0" data-role="spend-auto-top-up">{{ $spend['autoTopUp'] ? 'On' : 'Off' }}</dd>
            </dl>
        @endif
    </x-card>
</section>
