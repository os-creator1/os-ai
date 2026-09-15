<?php

namespace App\Library\Entitlement;

use App\Library\Business\LegacyBusinessResolver;
use App\Models\Business;
use Illuminate\Http\JsonResponse;

/**
 * PR #302 correction 3 — the ONE reusable seam every non-web-session HTTP
 * surface calls to ask "is the Business/Workspace this request touches
 * currently locked", instead of each surface (Sanctum /api/v3, legacy
 * token-authenticated /api/http, legacy resource-addressed web routes)
 * re-deriving its own copy of the same two questions:
 *
 *  1. WHICH Business does this request actually touch? A resource-addressed
 *     request (a route, or a controller call, that already has a concrete
 *     target — a ContactGroups, a Contacts row, a Campaign) is decided by
 *     THAT resource's own Business, via decisionForBusiness(). A request
 *     with no target at all (send a one-off SMS, create a brand-new contact
 *     group) is decided by the actor's own single deterministic Business,
 *     via decisionForActor() — LegacyBusinessResolver, the same
 *     conservative "legacy owner user id -> single deterministic Business"
 *     resolver every other non-Workspace-aware legacy write path already
 *     uses. decisionForActor() never guesses when that is ambiguous (more
 *     than one Business, no single primary): it returns null, and the
 *     caller must fail closed (ambiguousJsonError() below is the shared
 *     JSON shape for that).
 *
 *  2. Given that Business (or none), what does CustomerAccountAccessResolver
 *     say? This class only ever asks that resolver — it is never a second
 *     Active/Inactive/Suspended policy of its own.
 *
 * jsonError()/ambiguousJsonError() exist because every caller of this class
 * is a JSON API surface answering in the same {status, message, reason}
 * shape CustomerAccountAccessGate/CustomerAccountAccessApiGate already use
 * for their own 403s — one shared shape, not one copy per controller.
 */
final class CustomerAccountAccessGuard
{
    public function __construct(
        private readonly LegacyBusinessResolver $legacyBusinessResolver,
        private readonly CustomerAccountAccessResolver $resolver,
    ) {
    }

    /**
     * A concrete target Business is already known (a bound route resource,
     * or a repository/controller that has already loaded it). A null
     * Business (the resource itself is somehow unscoped) is nothing to
     * lock, exactly like a Workspace-less request elsewhere in this gate
     * family.
     */
    public function decisionForBusiness(?Business $business): CustomerAccountAccessDecision
    {
        return $this->resolver->resolve($business?->workspace);
    }

    /**
     * No target resource at all on this request — the actor's own single
     * deterministic Business decides. Returns null only when that is
     * genuinely ambiguous (more than one Business, no single primary):
     * never guessed, never defaulted to "the first one" — the caller must
     * treat a null return as a fail-closed denial.
     */
    public function decisionForActor(int $userId): ?CustomerAccountAccessDecision
    {
        $businessCount = Business::where('customer_id', $userId)->count();

        if ($businessCount === 0) {
            return CustomerAccountAccessDecision::usable();
        }

        $business = $this->legacyBusinessResolver->resolveForCustomer($userId);

        if ($business === null) {
            return null;
        }

        return $this->resolver->resolve($business->workspace);
    }

    public function jsonError(CustomerAccountAccessDecision $decision): JsonResponse
    {
        return response()->json([
            'status' => 'error',
            'message' => $decision->message ?? 'Your account is not currently active.',
            'reason' => $decision->reason,
        ], 403);
    }

    public function ambiguousJsonError(): JsonResponse
    {
        return response()->json([
            'status' => 'error',
            'message' => "We can't determine which account this request belongs to. Please contact support.",
            'reason' => 'workspace_ambiguous',
        ], 403);
    }

    /**
     * PR #302 correction 4 — a multi-resource request (e.g. a contact UID
     * scoped by a contact-group UID) whose bound resources resolve to
     * DIFFERENT Businesses. Never evaluated against either candidate: doing
     * so would either authorize a mutation against the wrong one, or
     * disclose one candidate's plan state to a request that may not even
     * legitimately reach it. This is the multi-resource counterpart to
     * ambiguousJsonError() — same fail-closed shape, a different root
     * cause (a route/relationship mismatch, not an unresolvable actor).
     */
    public function mismatchJsonError(): JsonResponse
    {
        return response()->json([
            'status' => 'error',
            'message' => "We can't determine which account this request belongs to. Please contact support.",
            'reason' => 'resource_mismatch',
        ], 403);
    }
}
