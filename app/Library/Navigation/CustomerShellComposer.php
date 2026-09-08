<?php

namespace App\Library\Navigation;

use App\Library\ViewAs\ViewAsManager;
use App\Models\User;
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
            'customerMenu' => $this->menuBuilder->build($context, $user),
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
