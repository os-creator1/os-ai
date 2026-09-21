<?php

declare(strict_types=1);

namespace App\Library\Opportunity;

use App\Enums\Entitlement\PlatformFeature;
use App\Enums\Opportunity\OpportunityInitiatedByType;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Opportunity\Exceptions\OpportunityActionNotExecutableException;
use App\Library\Opportunity\Exceptions\OpportunityActorCapabilityRevokedException;
use App\Library\Opportunity\Exceptions\OpportunityApprovalExpiredException;
use App\Library\Opportunity\Exceptions\OpportunityConfirmingPrincipalNotHumanException;
use App\Library\Opportunity\Exceptions\OpportunityEngineDisabledException;
use App\Library\Opportunity\Exceptions\OpportunityEntitlementRevokedException;
use App\Library\Opportunity\Exceptions\OpportunityLocationAccessRevokedException;
use App\Library\Opportunity\Exceptions\OpportunityPaidEffectEstimateMissingException;
use App\Models\BusinessLocation;
use App\Models\Opportunity;
use App\Models\OpportunityActionExecution;
use App\Models\User;
use App\Library\Workspace\WorkspaceManager;
use App\Repositories\Contracts\AccountRepository;
use App\Library\Workspace\LocationAccessGuard;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Implementation Contract 19 §5.4(2) — THE one implementation of the
 * approve-then-execute guard chain.
 *
 * WHY THIS CLASS EXISTS AT ALL. §5.4(2) requires the same chain "at approval
 * and again at each execution attempt", and the engine had no shared seam:
 * approval lived in OpportunityManager::confirmApproval(), execution in
 * beginExecutionAttempt(), and action integrity was already duplicated
 * across three private validators plus the executor. Threading a nine-gate
 * chain through those call sites separately would reproduce, at far higher
 * stakes, exactly the drift that left the kill switch in six methods and
 * absent from thirteen. So the chain is written ONCE, here, and every
 * lifecycle entry point calls it.
 *
 * APPROVAL IS NOT EXECUTION AUTHORITY. Every gate below reads state that can
 * change between the two moments — a permission revoked, a plan downgraded,
 * a Location grant withdrawn, the action reconfigured, the window elapsed.
 * None of them is a cached answer from the approval; each is re-derived from
 * persistence on every call. That is the whole point of §5.4(2)'s closing
 * sentence: "Every gate is re-evaluated at execution; none is inherited from
 * the approval."
 *
 * THE CHAIN, IN ITS FIXED ORDER (§5.4(2)):
 *   1. kill switch            assertEngineEnabled()
 *   2. tenancy                the CALLER's findOwnedForUpdate() — §7.4
 *                             requires the row lock before any guard that
 *                             reads mutable state, so it cannot live here
 *   3. capability             assertActorHoldsCapability()
 *   4. Location               assertLocationAccess()
 *   5. entitlement            assertEntitled()
 *   6. action-hash match      the caller's existing hash guards, unchanged
 *   7. approval freshness     assertApprovalIsFresh()
 *   8. paid-effect guards     assertPaidEffectIsCovered()
 *   9. idempotency claim      the caller's existing UNIQUE-key claim
 *
 * Gates 3-5 are grouped in assertMutableAuthority(); gate 8 is called after
 * the hash and freshness checks. Gates 2, 6 and 9 stay with the caller
 * because they are inseparable from its lock and its writes.
 *
 * WHY THE REFUSALS ARE OpportunityActionNotExecutableException SUBCLASSES.
 * The queued ExecuteOpportunityAction already routes that type to a clean,
 * allowlisted recorded failure without rethrowing
 * (ExecuteOpportunityAction.php:88-100). A revoked capability is permanent
 * until someone fixes it, so it must NOT become a three-attempt retry storm
 * with backoff — it must fail once, visibly, having mutated nothing.
 */
final class OpportunityAuthorityGuard
{
    public const ACTION_COST_FIELDS = [
        'action_cost_payer_type', 'action_cost_payer_workspace_id',
        'action_cost_currency_code', 'action_cost_amount_minor_upper_bound',
        'action_cost_unit_count', 'action_cost_unit_kind', 'action_cost_basis',
        'action_cost_price_version', 'action_cost_estimated_at',
        'action_cost_expires_at', 'action_cost_wallet_sufficient',
    ];
    /**
     * §5.4(2) gate 3 — "the actor's feature permission for the action's
     * domain". One key for the whole Advisor module; see
     * config/customer-permissions.php for why no existing key fitted.
     */
    public const CAPABILITY = 'business_advisor';

    /** §5.4(2) gate 5 — the PlatformFeature this engine belongs to. */
    public const FEATURE = PlatformFeature::AiCooBasic;

    public function __construct(
        private readonly AccountRepository $accounts,
        private readonly EntitlementManager $entitlements,
        private readonly LocationAccessGuard $locations,
        private readonly WorkspaceManager $workspaces,
    ) {
    }

    /**
     * §5.4(2) gate 1, and §5.4(7)'s symmetry requirement.
     *
     * Deliberately a method on the guard rather than an inline
     * `config('opportunity.enabled')` repeated per call site: the reason the
     * switch was honoured by six methods and ignored by thirteen is that it
     * was a copied literal. One implementation cannot drift.
     */
    public function assertEngineEnabled(): void
    {
        if (! config('opportunity.enabled', false)) {
            throw new OpportunityEngineDisabledException();
        }
    }

    /**
     * §5.4(2) gates 3, 4 and 5, in order, against the LOCKED Opportunity.
     *
     * $lockedOpportunity must be the row returned by
     * findOwnedForUpdate() — never a caller-supplied model — so every read
     * below is serialized behind the same row lock that gate 2 took (§7.4).
     *
     * @param  array<string, mixed>  $actionDefinition  the trusted registry entry
     */
    public function assertMutableAuthority(
        Opportunity $lockedOpportunity,
        int $actorUserId,
        string $actionKey,
        array $actionDefinition,
        ?OpportunityActionExecution $execution = null,
    ): void {
        $this->assertActorHoldsCapability($actorUserId);
        $this->assertLocationAccess($lockedOpportunity, $actorUserId, $actionKey);
        $this->assertEntitled($lockedOpportunity, $actorUserId);
    }

    public function assertTenancy(Opportunity $lockedOpportunity, int $actorUserId): void
    {
        $business = $lockedOpportunity->business;

        if ($business === null || ! $this->workspaces->userCanAccessBusiness($actorUserId, $business)) {
            throw new OpportunityActionNotExecutableException('Opportunity tenancy is no longer available.');
        }
    }

    /**
     * §5.4(2) gate 3 — capability, read from the DURABLE permission set.
     *
     * `durablePermissions($user, fresh: true)` and not `hasPermission()`,
     * for two independent reasons that both matter here:
     *
     *  - `hasPermission()` prefers `Session::get('permissions')`, and an
     *    execution runs inside a queued job. Under the `sync` queue driver
     *    that job runs inside the confirming REQUEST, so it would inherit
     *    that request's session copy and happily execute with a capability
     *    revoked seconds earlier — precisely the stale authority §5.4(2)
     *    exists to catch.
     *  - `fresh: true` re-reads the customer row instead of trusting an
     *    already-hydrated relation, so a revocation committed by another
     *    connection after this request booted is still seen.
     *
     * A missing or non-customer User fails closed.
     */
    public function assertActorHoldsCapability(int $actorUserId): void
    {
        $user = User::query()->find($actorUserId);

        if ($user === null) {
            throw OpportunityActorCapabilityRevokedException::forActor($actorUserId, self::CAPABILITY);
        }

        if (! $this->accounts->durablePermissions($user, true)->contains(self::CAPABILITY)) {
            throw OpportunityActorCapabilityRevokedException::forActor($actorUserId, self::CAPABILITY);
        }
    }

    /**
     * §5.4(2) gate 4 — LocationAccessGuard, but ONLY for an action the
     * registry declares Location-bound.
     *
     * A Business-level action has no Location to check, and inventing one
     * (say, "the Business's first Location") would be a fabricated
     * authorization claim. Conversely a Location-bound action that cannot
     * name its Location is refused rather than waved through: the bound
     * Location id comes from the action's own persisted parameters, which
     * are covered by the action hash, so it cannot be swapped without
     * invalidating gate 6.
     */
    public function assertLocationAccess(Opportunity $lockedOpportunity, int $actorUserId, string $actionKey): void
    {
        if (! OpportunityActionRegistry::isLocationBound($actionKey)) {
            return;
        }

        $parameters = $lockedOpportunity->recommended_action['parameters'] ?? [];
        $locationId = is_array($parameters) ? ($parameters['business_location_id'] ?? null) : null;

        if (! is_int($locationId) && ! (is_string($locationId) && ctype_digit($locationId))) {
            throw new OpportunityActionNotExecutableException(
                "Action [{$actionKey}] on Opportunity [{$lockedOpportunity->id}] is Location-bound but names no Business Location."
            );
        }

        $location = BusinessLocation::query()
            ->where('id', (int) $locationId)
            ->where('business_id', $lockedOpportunity->business_id)
            ->first();

        if ($location === null) {
            throw OpportunityLocationAccessRevokedException::forLocation($actorUserId, (int) $locationId);
        }

        if (! $this->locations->userCanAccessLocation($actorUserId, $location)) {
            throw OpportunityLocationAccessRevokedException::forLocation($actorUserId, (int) $location->id);
        }
    }

    /**
     * §5.4(2) gate 5 — EntitlementManager::decide(), the canonical RFC-004
     * §14 authority. Never a second entitlement algorithm and never a cached
     * decision from the approval.
     */
    public function assertEntitled(Opportunity $lockedOpportunity, int $actorUserId): void
    {
        $business = $lockedOpportunity->business;

        if ($business === null) {
            throw new OpportunityActionNotExecutableException(
                "Opportunity [{$lockedOpportunity->id}] has no owning Business."
            );
        }

        $workspace = $business->workspace;

        if ($workspace === null) {
            throw OpportunityEntitlementRevokedException::forFeature(
                (int) $business->id,
                self::FEATURE->value,
                'the Business belongs to no Workspace'
            );
        }

        $decision = $this->entitlements->decide($workspace, $business, self::FEATURE->value, $actorUserId);

        if (! $decision->allowed) {
            throw OpportunityEntitlementRevokedException::forFeature(
                (int) $business->id,
                self::FEATURE->value,
                $decision->reason ?? 'not entitled'
            );
        }
    }

    /**
     * §5.4(3) gate 7 — approval freshness.
     *
     * A NULL window is not "never expires": every path that can reach an
     * execution stamps one, so NULL here means the approval predates this
     * hardening or was never properly requested. It is treated as EXPIRED,
     * because failing closed is the only safe reading of "I cannot tell when
     * this was approved".
     */
    public function assertApprovalIsFresh(int $opportunityId, CarbonInterface|string|null $expiresAt): void
    {
        if ($expiresAt === null) {
            throw OpportunityApprovalExpiredException::forOpportunity($opportunityId, 'an unrecorded time');
        }

        $expiry = $expiresAt instanceof CarbonInterface ? $expiresAt : Carbon::parse($expiresAt);

        if ($expiry->isPast()) {
            throw OpportunityApprovalExpiredException::forOpportunity($opportunityId, $expiry->toDateTimeString());
        }
    }

    /**
     * §5.4(5) gate 8 — a `paid_effect` action may not run without an
     * approved cost estimate.
     *
     * The awaiting-approval Opportunity owns the approved customer action
     * ceiling. At confirmation there is no execution yet; its snapshot is
     * compared with that approval record on each execution attempt. The
     * estimator and live payer/wallet recheck belong to 19.E.
     */
    public function assertPaidEffectIsCovered(
        Opportunity $lockedOpportunity,
        string $actionKey,
        ?OpportunityActionExecution $execution = null,
    ): void {
        if (! OpportunityActionRegistry::hasPaidEffect($actionKey)) {
            return;
        }

        $approval = $this->actionCostSnapshot($lockedOpportunity);
        $complete = $approval['action_cost_payer_type'] !== null
            && $approval['action_cost_payer_workspace_id'] !== null
            && $approval['action_cost_basis'] !== null
            && $approval['action_cost_price_version'] !== null
            && $approval['action_cost_estimated_at'] !== null
            && $approval['action_cost_expires_at'] !== null
            && Carbon::parse($approval['action_cost_expires_at'])->isFuture()
            && $approval['action_cost_wallet_sufficient'] === true
            && ($approval['action_cost_amount_minor_upper_bound'] !== null
                || $approval['action_cost_unit_count'] !== null)
            && ($approval['action_cost_amount_minor_upper_bound'] === null
                || $approval['action_cost_currency_code'] !== null)
            && ($approval['action_cost_unit_count'] === null
                || $approval['action_cost_unit_kind'] !== null);

        if ($complete && $execution !== null) {
            $complete = $approval === $this->actionCostSnapshot($execution);
        }

        if (! $complete) {
            throw OpportunityPaidEffectEstimateMissingException::forAction(
                (int) $lockedOpportunity->id,
                $actionKey
            );
        }
    }

    /** @return array<string, mixed> */
    public function actionCostSnapshot(Opportunity|OpportunityActionExecution $record): array
    {
        $snapshot = [];

        foreach (self::ACTION_COST_FIELDS as $field) {
            $value = $record->{$field};
            $snapshot[$field] = $value instanceof CarbonInterface ? $value->toISOString() : $value;
        }

        return $snapshot;
    }

    /**
     * §5.4(1) — the confirming principal is always a human.
     *
     * The proposal type is deliberately separate from the confirmer type.
     * A COO proposal may be confirmed by a human; a COO principal may not
     * confirm even a customer proposal.
     */
    public function assertConfirmingPrincipalIsHuman(
        int $opportunityId,
        ?int $confirmingUserId,
        OpportunityInitiatedByType $confirmingPrincipalType,
    ): void {
        if ($confirmingUserId === null || $confirmingUserId <= 0) {
            throw OpportunityConfirmingPrincipalNotHumanException::forOpportunity($opportunityId, 'absent');
        }

        if (! $confirmingPrincipalType->mayConfirm() || User::query()->find($confirmingUserId)?->customer === null) {
            throw OpportunityConfirmingPrincipalNotHumanException::forOpportunity($opportunityId, $confirmingPrincipalType->value);
        }
    }

    /**
     * §5.4(3) — the configured approval window, resolved from
     * config/opportunity.php and never a literal. Returns the instant an
     * approval requested now would expire.
     */
    public function approvalExpiryFromNow(): CarbonInterface
    {
        $minutes = (int) config('opportunity.approval_window_minutes');

        if ($minutes < 1) {
            $minutes = 1;
        }

        return Carbon::now()->addMinutes($minutes);
    }
}
