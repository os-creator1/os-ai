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
            'customerMenu' => $this->menuBuilder->build($context, $user, $this->menuEntitlements($context)),
        ]);
    }

    /**
     * Slice 2A §6.4/§6.5 — resolve every menu feature decision once.
     *
     * Built here, beside the context that names the selected Workspace and
     * Business, so the whole render costs one bulk snapshot rather than a
     * query per entry. In the Account frame there is no Business in scope,
     * so this returns the empty snapshot and issues ZERO queries.
     *
     * The Workspace and Business handed to the manager carry only their ids.
     * That is not a shortcut: snapshotBusinessFeatureDecisions() re-reads the
     * Business by id and compares it to the Workspace id, exactly as
     * decide() does — both treat the passed model as an identifier, never as
     * trusted state. Loading the full models here would add two queries to
     * answer questions nothing asks.
     */
    private function menuEntitlements(CustomerContext $context): MenuEntitlements
    {
        $workspace = $context->frameWorkspace();
        $business = $context->selectedBusiness;

        if (! $context->isBusinessFrame() || $workspace === null || $business === null) {
            return MenuEntitlements::none();
        }

        return MenuEntitlements::forBusiness(
            $this->entitlements,
            (new Workspace)->forceFill(['id' => $workspace->id]),
            (new Business)->forceFill(['id' => $business->id]),
            CustomerMenuBuilder::ENTITLEMENT_GATED_FEATURES,
            $context->userId,
        );
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
