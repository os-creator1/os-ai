<?php

namespace App\Http\Middleware;

use App\Library\Business\LegacyBusinessResolver;
use App\Library\Entitlement\CustomerAccountAccessResolver;
use App\Models\Business;
use App\Models\User;
use App\Models\Workspace;
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
 * REUSES the same authority as the web gate — CustomerAccountAccessResolver
 * — for the actual Active/Inactive/Suspended decision. This class's only
 * new responsibility is resolving WHICH Workspace a stateless Sanctum
 * request belongs to, since there is no web-session CustomerContext here.
 *
 * TENANCY. Every route this gate covers predates RFC-004 Workspaces and
 * still authorizes and operates purely off the authenticated User, with no
 * {workspaceUid} anywhere in routes/api.php. Rather than inventing a second
 * mapping, this reuses LegacyBusinessResolver — the same conservative
 * "legacy owner user id -> single deterministic Business" resolver every
 * other non-Workspace-aware legacy write path already uses
 * (EloquentContactsRepository, EloquentCampaignRepository,
 * EloquentSenderIDRepository, RegisterController, and others).
 *
 * AMBIGUITY. LegacyBusinessResolver's own null return does not distinguish
 * "no Business at all" (a legitimate, unlocked state — the same "null
 * Workspace is usable" precedent CustomerAccountAccessResolver itself
 * already applies, e.g. a brand-new customer with nothing to lock yet) from
 * "genuinely ambiguous" (more than one Business, no single primary). This
 * gate tells the two apart itself and fails closed only for the second: a
 * request whose Workspace cannot be resolved unambiguously is refused,
 * never guessed, and never defaulted to "the first one" or "the newest
 * one".
 */
class CustomerAccountAccessApiGate
{
    public function __construct(
        private readonly LegacyBusinessResolver $legacyBusinessResolver,
        private readonly CustomerAccountAccessResolver $resolver,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->is_customer) {
            return $next($request);
        }

        $businessCount = Business::where('customer_id', $user->id)->count();

        if ($businessCount === 0) {
            // Nothing to lock: the same null-Workspace-is-usable case
            // CustomerAccountAccessResolver itself already applies.
            return $next($request);
        }

        $business = $this->legacyBusinessResolver->resolveForCustomer($user->id);

        if ($business === null) {
            // $businessCount > 0 but the resolver still returned null: more
            // than one Business and no single primary — genuinely
            // ambiguous. Never guess which one this request is for.
            return response()->json([
                'status' => 'error',
                'message' => "We can't determine which account this request belongs to. Please contact support.",
                'reason' => 'workspace_ambiguous',
            ], 403);
        }

        $workspace = $business->workspace;
        $decision = $this->resolver->resolve($workspace instanceof Workspace ? $workspace : null);

        if (! $decision->isLocked()) {
            return $next($request);
        }

        return response()->json([
            'status' => 'error',
            'message' => $decision->message ?? 'Your account is not currently active.',
            'reason' => $decision->reason,
        ], 403);
    }
}
