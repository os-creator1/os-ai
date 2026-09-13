<?php

namespace App\Http\Controllers\Customer\Business;

use App\Enums\Business\BusinessStatus;
use App\Enums\Entitlement\PlatformFeature;
use App\Exceptions\Workspace\BusinessWorkspaceMismatchException;
use App\Exceptions\Workspace\WorkspaceBusinessNotFoundException;
use App\Http\Controllers\Customer\Business\Concerns\ResolvesBusinessTenancy;
use App\Http\Controllers\Customer\CustomerBaseController;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Messaging\BusinessMessagingProviderCatalog;
use App\Library\Navigation\CustomerContext;
use App\Library\Workspace\WorkspaceManager;
use App\Models\Business;
use App\Models\CustomerBasedSendingServer;
use App\Models\PhoneNumbers;
use App\Models\Senderid;
use App\Models\SendingServer;
use App\Models\Workspace;
use App\Repositories\Contracts\SendingServerRepository;
use App\Repositories\Contracts\WorkspaceRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\DB;

/**
 * B2 — Business Messaging Channels: a small, simple Business-level
 * connect/manage experience for exactly two launch providers (Twilio,
 * Telnyx), built on top of the existing, unmodified SendingServer backend.
 *
 * A "connection" is represented entirely with existing schema:
 * Business -> CustomerBasedSendingServer -> SendingServer. A B2-created
 * connection always gets its OWN dedicated, non-shared SendingServer row
 * (never silently shared across Businesses); a pre-existing legacy/admin
 * assignment that IS shared (or whose SendingServer's legacy owner isn't
 * this Business's owner) is surfaced read-only ("Managed").
 *
 * Every action resolves its Business via the exact RFC-003 §14.1 boundary
 * (WorkspaceRepository::findByUid()/businessesForWorkspace() +
 * WorkspaceManager::userCanAccessBusiness()), mirroring
 * OutreachController::resolveAccessibleBusiness() verbatim — never
 * business.customer_id === Auth::id().
 *
 * Security Remediation Slice 0 §16.A.3 (D-9). Menu visibility
 * (CustomerMenuBuilder::advancedItems() hiding this entry unless
 * isAgency() && canManageWorkspace()) was never the authorization
 * boundary — every one of the eight public methods below previously
 * gated only on the existing `view_numbers` permission (default true for
 * every customer) plus ordinary Business tenancy, so any Core or Growth
 * customer, or any Business-scoped staff member, could reach this
 * provider-credential surface by direct URL. guardAdvancedProviderAccess()
 * is the actual, additive, fail-closed boundary now: Agency tier, owner-or-
 * active-admin role, the account's own entitlement (checked, not inferred
 * from tier), active Workspace/Business state, all before the existing
 * tenancy resolution and `view_numbers` check — neither of which this
 * change relocates, weakens or replaces. Slice 3's contract §4.7 owns
 * relocating this surface, introducing `manage_advanced_provider`, and
 * removing these routes entirely; this guard does not anticipate or
 * duplicate that work.
 */
class MessagingChannelsController extends CustomerBaseController
{
    use ResolvesBusinessTenancy;

    public function __construct(
        private readonly SendingServerRepository $sendingServers,
        private readonly WorkspaceRepository $workspaceRepository,
        private readonly WorkspaceManager $workspaceManager,
        private readonly EntitlementManager $entitlementManager,
        private readonly BusinessMessagingProviderCatalog $catalog,
    ) {
    }

    /**
     * Bare /channels entry/selector route. Never guesses a Business.
     */
    public function entry(): View|Factory|Application|RedirectResponse
    {
        $this->authorize('view_numbers');

        // Security Remediation Slice 0 §16.A.3 — entry() has no
        // {workspaceUid}/{businessUid} to guard directly, so the same
        // predicate the other seven methods enforce via
        // guardAdvancedProviderAccess() is applied here as a filter:
        // a Core/Growth-only actor's accessible list becomes empty
        // (rendering the existing empty state below, never a redirect
        // into the surface); an Agency actor with mixed-tier access sees
        // only the Business(es) they are actually authorized for.
        $accessible = array_values(array_filter(
            $this->accessibleBusinesses(),
            fn (array $pair): bool => $this->hasAdvancedProviderAccess($pair[0], $pair[1]),
        ));

        if (count($accessible) === 0) {
            return view('customer.settings.advanced.entry', ['accessible' => []]);
        }

        if (count($accessible) === 1) {
            [$workspace, $business] = $accessible[0];

            return redirect()->route('customer.workspaces.businesses.channels.index', [$workspace->uid, $business->uid]);
        }

        return view('customer.settings.advanced.entry', ['accessible' => $accessible]);
    }

    public function channels(string $workspaceUid, string $businessUid): View|Factory|Application
    {
        $this->authorize('view_numbers');
        $this->guardAdvancedProviderAccess($workspaceUid, $businessUid);

        $business = $this->resolveAccessibleBusiness($workspaceUid, $businessUid);

        $connections = CustomerBasedSendingServer::where('business_id', $business->id)
            ->whereHas('sendingServer', function ($query) {
                $query->whereIn('settings', $this->catalog->types());
            })
            ->with('sendingServer')
            ->get();

        $providers = [];
        foreach ($this->catalog->types() as $type) {
            $providers[$type] = [
                'type' => $type,
                'label' => $this->catalog->label($type),
                'mms' => $this->catalog->supportsMms($type),
                'connections' => $connections->filter(fn ($connection) => $connection->sendingServer?->settings === $type)->values(),
            ];
        }

        return view('customer.settings.advanced.index', [
            'workspaceUid' => $workspaceUid,
            'businessUid' => $businessUid,
            'providers' => $providers,
            'phoneNumbers' => PhoneNumbers::where('business_id', $business->id)->get(),
            'senderIds' => Senderid::where('business_id', $business->id)->get(),
        ]);
    }

    public function connect(string $workspaceUid, string $businessUid, string $provider): View|Factory|Application|RedirectResponse
    {
        $this->authorize('view_numbers');
        $this->guardAdvancedProviderAccess($workspaceUid, $businessUid);

        $business = $this->resolveAccessibleBusiness($workspaceUid, $businessUid);

        if (! $this->isAllowedProvider($provider)) {
            return $this->channelsError($workspaceUid, $businessUid, 'Unsupported provider.');
        }

        return view('customer.settings.advanced.connect', [
            'workspaceUid' => $workspaceUid,
            'businessUid' => $businessUid,
            'provider' => $provider,
            'providerLabel' => $this->catalog->label($provider),
            'fields' => $this->catalog->credentialFields($provider),
            'mmsSupported' => $this->catalog->supportsMms($provider),
            'mmsFields' => $this->catalog->mmsFields($provider),
        ]);
    }

    public function storeConnect(Request $request, string $workspaceUid, string $businessUid, string $provider): RedirectResponse
    {
        $this->authorize('view_numbers');
        $this->guardAdvancedProviderAccess($workspaceUid, $businessUid);

        $business = $this->resolveAccessibleBusiness($workspaceUid, $businessUid);

        if (! $this->isAllowedProvider($provider)) {
            return $this->channelsError($workspaceUid, $businessUid, 'Unsupported provider.');
        }

        if (config('app.stage') === 'demo') {
            return $this->channelsError($workspaceUid, $businessUid, 'Sorry! This option is not available in demo mode');
        }

        // A provider without MMS support never gets an MMS connection,
        // regardless of what was submitted — the checkbox is defensive on
        // the server, not just hidden in the form.
        $mmsEnabled = $this->catalog->supportsMms($provider) && $request->boolean('enable_mms', true);

        [$errors, $credentials] = $this->catalog->validate($provider, $request->all(), false, $mmsEnabled);

        if (! empty($errors)) {
            return redirect()->route('customer.workspaces.businesses.channels.connect', [$workspaceUid, $businessUid, $provider])
                ->withErrors($errors);
        }

        // Correction-style discipline carried over from B1: the explicit
        // selected Business is authoritative. The new SendingServer's
        // legacy owner and the CustomerBasedSendingServer assignment both
        // use the Business owner's id — never the acting Workspace
        // member's — and the assignment's business_id is always the
        // selected Business.
        $metadata = $this->sendingServers->allSendingServer()[$provider];
        $input = array_merge($metadata, $credentials, [
            'settings' => $provider,
            'user_id' => $business->customer_id,
            'mms' => $mmsEnabled,
        ]);

        // A new connection's SendingServer and its CustomerBasedSendingServer
        // assignment must be created together or not at all — otherwise a
        // failure between the two writes would leave an orphan,
        // credential-bearing SendingServer with no Business assignment.
        $connection = DB::transaction(function () use ($input, $business): CustomerBasedSendingServer {
            $sendingServer = $this->sendingServers->store($input);

            return CustomerBasedSendingServer::create([
                'user_id' => $business->customer_id,
                'business_id' => $business->id,
                'sending_server' => $sendingServer->id,
                'status' => true,
            ]);
        });

        // Land on the new connection's own detail page — the sensible
        // customer-facing "what did I just set up" screen — not back on
        // the generic list.
        return redirect()->route('customer.workspaces.businesses.channels.connections.show', [$workspaceUid, $businessUid, $connection->uid])->with([
            'status' => 'success',
            'message' => $this->catalog->label($provider) . ' connected.',
        ]);
    }

    public function show(string $workspaceUid, string $businessUid, CustomerBasedSendingServer $connection): View|Factory|Application
    {
        $this->authorize('view_numbers');
        $this->guardAdvancedProviderAccess($workspaceUid, $businessUid);

        $business = $this->resolveAccessibleBusiness($workspaceUid, $businessUid);
        $this->resolveOwnedConnection($connection, $business);

        $provider = $connection->sendingServer->settings;

        return view('customer.settings.advanced.show', [
            'workspaceUid' => $workspaceUid,
            'businessUid' => $businessUid,
            'connection' => $connection,
            'provider' => $provider,
            'providerLabel' => $this->catalog->label($provider) ?? $provider,
            'fields' => $this->catalog->credentialFields($provider),
            'managed' => $this->isManagedConnection($business, $connection),
            'inboundUrl' => $this->inboundUrl($provider, $connection->sendingServer->uid),
            'phoneNumbers' => PhoneNumbers::where('business_id', $business->id)->get(),
            'senderIds' => Senderid::where('business_id', $business->id)->get(),
        ]);
    }

    public function update(Request $request, string $workspaceUid, string $businessUid, CustomerBasedSendingServer $connection): RedirectResponse
    {
        $this->authorize('view_numbers');
        $this->guardAdvancedProviderAccess($workspaceUid, $businessUid);

        $business = $this->resolveAccessibleBusiness($workspaceUid, $businessUid);
        $this->resolveOwnedConnection($connection, $business);

        if ($this->isManagedConnection($business, $connection)) {
            return $this->channelsError($workspaceUid, $businessUid, 'This connection is managed and cannot be edited here.');
        }

        if (config('app.stage') === 'demo') {
            return $this->channelsError($workspaceUid, $businessUid, 'Sorry! This option is not available in demo mode');
        }

        $provider = $connection->sendingServer->settings;

        if (! $this->isAllowedProvider($provider)) {
            return $this->channelsError($workspaceUid, $businessUid, 'Unsupported provider.');
        }

        // Blank credential fields intentionally preserve the current
        // secret — only non-blank submitted values are applied. This edit
        // form has no MMS toggle of its own, so eligibility follows the
        // connection's own already-stored mms state.
        $mmsEnabled = $this->catalog->supportsMms($provider) && (bool) $connection->sendingServer->mms;

        [$errors, $credentials] = $this->catalog->validate($provider, $request->all(), true, $mmsEnabled);

        if (! empty($errors)) {
            return redirect()->route('customer.workspaces.businesses.channels.connections.show', [$workspaceUid, $businessUid, $connection->uid])
                ->withErrors($errors);
        }

        if (! empty($credentials)) {
            $this->sendingServers->update($connection->sendingServer, $credentials);
        }

        return redirect()->route('customer.workspaces.businesses.channels.connections.show', [$workspaceUid, $businessUid, $connection->uid])->with([
            'status' => 'success',
            'message' => 'Connection updated.',
        ]);
    }

    public function enable(string $workspaceUid, string $businessUid, CustomerBasedSendingServer $connection): RedirectResponse
    {
        $this->authorize('view_numbers');
        $this->guardAdvancedProviderAccess($workspaceUid, $businessUid);

        $business = $this->resolveAccessibleBusiness($workspaceUid, $businessUid);
        $this->resolveOwnedConnection($connection, $business);

        // A Business may never override an administrator-disabled global
        // SendingServer by re-enabling its own assignment.
        if (! $connection->sendingServer || ! $connection->sendingServer->status) {
            return $this->channelsError($workspaceUid, $businessUid, 'This provider is not currently available.');
        }

        $connection->update(['status' => true]);

        return redirect()->route('customer.workspaces.businesses.channels.index', [$workspaceUid, $businessUid])->with([
            'status' => 'success',
            'message' => 'Connection enabled.',
        ]);
    }

    public function disable(string $workspaceUid, string $businessUid, CustomerBasedSendingServer $connection): RedirectResponse
    {
        $this->authorize('view_numbers');
        $this->guardAdvancedProviderAccess($workspaceUid, $businessUid);

        $business = $this->resolveAccessibleBusiness($workspaceUid, $businessUid);
        $this->resolveOwnedConnection($connection, $business);

        // Disabling this Business's assignment only ever touches this row
        // — never the underlying (possibly shared) SendingServer, and
        // never another Business's assignment of the same server.
        $connection->update(['status' => false]);

        return redirect()->route('customer.workspaces.businesses.channels.index', [$workspaceUid, $businessUid])->with([
            'status' => 'success',
            'message' => 'Connection disabled.',
        ]);
    }

    // -----------------------------------------------------------------
    // Security Remediation Slice 0 §16.A.3 — the actual authorization
    // boundary.
    // -----------------------------------------------------------------

    /**
     * Fails closed (404) unless every one of §16.A.3's conditions holds
     * for the exact {workspaceUid}/{businessUid} pair in the URL. Additive
     * to, never a replacement for, resolveAccessibleBusiness() (called
     * again, unchanged, by the caller immediately after this returns) and
     * the existing `view_numbers` permission check.
     *
     * 404, never 403 — matching the existence-disclosure discipline
     * already established in resolveAccessibleBusiness() and the GBP
     * controller: a Core or Growth actor must not learn this surface
     * exists at all.
     */
    private function guardAdvancedProviderAccess(string $workspaceUid, string $businessUid): void
    {
        $workspace = $this->workspaceRepository->findByUid($workspaceUid);
        $business = $this->resolveAccessibleBusiness($workspaceUid, $businessUid);

        abort_unless($workspace !== null && $this->hasAdvancedProviderAccess($workspace, $business), 404);
    }

    /**
     * The non-aborting predicate guardAdvancedProviderAccess() enforces,
     * reused by entry() to filter its accessible-Business list across
     * potentially several Workspaces at once.
     *
     * Deliberately looks the matching WorkspaceCandidate up in
     * CustomerContext::$workspaces by uid, rather than trusting
     * frameWorkspace()/isAgency()/canManageWorkspace() directly: those
     * reflect only the single AMBIENT selected Workspace (the route's own
     * {workspaceUid} for the other seven methods, but a remembered
     * preference or "the only one" for entry(), which has no route
     * parameter). entry() filters across every Workspace the actor can
     * access, which may include more than one Agency-tier Workspace, or a
     * mix of tiers — looking each one up individually is what makes the
     * filter correct for all of them, not only whichever one happens to
     * be ambient.
     */
    private function hasAdvancedProviderAccess(Workspace $workspace, Business $business): bool
    {
        $context = app(CustomerContext::class);
        $workspaceCandidate = null;

        foreach ($context->workspaces as $candidate) {
            if ($candidate->uid === $workspace->uid) {
                $workspaceCandidate = $candidate;

                break;
            }
        }

        if ($workspaceCandidate === null || ! $workspaceCandidate->isAgency() || ! $workspaceCandidate->isActive) {
            return false;
        }

        // Customer Experience Slice 3 §4.7 — the one-clause tightening.
        //
        // Slice 0 used canManage() (owner-or-active-admin) to close an
        // immediately exploitable fail-open gap quickly; that was correct for
        // its purpose and is not a defect. Slice 3 relocates this surface and
        // narrows it to its final, contractually-correct rule: the parent
        // contract's §6 row marks Agency ADMIN as denied and only Agency
        // OWNER as allowed, so authoritative Workspace ownership is what
        // decides here.
        //
        // This can only narrow Slice 0's merged behaviour — every actor it
        // already denied stays denied, and the only newly denied actor is an
        // Agency-wide active Admin who is not the Workspace owner.
        if (! $workspaceCandidate->isOwner) {
            return false;
        }

        // Stacked, independent requirement: the granted permission is
        // necessary but never sufficient, and ownership is never sufficient
        // without it. Neither check may substitute for or widen past the
        // other.
        if (! Gate::allows('manage_advanced_provider')) {
            return false;
        }

        if ($business->status !== BusinessStatus::Active) {
            return false;
        }

        try {
            $decision = $this->entitlementManager->decide(
                $workspace,
                $business,
                PlatformFeature::Conversations->value,
                (int) Auth::id(),
            );
        } catch (WorkspaceBusinessNotFoundException|BusinessWorkspaceMismatchException) {
            return false;
        }

        return $decision->allowed;
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function isAllowedProvider(string $provider): bool
    {
        return $this->catalog->isAllowed($provider);
    }

    private function isManagedConnection(Business $business, CustomerBasedSendingServer $connection): bool
    {
        $isShared = CustomerBasedSendingServer::where('sending_server', $connection->sending_server)->count() > 1;
        $ownerMismatch = $connection->sendingServer === null || (int) $connection->sendingServer->user_id !== (int) $business->customer_id;

        return $isShared || $ownerMismatch;
    }

    private function inboundUrl(string $provider, string $sendingServerUid): ?string
    {
        return match ($provider) {
            SendingServer::TYPE_TWILIO => route('inbound.twilio', $sendingServerUid),
            SendingServer::TYPE_TELNYX => route('inbound.telnyx', $sendingServerUid),
            default => null,
        };
    }

    /**
     * @return array<int, array{0: \App\Models\Workspace, 1: Business}>
     */
    private function accessibleBusinesses(): array
    {
        $userId = (int) Auth::id();
        $accessible = [];

        foreach ($this->workspaceRepository->allForUser($userId) as $workspace) {
            foreach ($this->workspaceRepository->businessesForWorkspace($workspace) as $business) {
                if ($this->workspaceManager->userCanAccessBusiness($userId, $business)) {
                    $accessible[] = [$workspace, $business];
                }
            }
        }

        return $accessible;
    }

    /**
     * RFC-003 §14.1 boundary, mirroring
     * Customer\Business\UsageBillingController::resolveViewableBusiness()
     * verbatim: unknown Workspace, unknown Business, Business in the
     * wrong Workspace, and an inaccessible Business all fail identically
     * as 404.
     */
    private function resolveAccessibleBusiness(string $workspaceUid, string $businessUid): Business
    {
        [, $business] = $this->resolveBusinessTenancy($workspaceUid, $businessUid, requireActive: false);

        return $business;
    }

    /**
     * Correction 1 — every connection-specific B2 action must positively
     * prove BOTH invariants before touching a connection: it belongs to
     * the selected Business, AND its underlying SendingServer's provider
     * type is inside B2's hard allowlist. Without the second check, an
     * already-existing Business-owned connection for an inherited
     * provider outside B2's scope (e.g. Plivo) could still be opened/
     * enabled/disabled through B2 merely because its uid was known.
     * Centralizing both checks here means the invariant cannot drift
     * between show()/update()/enable()/disable().
     */
    private function resolveOwnedConnection(CustomerBasedSendingServer $connection, Business $business): CustomerBasedSendingServer
    {
        abort_unless($connection->business_id === $business->id, 404);
        abort_unless($connection->sendingServer !== null && $this->isAllowedProvider($connection->sendingServer->settings), 404);

        return $connection;
    }

    private function channelsError(string $workspaceUid, string $businessUid, string $message): RedirectResponse
    {
        return redirect()->route('customer.workspaces.businesses.channels.index', [$workspaceUid, $businessUid])->with([
            'status' => 'error',
            'message' => $message,
        ]);
    }
}
