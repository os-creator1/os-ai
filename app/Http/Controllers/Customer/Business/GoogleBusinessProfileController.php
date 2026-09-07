<?php

namespace App\Http\Controllers\Customer\Business;

use App\Enums\Business\BusinessStatus;
use App\Enums\Entitlement\PlatformFeature;
use App\Exceptions\GoogleBusinessProfile\GoogleBusinessProfileProviderException;
use App\Exceptions\GoogleBusinessProfile\GoogleLocationAlreadyClaimedException;
use App\Exceptions\Workspace\BusinessWorkspaceMismatchException;
use App\Exceptions\Workspace\WorkspaceBusinessNotFoundException;
use App\Http\Controllers\Customer\CustomerBaseController;
use App\Http\Requests\GoogleBusinessProfile\GoogleBusinessProfileBindRequest;
use App\Http\Requests\GoogleBusinessProfile\GoogleBusinessProfileUnbindRequest;
use App\Library\Entitlement\EntitlementManager;
use App\Library\GoogleBusinessProfile\GoogleBusinessProfileBindingManager;
use App\Library\GoogleBusinessProfile\GoogleBusinessProfileComparator;
use App\Library\GoogleBusinessProfile\GoogleBusinessProfileConnectionManager;
use App\Library\GoogleBusinessProfile\GoogleBusinessProfileEnumerator;
use App\Library\GoogleBusinessProfile\GoogleBusinessProfileMirrorService;
use App\Library\GoogleBusinessProfile\GoogleBusinessProfileReadMask;
use App\Library\GoogleBusinessProfile\GoogleOAuthStateSigner;
use App\Library\Workspace\WorkspaceManager;
use App\Models\Business;
use App\Models\BusinessGoogleConnection;
use App\Models\BusinessLocation;
use App\Repositories\Contracts\BusinessGoogleConnectionRepository;
use App\Repositories\Contracts\BusinessGoogleLocationRepository;
use App\Repositories\Contracts\BusinessGoogleOperationRepository;
use App\Repositories\Contracts\WorkspaceRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Google Business Profile Slice A — the Business-scoped, READ-ONLY
 * management surface (contract §17, §18).
 *
 * Every action, without exception, runs the mandatory chain (§15.1):
 * Workspace by UID → Business inside that Workspace →
 * WorkspaceManager::userCanAccessBusiness() → active Business →
 * Business-scoped EntitlementManager::decide() for
 * PlatformFeature::GoogleBusinessProfileModule → the connection/binding
 * resolved INSIDE that Business. A foreign Workspace, foreign Business,
 * foreign connection or foreign binding fails closed as 404 exactly like a
 * nonexistent one — never 403 (§15.2). Auth::id() appears only as the
 * capability subject and the audit actor, never as tenant identity; there
 * is no primary-Business inference and no LegacyBusinessResolver.
 *
 * THE LISTING METHOD IS NAMED overview(), NOT index():
 * CustomerBaseController::index() takes zero parameters, so an
 * index(string, string) override is a fatal LSP error (the same reason B4
 * names its listing listing()).
 *
 * SLICE A WRITES NOTHING TO GOOGLE. There is no edit, publish, reply,
 * verification, photo-upload or any other mutation action here, and the
 * provider interface this controller depends on declares no method that
 * could perform one (§14.2).
 */
class GoogleBusinessProfileController extends CustomerBaseController
{
    public function __construct(
        private readonly WorkspaceRepository $workspaceRepository,
        private readonly WorkspaceManager $workspaceManager,
        private readonly EntitlementManager $entitlementManager,
        private readonly BusinessGoogleConnectionRepository $connectionRepository,
        private readonly BusinessGoogleLocationRepository $bindingRepository,
        private readonly BusinessGoogleOperationRepository $operationRepository,
        private readonly GoogleBusinessProfileConnectionManager $connections,
        private readonly GoogleBusinessProfileEnumerator $enumerator,
        private readonly GoogleBusinessProfileBindingManager $bindings,
        private readonly GoogleBusinessProfileMirrorService $mirror,
        private readonly GoogleBusinessProfileComparator $comparator,
        private readonly GoogleBusinessProfileReadMask $readMask,
        private readonly GoogleOAuthStateSigner $stateSigner,
    ) {
    }

    /**
     * Contract §17.1 — the bare /gbp entry/selector. NEVER guesses a
     * Business: zero accessible show an empty state, exactly one redirects
     * straight through, several show a chooser.
     *
     * "Accessible" here means the Business passed the FULL §15 chain,
     * entitlement included, so a Core-tier Business never appears.
     */
    public function entry(): View|Factory|Application|RedirectResponse
    {
        $this->authorize('view_google_business_profile');

        $accessible = $this->entitledBusinesses();

        if (count($accessible) === 0) {
            return view('customer.business.googleBusinessProfile.entry', ['accessible' => []]);
        }

        if (count($accessible) === 1) {
            [$workspace, $business] = $accessible[0];

            return redirect()->route('customer.workspaces.businesses.gbp.index', [$workspace->uid, $business->uid]);
        }

        return view('customer.business.googleBusinessProfile.entry', ['accessible' => $accessible]);
    }

    public function overview(string $workspaceUid, string $businessUid): View|Factory|Application
    {
        $this->authorize('view_google_business_profile');
        [, $business] = $this->resolveEntitledBusiness($workspaceUid, $businessUid);

        return view('customer.business.googleBusinessProfile.index', $this->overviewData($workspaceUid, $businessUid, $business));
    }

    /**
     * Contract §22 / §13.3 — the comparison, computed AT READ TIME from
     * live platform models plus a non-expired mirror. It is never
     * persisted and never cached.
     */
    public function comparison(string $workspaceUid, string $businessUid): View|Factory|Application|RedirectResponse
    {
        $this->authorize('view_google_business_profile');
        [, $business] = $this->resolveEntitledBusiness($workspaceUid, $businessUid);

        $binding = $this->bindingRepository->findForBusiness($business);

        if ($binding === null || $binding->businessLocation === null) {
            return redirect()->route('customer.workspaces.businesses.gbp.index', [$workspaceUid, $businessUid]);
        }

        return view('customer.business.googleBusinessProfile.comparison', [
            'workspaceUid' => $workspaceUid,
            'businessUid' => $businessUid,
            'business' => $business,
            'binding' => $binding,
            'location' => $binding->businessLocation,
            'rows' => $this->comparator->compare($business, $binding->businessLocation, $binding),
            'mirrorIsFresh' => $binding->mirrorIsFresh(),
        ]);
    }

    public function settings(string $workspaceUid, string $businessUid): View|Factory|Application
    {
        $this->authorize('view_google_business_profile');
        [, $business] = $this->resolveEntitledBusiness($workspaceUid, $businessUid);

        return view('customer.business.googleBusinessProfile.settings', array_merge(
            $this->overviewData($workspaceUid, $businessUid, $business),
            ['operations' => $this->operationRepository->recentForBusiness($business, 20)],
        ));
    }

    /**
     * Contract §9.3 — connect initiation. Redirects AWAY to Google with a
     * signed, single-use, expiring state.
     */
    public function connect(string $workspaceUid, string $businessUid): RedirectResponse
    {
        $this->authorize('manage_google_business_profile');
        [, $business] = $this->resolveEntitledBusiness($workspaceUid, $businessUid);

        if ($demo = $this->demoGuard($workspaceUid, $businessUid)) {
            return $demo;
        }

        $url = $this->connections->beginConnect($business, (int) Auth::id());

        return redirect()->away($url);
    }

    /**
     * Contract §9.5 — the OAuth callback. Registered INSIDE the
     * authenticated Business-scoped group, so it inherits
     * ['web','auth','can:access_backend','ValidProduct','twofactor'].
     *
     * THE TWELVE REVALIDATION STEPS RUN IN ORDER AND EVERY FAILURE IS 404
     * BEFORE ANY TOKEN EXCHANGE. The Business comes only from our signed
     * state; the route parameter must agree with it, and nothing Google
     * returns is ever used to select a Business.
     *
     * This method never authenticates a user, never creates one, never
     * touches email_verified_at and never calls findOrCreateSocial().
     */
    public function callback(Request $request, string $workspaceUid, string $businessUid): RedirectResponse
    {
        // Steps 5-7, 9-12 (authenticated actor, Workspace, Business,
        // access, active, entitlement) plus the manage permission.
        $this->authorize('manage_google_business_profile');
        [, $business] = $this->resolveEntitledBusiness($workspaceUid, $businessUid);

        // Steps 1-3: state present, signature valid, not expired.
        $payload = $this->stateSigner->verify($request->query('state'));

        if ($payload === null) {
            abort(404);
        }

        // Step 8: the Business comes ONLY from our signed state.
        if ($payload['b'] !== (int) $business->id) {
            abort(404);
        }

        $connection = $this->connectionRepository->findForBusiness($business);

        if ($connection === null) {
            abort(404);
        }

        // Step 4: ATOMIC single-use nonce consumption. A replay affects
        // zero rows and 404s here — still before any token exchange.
        if (! $this->stateSigner->consume((int) $business->id, $payload['n'])) {
            abort(404);
        }

        if ($request->query('error') !== null || $request->query('code') === null) {
            // Google declined or the user cancelled. A neutral state, not
            // an exception page, and the provider payload is never echoed.
            return $this->redirectWithError($workspaceUid, $businessUid, 'Google did not complete the connection.');
        }

        try {
            $this->connections->completeConnect($connection, (string) $request->query('code'), (int) Auth::id());
        } catch (GoogleBusinessProfileProviderException $exception) {
            return $this->redirectWithError($workspaceUid, $businessUid, $exception->userMessage());
        }

        return redirect()
            ->route('customer.workspaces.businesses.gbp.locations', [$workspaceUid, $businessUid])
            ->with(['status' => 'success', 'message' => 'Google account connected. Choose the location to link.']);
    }

    /**
     * Contract §8.3 — REQUEST-SCOPED candidate enumeration. Nothing here
     * is persisted, and nothing is pre-selected.
     */
    public function candidates(string $workspaceUid, string $businessUid): View|Factory|Application|RedirectResponse
    {
        $this->authorize('manage_google_business_profile');
        [, $business] = $this->resolveEntitledBusiness($workspaceUid, $businessUid);

        $connection = $this->connectionRepository->findForBusiness($business);

        if ($connection === null || ! $connection->isActive()) {
            return $this->redirectWithError($workspaceUid, $businessUid, 'Connect a Google account first.');
        }

        try {
            $result = $this->enumerator->enumerate($business, $connection, (int) Auth::id());
        } catch (GoogleBusinessProfileProviderException $exception) {
            return $this->redirectWithError($workspaceUid, $businessUid, $exception->userMessage());
        }

        return view('customer.business.googleBusinessProfile.locations', [
            'workspaceUid' => $workspaceUid,
            'businessUid' => $businessUid,
            'business' => $business,
            'accounts' => $result['accounts'],
            'candidates' => $result['candidates'],
            'locations' => BusinessLocation::query()
                ->where('business_id', $business->id)
                ->orderByDesc('is_primary')
                ->orderBy('id')
                ->get(),
            'binding' => $this->bindingRepository->findForBusiness($business),
        ]);
    }

    /**
     * Contract §19.2 — binding is an EXPLICIT POST carrying the user's
     * chosen provider_location_resource_name. Nothing is ever
     * auto-selected or auto-bound.
     */
    public function bind(GoogleBusinessProfileBindRequest $request, string $workspaceUid, string $businessUid): RedirectResponse
    {
        $this->authorize('manage_google_business_profile');
        [, $business] = $this->resolveEntitledBusiness($workspaceUid, $businessUid);

        if ($demo = $this->demoGuard($workspaceUid, $businessUid)) {
            return $demo;
        }

        $connection = $this->connectionRepository->findForBusiness($business);

        if ($connection === null || ! $connection->isActive()) {
            return $this->redirectWithError($workspaceUid, $businessUid, 'Connect a Google account first.');
        }

        $validated = $request->validated();

        // Contract §18.2 — the BusinessLocation is resolved INSIDE the
        // already-resolved Business; a foreign or unknown uid is a 404.
        $location = BusinessLocation::query()
            ->where('business_id', $business->id)
            ->where('uid', $validated['business_location_uid'])
            ->first();

        abort_unless($location !== null, 404);

        try {
            $this->bindings->bind(
                $business,
                $connection,
                $location,
                $validated['provider_account_resource_name'],
                $validated['provider_location_resource_name'],
                (int) Auth::id(),
            );
        } catch (GoogleLocationAlreadyClaimedException $exception) {
            return $this->redirectWithError($workspaceUid, $businessUid, $exception->userMessage());
        } catch (GoogleBusinessProfileProviderException $exception) {
            return $this->redirectWithError($workspaceUid, $businessUid, $exception->userMessage());
        }

        return redirect()
            ->route('customer.workspaces.businesses.gbp.index', [$workspaceUid, $businessUid])
            ->with(['status' => 'success', 'message' => 'Google location linked.']);
    }

    /**
     * Contract §39.4 — unbind runs the §15 chain WITHOUT the entitlement
     * step, so a Business whose plan was downgraded can still remove its
     * own binding. It makes no provider call.
     */
    public function unbind(GoogleBusinessProfileUnbindRequest $request, string $workspaceUid, string $businessUid): RedirectResponse
    {
        $this->authorize('manage_google_business_profile');
        [, $business] = $this->resolveAccessibleBusiness($workspaceUid, $businessUid);

        if ($demo = $this->demoGuard($workspaceUid, $businessUid)) {
            return $demo;
        }

        $binding = $this->bindingRepository->findByUidForBusiness($business, $request->validated()['binding_uid']);

        abort_unless($binding !== null, 404);

        $this->bindings->unbind($binding, (int) Auth::id());

        return redirect()
            ->route('customer.workspaces.businesses.gbp.index', [$workspaceUid, $businessUid])
            ->with(['status' => 'success', 'message' => 'Google location unlinked.']);
    }

    /**
     * Contract §13.5 / §39.4 — disconnect DESTROYS stored authorization,
     * and like unbind it deliberately skips the entitlement step so
     * credentials can never be trapped by a downgrade. It makes no
     * provider call.
     */
    public function disconnect(string $workspaceUid, string $businessUid): RedirectResponse
    {
        $this->authorize('manage_google_business_profile');
        [, $business] = $this->resolveAccessibleBusiness($workspaceUid, $businessUid);

        if ($demo = $this->demoGuard($workspaceUid, $businessUid)) {
            return $demo;
        }

        $connection = $this->connectionRepository->findForBusiness($business);

        if ($connection === null) {
            return redirect()->route('customer.workspaces.businesses.gbp.index', [$workspaceUid, $businessUid]);
        }

        $this->connections->disconnect($connection, (int) Auth::id());

        return redirect()
            ->route('customer.workspaces.businesses.gbp.index', [$workspaceUid, $businessUid])
            ->with(['status' => 'success', 'message' => 'Google account disconnected and stored authorization destroyed.']);
    }

    /**
     * Contract §24.1 — manual refresh, the primary mechanism. Throttled,
     * ledger-recorded, concurrency-claimed, and always outside a
     * transaction.
     */
    public function refresh(string $workspaceUid, string $businessUid): RedirectResponse
    {
        $this->authorize('manage_google_business_profile');
        [, $business] = $this->resolveEntitledBusiness($workspaceUid, $businessUid);

        if ($demo = $this->demoGuard($workspaceUid, $businessUid)) {
            return $demo;
        }

        $connection = $this->connectionRepository->findForBusiness($business);

        if ($connection === null || ! $connection->isActive()) {
            return $this->redirectWithError($workspaceUid, $businessUid, 'Connect a Google account first.');
        }

        $bindings = $this->bindingRepository->allForBusiness($business);

        if ($bindings->isEmpty()) {
            return $this->redirectWithError($workspaceUid, $businessUid, 'Link a Google location first.');
        }

        // Contract §24.3 — per-connection concurrency of one. A concurrent
        // refresh returns immediately without a provider call and without
        // an error.
        if (! $this->connections->claimRefresh($connection)) {
            return redirect()
                ->route('customer.workspaces.businesses.gbp.index', [$workspaceUid, $businessUid])
                ->with(['status' => 'success', 'message' => 'A refresh is already running for this business.']);
        }

        try {
            foreach ($bindings as $binding) {
                $this->mirror->refresh($binding, $connection, (int) Auth::id());
            }
        } catch (GoogleBusinessProfileProviderException $exception) {
            return $this->redirectWithError($workspaceUid, $businessUid, $exception->userMessage());
        } finally {
            $this->connections->releaseRefreshClaim($connection);
        }

        return redirect()
            ->route('customer.workspaces.businesses.gbp.index', [$workspaceUid, $businessUid])
            ->with(['status' => 'success', 'message' => 'Google profile refreshed.']);
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function overviewData(string $workspaceUid, string $businessUid, Business $business): array
    {
        $connection = $this->connectionRepository->findForBusiness($business);
        $binding = $this->bindingRepository->findForBusiness($business);
        $location = $binding?->businessLocation;

        return [
            'workspaceUid' => $workspaceUid,
            'businessUid' => $businessUid,
            'business' => $business,
            'connection' => $connection,
            'binding' => $binding,
            'location' => $location,
            'mirrorIsFresh' => $binding?->mirrorIsFresh() ?? false,
            // Contract §23.6 — the storefront/consent contradiction is
            // SURFACED, never silently resolved.
            'addressContradiction' => $location !== null
                && $location->service_mode === \App\Enums\Business\BusinessServiceMode::Storefront
                && $location->public_address !== true,
            'addressPermitted' => $location !== null && $this->readMask->addressPermittedForLocation($location),
        ];
    }

    /**
     * Contract §15.1 — the mandatory chain, mirroring
     * Business\AutomationsController::resolveEntitledBusiness() and
     * Business\WebsiteController::resolveEntitledBusiness() exactly.
     *
     * @return array{0: \App\Models\Workspace, 1: Business}
     */
    private function resolveEntitledBusiness(string $workspaceUid, string $businessUid): array
    {
        [$workspace, $business] = $this->resolveAccessibleBusiness($workspaceUid, $businessUid);

        try {
            // (int) Auth::id() is the audit/actor argument the decision
            // signature requires — never a tenancy decision.
            $decision = $this->entitlementManager->decide(
                $workspace,
                $business,
                PlatformFeature::GoogleBusinessProfileModule->value,
                (int) Auth::id(),
            );
        } catch (WorkspaceBusinessNotFoundException|BusinessWorkspaceMismatchException) {
            abort(404);
        }

        if (! $decision->allowed) {
            abort(404);
        }

        return [$workspace, $business];
    }

    /**
     * Contract §39.4 — the same chain WITHOUT the entitlement step, used
     * ONLY by disconnect() and unbind() so stored credentials can never be
     * trapped by a plan downgrade. Neither makes a provider call.
     *
     * @return array{0: \App\Models\Workspace, 1: Business}
     */
    private function resolveAccessibleBusiness(string $workspaceUid, string $businessUid): array
    {
        $workspace = $this->workspaceRepository->findByUid($workspaceUid);

        if ($workspace === null || ! $workspace->is_active) {
            abort(404);
        }

        $business = $this->workspaceRepository->businessesForWorkspace($workspace)->firstWhere('uid', $businessUid);

        if ($business === null || ! $this->workspaceManager->userCanAccessBusiness((int) Auth::id(), $business)) {
            abort(404);
        }

        if ($business->status !== BusinessStatus::Active) {
            abort(404);
        }

        return [$workspace, $business];
    }

    /**
     * Contract §17.1 — every Business the actor can reach that is ALSO
     * entitled. A Core-tier Business never appears in the chooser.
     *
     * @return array<int, array{0: \App\Models\Workspace, 1: Business}>
     */
    private function entitledBusinesses(): array
    {
        $userId = (int) Auth::id();
        $accessible = [];

        foreach ($this->workspaceRepository->allForUser($userId) as $workspace) {
            if (! $workspace->is_active) {
                continue;
            }

            foreach ($this->workspaceRepository->businessesForWorkspace($workspace) as $business) {
                if (! $this->workspaceManager->userCanAccessBusiness($userId, $business)) {
                    continue;
                }

                if ($business->status !== BusinessStatus::Active) {
                    continue;
                }

                try {
                    $decision = $this->entitlementManager->decide(
                        $workspace,
                        $business,
                        PlatformFeature::GoogleBusinessProfileModule->value,
                        $userId,
                    );
                } catch (WorkspaceBusinessNotFoundException|BusinessWorkspaceMismatchException) {
                    continue;
                }

                if ($decision->allowed) {
                    $accessible[] = [$workspace, $business];
                }
            }
        }

        return $accessible;
    }

    private function redirectWithError(string $workspaceUid, string $businessUid, string $message): RedirectResponse
    {
        return redirect()
            ->route('customer.workspaces.businesses.gbp.index', [$workspaceUid, $businessUid])
            ->with(['status' => 'error', 'message' => $message]);
    }

    private function demoGuard(string $workspaceUid, string $businessUid): ?RedirectResponse
    {
        if (config('app.stage') !== 'demo') {
            return null;
        }

        return redirect()
            ->route('customer.workspaces.businesses.gbp.index', [$workspaceUid, $businessUid])
            ->with(['status' => 'error', 'message' => 'Sorry! This option is not available in demo mode']);
    }
}
