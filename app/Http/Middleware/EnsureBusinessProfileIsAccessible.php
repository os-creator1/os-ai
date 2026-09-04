<?php

namespace App\Http\Middleware;

use App\Library\Workspace\WorkspaceManager;
use App\Repositories\Contracts\BusinessRepository;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Boundary A (Design System M2 A2 nonvisual remediation, Finding 6): gates
 * the customer direct Business-profile route group before controller
 * dispatch and before UpdateBusinessRequest is resolved/validated, so a
 * malformed PUT against an inactive Workspace is denied identically to a
 * well-formed one. Reuses WorkspaceManager::userCanAccessBusiness()
 * (RFC-003 §14.1) verbatim -- never a second access algorithm. A missing
 * customer or missing primary Business both pass through unchanged,
 * deferring entirely to BusinessController's own existing "redirect to
 * onboarding" logic.
 */
class EnsureBusinessProfileIsAccessible
{
    public function __construct(
        private readonly BusinessRepository $businessRepository,
        private readonly WorkspaceManager $workspaceManager,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (! auth()->check()) {
            return $next($request);
        }

        $customer = auth()->user()->customer;

        if ($customer === null) {
            return $next($request);
        }

        $business = $this->businessRepository->findPrimaryByCustomer($customer->user_id);

        if ($business === null) {
            return $next($request);
        }

        if (! $this->workspaceManager->userCanAccessBusiness($customer->user_id, $business)) {
            abort(404);
        }

        return $next($request);
    }
}
