<?php

namespace App\Library\Navigation;

use App\Library\Entitlement\EntitlementManager;
use App\Library\ViewAs\ViewAsManager;
use App\Models\Business;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;

/**
 * Supplies `$customerContext` and `$customerMenu` to the shell partials
 * (sidebar, navbar, breadcrumb) and the shell components. Normally the
 * context was already resolved once by ResolveCustomerContext for this
 * request; if a view renders without the middleware (a direct render in a
 * test, for example) it is resolved lazily here instead, so the shell never
 * falls back to the legacy static menu for a customer.
 */
final class CustomerShellComposer
{
    /**
     * Where this request keeps the entitlement snapshots it has already
     * built, keyed by the resolved context (see menuEntitlementsKey()).
     *
     * A REQUEST attribute, deliberately: Laravel builds a fresh Request for
     * every HTTP request, so the snapshot dies with the request that made it.
     * Nothing is held on this class (a new composer is built for every
     * composed view), in a static, or in the container, so one customer's
     * decisions can never outlive their request or reach another.
     */
    public const MENU_ENTITLEMENTS_ATTRIBUTE = 'customer_shell.menu_entitlements';

    public function __construct(
        private readonly Container $container,
        private readonly CustomerContextResolver $resolver,
        private readonly CustomerMenuBuilder $menuBuilder,
        private readonly ViewAsManager $viewAs,
        private readonly EntitlementManager $entitlements,
    ) {
    }

    public function compose(View $view): void
    {
        $user = Auth::user();

        if (! $user instanceof User || ! $this->isCustomerPortal($user)) {
            $view->with(['customerContext' => null, 'customerMenu' => []]);

            return;
        }

        $context = $this->currentContext($user);

        $view->with([
            'customerContext' => $context,
            'customerMenu' => $this->menuBuilder->build($context, $user, $this->currentMenuEntitlements($context)),
        ]);
    }

    /**
     * Slice 2A §6.4/§6.5 — every menu feature decision, resolved ONCE per
     * HTTP request and shared by every consumer in it: the sidebar, navbar,
     * breadcrumb and every other composed shell view, and the Dashboard body
     * (Slice 4 Correction 1), which reads it before the shell renders.
     *
     * The first consumer runs the one bulk snapshot; every later consumer in
     * the same request receives that very object and issues no query. Before
     * this, each composed shell view rebuilt it — five identical snapshots,
     * about thirty queries, on a single Dashboard request.
     *
     * Only WHERE the answer is kept changed. The decision is still
     * EntitlementManager's own bulk snapshot over the same feature list;
     * no policy, precedence or menu behaviour differs.
     *
     * In the Account frame there is no Business in scope, so this returns the
     * empty snapshot and issues ZERO queries.
     *
     * The Workspace and Business handed to the manager carry only their ids.
     * That is not a shortcut: snapshotBusinessFeatureDecisions() re-reads the
     * Business by id and compares it to the Workspace id, exactly as
     * decide() does — both treat the passed model as an identifier, never as
     * trusted state. Loading the full models here would add two queries to
     * answer questions nothing asks.
     */
    public function currentMenuEntitlements(CustomerContext $context): MenuEntitlements
    {
        $workspace = $context->frameWorkspace();
        $business = $context->selectedBusiness;

        if (! $context->isBusinessFrame() || $workspace === null || $business === null) {
            return MenuEntitlements::none();
        }

        $request = request();
        $key = self::menuEntitlementsKey($context, $workspace->id, $business->id);
        $built = $request->attributes->get(self::MENU_ENTITLEMENTS_ATTRIBUTE, []);

        if (is_array($built) && ($built[$key] ?? null) instanceof MenuEntitlements) {
            return $built[$key];
        }

        $entitlements = MenuEntitlements::forBusiness(
            $this->entitlements,
            (new Workspace)->forceFill(['id' => $workspace->id]),
            (new Business)->forceFill(['id' => $business->id]),
            CustomerMenuBuilder::ENTITLEMENT_GATED_FEATURES,
            $context->userId,
        );

        $built = is_array($built) ? $built : [];
        $built[$key] = $entitlements;
        $request->attributes->set(self::MENU_ENTITLEMENTS_ATTRIBUTE, $built);

        return $entitlements;
    }

    /**
     * Everything the snapshot's answer depends on, so two contexts can never
     * share one: the actor, the frame, the Workspace, the selected Business,
     * the view-as session (a viewed Business never inherits a non-view-as
     * answer, nor the reverse) and the feature list asked about.
     */
    private static function menuEntitlementsKey(CustomerContext $context, int $workspaceId, int $businessId): string
    {
        return implode('|', [
            'user:' . $context->userId,
            'frame:' . $context->frame->value,
            'workspace:' . $workspaceId,
            'business:' . $businessId,
            'view-as:' . ($context->viewAs !== null ? $context->viewAs->sessionId : 'none'),
            'features:' . implode(',', CustomerMenuBuilder::ENTITLEMENT_GATED_FEATURES),
        ]);
    }

    public function currentContext(User $user): CustomerContext
    {
        if ($this->container->bound(CustomerContext::class)) {
            $bound = $this->container->make(CustomerContext::class);

            if ($bound instanceof CustomerContext && $bound->userId === (int) $user->id) {
                return $bound;
            }
        }

        $context = $this->resolver->resolve($user, request(), $this->viewAs->current($user));
        $this->container->instance(CustomerContext::class, $context);

        return $context;
    }

    public static function isCustomerPortal(User $user): bool
    {
        return (bool) $user->is_customer && $user->active_portal === 'customer';
    }
}
