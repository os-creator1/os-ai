<?php

namespace App\Http\Controllers\Customer\Business;

use App\Http\Controllers\Controller;
use App\Http\Requests\Business\BusinessServiceNameRequest;
use App\Library\Business\BusinessServiceManager;
use App\Library\Workspace\BusinessRouteAccess;
use App\Models\Business;
use App\Models\BusinessService;
use App\Models\Workspace;
use App\Repositories\Contracts\WorkspaceRepository;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;

/**
 * Settings → Business → Services.
 *
 * The services THIS Business offers (what its website and local SEO are about) -- not catalog items. Every write
 * delegates to BusinessServiceManager, which reuses the canonical BusinessService sync; this controller performs none.
 *
 * Tenancy: Workspace by uid -> Business inside it -> route access; anything else is 404. Everyone who can reach the
 * Business can read; changing needs Workspace owner-or-active-Admin authority, re-checked by the manager.
 */
class BusinessServicesController extends Controller
{
    public function __construct(
        private readonly WorkspaceRepository $workspaceRepository,
        private readonly BusinessServiceManager $services,
    ) {
    }

    public function index(string $workspaceUid, string $businessUid): View
    {
        [$workspace, $business] = $this->resolveBusiness($workspaceUid, $businessUid);

        return view('customer.business.services.index', [
            'workspace' => $workspace,
            'business' => $business,
            'services' => $this->services->services($business),
            'canManage' => $this->services->canManage((int) Auth::id(), $business),
        ]);
    }

    public function store(BusinessServiceNameRequest $request, string $workspaceUid, string $businessUid): RedirectResponse
    {
        [, $business] = $this->resolveBusiness($workspaceUid, $businessUid);

        return $this->run($workspaceUid, $businessUid, fn () => 'Saved ' . $this->services->add((int) Auth::id(), $business, (string) $request->validated('name'))->name . '.');
    }

    public function rename(BusinessServiceNameRequest $request, string $workspaceUid, string $businessUid, string $serviceUid): RedirectResponse
    {
        [, $business] = $this->resolveBusiness($workspaceUid, $businessUid);
        $service = $this->resolveService($business, $serviceUid);

        return $this->run($workspaceUid, $businessUid, fn () => 'Saved ' . $this->services->rename((int) Auth::id(), $business, $service, (string) $request->validated('name'))->name . '.');
    }

    public function activate(string $workspaceUid, string $businessUid, string $serviceUid): RedirectResponse
    {
        [, $business] = $this->resolveBusiness($workspaceUid, $businessUid);
        $service = $this->resolveService($business, $serviceUid);

        return $this->run($workspaceUid, $businessUid, fn () => $this->services->activate((int) Auth::id(), $business, $service)->name . ' is active.');
    }

    public function deactivate(string $workspaceUid, string $businessUid, string $serviceUid): RedirectResponse
    {
        [, $business] = $this->resolveBusiness($workspaceUid, $businessUid);
        $service = $this->resolveService($business, $serviceUid);

        return $this->run($workspaceUid, $businessUid, fn () => $this->services->deactivate((int) Auth::id(), $business, $service)->name . ' is turned off. It is kept, and you can turn it back on.');
    }

    public function makePrimary(string $workspaceUid, string $businessUid, string $serviceUid): RedirectResponse
    {
        [, $business] = $this->resolveBusiness($workspaceUid, $businessUid);
        $service = $this->resolveService($business, $serviceUid);

        return $this->run($workspaceUid, $businessUid, fn () => $this->services->makePrimary((int) Auth::id(), $business, $service)->name . ' is now your primary service.');
    }

    /** @param  callable(): string  $action */
    private function run(string $workspaceUid, string $businessUid, callable $action): RedirectResponse
    {
        $back = redirect()->route('customer.workspaces.businesses.services.index', [$workspaceUid, $businessUid]);

        try {
            return $back->with('flash_success', $action());
        } catch (InvalidArgumentException $e) {
            return $back->with('flash_error', $e->getMessage())->withInput();
        }
    }

    /** @return array{0: Workspace, 1: Business} */
    private function resolveBusiness(string $workspaceUid, string $businessUid): array
    {
        $workspace = $this->workspaceRepository->findByUid($workspaceUid);

        if ($workspace === null || ! $workspace->is_active) {
            abort(404);
        }

        $business = $this->workspaceRepository->businessesForWorkspace($workspace)->firstWhere('uid', $businessUid);

        if ($business === null || ! app(BusinessRouteAccess::class)->actorMayUseBusinessRoute(Auth::user(), $workspace, $business)) {
            abort(404);
        }

        return [$workspace, $business];
    }

    private function resolveService(Business $business, string $serviceUid): BusinessService
    {
        $service = $business->services()->where('uid', $serviceUid)->first();

        if ($service === null) {
            abort(404);
        }

        return $service;
    }
}
