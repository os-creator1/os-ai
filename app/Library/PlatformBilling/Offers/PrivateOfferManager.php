<?php

namespace App\Library\PlatformBilling\Offers;

use App\Enums\PlatformBilling\PlatformSubscriptionStatus;
use App\Library\PlatformBilling\BillingCycle;
use App\Library\PlatformBilling\PlatformStripeGateway;
use App\Library\PlatformBilling\PlatformSubscriptionManager;
use App\Library\PlatformOwner\PlatformAdminAuditLog;
use App\Library\PlatformOwner\PlatformOwnerAuthority;
use App\Models\Currency;
use App\Models\PlatformPrivateOffer;
use App\Models\PlatformPrivateOfferRedemption;
use App\Models\PlatformSubscription;
use App\Models\Workspace;
use App\Models\WorkspacePlanCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

/**
 * The Platform Owner's side of Private Offers: create, copy the link, revoke.
 *
 * EVERY action is by an authenticated platform administrator, reasoned, and
 * recorded in the platform audit trail. The owner never touches Stripe: the
 * Prices an offer needs are created and verified by MotionGrove when a customer
 * accepts it (PrivateOfferRedemptionManager), not typed in here.
 *
 * AN OFFER NEVER REACHES BACK INTO AN ACCEPTED AGREEMENT. Revoking stops anyone
 * who has not yet paid; it does not change the price of a customer who has
 * already redeemed, whose terms are frozen on their redemption.
 */
final class PrivateOfferManager
{
    public function __construct(
        private readonly OfferTermsCalculator $calculator,
        private readonly PlatformOwnerAuthority $authority,
        private readonly PlatformAdminAuditLog $audit,
        private readonly PlatformStripeGateway $gateway,
        private readonly TrialLedger $trials,
        private readonly PlatformSubscriptionManager $subscriptions,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{offer: PlatformPrivateOffer, token: string}  the plain token is returned ONCE; only its hash is stored
     *
     * @throws ValidationException
     */
    public function create(array $data, int $actorUserId): array
    {
        $this->authority->assertAdministrator($actorUserId);

        $reason = trim((string) ($data['reason'] ?? ''));

        if (mb_strlen($reason) < 3) {
            throw ValidationException::withMessages(['reason' => __('Say why you are making this offer (at least 3 characters).')]);
        }

        $kind = (string) ($data['kind'] ?? PlatformPrivateOffer::KIND_NEW_SUBSCRIPTION);

        if (! in_array($kind, [PlatformPrivateOffer::KIND_NEW_SUBSCRIPTION, PlatformPrivateOffer::KIND_BILLING_CHANGE], true)) {
            throw ValidationException::withMessages(['kind' => __('Unknown offer type.')]);
        }

        $workspace = $this->resolveWorkspace($data['workspace_uid'] ?? null);
        $email = $this->resolveEmail($data['customer_email'] ?? null);

        if ($workspace === null && $email === null) {
            throw ValidationException::withMessages(['customer_email' => __('Choose who this offer is for: a customer email address or an existing Workspace.')]);
        }

        if ($kind === PlatformPrivateOffer::KIND_BILLING_CHANGE && $workspace === null) {
            throw ValidationException::withMessages(['workspace_uid' => __('A billing change applies to an existing Workspace.')]);
        }

        $existingSubscription = $workspace === null ? null : PlatformSubscription::query()->where('workspace_id', $workspace->id)->first();

        $catalog = $this->resolveCatalog($kind, $data['tier'] ?? null, $existingSubscription);
        $currency = Currency::query()->find($catalog->currency_id)
            ?? throw ValidationException::withMessages(['tier' => __('That plan has no currency yet.')]);

        $this->assertWorkspaceFitsKind($kind, $workspace, $existingSubscription, $catalog);

        $attributes = $this->commercialAttributes($data, $kind);
        $expiresAt = $this->resolveExpiry($data);

        $limit = $kind === PlatformPrivateOffer::KIND_BILLING_CHANGE ? 1 : (int) ($data['redemption_limit'] ?? 1);

        if ($limit < 1 || $limit > 50) {
            throw ValidationException::withMessages(['redemption_limit' => __('The redemption limit must be between 1 and 50.')]);
        }

        $offer = new PlatformPrivateOffer();
        $offer->forceFill([
            'kind' => $kind,
            'label' => mb_substr(trim((string) ($data['label'] ?? '')) ?: $this->defaultLabel($catalog, $email, $workspace), 0, 120),
            'workspace_plan_catalog_id' => $catalog->id,
            'customer_email' => $email,
            'target_workspace_id' => $workspace?->id,
            'currency_id' => $currency->id,
            'expires_at' => $expiresAt,
            'redemption_limit' => $limit,
            'created_by_user_id' => $actorUserId,
            'created_reason' => mb_substr($reason, 0, 500),
            ...$attributes,
        ]);

        // PROVE THE TERMS ARE REAL before anything is stored: the exact same
        // calculator the customer's page will use, so an offer that cannot be
        // expressed never exists.
        $this->previewTerms($offer, $catalog, $currency, $existingSubscription);

        [$token, $hash] = PlatformPrivateOffer::mintToken();

        return DB::transaction(function () use ($offer, $hash, $token, $actorUserId, $reason, $catalog, $kind) {
            if ($kind === PlatformPrivateOffer::KIND_BILLING_CHANGE) {
                $duplicate = PlatformPrivateOffer::query()
                    ->where('kind', PlatformPrivateOffer::KIND_BILLING_CHANGE)
                    ->where('target_workspace_id', $offer->target_workspace_id)
                    ->whereNull('revoked_at')
                    ->where('expires_at', '>', now())
                    ->lockForUpdate()
                    ->exists();

                if ($duplicate) {
                    throw ValidationException::withMessages(['workspace_uid' => __('This Workspace already has an open billing change. Revoke it first.')]);
                }
            }

            $offer->forceFill(['token_hash' => $hash])->save();

            $this->audit->record(
                $actorUserId,
                'private_offer.created',
                'private_offer',
                (string) $offer->uid,
                sprintf('Private offer created: %s (%s)', $offer->label, $catalog->tier->value),
                $reason,
                ['terms' => $this->auditTerms($offer)],
            );

            return ['offer' => $offer->refresh(), 'token' => $token];
        });
    }

    /**
     * A new link for an offer that has not been used up. The old link stops
     * working at once — which is also the answer to "I sent it to the wrong
     * person".
     */
    public function rotateLink(PlatformPrivateOffer $offer, int $actorUserId, string $reason): string
    {
        $this->authority->assertAdministrator($actorUserId);

        [$token, $hash] = PlatformPrivateOffer::mintToken();

        DB::transaction(function () use ($offer, $hash, $actorUserId, $reason): void {
            $locked = PlatformPrivateOffer::query()->whereKey($offer->id)->lockForUpdate()->firstOrFail();

            if ($locked->isRevoked()) {
                throw ValidationException::withMessages(['offer' => __('A revoked offer cannot be given a new link.')]);
            }

            $locked->forceFill(['token_hash' => $hash, 'token_rotated_at' => now()])->save();

            $this->audit->record($actorUserId, 'private_offer.link_rotated', 'private_offer', (string) $locked->uid,
                'Private offer link replaced: ' . $locked->label, $reason);
        });

        return $token;
    }

    /**
     * Stop an offer. Anyone who has not yet paid is stopped; anyone who has
     * already redeemed is untouched.
     */
    public function revoke(PlatformPrivateOffer $offer, int $actorUserId, string $reason): PlatformPrivateOffer
    {
        $this->authority->assertAdministrator($actorUserId);

        if (mb_strlen(trim($reason)) < 3) {
            throw ValidationException::withMessages(['reason' => __('Say why you are revoking this offer (at least 3 characters).')]);
        }

        // 1. Close the door first, so nobody new can start while we tidy up.
        $revoked = DB::transaction(function () use ($offer, $actorUserId, $reason): PlatformPrivateOffer {
            $locked = PlatformPrivateOffer::query()->whereKey($offer->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isRevoked()) {
                $locked->forceFill([
                    'revoked_at' => now(),
                    'revoked_by_user_id' => $actorUserId,
                    'revoked_reason' => mb_substr(trim($reason), 0, 500),
                ])->save();

                $this->audit->record($actorUserId, 'private_offer.revoked', 'private_offer', (string) $locked->uid,
                    'Private offer revoked: ' . $locked->label, $reason);
            }

            return $locked->refresh();
        });

        // 2. Retire any Checkout a customer has open but has not paid. Provider
        //    calls, so: no transaction. One failure never blocks the rest.
        PlatformPrivateOfferRedemption::query()
            ->where('platform_private_offer_id', $revoked->id)
            ->where('state', PlatformPrivateOfferRedemption::STATE_RESERVED)
            ->get()
            ->each(fn (PlatformPrivateOfferRedemption $r) => $this->retireUnpaid($r, 'revoked'));

        return $revoked;
    }

    /**
     * Release one unpaid reservation. The provider is consulted first, so a
     * Checkout that was paid a moment ago is never released out from under a
     * customer: `complete` means money moved, and the webhook will confirm it.
     */
    public function retireUnpaid(PlatformPrivateOfferRedemption $redemption, string $reason): bool
    {
        try {
            if (! blank($redemption->provider_checkout_session_id)) {
                $session = $this->gateway->retrieveCheckoutSession((string) $redemption->provider_checkout_session_id);

                if ($session->status === 'complete') {
                    return false;
                }

                if ($session->status === 'open') {
                    $this->gateway->expireCheckoutSession($session->sessionId);
                }
            }
        } catch (Throwable $e) {
            Log::warning('Could not retire a private-offer Checkout at the provider; leaving the reservation', [
                'redemption_id' => $redemption->id,
                'exception' => class_basename($e),
            ]);

            return false;
        }

        return DB::transaction(function () use ($redemption, $reason): bool {
            $locked = PlatformPrivateOfferRedemption::query()->whereKey($redemption->id)->lockForUpdate()->firstOrFail();

            if ($locked->state !== PlatformPrivateOfferRedemption::STATE_RESERVED) {
                return false;
            }

            $locked->forceFill([
                'state' => PlatformPrivateOfferRedemption::STATE_RELEASED,
                'active_slot' => null,
                'released_at' => now(),
                'release_reason' => $reason,
            ])->save();

            $workspace = Workspace::query()->find($locked->workspace_id);

            if ($workspace !== null) {
                $this->trials->release($workspace);
            }

            return true;
        });
    }

    /** Releases reservations whose Checkout window has closed unpaid. */
    public function sweepExpiredReservations(int $limit = 100): int
    {
        $released = 0;

        PlatformPrivateOfferRedemption::query()
            ->where('state', PlatformPrivateOfferRedemption::STATE_RESERVED)
            ->where('reserved_until', '<', now())
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->each(function (PlatformPrivateOfferRedemption $r) use (&$released): void {
                if ($this->retireUnpaid($r, 'expired')) {
                    $released++;
                }
            });

        return $released;
    }

    /** The owner list. @return array<int, array<string, mixed>> */
    public function overview(?int $workspaceId = null, int $limit = 100): array
    {
        $offers = PlatformPrivateOffer::query()
            ->with(['catalog', 'workspace', 'currency'])
            ->when($workspaceId !== null, fn ($q) => $q->where('target_workspace_id', $workspaceId))
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        $counts = PlatformPrivateOfferRedemption::query()
            ->whereIn('platform_private_offer_id', $offers->pluck('id'))
            ->select('platform_private_offer_id', 'state', DB::raw('count(*) as total'))
            ->groupBy('platform_private_offer_id', 'state')
            ->get()
            ->groupBy('platform_private_offer_id');

        return $offers->map(function (PlatformPrivateOffer $offer) use ($counts): array {
            $byState = ($counts[$offer->id] ?? collect())->pluck('total', 'state');

            return [
                'offer' => $offer,
                'status' => $offer->status(),
                'redeemed' => (int) ($byState[PlatformPrivateOfferRedemption::STATE_REDEEMED] ?? 0),
                'in_progress' => (int) ($byState[PlatformPrivateOfferRedemption::STATE_RESERVED] ?? 0),
                'summary' => $this->describe($offer),
            ];
        })->all();
    }

    /** One-line, owner-facing description of the commercial terms. */
    public function describe(PlatformPrivateOffer $offer): string
    {
        $currency = $offer->currency?->code ?? '';
        $parts = [];

        if ($offer->custom_price !== null) {
            $parts[] = sprintf('%s %s %s', $offer->custom_price, $currency, mb_strtolower(BillingCycle::label((string) $offer->catalog?->billing_cycle)));
        } else {
            $parts[] = __('plan price');
        }

        if ($offer->discount_type !== null) {
            $off = $offer->discount_type === PlatformPrivateOffer::DISCOUNT_PERCENT
                ? rtrim(rtrim((string) $offer->discount_value, '0'), '.') . '% off'
                : $offer->discount_value . ' ' . $currency . ' off';
            $for = match ($offer->discount_duration) {
                PlatformPrivateOffer::DURATION_FOREVER => __('forever'),
                PlatformPrivateOffer::DURATION_ONCE => __('for 1 billing period'),
                default => __('for :n billing periods', ['n' => $offer->discount_periods]),
            };
            $parts[] = $off . ' ' . $for;
        }

        if ($offer->trial_days !== null) {
            $parts[] = __(':n-day free trial', ['n' => $offer->trial_days]);
        }

        return implode(' · ', $parts);
    }

    // ------------------------------------------------------------------ helpers

    private function resolveWorkspace(mixed $uid): ?Workspace
    {
        if ($uid === null || trim((string) $uid) === '') {
            return null;
        }

        return Workspace::query()->where('uid', trim((string) $uid))->first()
            ?? throw ValidationException::withMessages(['workspace_uid' => __('That Workspace could not be found.')]);
    }

    private function resolveEmail(mixed $email): ?string
    {
        if ($email === null || trim((string) $email) === '') {
            return null;
        }

        $normalized = EmailIdentity::normalize((string) $email);

        if (! filter_var($normalized, FILTER_VALIDATE_EMAIL) || mb_strlen($normalized) > 191) {
            throw ValidationException::withMessages(['customer_email' => __('Enter a valid email address.')]);
        }

        return $normalized;
    }

    private function resolveCatalog(string $kind, mixed $tier, ?PlatformSubscription $subscription): WorkspacePlanCatalog
    {
        if ($kind === PlatformPrivateOffer::KIND_BILLING_CHANGE) {
            // A billing change changes the PRICE of the plan the customer is
            // already on; it never moves them between plans or entitlements.
            if ($subscription === null) {
                throw ValidationException::withMessages(['workspace_uid' => __('That Workspace has no subscription to change.')]);
            }

            return WorkspacePlanCatalog::query()->findOrFail($subscription->workspace_plan_catalog_id);
        }

        if (! in_array((string) $tier, ['core', 'growth', 'agency'], true)) {
            throw ValidationException::withMessages(['tier' => __('Choose Core, Growth or Agency.')]);
        }

        $catalog = WorkspacePlanCatalog::query()->where('tier', (string) $tier)->first();

        if ($catalog === null || ! $catalog->is_active || $catalog->price === null || $catalog->currency_id === null) {
            throw ValidationException::withMessages(['tier' => __('That plan is not active and priced yet.')]);
        }

        if (BillingCycle::stripeRecurrence((string) $catalog->billing_cycle) === null) {
            throw ValidationException::withMessages(['tier' => __('That plan has no billing cycle yet.')]);
        }

        return $catalog;
    }

    private function assertWorkspaceFitsKind(string $kind, ?Workspace $workspace, ?PlatformSubscription $subscription, WorkspacePlanCatalog $catalog): void
    {
        if ($workspace === null) {
            return;
        }

        if ($kind === PlatformPrivateOffer::KIND_BILLING_CHANGE) {
            if ($subscription === null
                || ! in_array($subscription->status, [PlatformSubscriptionStatus::Active, PlatformSubscriptionStatus::Trialing], true)
                || blank($subscription->provider_subscription_id)) {
                throw ValidationException::withMessages(['workspace_uid' => __('Only a Workspace with an active (or trialing) paid subscription can be moved onto new terms.')]);
            }

            if ((bool) $subscription->cancel_at_period_end) {
                throw ValidationException::withMessages(['workspace_uid' => __('This subscription is set to cancel. Let the customer resume it first.')]);
            }

            if ((string) $subscription->billing_cycle_snapshot !== (string) $catalog->billing_cycle) {
                throw ValidationException::withMessages(['workspace_uid' => __('This subscription bills on a different cycle (:cycle). Move it to a current plan first.', [
                    'cycle' => BillingCycle::label((string) $subscription->billing_cycle_snapshot),
                ])]);
            }

            return;
        }

        if ($subscription !== null
            && ! in_array($subscription->status, [PlatformSubscriptionStatus::Pending, PlatformSubscriptionStatus::Canceled, PlatformSubscriptionStatus::IncompleteExpired], true)) {
            throw ValidationException::withMessages(['workspace_uid' => __('This Workspace already has a subscription. Use a billing change to give it special pricing.')]);
        }

        if (PlatformSubscriptionManager::agencyIsBillingWorkspace($workspace)) {
            throw ValidationException::withMessages(['workspace_uid' => __('This Workspace is billed through its Agency.')]);
        }
    }

    /** @param array<string, mixed> $data  @return array<string, mixed> */
    private function commercialAttributes(array $data, string $kind): array
    {
        $errors = [];

        $trial = $data['trial_days'] ?? null;
        $trial = ($trial === null || $trial === '') ? null : (int) $trial;

        if ($trial !== null && ($trial < 1 || $trial > 730)) {
            $errors['trial_days'] = __('A trial can be 1 to 730 days.');
        }

        if ($trial !== null && $kind === PlatformPrivateOffer::KIND_BILLING_CHANGE) {
            $errors['trial_days'] = __('A free trial cannot be added to an existing subscription.');
        }

        $custom = $data['custom_price'] ?? null;
        $custom = ($custom === null || trim((string) $custom) === '') ? null : trim((string) $custom);

        if ($custom !== null && ! preg_match('/^\d+(\.\d{1,2})?$/', $custom)) {
            $errors['custom_price'] = __('Enter the price as an amount such as 79 or 79.50.');
        }

        $type = $data['discount_type'] ?? null;
        $type = ($type === null || $type === '' || $type === 'none') ? null : (string) $type;
        $value = $data['discount_value'] ?? null;
        $value = ($value === null || trim((string) $value) === '') ? null : trim((string) $value);
        $duration = null;
        $periods = null;

        if ($type !== null) {
            if (! in_array($type, [PlatformPrivateOffer::DISCOUNT_FIXED, PlatformPrivateOffer::DISCOUNT_PERCENT], true)) {
                $errors['discount_type'] = __('Choose a fixed amount or a percentage.');
            } elseif ($value === null || ! preg_match('/^\d+(\.\d{1,2})?$/', $value) || (float) $value <= 0) {
                $errors['discount_value'] = __('Enter a discount greater than zero.');
            } elseif ($type === PlatformPrivateOffer::DISCOUNT_PERCENT && (float) $value >= 100) {
                $errors['discount_value'] = __('A percentage discount must be below 100%. Use a free trial for a free period.');
            }

            $duration = (string) ($data['discount_duration'] ?? '');

            if (! in_array($duration, [PlatformPrivateOffer::DURATION_FOREVER, PlatformPrivateOffer::DURATION_ONCE, PlatformPrivateOffer::DURATION_PERIODS], true)) {
                $errors['discount_duration'] = __('Say how long the discount lasts.');
            } elseif ($duration === PlatformPrivateOffer::DURATION_PERIODS) {
                $periods = (int) ($data['discount_periods'] ?? 0);

                if ($periods < 2 || $periods > 120) {
                    $errors['discount_periods'] = __('Enter between 2 and 120 billing periods (use "one billing period" for 1).');
                }
            } elseif ($duration === PlatformPrivateOffer::DURATION_ONCE) {
                $periods = 1;
            }
        } else {
            $value = null;
        }

        if ($kind === PlatformPrivateOffer::KIND_BILLING_CHANGE && $custom === null && $type === null) {
            $errors['custom_price'] = __('Set a negotiated recurring price and/or a discount.');
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [
            'trial_days' => $trial,
            'custom_price' => $custom,
            'discount_type' => $type,
            'discount_value' => $value,
            'discount_duration' => $duration,
            'discount_periods' => $periods,
        ];
    }

    /** @param array<string, mixed> $data */
    private function resolveExpiry(array $data): CarbonImmutable
    {
        try {
            $expires = isset($data['expires_at']) && trim((string) $data['expires_at']) !== ''
                ? CarbonImmutable::parse((string) $data['expires_at'])->endOfDay()
                : CarbonImmutable::now()->addDays((int) ($data['expires_in_days'] ?? 14));
        } catch (Throwable) {
            throw ValidationException::withMessages(['expires_at' => __('Enter a valid expiry date.')]);
        }

        if ($expires->lessThanOrEqualTo(now())) {
            throw ValidationException::withMessages(['expires_at' => __('The expiry must be in the future.')]);
        }

        if ($expires->greaterThan(now()->addDays(366))) {
            throw ValidationException::withMessages(['expires_at' => __('An offer can stay open for at most a year.')]);
        }

        return $expires;
    }

    private function previewTerms(PlatformPrivateOffer $offer, WorkspacePlanCatalog $catalog, Currency $currency, ?PlatformSubscription $subscription): void
    {
        try {
            $existing = null;

            if ($offer->isBillingChange() && $subscription !== null) {
                $existing = [
                    'amount_minor' => (int) PlatformMoney::toMinor((string) $subscription->price_snapshot, (string) $currency->code),
                    'effective_at' => $subscription->trial_ends_at?->isFuture() ? $subscription->trial_ends_at : ($subscription->current_period_end ?? now()),
                ];
            }

            $this->calculator->calculate($offer, $catalog, (string) $currency->code, true, now(), $existing);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['terms' => $e->getMessage()]);
        }
    }

    private function defaultLabel(WorkspacePlanCatalog $catalog, ?string $email, ?Workspace $workspace): string
    {
        return trim(($workspace?->name ?? $email ?? 'Customer') . ' – ' . $catalog->display_name);
    }

    /** @return array<string, mixed> */
    private function auditTerms(PlatformPrivateOffer $offer): array
    {
        return $offer->only([
            'kind', 'workspace_plan_catalog_id', 'customer_email', 'target_workspace_id', 'trial_days', 'custom_price',
            'discount_type', 'discount_value', 'discount_duration', 'discount_periods', 'redemption_limit',
        ]) + ['expires_at' => $offer->expires_at?->toIso8601String()];
    }
}
