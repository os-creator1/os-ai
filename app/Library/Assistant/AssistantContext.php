<?php

namespace App\Library\Assistant;

use App\Library\Navigation\CustomerContext;
use App\Library\Navigation\MenuEntitlements;
use App\Models\Business;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Gate;

/**
 * Who is asking, about which Business, and what they may reach — resolved by the SERVER from the
 * authenticated request (HomeActionsController::resolve), never from anything the browser or the model said.
 *
 * `actions` is HomeActionRouter's four-way decision (route exists, actor holds a permission that reaches it,
 * view-as allows it, feature entitled), so a tool is offered to — and run for — exactly the actors for whom the
 * module's own page would be reachable. A tool re-checks the module's own Location rules as well.
 */
final readonly class AssistantContext
{
    /**
     * @param  array<string, array{drawer: ?string, drawerUrl: ?string, fullUrl: ?string, label: string}|null>  $actions
     * @param  array<int, string>  $scoped  [workspaceUid, businessUid]
     */
    public function __construct(
        public Workspace $workspace,
        public Business $business,
        public User $user,
        public CustomerContext $customer,
        public MenuEntitlements $entitlements,
        public array $actions,
        public array $scoped,
    ) {
    }

    public function can(string $permission): bool
    {
        return Gate::forUser($this->user)->allows($permission);
    }

    public function entitled(string $feature): bool
    {
        return $this->entitlements->allows($feature);
    }

    /** @return array{drawer: ?string, drawerUrl: ?string, fullUrl: ?string, label: string}|null */
    public function action(string $key): ?array
    {
        return $this->actions[$key] ?? null;
    }

    public function timezone(): string
    {
        return (string) ($this->business->timezone ?: config('app.timezone', 'UTC'));
    }

    public function viewingAsClient(): bool
    {
        return $this->customer->isViewingAsClient();
    }
}
