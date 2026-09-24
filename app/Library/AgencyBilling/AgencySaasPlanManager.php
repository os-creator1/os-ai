<?php

namespace App\Library\AgencyBilling;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Exceptions\AgencyBilling\AgencyBillingException;
use App\Models\AgencyClientSubscription;
use App\Models\AgencySaasPlan;
use App\Models\AgencySaasPlanPricingChange;
use App\Models\Workspace;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Lane C §C3.2/§C5.2 — the Agency's own resale catalog.
 *
 * THIS IS THE AGENCY'S PRODUCT, NOT OURS. The Agency chooses the name, the
 * description, the price, the currency, the cycle and the trial. What it cannot
 * choose is a capability set the product has no way to entitle, so every plan
 * maps onto a canonical `WorkspacePlanTier` — and never onto Agency, because
 * reselling the Agency tier would let a client manage its own clients on
 * somebody else's lane-A subscription.
 *
 * REPRICING IS NEVER RETROACTIVE. Changing a published plan writes a pricing
 * change row and updates the plan; it cannot reach an existing subscriber,
 * whose terms live on their own subscription row's snapshot (§C3.4). That
 * separation is the whole reason the snapshot exists.
 *
 * NO DB EDIT IS EVER REQUIRED to operate lane C: the Agency can create a plan,
 * have its Stripe Price generated, verify it, publish it and unpublish it,
 * entirely from the product.
 */
final class AgencySaasPlanManager
{
    public function __construct(
        private readonly AgencyStripeConnectManager $connections,
        private readonly AgencyPriceVerifier $prices,
    ) {
    }

    /** @return Collection<int, AgencySaasPlan> */
    public function forAgency(Workspace $agencyWorkspace): Collection
    {
        return AgencySaasPlan::query()
            ->where('agency_workspace_id', $agencyWorkspace->id)
            ->orderByDesc('is_published')
            ->orderBy('name')
            ->get();
    }

    /** @return Collection<int, AgencySaasPlan> */
    public function sellablePlans(Workspace $agencyWorkspace): Collection
    {
        return $this->forAgency($agencyWorkspace)->filter(fn (AgencySaasPlan $plan) => $plan->isSellable())->values();
    }

    /**
     * How many clients are currently on each plan — the "see its current
     * subscribers" the Agency surface needs (§C8).
     *
     * @return array<int, int> plan id => live subscriber count
     */
    public function subscriberCounts(Workspace $agencyWorkspace): array
    {
        return AgencyClientSubscription::query()
            ->where('agency_workspace_id', $agencyWorkspace->id)
            ->whereIn('status', ['trialing', 'active', 'past_due', 'unpaid', 'paused'])
            ->selectRaw('agency_saas_plan_id, COUNT(*) as total')
            ->groupBy('agency_saas_plan_id')
            ->pluck('total', 'agency_saas_plan_id')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    /**
     * §C3.2 — create a resale plan. Terms only; no provider call, nothing
     * published, nothing sellable yet.
     *
     * @param  array{name: string, description?: ?string, tier: string, price: string, currency_id: int, currency_code: string, billing_cycle: string, trial_enabled?: bool, trial_days?: ?int}  $data
     *
     * @throws AgencyBillingException
     */
    public function create(int $actorUserId, Workspace $agencyWorkspace, array $data): AgencySaasPlan
    {
        $this->assertAgencyOwner($actorUserId, $agencyWorkspace);
        self::assertResellableTier($data['tier']);

        $plan = new AgencySaasPlan([
            'agency_workspace_id' => $agencyWorkspace->id,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'tier' => $data['tier'],
            'price' => $data['price'],
            'currency_id' => $data['currency_id'],
            'currency_code' => mb_strtoupper($data['currency_code']),
            'billing_cycle' => $data['billing_cycle'],
            'trial_enabled' => (bool) ($data['trial_enabled'] ?? false),
            'trial_days' => $data['trial_days'] ?? null,
        ]);

        $plan->generateUid();
        $plan->created_by_user_id = $actorUserId;
        $plan->save();

        return $plan->refresh();
    }

    /**
     * §C3.2/§C3.3 — edit a plan. A change to the COMMERCIAL terms is audited
     * and un-publishes the plan until its Price is verified again, because the
     * old Price no longer represents the new terms and a Stripe Price's amount
     * cannot be edited in place.
     *
     * Existing subscribers are untouched, by construction: nothing here writes
     * to `agency_client_subscriptions`.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws AgencyBillingException
     */
    public function update(int $actorUserId, AgencySaasPlan $plan, array $data, ?string $reason = null): AgencySaasPlan
    {
        $this->assertAgencyOwner($actorUserId, $plan->agencyWorkspace);

        if (isset($data['tier'])) {
            self::assertResellableTier($data['tier']);
        }

        $before = [
            'price' => $plan->price,
            'currency_code' => $plan->currency_code,
            'billing_cycle' => $plan->billing_cycle,
            'provider_price_id' => $plan->provider_price_id,
        ];

        return DB::transaction(function () use ($plan, $data, $before, $actorUserId, $reason): AgencySaasPlan {
            $locked = AgencySaasPlan::query()->whereKey($plan->id)->lockForUpdate()->firstOrFail();

            $locked->fill(array_intersect_key($data, array_flip([
                'name', 'description', 'tier', 'price', 'currency_id', 'currency_code',
                'billing_cycle', 'trial_enabled', 'trial_days',
            ])));

            if (isset($data['currency_code'])) {
                $locked->currency_code = mb_strtoupper((string) $data['currency_code']);
            }

            $termsChanged = (string) $locked->price !== (string) $before['price']
                || (string) $locked->currency_code !== (string) $before['currency_code']
                || (string) $locked->billing_cycle !== (string) $before['billing_cycle'];

            if ($termsChanged) {
                // The bound Price describes the OLD terms. Keeping it published
                // would advertise one amount and charge another.
                $locked->provider_price_id = null;
                $locked->is_published = false;
                $locked->published_at = null;
            }

            $locked->save();

            if ($termsChanged) {
                $change = new AgencySaasPlanPricingChange([
                    'agency_saas_plan_id' => $locked->id,
                    'changed_by_user_id' => $actorUserId,
                    'from_price' => $before['price'],
                    'to_price' => $locked->price,
                    'from_currency_code' => $before['currency_code'],
                    'to_currency_code' => $locked->currency_code,
                    'from_billing_cycle' => $before['billing_cycle'],
                    'to_billing_cycle' => $locked->billing_cycle,
                    'from_provider_price_id' => $before['provider_price_id'],
                    'to_provider_price_id' => null,
                    'reason' => $reason,
                ]);
                $change->generateUid();
                $change->save();
            }

            return $locked->refresh();
        });
    }

    /**
     * §C5.2 — bind a Stripe Price to the plan, either one the Agency already
     * has or one generated from the terms it typed, and prove it matches.
     *
     * NO NETWORK UNDER A LOCK: the verification happens first, outside every
     * transaction, and only a proven Price is written.
     *
     * @throws AgencyBillingException
     */
    public function bindProviderPrice(
        int $actorUserId,
        AgencySaasPlan $plan,
        ?string $providerPriceId,
        ?string $reason = null,
    ): AgencySaasPlan {
        $agencyWorkspace = $plan->agencyWorkspace;
        $this->assertAgencyOwner($actorUserId, $agencyWorkspace);

        if ($plan->price === null || blank($plan->currency_code)) {
            throw AgencyBillingException::because(AgencyBillingException::PLAN_NOT_SELLABLE);
        }

        // A plan may only be bound to a Price on an account that can actually
        // take money; otherwise the Agency publishes something unsellable and
        // finds out when a client tries to pay.
        $connection = $this->connections->chargeableConnection($agencyWorkspace);
        $accountId = (string) $connection->stripe_account_id;

        $verified = $providerPriceId !== null && trim($providerPriceId) !== ''
            ? $this->prices->verify($accountId, trim($providerPriceId), (string) $plan->price, (string) $plan->currency_code, (string) $plan->billing_cycle)
            : $this->prices->createAndVerify(
                $accountId,
                (string) $plan->name,
                (string) $plan->price,
                (string) $plan->currency_code,
                (string) $plan->billing_cycle,
                // Keyed on the plan and its exact terms, so resubmitting the
                // same form cannot leave two Prices behind, while genuinely
                // new terms legitimately get a new key.
                'agency-plan:' . $plan->uid . ':price:' . sha1(implode('|', [
                    (string) $plan->price, (string) $plan->currency_code, (string) $plan->billing_cycle,
                ])),
            );

        $before = $plan->provider_price_id;

        $plan->forceFill([
            'provider_price_id' => $verified->id,
            'agency_stripe_connection_id' => $connection->id,
        ])->save();

        if ((string) $before !== (string) $verified->id) {
            $change = new AgencySaasPlanPricingChange([
                'agency_saas_plan_id' => $plan->id,
                'changed_by_user_id' => $actorUserId,
                'from_price' => $plan->price,
                'to_price' => $plan->price,
                'from_currency_code' => $plan->currency_code,
                'to_currency_code' => $plan->currency_code,
                'from_billing_cycle' => $plan->billing_cycle,
                'to_billing_cycle' => $plan->billing_cycle,
                'from_provider_price_id' => $before,
                'to_provider_price_id' => $verified->id,
                'reason' => $reason ?? 'Stripe price bound.',
            ]);
            $change->generateUid();
            $change->save();
        }

        return $plan->refresh();
    }

    /**
     * §C5.2 — publish. The parity check is re-run at this exact moment rather
     * than trusted from whenever the Price was bound, because an Agency can
     * archive or replace a Price in the Stripe dashboard at any time.
     *
     * @throws AgencyBillingException
     */
    public function publish(int $actorUserId, AgencySaasPlan $plan): AgencySaasPlan
    {
        $agencyWorkspace = $plan->agencyWorkspace;
        $this->assertAgencyOwner($actorUserId, $agencyWorkspace);

        if ($plan->price === null || blank($plan->currency_code) || blank($plan->provider_price_id)) {
            throw AgencyBillingException::because(AgencyBillingException::PLAN_NOT_SELLABLE);
        }

        $connection = $this->connections->chargeableConnection($agencyWorkspace);

        $this->prices->verify(
            (string) $connection->stripe_account_id,
            (string) $plan->provider_price_id,
            (string) $plan->price,
            (string) $plan->currency_code,
            (string) $plan->billing_cycle,
        );

        $plan->forceFill([
            'is_published' => true,
            'published_at' => now(),
            'agency_stripe_connection_id' => $connection->id,
        ])->save();

        return $plan->refresh();
    }

    /**
     * §C8 — stop offering a plan to NEW clients. Existing subscribers keep
     * their subscription and their terms: unpublishing is a shop-window
     * decision, not a cancellation.
     *
     * @throws AgencyBillingException
     */
    public function unpublish(int $actorUserId, AgencySaasPlan $plan): AgencySaasPlan
    {
        $this->assertAgencyOwner($actorUserId, $plan->agencyWorkspace);

        $plan->forceFill(['is_published' => false, 'published_at' => null])->save();

        return $plan->refresh();
    }

    /**
     * §C3.2 — an Agency resells capability, never the Agency tier itself.
     *
     * @throws AgencyBillingException
     */
    public static function assertResellableTier(string $tier): void
    {
        if (! in_array(mb_strtolower(trim($tier)), AgencySaasPlan::RESELLABLE_TIERS, true)) {
            throw AgencyBillingException::because(AgencyBillingException::TIER_NOT_RESELLABLE);
        }
    }

    /** @return array<int, WorkspacePlanTier> */
    public static function resellableTiers(): array
    {
        return array_map(
            static fn (string $tier): WorkspacePlanTier => WorkspacePlanTier::from($tier),
            AgencySaasPlan::RESELLABLE_TIERS,
        );
    }

    /**
     * §C5.1 — revenue configuration is the Agency Workspace OWNER's, never an
     * Admin's and never Staff's.
     *
     * @throws AgencyBillingException
     */
    private function assertAgencyOwner(int $actorUserId, Workspace $agencyWorkspace): void
    {
        if ((int) $agencyWorkspace->owner_user_id !== $actorUserId) {
            throw AgencyBillingException::because(AgencyBillingException::CONSENT_NOT_AUTHORIZED);
        }
    }
}
