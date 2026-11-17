<?php

namespace App\Http\Middleware;

use App\Enums\Workspace\WorkspaceMemberModule;
use App\Library\Navigation\CustomerContext;
use App\Library\Navigation\CustomerShellComposer;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Per-member module visibility, enforced on the server for direct URLs and
 * AJAX/JSON endpoints alike (the sidebar hiding a module is presentation only).
 *
 * If the matched route belongs to a module the Workspace owner switched OFF for
 * THIS person in THIS Workspace, the answer is the same 404 a foreign or
 * unentitled resource already gets — never a 403 that confirms the module
 * exists. It sits directly after ResolveCustomerContext and
 * CustomerAccountAccessGate in the web group, like the latter.
 *
 * It grants nothing and checks no role, permission or plan: those stay with each
 * controller. It can only refuse, and only for an ACTIVE member of that Workspace
 * (the membership the CustomerContext already carries): the owner, an Agency actor in View As, platform admins
 * and background work are never narrowed. A route no module owns (Home, Settings,
 * Team, billing, ...) is not touched.
 */
class EnforceMemberModuleVisibility
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if (! $user instanceof User || ! CustomerShellComposer::isCustomerPortal($user)) {
            return $next($request);
        }

        $route = $request->route();
        $module = WorkspaceMemberModule::forRouteName($route?->getName());

        if ($module === null) {
            return $next($request);
        }

        $hidden = $this->hiddenModulesFor($request);

        if (in_array($module, $hidden, true)) {
            abort(404);
        }

        return $next($request);
    }

    /**
     * The modules switched off for this actor in the Workspace this request
     * addresses, read from the CustomerContext ResolveCustomerContext already
     * built from one joined query — so enforcement costs no query of its own.
     * The route's own {workspaceUid} wins; a route without one uses the Workspace
     * the context stands in. A Workspace the actor is not a visible member of is
     * simply absent (nothing to narrow), and the controller's own tenancy step
     * refuses it as before.
     *
     * @return list<WorkspaceMemberModule>
     */
    private function hiddenModulesFor(Request $request): array
    {
        $context = $request->attributes->get('customerContext');

        if (! $context instanceof CustomerContext) {
            return [];
        }

        $uid = $request->route()?->parameter('workspaceUid');

        if (is_string($uid) && $uid !== '') {
            foreach ($context->workspaces as $candidate) {
                if ($candidate->uid === $uid) {
                    return $candidate->hiddenModules;
                }
            }

            return [];
        }

        return ($context->selectedWorkspace ?? $context->frameWorkspace())?->hiddenModules ?? [];
    }
}
