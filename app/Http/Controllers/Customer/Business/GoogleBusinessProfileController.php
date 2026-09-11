<?php

namespace App\Http\Controllers\Customer\Business;

use App\Enums\Business\BusinessServiceMode;
use App\Enums\Business\BusinessStatus;
use App\Enums\Entitlement\PlatformFeature;
use App\Exceptions\GoogleBusinessProfile\GoogleBusinessProfileConcurrencyException;
use App\Exceptions\GoogleBusinessProfile\GoogleBusinessProfileConfigurationException;
use App\Exceptions\GoogleBusinessProfile\GoogleBusinessProfileProviderException;
use App\Exceptions\GoogleBusinessProfile\GoogleLocationAlreadyClaimedException;
use App\Exceptions\Workspace\BusinessWorkspaceMismatchException;
use App\Exceptions\Workspace\WorkspaceBusinessNotFoundException;
use App\Http\Controllers\Customer\CustomerBaseController;
use App\Http\Requests\GoogleBusinessProfile\GoogleBusinessProfileBindRequest;
use App\Http\Requests\GoogleBusinessProfile\GoogleBusinessProfileUnbindRequest;
use App\Library\Entitlement\EntitlementManager;
use App\Library\GoogleBusinessProfile\GoogleBusinessProfileBindingManager;
use App\Library\GoogleBusinessProfile\GoogleBusinessProfileCandidateTokenSigner;
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
use App\Models\Workspace;
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
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use LogicException;

/**
 * Google Business Profile Slice A — the Business-scoped, READ-ONLY
 * management surface (contract §17, §18).
 *
 * Every Business-scoped action runs the mandatory chain (§15.1):
 * Workspace by UID → Business inside that Workspace →
 * WorkspaceManager::userCanAccessBusiness() → active Business →
 * EntitlementManager::decide() for
 * PlatformFeature::GoogleBusinessProfileModule → the connection/binding
 * resolved INSIDE that Business. Every tenancy failure is 404, never 403.
 * Auth::id() is only ever the capability subject and the audit actor.
 *
 * THE OAUTH CALLBACK IS DIFFERENT AND DELIBERATELY SO (correction item 1).
 * Google matches redirect_uri exactly against a registered URI, so the
 * callback is ONE FIXED, TENANT-FREE ROUTE. It therefore cannot start from
 * route parameters: it starts from the signed state, resolves the Business
 * and its Workspace from the database, and only then re-runs the entire
 * chain. Every failure there is 404 so it never reveals whether a Business
 * exists.
 *
 * SLICE A WRITES NOTHING TO GOOGLE.
 */
class GoogleBusinessProfileController extends CustomerBaseController
{
    /** The heading shown above customerMessage() when the platform cannot connect to Google at all. */
    private const CONNECTION_UNAVAILABLE_TITLE = 'Google connection unavailable';

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
        private readonly GoogleBusinessProfileCandidateTokenSigner $candidateTokens,
    ) {
    }

    /**
     * Contract §17.1 — the bare /gbp entry/selector. NEVER guesses a
     * Business. "Accessible" includes entitlement, so a Core-tier Business
     * never appears.
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
     * Contract §22 / §13.3 — computed AT READ TIME, never persisted.
     *
     * MULTI-LOCATION CORRECTION — addressed by BINDING, not by Business.
     * $bindingUid is resolved strictly inside the already-resolved
     * Business (§15.3), so a valid binding uid owned by another Business
     * or Workspace is a 404 exactly like an unknown one. There is no
     * implicit route-model binding: the uid never reaches the database
     * without the Business in the same query.
     */
    public function comparison(string $workspaceUid, string $businessUid, string $bindingUid): View|Factory|Application|RedirectResponse
    {
        $this->authorize('view_google_business_profile');
        [, $business] = $this->resolveEntitledBusiness($workspaceUid, $businessUid);

        $binding = $this->bindingRepository->findByUidForBusiness($business, $bindingUid);

        abort_unless($binding !== null, 404);

        if ($binding->businessLocation === null) {
            return redirect()->route('customer.workspaces.businesses.gbp.index', [$workspaceUid, $businessUid]);
        }

        return view('customer.business.googleBusinessProfile.comparison', [
            'workspaceUid' => $workspaceUid,
            'businessUid' => $businessUid,
            'business' => $business,
            'comparisons' => [[
                'binding' => $binding,
                'location' => $binding->businessLocation,
                'rows' => $this->comparator->compare($business, $binding->businessLocation, $binding),
                'mirrorIsFresh' => $binding->mirrorIsFresh(),
            ]],
            'failures' => [],
            'ephemeral' => false,
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
     * Contract §9.3 — connect initiation.
     *
     * Correction item 9: this is a CSRF-protected POST because it mutates
     * connection state, the nonce, actor attribution and the ledger.
     * Correction item 7: configuration is validated inside beginConnect()
     * BEFORE any of that state is written, so a misconfigured deployment
     * leaves nothing behind.
     */
    public function connect(string $workspaceUid, string $businessUid): RedirectResponse
    {
        $this->authorize('manage_google_business_profile');
        [, $business] = $this->resolveEntitledBusiness($workspaceUid, $businessUid);

        if ($demo = $this->demoGuard($workspaceUid, $businessUid)) {
            return $demo;
        }

        // Correction item 4 — there is no "reconnect a healthy connection"
        // path. Refusing here means no state change and no provider call.
        $existing = $this->connectionRepository->findForBusiness($business);

        if ($existing !== null && $existing->isActive()) {
            return $this->redirectWithError($workspaceUid, $businessUid, 'This business is already connected to Google. Disconnect first to connect a different account.');
        }

        try {
            $url = $this->connections->beginConnect($business, (int) Auth::id());
        } catch (GoogleBusinessProfileConfigurationException $exception) {
            // Security Remediation Slice 0 §16.A.4 (D-21) — the exact
            // setting name is an operator diagnostic, logged here, never
            // customer-visible. The customer only ever sees customerMessage().
            Log::error('Google Business Profile configuration error.', [
                'reason' => $exception->reason,
                'operator_message' => $exception->operatorMessage(),
                'workspace_uid' => $workspaceUid,
                'business_uid' => $businessUid,
            ]);

            return $this->redirectWithError($workspaceUid, $businessUid, $exception->customerMessage(), self::CONNECTION_UNAVAILABLE_TITLE);
        } catch (GoogleBusinessProfileConcurrencyException $exception) {
            return $this->redirectWithError($workspaceUid, $businessUid, $exception->userMessage());
        } catch (LogicException) {
            return $this->redirectWithError($workspaceUid, $businessUid, 'This business is already connected to Google.');
        }

        return redirect()->away($url);
    }

    /**
     * Contract §9.5, as corrected by item 1 — THE ONE FIXED, TENANT-FREE
     * OAUTH CALLBACK.
     *
     * Validation order, all failures 404 with ZERO token exchange and no
     * disclosure of whether a Business exists:
     *
     *   1. signed state present, signature valid, not expired
     *   2. Business resolved EXCLUSIVELY from the signed identifier;
     *      connection resolved from that Business
     *   3. that Business's Workspace resolved from the database
     *   4. active Workspace, Business inside it, userCanAccessBusiness(),
     *      active Business, entitlement, manage permission
     *   5. the callback actor is the actor who initiated THIS attempt
     *      (item 2)
     *   6. only then is the nonce consumed, atomically and once
     *   7. only after successful consumption is the code exchanged
     *   8. redirect using the canonical Workspace/Business UIDs
     *
     * This method never authenticates a user, never creates one, and never
     * touches email_verified_at.
     */
    public function callback(Request $request): RedirectResponse
    {
        // 1 — state first, before any tenant data is touched.
        $payload = $this->stateSigner->verify($request->query('state'));

        if ($payload === null) {
            abort(404);
        }

        // 2 — Business and connection come ONLY from the signed state.
        $business = Business::query()->find($payload['b']);

        if ($business === null) {
            abort(404);
        }

        $connection = $this->connectionRepository->findForBusiness($business);

        if ($connection === null) {
            abort(404);
        }

        // 3 — the Workspace is looked up, never supplied by the caller.
        if ($business->workspace_id === null) {
            abort(404);
        }

        $workspace = Workspace::query()->find($business->workspace_id);

        // 4 — the complete chain, re-run from scratch.
        if ($workspace === null || ! $workspace->is_active) {
            abort(404);
        }

        if ($this->workspaceRepository->businessesForWorkspace($workspace)->firstWhere('uid', $business->uid) === null) {
            abort(404);
        }

        if (! $this->workspaceManager->userCanAccessBusiness((int) Auth::id(), $business)) {
            abort(404);
        }

        if ($business->status !== BusinessStatus::Active) {
            abort(404);
        }

        try {
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

        // Deliberately 404 rather than the usual 401: this route has no
        // tenant parameters, so a permission-shaped response would itself
        // disclose that the signed Business exists and is reachable.
        if (Gate::denies('manage_google_business_profile')) {
            abort(404);
        }

        // 5 — item 2: the callback actor must be the one who started THIS
        // attempt. Checked BEFORE consumption, so a mismatched actor
        // cannot burn the rightful actor's still-valid nonce.
        if (! $this->connections->attemptBelongsToActor($connection, (int) Auth::id())) {
            abort(404);
        }

        // 6 — atomic, single-use consumption.
        if (! $this->stateSigner->consume((int) $business->id, $payload['n'])) {
            abort(404);
        }

        $workspaceUid = (string) $workspace->uid;
        $businessUid = (string) $business->uid;

        if ($request->query('error') !== null || $request->query('code') === null) {
            return $this->redirectWithError($workspaceUid, $businessUid, 'Google did not complete the connection.');
        }

        // 7 — only now.
        try {
            $this->connections->completeConnect($connection, (string) $request->query('code'), (int) Auth::id());
        } catch (GoogleBusinessProfileConfigurationException $exception) {
            // Security Remediation Slice 0 §16.A.4 (D-21) — see the
            // identical handling in connect() above.
            Log::error('Google Business Profile configuration error.', [
                'reason' => $exception->reason,
                'operator_message' => $exception->operatorMessage(),
                'workspace_uid' => $workspaceUid,
                'business_uid' => $businessUid,
            ]);

            return $this->redirectWithError($workspaceUid, $businessUid, $exception->customerMessage(), self::CONNECTION_UNAVAILABLE_TITLE);
        } catch (GoogleBusinessProfileConcurrencyException $exception) {
            return $this->redirectWithError($workspaceUid, $businessUid, $exception->userMessage());
        } catch (GoogleBusinessProfileProviderException $exception) {
            return $this->redirectWithError($workspaceUid, $businessUid, $exception->userMessage());
        }

        // 8 — canonical UIDs, resolved server-side.
        return redirect()
            ->route('customer.workspaces.businesses.gbp.locations', [$workspaceUid, $businessUid])
            ->with(['status' => 'success', 'message' => 'Google account connected. Choose the location to link.']);
    }

    /**
     * Contract §8.3 — REQUEST-SCOPED enumeration. Nothing is persisted and
     * nothing is pre-selected.
     *
     * Correction item 5: each rendered candidate carries a short-lived
     * HMAC token binding it to this Business, connection, actor and
     * account/location pair. The bind POST returns that token instead of
     * raw resource names.
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

        $offers = [];

        foreach ($result['candidates'] as $candidate) {
            $offers[] = [
                'candidate' => $candidate,
                'token' => $this->candidateTokens->issue($business, $connection, (int) Auth::id(), $candidate),
            ];
        }

        return view('customer.business.googleBusinessProfile.locations', [
            'workspaceUid' => $workspaceUid,
            'businessUid' => $businessUid,
            'business' => $business,
            'accounts' => $result['accounts'],
            'offers' => $offers,
            'locations' => BusinessLocation::query()
                ->where('business_id', $business->id)
                ->orderByDesc('is_primary')
                ->orderBy('id')
                ->get(),
            // MULTI-LOCATION CORRECTION — the COMPLETE existing binding
            // set, keyed by local business_locations.id. The chooser uses
            // it to mark each platform location as already bound or still
            // bindable; one existing binding never prevents another
            // eligible BusinessLocation from being bound.
            'boundByLocationId' => $this->bindingRepository->allForBusinessKeyedByLocationId($business),
        ]);
    }

    /**
     * Contract §19.2 — binding is an EXPLICIT POST.
     *
     * Correction item 5: BOTH provider resource names are derived
     * exclusively from the verified candidate token. There is no parallel
     * raw resource-name field to trust, so a tampered, expired,
     * wrong-actor, wrong-Business, wrong-connection or substituted pair is
     * rejected BEFORE any provider read and before any write.
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

        $pair = $this->candidateTokens->verify(
            $validated['candidate_token'],
            $business,
            $connection,
            (int) Auth::id(),
        );

        if ($pair === null) {
            return $this->redirectWithError(
                $workspaceUid,
                $businessUid,
                'That Google location selection is no longer valid. Choose the location again.',
            );
        }

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
                $pair['account'],
                $pair['location'],
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
     * Contract §39.4 — unbind skips the ENTITLEMENT step so a downgraded
     * Business can still remove its own binding. It makes no provider call.
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
     * Contract §13.5 / §39.4 — disconnect DESTROYS stored authorization
     * and, like unbind, skips the entitlement step so credentials can never
     * be trapped by a downgrade. It makes no provider call.
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

        try {
            $this->connections->disconnect($connection, (int) Auth::id());
        } catch (GoogleBusinessProfileConcurrencyException $exception) {
            return $this->redirectWithError($workspaceUid, $businessUid, $exception->userMessage());
        }

        return redirect()
            ->route('customer.workspaces.businesses.gbp.index', [$workspaceUid, $businessUid])
            ->with(['status' => 'success', 'message' => 'Google account disconnected and stored authorization destroyed.']);
    }

    /**
     * Contract §24.1 — manual refresh, the primary mechanism.
     *
     * Correction item 8: with an effective TTL of ZERO nothing reusable is
     * persisted, so redirecting would show the user nothing. In that case
     * this action RENDERS the freshly-fetched comparison directly, in this
     * same response, from the in-memory result — no session flash, no
     * cache, no queue payload and no ledger field carries the Content, and
     * the next GET correctly reports "refresh required".
     */
    public function refresh(string $workspaceUid, string $businessUid): View|Factory|Application|RedirectResponse
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

        if (! $this->connections->claimRefresh($connection)) {
            return redirect()
                ->route('customer.workspaces.businesses.gbp.index', [$workspaceUid, $businessUid])
                ->with(['status' => 'success', 'message' => 'A refresh is already running for this business.']);
        }

        // MULTI-LOCATION CORRECTION — every binding is refreshed
        // INDEPENDENTLY, and every ephemeral result is collected rather
        // than overwritten. The previous implementation kept only the most
        // recent result and its binding, so a Business with several
        // bindings saw exactly one comparison and silently lost the rest.
        $comparisons = [];
        $failures = [];
        $ephemeral = false;
        $succeeded = 0;

        try {
            foreach ($bindings as $binding) {
                $locationName = $binding->businessLocation?->name;

                try {
                    $result = $this->mirror->refresh($binding, $connection, (int) Auth::id());
                } catch (GoogleBusinessProfileProviderException $exception) {
                    // Contract §24.5/§24.10 — one binding's provider
                    // failure is recorded against ITS OWN ledger operation
                    // and leaves the other bindings' already-completed,
                    // independent read outcomes exactly as they are. We
                    // never claim the failed one refreshed.
                    $failures[] = [
                        'location' => $locationName,
                        'message' => $exception->userMessage(),
                    ];

                    continue;
                }

                $succeeded++;

                if ($result->persisted || $binding->businessLocation === null) {
                    continue;
                }

                // Effective TTL zero — this object is the ONLY place the
                // Content exists. It is rendered below in this same
                // response and never flashed, cached, logged, queued or
                // written to a ledger field.
                $ephemeral = true;

                $comparisons[] = [
                    'binding' => $binding->fresh(),
                    'location' => $binding->businessLocation,
                    'rows' => $this->comparator->compareWithMirror(
                        $business,
                        $binding->businessLocation,
                        $result->mirror(),
                        $result->profile->openStatus,
                    ),
                    'mirrorIsFresh' => true,
                ];
            }
        } finally {
            $this->connections->releaseRefreshClaim($connection);
        }

        if ($ephemeral) {
            return view('customer.business.googleBusinessProfile.comparison', [
                'workspaceUid' => $workspaceUid,
                'businessUid' => $businessUid,
                'business' => $business,
                'comparisons' => $comparisons,
                'failures' => $failures,
                'ephemeral' => true,
            ]);
        }

        if ($succeeded === 0) {
            return $this->redirectWithError(
                $workspaceUid,
                $businessUid,
                $failures[0]['message'] ?? 'Google could not be reached for any linked location.',
            );
        }

        return redirect()
            ->route('customer.workspaces.businesses.gbp.index', [$workspaceUid, $businessUid])
            ->with($failures === []
                ? ['status' => 'success', 'message' => 'Refreshed all ' . $succeeded . ' linked Google ' . ($succeeded === 1 ? 'location' : 'locations') . '.']
                : ['status' => 'error', 'message' => 'Refreshed ' . $succeeded . ' of ' . ($succeeded + count($failures)) . ' linked Google locations. ' . $failures[0]['message']]);
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * MULTI-LOCATION CORRECTION — the overview and settings surfaces now
     * carry EVERY binding the Business owns, never a single inferred one.
     *
     * The schema permits one binding per BusinessLocation and therefore
     * many per Business, so the previous singular $binding/$location pair
     * silently rendered only the lowest-id row and made every other
     * binding invisible and unreachable. Nothing here infers a "primary"
     * GBP binding and nothing collapses the collection: each row carries
     * its own local location, provider identifiers, freshness, comparison
     * availability, health metadata and its own scoped action URLs.
     *
     * @return array<string, mixed>
     */
    private function overviewData(string $workspaceUid, string $businessUid, Business $business): array
    {
        $connection = $this->connectionRepository->findForBusiness($business);

        return [
            'workspaceUid' => $workspaceUid,
            'businessUid' => $businessUid,
            'business' => $business,
            'connection' => $connection,
            'bindings' => $this->bindingViewModels($workspaceUid, $businessUid, $business),
        ];
    }

    /**
     * One presentation row per binding, in a stable oldest-first order.
     *
     * @return array<int, array<string, mixed>>
     */
    private function bindingViewModels(string $workspaceUid, string $businessUid, Business $business): array
    {
        $models = [];

        foreach ($this->bindingRepository->allForBusiness($business) as $binding) {
            $location = $binding->businessLocation;

            $models[] = [
                'binding' => $binding,
                'location' => $location,
                // Provider identifiers are resource names, not Google
                // Content and not personal data — BusinessGoogleLocation
                // structurally has no address column at all (§23.4), so
                // showing them discloses nothing the private-address
                // invariant protects.
                'providerAccountResourceName' => $binding->provider_account_resource_name,
                'providerLocationResourceName' => $binding->provider_location_resource_name,
                'mirrorIsFresh' => $binding->mirrorIsFresh(),
                // A binding whose local location row has gone is still
                // listed (so it can be unlinked) but has no comparison.
                'comparisonAvailable' => $location !== null,
                'comparisonUrl' => $location === null ? null : route(
                    'customer.workspaces.businesses.gbp.comparison',
                    [$workspaceUid, $businessUid, $binding->uid],
                ),
                // Contract §23.6 — the storefront/consent contradiction is
                // SURFACED per location, never silently resolved.
                'addressContradiction' => $location !== null
                    && $location->service_mode === BusinessServiceMode::Storefront
                    && $location->public_address !== true,
                'addressPermitted' => $location !== null && $this->readMask->addressPermittedForLocation($location),
            ];
        }

        return $models;
    }

    /**
     * Contract §15.1 — the mandatory chain.
     *
     * @return array{0: Workspace, 1: Business}
     */
    private function resolveEntitledBusiness(string $workspaceUid, string $businessUid): array
    {
        [$workspace, $business] = $this->resolveAccessibleBusiness($workspaceUid, $businessUid);

        try {
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
     * ONLY by disconnect() and unbind().
     *
     * @return array{0: Workspace, 1: Business}
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
     * entitled.
     *
     * @return array<int, array{0: Workspace, 1: Business}>
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

    /**
     * $title is the calm heading the overview shows above the message
     * (<x-flash-alert>); the status and message are unchanged by it.
     */
    private function redirectWithError(string $workspaceUid, string $businessUid, string $message, ?string $title = null): RedirectResponse
    {
        return redirect()
            ->route('customer.workspaces.businesses.gbp.index', [$workspaceUid, $businessUid])
            ->with(array_filter(['status' => 'error', 'message' => $message, 'message_title' => $title], fn ($value) => $value !== null));
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
