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

        $target = $this->resolveTargetBusiness($request);

        if ($target !== null) {
            $decision = $this->guard->decisionForBusiness($target);
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
     * The route's own bound target resource, when it has one — never a
     * second tenancy algorithm, just reading the business_id every one of
     * these legacy models already carries and the controller already
     * trusts.
     */
    private function resolveTargetBusiness(Request $request): ?Business
    {
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

            return Business::find($value->business_id);
        }

        return null;
    }
}
