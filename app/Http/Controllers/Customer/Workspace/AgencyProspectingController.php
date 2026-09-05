<?php

namespace App\Http\Controllers\Customer\Workspace;

use App\Enums\AgencyProspecting\AgencyProspectCampaignStatus;
use App\Enums\AgencyProspecting\AgencyProspectStatus;
use App\Enums\Entitlement\PlatformFeature;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Http\Controllers\Customer\CustomerBaseController;
use App\Library\Entitlement\EntitlementManager;
use App\Models\AgencyProspect;
use App\Models\AgencyProspectCampaign;
use App\Models\AgencyProspectCampaignMember;
use App\Models\AgencyProspectingSetting;
use App\Models\Workspace;
use App\Repositories\Contracts\WorkspaceMembershipRepository;
use App\Repositories\Contracts\WorkspaceRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Agency AI Prospecting foundation — a Workspace-level (never Business-
 * scoped) acquisition system: the Agency itself finding/messaging
 * external businesses in order to acquire them as clients. This is a
 * distinct product from Business Outreach (Workspace -> Business ->
 * Outreach) and must never silently reuse a Business's CRM Contacts,
 * Outreach ContactGroups, campaigns, or ChatBox rows as its prospect
 * database — every resource here (settings, campaigns, prospects,
 * enrollments) is its own explicitly Workspace-scoped table.
 *
 * Every action resolves the target Workspace through resolveEntitledWorkspace(),
 * which enforces BOTH invariants together: the actor must be the Workspace
 * owner or an active Workspace Admin (ordinary Staff denied by default —
 * no existing Workspace-role mechanic establishes a narrower, appropriate
 * Staff grant for this product), AND the Workspace must be independently
 * entitled to PlatformFeature::ProspectOutreach via
 * EntitlementManager::decideForWorkspace() — RFC-004's own architecture,
 * never a hand-coded "if plan === agency" check. Neither invariant ever
 * substitutes for the other.
 *
 * This is a foundation pass only: no automatic AI responder/state-machine
 * engine is implemented (a repository-wide audit, including full git
 * history, found no real, connected implementation to preserve or port —
 * only disconnected manual/dashboard fragments over an unmigrated legacy
 * chat_boxes schema), and no Workspace-level SMS-sending/provider identity
 * is implemented (no such concept exists anywhere in the current schema;
 * inventing one here would mean silently borrowing an arbitrary client
 * Business's connection, which this product must never do). "Active"/
 * "Paused" campaign state and the prospect stage/status fields are
 * therefore pure persisted state in this pass, never claimed as live
 * automatic behavior.
 */
class AgencyProspectingController extends CustomerBaseController
{
    public function __construct(
        private readonly WorkspaceRepository $workspaceRepository,
        private readonly WorkspaceMembershipRepository $membershipRepository,
        private readonly EntitlementManager $entitlementManager,
    ) {
    }

    /**
     * Bare /prospecting entry/selector route. Never guesses a Workspace:
     * exactly one accessible-and-entitled Workspace redirects straight
     * through, several show a chooser, none shows an empty state.
     */
    public function entry(): View|Factory|Application|RedirectResponse
    {
        $accessible = $this->accessibleWorkspaces();

        if (count($accessible) === 0) {
            return view('customer.workspaces.prospecting.entry', ['accessible' => []]);
        }

        if (count($accessible) === 1) {
            return redirect()->route('customer.workspaces.prospecting.overview', $accessible[0]->uid);
        }

        return view('customer.workspaces.prospecting.entry', ['accessible' => $accessible]);
    }

    public function overview(string $workspaceUid): View|Factory|Application
    {
        $workspace = $this->resolveEntitledWorkspace($workspaceUid);

        $prospects = AgencyProspect::where('workspace_id', $workspace->id);

        return view('customer.workspaces.prospecting.overview', [
            'workspaceUid' => $workspaceUid,
            'totalProspects' => (clone $prospects)->count(),
            'activeCampaigns' => AgencyProspectCampaign::where('workspace_id', $workspace->id)
                ->where('status', AgencyProspectCampaignStatus::Active->value)
                ->count(),
            'stoppedProspects' => (clone $prospects)->where('status', AgencyProspectStatus::Stopped->value)->count(),
            'bookedProspects' => (clone $prospects)->where('status', AgencyProspectStatus::Booked->value)->count(),
        ]);
    }

    public function prospects(string $workspaceUid): View|Factory|Application
    {
        $workspace = $this->resolveEntitledWorkspace($workspaceUid);

        return view('customer.workspaces.prospecting.prospects', [
            'workspaceUid' => $workspaceUid,
            'prospects' => AgencyProspect::where('workspace_id', $workspace->id)
                ->orderByDesc('id')
                ->get(),
        ]);
    }

    public function storeProspect(Request $request, string $workspaceUid): RedirectResponse
    {
        $workspace = $this->resolveEntitledWorkspace($workspaceUid);

        $validated = $request->validate([
            'company_name' => ['required', 'string', 'max:255'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:255'],
            'website' => ['nullable', 'string', 'max:2048'],
            'source' => ['nullable', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:255'],
        ]);

        AgencyProspect::create(array_merge($validated, [
            'workspace_id' => $workspace->id,
            'status' => AgencyProspectStatus::Active->value,
        ]));

        return redirect()
            ->route('customer.workspaces.prospecting.prospects.index', $workspaceUid)
            ->with('flash_success', 'Prospect added.');
    }

    public function showProspect(string $workspaceUid, AgencyProspect $prospect): View|Factory|Application
    {
        $workspace = $this->resolveEntitledWorkspace($workspaceUid);
        $this->resolveWorkspaceProspect($workspace, $prospect);

        return view('customer.workspaces.prospecting.prospect-show', [
            'workspaceUid' => $workspaceUid,
            'prospect' => $prospect,
            'memberships' => $prospect->campaignMemberships()->with('campaign')->get(),
        ]);
    }

    /**
     * Explicit, human-initiated opt-out. Workspace-scoped by construction:
     * this AgencyProspect row already belongs to exactly one Workspace, so
     * this action can never suppress the same phone number for a
     * different Workspace's prospecting, and never touches any Business's
     * own CRM Blacklist — a completely separate table this controller
     * never references.
     */
    public function stopProspect(string $workspaceUid, AgencyProspect $prospect): RedirectResponse
    {
        $workspace = $this->resolveEntitledWorkspace($workspaceUid);
        $this->resolveWorkspaceProspect($workspace, $prospect);

        $prospect->update([
            'status' => AgencyProspectStatus::Stopped->value,
            'stopped_at' => now(),
        ]);

        return redirect()
            ->route('customer.workspaces.prospecting.prospects.show', [$workspaceUid, $prospect->uid])
            ->with('flash_success', 'Prospect stopped.');
    }

    /**
     * Manual "mark booked" fallback (task-specified allowance for this
     * foundation pass): no automatic booking-linkage exists yet — no
     * calendar/booking model of any kind exists anywhere in this
     * codebase — so this is an explicit, human-initiated correction only.
     */
    public function markProspectBooked(string $workspaceUid, AgencyProspect $prospect): RedirectResponse
    {
        $workspace = $this->resolveEntitledWorkspace($workspaceUid);
        $this->resolveWorkspaceProspect($workspace, $prospect);

        $prospect->update([
            'status' => AgencyProspectStatus::Booked->value,
            'booked_at' => now(),
        ]);

        return redirect()
            ->route('customer.workspaces.prospecting.prospects.show', [$workspaceUid, $prospect->uid])
            ->with('flash_success', 'Prospect marked as booked.');
    }

    public function campaigns(string $workspaceUid): View|Factory|Application
    {
        $workspace = $this->resolveEntitledWorkspace($workspaceUid);

        return view('customer.workspaces.prospecting.campaigns', [
            'workspaceUid' => $workspaceUid,
            'campaigns' => AgencyProspectCampaign::where('workspace_id', $workspace->id)
                ->orderByDesc('id')
                ->get(),
        ]);
    }

    public function storeCampaign(Request $request, string $workspaceUid): RedirectResponse
    {
        $workspace = $this->resolveEntitledWorkspace($workspaceUid);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'context' => ['nullable', 'string', 'max:10000'],
        ]);

        AgencyProspectCampaign::create(array_merge($validated, [
            'workspace_id' => $workspace->id,
            'status' => AgencyProspectCampaignStatus::Draft->value,
        ]));

        return redirect()
            ->route('customer.workspaces.prospecting.campaigns.index', $workspaceUid)
            ->with('flash_success', 'Campaign created.');
    }

    public function showCampaign(string $workspaceUid, AgencyProspectCampaign $campaign): View|Factory|Application
    {
        $workspace = $this->resolveEntitledWorkspace($workspaceUid);
        $this->resolveWorkspaceCampaign($workspace, $campaign);

        return view('customer.workspaces.prospecting.campaign-show', [
            'workspaceUid' => $workspaceUid,
            'campaign' => $campaign,
            'members' => $campaign->members()->with('prospect')->get(),
            'enrollableProspects' => AgencyProspect::where('workspace_id', $workspace->id)
                ->where('status', AgencyProspectStatus::Active->value)
                ->whereNotIn('id', $campaign->members()->pluck('prospect_id'))
                ->orderBy('company_name')
                ->get(),
        ]);
    }

    public function updateCampaignStatus(Request $request, string $workspaceUid, AgencyProspectCampaign $campaign): RedirectResponse
    {
        $workspace = $this->resolveEntitledWorkspace($workspaceUid);
        $this->resolveWorkspaceCampaign($workspace, $campaign);

        $validated = $request->validate([
            'status' => ['required', 'in:draft,active,paused'],
        ]);

        $campaign->update(['status' => $validated['status']]);

        return redirect()
            ->route('customer.workspaces.prospecting.campaigns.show', [$workspaceUid, $campaign->uid])
            ->with('flash_success', 'Campaign status updated.');
    }

    /**
     * Explicit prospect enrollment — the ONLY path that associates a
     * prospect with a campaign. Both the campaign (route-bound) and the
     * submitted prospect uid are independently re-authorized against this
     * same Workspace; a foreign-Workspace prospect uid fails exactly like
     * an unknown one.
     */
    public function enrollProspect(Request $request, string $workspaceUid, AgencyProspectCampaign $campaign): RedirectResponse
    {
        $workspace = $this->resolveEntitledWorkspace($workspaceUid);
        $this->resolveWorkspaceCampaign($workspace, $campaign);

        $validated = $request->validate([
            'prospect_uid' => ['required', 'string'],
        ]);

        $prospect = AgencyProspect::where('uid', $validated['prospect_uid'])->first();

        if ($prospect === null || (int) $prospect->workspace_id !== (int) $workspace->id) {
            abort(404);
        }

        AgencyProspectCampaignMember::firstOrCreate(
            ['campaign_id' => $campaign->id, 'prospect_id' => $prospect->id],
            ['workspace_id' => $workspace->id, 'enrolled_at' => now()],
        );

        return redirect()
            ->route('customer.workspaces.prospecting.campaigns.show', [$workspaceUid, $campaign->uid])
            ->with('flash_success', 'Prospect enrolled.');
    }

    public function settings(string $workspaceUid): View|Factory|Application
    {
        $workspace = $this->resolveEntitledWorkspace($workspaceUid);

        $settings = AgencyProspectingSetting::firstOrNew(['workspace_id' => $workspace->id]);

        return view('customer.workspaces.prospecting.settings', [
            'workspaceUid' => $workspaceUid,
            'settings' => $settings,
        ]);
    }

    public function updateSettings(Request $request, string $workspaceUid): RedirectResponse
    {
        $workspace = $this->resolveEntitledWorkspace($workspaceUid);

        $validated = $request->validate([
            'agency_name' => ['nullable', 'string', 'max:255'],
            'offer' => ['nullable', 'string', 'max:10000'],
            'niche' => ['nullable', 'string', 'max:255'],
            'value_proposition' => ['nullable', 'string', 'max:10000'],
            'pricing_context' => ['nullable', 'string', 'max:10000'],
            'qualification_context' => ['nullable', 'string', 'max:10000'],
            'geography_context' => ['nullable', 'string', 'max:10000'],
            'tone' => ['nullable', 'string', 'max:255'],
            'faqs_objections' => ['nullable', 'string', 'max:10000'],
            'booking_context' => ['nullable', 'string', 'max:10000'],
            'follow_up_policy' => ['nullable', 'string', 'max:10000'],
        ]);

        AgencyProspectingSetting::updateOrCreate(
            ['workspace_id' => $workspace->id],
            $validated,
        );

        return redirect()
            ->route('customer.workspaces.prospecting.settings.show', $workspaceUid)
            ->with('flash_success', 'Agent settings updated.');
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * The sole resolver for every action: BOTH the Workspace-role
     * invariant (owner or active Admin; Staff denied by default) AND the
     * independent RFC-004 entitlement decision must pass. Centralizing
     * both checks here means the invariant cannot drift between actions —
     * the same discipline B2's resolveOwnedConnection() correction
     * established for its own connection-specific actions.
     */
    private function resolveEntitledWorkspace(string $workspaceUid): Workspace
    {
        $workspace = $this->workspaceRepository->findByUid($workspaceUid);

        if ($workspace === null || ! $this->hasProspectingRole($workspace, (int) Auth::id())) {
            abort(404);
        }

        $decision = $this->entitlementManager->decideForWorkspace($workspace, PlatformFeature::ProspectOutreach->value);

        if (! $decision->allowed) {
            abort(404);
        }

        return $workspace;
    }

    /**
     * Owner or active Workspace Admin only — ordinary Staff denied by
     * default, per the foundation task's own instruction: no existing
     * Workspace-role mechanic establishes a narrower, appropriate Staff
     * grant for a product this sensitive (external outbound messaging on
     * the Agency's own behalf), so this defaults closed rather than
     * reusing Business-access-scope staff grants, which govern access to
     * client Businesses, not the Agency's own acquisition system.
     */
    private function hasProspectingRole(Workspace $workspace, int $userId): bool
    {
        if ((int) $workspace->owner_user_id === $userId) {
            return true;
        }

        $membership = $this->membershipRepository->findByWorkspaceAndUser($workspace, $userId);

        return $membership !== null
            && $membership->is_active
            && $membership->role === WorkspaceMembershipRole::Admin;
    }

    private function resolveWorkspaceProspect(Workspace $workspace, AgencyProspect $prospect): AgencyProspect
    {
        abort_unless((int) $prospect->workspace_id === (int) $workspace->id, 404);

        return $prospect;
    }

    private function resolveWorkspaceCampaign(Workspace $workspace, AgencyProspectCampaign $campaign): AgencyProspectCampaign
    {
        abort_unless((int) $campaign->workspace_id === (int) $workspace->id, 404);

        return $campaign;
    }

    /**
     * @return array<int, Workspace>
     */
    private function accessibleWorkspaces(): array
    {
        $userId = (int) Auth::id();

        return $this->workspaceRepository->allForUser($userId)
            ->filter(fn (Workspace $workspace) => $this->hasProspectingRole($workspace, $userId))
            ->filter(fn (Workspace $workspace) => $this->entitlementManager
                ->decideForWorkspace($workspace, PlatformFeature::ProspectOutreach->value)->allowed)
            ->values()
            ->all();
    }
}
