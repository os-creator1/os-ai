<?php

namespace App\Http\Middleware;

use App\Library\Entitlement\CustomerAccountAccessGuard;
use App\Models\Business;
use App\Models\Campaigns;
use App\Models\ContactGroups;
use App\Models\Contacts;
use App\Models\Reports;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * PR #302 correction 1 — the /api/v3 (Sanctum) counterpart to
 * CustomerAccountAccessGate. That gate lives only in the `web` Kernel
 * middleware group and explicitly no-ops for API/Sanctum traffic, so an
 * Inactive/Suspended customer locked out of the web UI could still call
 * operational endpoints (sms/send, sms/campaign, contact create/update/
 * delete) directly. This closes that bypass at the same request boundary
 * the web gate uses conceptually — before the controller ever runs.
 *
 * REUSES the same authority as the web gate — CustomerAccountAccessResolver,
 * via CustomerAccountAccessGuard — for the actual Active/Inactive/Suspended
 * decision. This class's only responsibility is resolving WHICH
 * Business/Workspace a stateless Sanctum request belongs to.
 *
 * PR #302 CORRECTION 3, finding B. Every route this gate covers predates
 * RFC-004 Workspaces and carries no {workspaceUid}, but several of them ARE
 * addressed to a concrete resource — routes/api.php's own {group_id}/{uid}
 * segments. SubstituteBindings (part of the 'api' Kernel group, ahead of
 * this middleware in the pipeline for every route this covers) has already
 * resolved those into real ContactGroups/Contacts/Campaigns/Reports model
 * instances by the time this runs. resolveTargetBusiness() reads that
 * resource's OWN business_id when one is bound — the SAME field its
 * controller already writes through (e.g. ContactsController::storeContact()
 * creates the new contact inside the routed ContactGroups). A customer's
 * primary Business being Active must never authorize a mutation against a
 * different, locked, Business the same request is actually addressed to.
 *
 * A route with no bound resource at all (sms/send, sms/campaign, creating a
 * brand-new contact group) is genuinely actor/global-scoped — there is
 * nothing to derive a target from — and falls back to
 * CustomerAccountAccessGuard::decisionForActor(), unchanged from correction
 * 1: the customer's own single deterministic Business via
 * LegacyBusinessResolver, failing closed (never guessing) when that is
 * itself ambiguous.
 *
 * PR #302 CORRECTION 4. A route can bind MORE than one resource at once —
 * contacts/{group_id}/update/{uid} binds a ContactGroups AND a Contacts
 * row. Correction 3's resolveTargetBusiness() returned whichever one it
 * found FIRST, so a request whose bound resources belong to two DIFFERENT
 * Businesses (an Active group_id paired with a uid that is actually inside
 * a Suspended one) was evaluated only against the first — the customer's
 * mutation could still land on the locked Business the request never
 * proved it was actually addressed to. This never picks a winner: every
 * bound resource's business_id is collected, and the request proceeds only
 * when they all agree (the ordinary, well-formed case) or there are none at
 * all (the actor-scoped case, unchanged). Any disagreement fails closed —
 * mismatchJsonError() — without ever calling
 * CustomerAccountAccessResolver on either candidate, so neither one's plan
 * state is disclosed to a request that has not proven which Business it
 * actually belongs to.
 *
 * This still is not a second tenancy algorithm: it does not decide WHETHER
 * a Contacts row genuinely belongs to a ContactGroups row (that relationship
 * check belongs to, and now also lives in, the controller — see
 * ContactsController::updateContact()/ContactsHTTPController::updateContact()
 * — since middleware must not be the only protection against a mismatched
 * pair). It only refuses to let two disagreeing business_id readings both
 * pass this gate.
 */
class CustomerAccountAccessApiGate
{
    public function __construct(
        private readonly CustomerAccountAccessGuard $guard,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->is_customer) {
            return $next($request);
        }

        $businessIds = $this->resolveTargetBusinessIds($request);

        if (count($businessIds) > 1) {
            return $this->guard->mismatchJsonError();
        }

        if ($businessIds !== []) {
            $decision = $this->guard->decisionForBusiness(Business::find($businessIds[0]));
        } else {
            $decision = $this->guard->decisionForActor((int) $user->id);

            if ($decision === null) {
                return $this->guard->ambiguousJsonError();
            }
        }

        if (! $decision->isLocked()) {
            return $next($request);
        }

        return $this->guard->jsonError($decision);
    }

    /**
     * Every DISTINCT business_id carried by the route's bound resources —
     * never just the first one. A resource with no business_id at all
     * (never backfilled) contributes nothing; it is neither a target nor a
     * conflict.
     *
     * @return array<int, int>
     */
    private function resolveTargetBusinessIds(Request $request): array
    {
        $ids = [];

        foreach ($request->route()?->parameters() ?? [] as $value) {
            if (! $value instanceof ContactGroups
                && ! $value instanceof Contacts
                && ! $value instanceof Campaigns
                && ! $value instanceof Reports) {
                continue;
            }

            if ($value->business_id === null) {
                continue;
            }

            $ids[(int) $value->business_id] = true;
        }

        return array_keys($ids);
    }
}
