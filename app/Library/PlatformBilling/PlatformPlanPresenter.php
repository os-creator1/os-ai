<?php

namespace App\Library\PlatformBilling;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Entitlement\PlatformFeatureCopy;
use App\Models\Currency;
use App\Models\WorkspacePlanCatalog;
use App\Repositories\Contracts\WorkspacePlanFeatureRepository;

/**
 * Implementation Contract 21 §7/§11/§13 — the ONE read model for "what are we
 * selling, and on what terms", shared by the signup page, the Platform Owner's
 * commercial surface and the customer's Plan & subscription page.
 *
 * IT READS THE V1 CATALOG AND NOTHING ELSE. Legacy `Plan` rows are never
 * consulted (§4): a tier's display name, price, currency, billing cycle, trial
 * policy, sellability and feature packaging all come from
 * `workspace_plan_catalog` and `workspace_plan_features`, which are the V1
 * authority. Three surfaces reading one presenter is what stops them drifting
 * into three different answers about what a plan costs.
 *
 * NO PRICES, TRIAL LENGTHS OR CAPABILITY LISTS ARE HARD-CODED HERE (§8). Every
 * value is configuration the Platform Owner sets; this class only shapes it.
 */
final class PlatformPlanPresenter
{
    public function __construct(
        private readonly WorkspacePlanFeatureRepository $planFeatures,
        private readonly PlatformStripeGateway $gateway,
    ) {
    }

    /**
     * §7 — the tiers a NEW customer may buy right now: active, offered for
     * signup, priced, and bound to a provider Price. A tier missing any of
     * those is simply not shown, rather than shown and then refused at
     * checkout.
     *
     * @return array<int, array<string, mixed>>
     */
    public function sellablePlans(): array
    {
        return array_values(array_filter(
            $this->allPlans(),
            static fn (array $plan): bool => $plan['sellable'],
        ));
    }

    /**
     * Every tier, sellable or not, with the reasons it is not — the Platform
     * Owner's view (§11), which must show what is wrong rather than silently
     * hiding a tier the owner thinks they are selling.
     *
     * @return array<int, array<string, mixed>>
     */
    public function allPlans(): array
    {
        $currencies = Currency::query()->pluck('code', 'id');
        $plans = [];

        foreach (WorkspacePlanTier::cases() as $tier) {
            $catalog = WorkspacePlanCatalog::query()->where('tier', $tier->value)->first();

            if ($catalog === null) {
                continue;
            }

            $plans[] = $this->present($catalog, $currencies);
        }

        return $plans;
    }

    public function presentTier(WorkspacePlanTier $tier): ?array
    {
        $catalog = WorkspacePlanCatalog::query()->where('tier', $tier->value)->first();

        return $catalog === null ? null : $this->present($catalog, Currency::query()->pluck('code', 'id'));
    }

    /**
     * §11 — whether lane A can accept payments at all, as booleans and a mode
     * word. It never returns a secret, a prefix or a length (§5.1).
     *
     * @return array{configured: bool, webhook_configured: bool, mode: string, webhook_url: string}
     */
    public function providerStatus(): array
    {
        return [...$this->gateway->configurationStatus(), 'webhook_url' => url('/stripe/webhook/platform-subscriptions')];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, string>  $currencies
     * @return array<string, mixed>
     */
    private function present(WorkspacePlanCatalog $catalog, $currencies): array
    {
        $featureKeys = $this->planFeatures->featureKeysForCatalog($catalog)->all();
        $trialDays = $catalog->configuredTrialDays();

        return [
            'id' => (int) $catalog->id,
            'tier' => $catalog->tier,
            'tier_value' => $catalog->tier->value,
            'display_name' => (string) $catalog->display_name,
            'price' => $catalog->price,
            'currency_id' => $catalog->currency_id,
            'currency_code' => $catalog->currency_id === null ? null : ($currencies[$catalog->currency_id] ?? null),
            'billing_cycle' => (string) $catalog->billing_cycle,
            'trial_enabled' => (bool) $catalog->trial_enabled,
            'trial_days' => $trialDays,
            'available_for_signup' => (bool) $catalog->available_for_signup,
            'is_active' => (bool) $catalog->is_active,
            // The Price ID is a PUBLIC provider object identifier, not a
            // secret, so the owner may see and set it (§11). It is never shown
            // to a customer.
            'provider_price_id' => $catalog->provider_price_id,
            'sellable' => $catalog->isSellable(),
            'blockers' => $this->blockers($catalog),
            // §7 — a meaningful capability summary from the V1 packaging, not
            // a hand-written marketing list that could drift from entitlements.
            'capabilities' => PlatformFeatureCopy::names($featureKeys),
            'business_slots_included' => (int) $catalog->business_slot_included,
            'unlimited_business_slots' => (bool) $catalog->unlimited_business_slots,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function blockers(WorkspacePlanCatalog $catalog): array
    {
        $blockers = [];

        if (! $catalog->is_active) {
            $blockers[] = 'Not active in the plan catalog.';
        }

        if (! $catalog->available_for_signup) {
            $blockers[] = 'Not offered to new signups.';
        }

        if ($catalog->price === null || $catalog->currency_id === null) {
            $blockers[] = 'No price and currency configured.';
        }

        if (blank($catalog->provider_price_id)) {
            $blockers[] = 'No Stripe Price ID configured.';
        }

        if ((bool) $catalog->trial_enabled && $catalog->configuredTrialDays() === null) {
            $blockers[] = 'Trial is enabled but no trial length is set.';
        }

        return $blockers;
    }
}
