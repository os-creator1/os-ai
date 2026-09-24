<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AgencyBilling\AgencyClientSubscriptionStatus;
use App\Enums\PlatformBilling\PlatformSubscriptionStatus;
use App\Exceptions\PlatformBilling\PlatformBillingException;
use App\Http\Controllers\Controller;
use App\Library\Entitlement\EntitlementManager;
use App\Library\PlatformBilling\PlatformPlanPresenter;
use App\Library\PlatformBilling\PlatformPriceVerifier;
use App\Models\AgencyClientSubscription;
use App\Models\PlatformSubscription;
use App\Models\PlatformSubscriptionEvent;
use App\Models\WorkspacePlanAssignment;
use App\Models\WorkspacePlanCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Implementation Contract 21 §11 — the Platform Owner's lane-A commercial
 * surface: configure what is sold, and see enough to operate it.
 *
 * NO DATABASE EDITS ARE REQUIRED TO PUT A PLAN ON SALE. Price, currency,
 * billing cycle, trial policy, signup availability and the Stripe Price
 * identity are all set here.
 *
 * COMMERCIAL CONFIGURATION LIVES IN THE V1 CATALOG, NOT IN A PAYMENTS-ONLY
 * SETTINGS TABLE (§11). Price and currency go through
 * `EntitlementManager::updateCatalogPricing()`, which is already the ONLY
 * price-history authority and writes `workspace_plan_catalog_pricing_changes`;
 * this controller does not invent a second one.
 *
 * SECRETS ARE NEVER RENDERED (§5.1). The Stripe API key and the lane-A webhook
 * signing secret stay in secure runtime configuration. This surface shows
 * `Configured` / `Missing`, the mode, the endpoint URL to paste into Stripe,
 * and operator instructions — never a value, a prefix or a length. There is
 * deliberately no input on this page that could write a secret into the
 * database.
 *
 * THE STRIPE PRICE ID IS NOT A SECRET. `price_...` is a public provider object
 * identifier, and the owner has to be able to set it without a DB client, so
 * it is an ordinary validated field here.
 *
 * LANE A ONLY (§11). The revenue figures below come from lane-A rows.
 * Lane B (Business revenue), lane C (Agency revenue) and lane D (usage
 * funding) are never counted as platform SaaS revenue.
 */
class PlatformBillingController extends Controller
{
    /** §11 — the shape `price_...` identifiers take, so a typo is caught here. */
    private const PRICE_ID_PATTERN = '/\Aprice_[A-Za-z0-9]{6,}\z/';

    public function __construct(
        private readonly PlatformPlanPresenter $plans,
        private readonly EntitlementManager $entitlements,
        private readonly PlatformPriceVerifier $prices,
    ) {
    }

    public function index(): View
    {
        return view('admin.platform-billing.index', [
            'plans' => $this->plans->allPlans(),
            'provider' => $this->plans->providerStatus(),
            'currencies' => DB::table('currencies')->orderBy('code')->get(['id', 'code', 'name']),
            'metrics' => $this->metrics(),
            'attention' => $this->attention(),
            'subscriptions' => $this->recentSubscriptions(),
            'webhook' => $this->webhookHealth(),
        ]);
    }

    /**
     * §11 — commercial configuration for one tier.
     *
     * Price and currency are routed through updateCatalogPricing() so the
     * change is audited in the existing pricing-change history; the trial and
     * availability switches are catalog columns with no price history of their
     * own.
     */
    public function update(Request $request, string $tier): RedirectResponse
    {
        $catalog = WorkspacePlanCatalog::query()->where('tier', $tier)->firstOrFail();

        $data = $request->validate([
            'price' => ['required', 'regex:/^\d+(\.\d{1,2})?$/'],
            'currency_id' => ['required', 'integer', 'exists:currencies,id'],
            'billing_cycle' => ['required', 'string', 'in:monthly,yearly'],
            'trial_enabled' => ['nullable', 'boolean'],
            'trial_days' => ['nullable', 'integer', 'min:1', 'max:730'],
            'available_for_signup' => ['nullable', 'boolean'],
            // §10.1 — a Stripe Price is immutable in the relevant sense, so
            // changing the amount means creating a NEW Price in Stripe and
            // entering its id here. Validated for shape so a Product id, a
            // secret, or a stray paste cannot be stored as a Price.
            'provider_price_id' => ['nullable', 'string', 'regex:' . self::PRICE_ID_PATTERN],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $trialEnabled = (bool) ($data['trial_enabled'] ?? false);

        if ($trialEnabled && ($data['trial_days'] ?? null) === null) {
            return back()->withInput()->withErrors([
                'trial_days' => __('Set how many days the trial lasts, or turn the trial off.'),
            ]);
        }

        // §11 — THE PARITY CHECK, before a single row changes.
        //
        // The regex above proves only that the operator typed something
        // Price-shaped. This proves the Price actually exists on THIS
        // platform's Stripe account, is active and recurring, and charges
        // exactly the amount, currency and interval being saved. Without it
        // the catalog could say €297/yearly while Stripe charges $99/monthly.
        //
        // It runs OUTSIDE any transaction (§5) and BEFORE updateCatalogPricing(),
        // so a failure leaves zero catalog and zero pricing-history rows
        // written.
        if (! blank($data['provider_price_id'] ?? null)) {
            $currencyCode = (string) DB::table('currencies')->where('id', (int) $data['currency_id'])->value('code');

            try {
                $mismatches = $this->prices->mismatches(
                    (string) $data['provider_price_id'],
                    (string) $data['price'],
                    $currencyCode,
                    (string) $data['billing_cycle'],
                );
            } catch (PlatformBillingException $e) {
                return back()->withInput()->withErrors(['provider_price_id' => $e->customerMessage()]);
            }

            if ($mismatches !== []) {
                return back()->withInput()->withErrors(['provider_price_id' => $mismatches]);
            }
        }

        // Price + currency: the existing audited authority.
        $this->entitlements->updateCatalogPricing(
            $catalog,
            $data['price'],
            (int) $data['currency_id'],
            $catalog->additional_business_slot_price_ratio === null ? null : (string) $catalog->additional_business_slot_price_ratio,
            (int) Auth::id(),
            $data['reason'],
        );

        // Commercial switches that carry no price history.
        $catalog->refresh()->forceFill([
            'billing_cycle' => $data['billing_cycle'],
            'trial_enabled' => $trialEnabled,
            'trial_days' => $trialEnabled ? (int) $data['trial_days'] : $catalog->trial_days,
            'available_for_signup' => (bool) ($data['available_for_signup'] ?? false),
            'provider_price_id' => $data['provider_price_id'] ?? null,
        ])->save();

        return back()->with([
            'status' => 'success',
            'message' => __(':plan updated.', ['plan' => $catalog->display_name]),
        ]);
    }

    /**
     * §11 — the counts an operator needs. Derived from LOCAL durable facts, so
     * the page works without calling Stripe.
     *
     * @return array<string, int>
     */
    private function metrics(): array
    {
        $byStatus = PlatformSubscription::query()
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        // Grace and Locked are LIFECYCLE facts, not provider statuses, so they
        // come from the plan assignment — the canonical authority — rather
        // than being guessed from Stripe's vocabulary.
        //
        // Only LANE-A accounts: a Workspace with a platform subscription of its
        // own, and not one its managing Agency now bills through lane C (§2.1 —
        // an agency client's grace or lock is the Agency's delinquency, never
        // a platform subscriber's). Reporting may read every lane; it may not
        // present one lane's accounts as another's.
        $graceOrLocked = WorkspacePlanAssignment::query()
            ->where('is_complimentary', false)
            ->whereIn('workspace_id', PlatformSubscription::query()->select('workspace_id'))
            ->whereNotIn('workspace_id', AgencyClientSubscription::query()
                ->whereNotIn('status', [
                    AgencyClientSubscriptionStatus::Offered->value,
                    AgencyClientSubscriptionStatus::Canceled->value,
                    AgencyClientSubscriptionStatus::IncompleteExpired->value,
                ])
                ->select('client_workspace_id'))
            ->selectRaw('sum(case when locked_at is not null then 1 else 0 end) as locked')
            ->selectRaw('sum(case when locked_at is null and grace_started_at is not null then 1 else 0 end) as grace')
            ->first();

        return [
            'trialing' => (int) ($byStatus[PlatformSubscriptionStatus::Trialing->value] ?? 0),
            'active' => (int) ($byStatus[PlatformSubscriptionStatus::Active->value] ?? 0),
            'past_due' => (int) ($byStatus[PlatformSubscriptionStatus::PastDue->value] ?? 0),
            'canceling' => (int) PlatformSubscription::query()->where('cancel_at_period_end', true)
                ->whereNotIn('status', [PlatformSubscriptionStatus::Canceled->value])->count(),
            'canceled' => (int) ($byStatus[PlatformSubscriptionStatus::Canceled->value] ?? 0),
            'pending' => (int) ($byStatus[PlatformSubscriptionStatus::Pending->value] ?? 0),
            'grace' => (int) ($graceOrLocked->grace ?? 0),
            'locked' => (int) ($graceOrLocked->locked ?? 0),
            'complimentary' => (int) WorkspacePlanAssignment::query()->where('is_complimentary', true)->count(),
        ];
    }

    /**
     * §11 — failed-payment attention items: who is in trouble, and since when.
     *
     * @return array<int, array<string, mixed>>
     */
    private function attention(): array
    {
        // DB::table rather than the Eloquent builder: this is a flat read model
        // for an operator table, and hydrating partial models would only invite
        // someone to save one.
        return DB::table('platform_subscriptions')
            ->join('workspaces', 'workspaces.id', '=', 'platform_subscriptions.workspace_id')
            ->leftJoin('workspace_plan_assignments', 'workspace_plan_assignments.workspace_id', '=', 'platform_subscriptions.workspace_id')
            ->whereIn('platform_subscriptions.status', [
                PlatformSubscriptionStatus::PastDue->value,
                PlatformSubscriptionStatus::Unpaid->value,
            ])
            ->orderBy('workspace_plan_assignments.grace_started_at')
            ->limit(50)
            ->get([
                'workspaces.uid as workspace_uid',
                'workspaces.name as workspace_name',
                'platform_subscriptions.status',
                'platform_subscriptions.price_snapshot',
                'platform_subscriptions.currency_code',
                'workspace_plan_assignments.grace_started_at',
                'workspace_plan_assignments.locked_at',
            ])
            ->map(static fn ($row): array => (array) $row)
            ->all();
    }

    /**
     * §11 — current subscriptions with the facts needed to answer a support
     * question without opening Stripe.
     *
     * @return array<int, array<string, mixed>>
     */
    private function recentSubscriptions(): array
    {
        return DB::table('platform_subscriptions')
            ->join('workspaces', 'workspaces.id', '=', 'platform_subscriptions.workspace_id')
            ->join('workspace_plan_catalog', 'workspace_plan_catalog.id', '=', 'platform_subscriptions.workspace_plan_catalog_id')
            ->orderByDesc('platform_subscriptions.id')
            ->limit(100)
            ->get([
                'workspaces.uid as workspace_uid',
                'workspaces.name as workspace_name',
                'workspace_plan_catalog.display_name as tier_name',
                'platform_subscriptions.status',
                'platform_subscriptions.price_snapshot',
                'platform_subscriptions.currency_code',
                'platform_subscriptions.billing_cycle_snapshot',
                'platform_subscriptions.trial_ends_at',
                'platform_subscriptions.current_period_end',
                'platform_subscriptions.cancel_at_period_end',
            ])
            ->map(static fn ($row): array => (array) $row)
            ->all();
    }

    /**
     * §11 — webhook health from durable local facts: is anything arriving, and
     * is anything failing.
     *
     * @return array<string, mixed>
     */
    private function webhookHealth(): array
    {
        $latest = PlatformSubscriptionEvent::query()->orderByDesc('id')->first();

        return [
            'total' => (int) PlatformSubscriptionEvent::query()->count(),
            'failed' => (int) PlatformSubscriptionEvent::query()->where('state', 'failed')->count(),
            'latest_at' => $latest?->created_at,
            'latest_type' => $latest?->event_type,
            'latest_state' => $latest?->state?->value,
        ];
    }
}
