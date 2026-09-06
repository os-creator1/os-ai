<?php

namespace App\Http\Controllers\Customer\Workspace;

use App\Enums\AgencyProspecting\AgencyProspectCampaignStatus;
use App\Enums\AgencyProspecting\AgencyProspectStage;
use App\Enums\AgencyProspecting\AgencyProspectStatus;
use App\Http\Controllers\Customer\CustomerBaseController;
use App\Http\Controllers\Customer\Workspace\Concerns\ResolvesAgencyProspectingWorkspace;
use App\Library\AgencyProspecting\AgencyProspectPhoneNormalizer;
use App\Library\Entitlement\EntitlementManager;
use App\Models\AgencyProspect;
use App\Models\AgencyProspectCampaign;
use App\Models\AgencyProspectCampaignMember;
use App\Models\AgencyProspectingSetting;
use App\Models\AgencyProspectMessage;
use App\Models\Workspace;
use App\Repositories\Contracts\WorkspaceMembershipRepository;
use App\Repositories\Contracts\WorkspaceRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
    use ResolvesAgencyProspectingWorkspace;

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

    /**
     * Runtime pass — real, prospecting-local operational metrics only
     * (never B5 Analytics). Every count is a direct query over this
     * Workspace's own rows; no fabricated/placeholder value is ever
     * shown, and a rate is only computed when its denominator is
     * positive (null otherwise, rendered as "—" by the view).
     */
    public function overview(string $workspaceUid): View|Factory|Application
    {
        $workspace = $this->resolveEntitledWorkspace($workspaceUid);

        $prospects = AgencyProspect::where('workspace_id', $workspace->id);
        $totalProspects = (clone $prospects)->count();
        $stoppedProspects = (clone $prospects)->where('status', AgencyProspectStatus::Stopped->value)->count();
        $bookedProspects = (clone $prospects)->where('status', AgencyProspectStatus::Booked->value)->count();

        $memberIds = AgencyProspectCampaignMember::where('workspace_id', $workspace->id)->pluck('id');

        // Correction 1 — "initial messages sent" must count only the true
        // opening send per member (purpose=initial), never AI replies or
        // follow-ups; otherwise this metric (and the reply-rate
        // denominator derived from it) drifts upward as conversations run.
        $initialMessagesSent = AgencyProspectMessage::whereIn('campaign_member_id', $memberIds)
            ->where('direction', AgencyProspectMessage::DIRECTION_OUTBOUND)
            ->where('purpose', AgencyProspectMessage::PURPOSE_INITIAL)
            ->where('status', AgencyProspectMessage::STATUS_SENT)
            ->count();

        $inboundReplies = AgencyProspectMessage::whereIn('campaign_member_id', $memberIds)
            ->where('direction', AgencyProspectMessage::DIRECTION_INBOUND)
            ->count();

        $engagedStageCount = AgencyProspectCampaignMember::where('workspace_id', $workspace->id)
            ->whereIn('stage', [2, 3, 4, 5])
            ->count();

        $bookingLinksSent = AgencyProspectCampaignMember::where('workspace_id', $workspace->id)
            ->whereNotNull('booking_link_sent_at')
            ->count();

        return view('customer.workspaces.prospecting.overview', [
            'workspaceUid' => $workspaceUid,
            'totalProspects' => $totalProspects,
            'activeCampaigns' => AgencyProspectCampaign::where('workspace_id', $workspace->id)
                ->where('status', AgencyProspectCampaignStatus::Active->value)
                ->count(),
            'stoppedProspects' => $stoppedProspects,
            'bookedProspects' => $bookedProspects,
            'initialMessagesSent' => $initialMessagesSent,
            'inboundReplies' => $inboundReplies,
            'engagedStageCount' => $engagedStageCount,
            'bookingLinksSent' => $bookingLinksSent,
            'replyRate' => $initialMessagesSent > 0 ? round($inboundReplies / $initialMessagesSent * 100, 1) : null,
            'bookedRate' => $totalProspects > 0 ? round($bookedProspects / $totalProspects * 100, 1) : null,
            'optOutRate' => $totalProspects > 0 ? round($stoppedProspects / $totalProspects * 100, 1) : null,
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

    /**
     * Runtime pass — a phone number identifies at most one prospect inside
     * one Workspace (agency_prospects.unique(workspace_id, phone)), now
     * enforced against the canonical digits-only international form
     * (AgencyProspectPhoneNormalizer), the same normalizer used for
     * channel sender numbers and both providers' inbound From/To. An
     * unparseable number (no explicit country code — never guessed) and a
     * canonical duplicate both surface as ordinary validation errors, not
     * a database integrity exception.
     */
    public function storeProspect(Request $request, string $workspaceUid): RedirectResponse
    {
        $workspace = $this->resolveEntitledWorkspace($workspaceUid);

        $validated = $request->validate([
            'company_name' => ['required', 'string', 'max:255'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:64'],
            'email' => ['nullable', 'email', 'max:255'],
            'website' => ['nullable', 'string', 'max:2048'],
            'source' => ['nullable', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:255'],
        ]);

        $canonicalPhone = AgencyProspectPhoneNormalizer::normalize($validated['phone']);

        if ($canonicalPhone === null) {
            return redirect()->back()->withInput()
                ->withErrors(['phone' => 'Enter a valid international phone number (include a country code).']);
        }

        if (AgencyProspect::where('workspace_id', $workspace->id)->where('phone', $canonicalPhone)->exists()) {
            return redirect()->back()->withInput()
                ->withErrors(['phone' => 'A prospect with this phone number already exists in this Workspace.']);
        }

        AgencyProspect::create(array_merge($validated, [
            'phone' => $canonicalPhone,
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
     * never references. Correction 1 — every existing campaign
     * membership for this prospect is synchronized to the terminal
     * AgencyProspectStage::StoppedOptOut stage in the same transaction,
     * so no membership is ever left contradicting its own prospect's
     * terminal state before the responder engine exists to reconcile it.
     * Memberships are updated, never deleted — attribution/history is
     * preserved. Correction 3 — the prospect row is locked
     * (lockWorkspaceProspect()) for the duration of this transaction, so
     * this can never interleave with a concurrent markProspectBooked()/
     * enrollProspect() call on the same row: STOP always wins regardless
     * of which request reaches the lock first (see markProspectBooked()'s
     * own Stopped check, evaluated only after it acquires this same
     * lock).
     */
    public function stopProspect(string $workspaceUid, AgencyProspect $prospect): RedirectResponse
    {
        $workspace = $this->resolveEntitledWorkspace($workspaceUid);
        $this->resolveWorkspaceProspect($workspace, $prospect);

        DB::transaction(function () use ($workspace, $prospect): void {
            $locked = $this->lockWorkspaceProspect($workspace, $prospect);

            $locked->update([
                'status' => AgencyProspectStatus::Stopped->value,
                'stopped_at' => now(),
            ]);

            $locked->campaignMemberships()->update(['stage' => AgencyProspectStage::StoppedOptOut->value]);
        });

        return redirect()
            ->route('customer.workspaces.prospecting.prospects.show', [$workspaceUid, $prospect->uid])
            ->with('flash_success', 'Prospect stopped.');
    }

    /**
     * Manual "mark booked" fallback (task-specified allowance for this
     * foundation pass): no automatic booking-linkage exists yet — no
     * calendar/booking model of any kind exists anywhere in this
     * codebase — so this is an explicit, human-initiated correction only.
     * Correction 1 — every existing campaign membership for this prospect
     * is synchronized to AgencyProspectStage::Booked in the same
     * transaction, mirroring stopProspect()'s discipline exactly.
     * Correction 2 — STOP/opt-out dominates: once a prospect is Stopped,
     * this action must refuse rather than reactivate it — a forged direct
     * POST must never be able to reverse an opt-out into a Booked state.
     * Correction 3 — that Stopped check is no longer made from the
     * route-bound (possibly stale) model: lockWorkspaceProspect() re-reads
     * the row under SELECT ... FOR UPDATE first, and the decision is made
     * from that freshly-locked row, so a concurrent stopProspect() call
     * can never race past this check — whichever request acquires the
     * lock first determines the outcome, and STOP always wins either way
     * (if mark-booked commits first, a subsequent STOP still overwrites
     * it to Stopped/99; if STOP commits first, mark-booked's own locked
     * read sees Stopped and refuses).
     */
    public function markProspectBooked(string $workspaceUid, AgencyProspect $prospect): RedirectResponse
    {
        $workspace = $this->resolveEntitledWorkspace($workspaceUid);
        $this->resolveWorkspaceProspect($workspace, $prospect);

        return DB::transaction(function () use ($workspace, $workspaceUid, $prospect) {
            $locked = $this->lockWorkspaceProspect($workspace, $prospect);

            if ($locked->status === AgencyProspectStatus::Stopped) {
                return redirect()
                    ->route('customer.workspaces.prospecting.prospects.show', [$workspaceUid, $locked->uid])
                    ->with('flash_error', 'A stopped prospect cannot be marked as booked.');
            }

            $locked->update([
                'status' => AgencyProspectStatus::Booked->value,
                'booked_at' => now(),
            ]);

            $locked->campaignMemberships()->update(['stage' => AgencyProspectStage::Booked->value]);

            return redirect()
                ->route('customer.workspaces.prospecting.prospects.show', [$workspaceUid, $locked->uid])
                ->with('flash_success', 'Prospect marked as booked.');
        });
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
            'availableChannels' => \App\Models\AgencyProspectingChannel::where('workspace_id', $workspace->id)
                ->where('status', \App\Models\AgencyProspectingChannel::STATUS_ACTIVE)
                ->get(),
        ]);
    }

    /**
     * Runtime pass — the minimum campaign runtime configuration: a
     * same-Workspace, active channel and an explicit, user-authored
     * opening message. Never AI-generated at send time (keeps campaign
     * intent under user control and avoids one model request per initial
     * outbound).
     *
     * Correction 1 — Draft-only, enforced server-side regardless of what
     * the UI exposes: once a campaign has Started (Active or Paused), an
     * already-open conversation may be mid-flight on the currently
     * configured channel/opener — a forged POST must never be able to
     * switch either out from under it.
     */
    public function updateCampaignConfig(Request $request, string $workspaceUid, AgencyProspectCampaign $campaign): RedirectResponse
    {
        $workspace = $this->resolveEntitledWorkspace($workspaceUid);
        $this->resolveWorkspaceCampaign($workspace, $campaign);

        if ($campaign->status !== AgencyProspectCampaignStatus::Draft) {
            return redirect()->route('customer.workspaces.prospecting.campaigns.show', [$workspaceUid, $campaign->uid])
                ->with('flash_error', 'Only a draft campaign\'s configuration can be changed.');
        }

        $validated = $request->validate([
            'channel_uid' => ['nullable', 'string'],
            'opening_message' => ['nullable', 'string', 'max:1600'],
        ]);

        $channelId = null;

        if (! empty($validated['channel_uid'])) {
            $channel = \App\Models\AgencyProspectingChannel::where('uid', $validated['channel_uid'])
                ->where('workspace_id', $workspace->id)
                ->first();

            if ($channel === null) {
                abort(404);
            }

            $channelId = $channel->id;
        }

        $campaign->update([
            'channel_id' => $channelId,
            'opening_message' => $validated['opening_message'] ?? null,
        ]);

        return redirect()
            ->route('customer.workspaces.prospecting.campaigns.show', [$workspaceUid, $campaign->uid])
            ->with('flash_success', 'Campaign configuration updated.');
    }

    /**
     * Runtime pass — the explicit human action that turns a draft campaign
     * into a live one. Creating a campaign, enrolling a prospect, or
     * connecting a channel must never, by themselves, cause a send —
     * only this action does, and only after re-validating every readiness
     * condition server-side. Dispatches one initial-send job per eligible
     * member; the job itself re-checks everything again at execution time
     * (never trusts this method's own read).
     *
     * Correction 1 — the read-check-write is now ATOMIC: the campaign row
     * and every involved AgencyProspect row (locked in deterministic
     * ascending-id order, to avoid a Start-vs-Start deadlock over shared
     * prospects) are locked inside ONE transaction, and the conflict
     * check itself is a locking read (`lockForUpdate()`), so it always
     * observes the latest committed state of any competing campaign
     * rather than a stale MVCC snapshot — two Drafts racing to Start over
     * a shared prospect are genuinely serialized by the database, not by
     * a pre-transaction read that both requests could pass. The open-
     * conversation definition itself now also treats a PAUSED campaign as
     * reserving its prospects, matching the corrected one-open-
     * conversation invariant. The provider network call (the initial
     * send) happens strictly AFTER this transaction commits.
     */
    public function startCampaign(string $workspaceUid, AgencyProspectCampaign $campaign): RedirectResponse
    {
        $workspace = $this->resolveEntitledWorkspace($workspaceUid);
        $this->resolveWorkspaceCampaign($workspace, $campaign);

        $outcome = DB::transaction(function () use ($workspace, $campaign) {
            $lockedCampaign = AgencyProspectCampaign::where('id', $campaign->id)
                ->where('workspace_id', $workspace->id)
                ->lockForUpdate()
                ->first();

            if ($lockedCampaign === null || $lockedCampaign->status !== AgencyProspectCampaignStatus::Draft) {
                return ['error' => 'Only a draft campaign can be started.'];
            }

            if (empty($lockedCampaign->opening_message)) {
                return ['error' => 'Set an opening message before starting this campaign.'];
            }

            $channel = $lockedCampaign->channel;

            if ($channel === null || (int) $channel->workspace_id !== (int) $workspace->id) {
                return ['error' => 'Select a channel before starting this campaign.'];
            }

            if (! $channel->isActive() || $channel->sendingServer === null || ! $channel->sendingServer->status) {
                return ['error' => 'The selected channel is not currently active.'];
            }

            $memberRows = $lockedCampaign->members()
                ->whereNotIn('stage', [AgencyProspectStage::Booked->value, AgencyProspectStage::StoppedOptOut->value])
                ->get();

            if ($memberRows->isEmpty()) {
                return ['error' => 'Enroll at least one active prospect before starting this campaign.'];
            }

            $prospectIds = $memberRows->pluck('prospect_id')->unique()->sort()->values()->all();

            // Deterministic ascending-id lock order — two campaigns
            // sharing overlapping prospects can never deadlock each other
            // regardless of which member list was built first.
            $lockedProspects = AgencyProspect::whereIn('id', $prospectIds)
                ->where('workspace_id', $workspace->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $eligibleMembers = $memberRows->filter(function ($member) use ($lockedProspects) {
                $prospect = $lockedProspects->get($member->prospect_id);

                return $prospect !== null && $prospect->status === AgencyProspectStatus::Active;
            })->values();

            if ($eligibleMembers->isEmpty()) {
                return ['error' => 'Enroll at least one active prospect before starting this campaign.'];
            }

            $eligibleProspectIds = $eligibleMembers->pluck('prospect_id')->all();

            // One-open-conversation invariant: none of this campaign's own
            // eligible prospects may already have a non-terminal
            // membership in a DIFFERENT campaign that is Active OR
            // Paused (a paused conversation still reserves the prospect).
            // A locking read here (not a plain snapshot read) is what
            // makes the whole Start-vs-Start race genuinely serialized.
            $conflict = AgencyProspectCampaignMember::where('workspace_id', $workspace->id)
                ->whereIn('prospect_id', $eligibleProspectIds)
                ->where('campaign_id', '!=', $lockedCampaign->id)
                ->whereNotIn('stage', [AgencyProspectStage::Booked->value, AgencyProspectStage::StoppedOptOut->value])
                ->whereHas('campaign', fn ($query) => $query->whereIn('status', [
                    AgencyProspectCampaignStatus::Active->value,
                    AgencyProspectCampaignStatus::Paused->value,
                ]))
                ->lockForUpdate()
                ->exists();

            if ($conflict) {
                return ['error' => 'One or more enrolled prospects already have an open conversation in another active campaign.'];
            }

            $lockedCampaign->update(['status' => AgencyProspectCampaignStatus::Active->value]);

            return ['memberIds' => $eligibleMembers->pluck('id')->all()];
        });

        if (isset($outcome['error'])) {
            return redirect()->route('customer.workspaces.prospecting.campaigns.show', [$workspaceUid, $campaign->uid])
                ->with('flash_error', $outcome['error']);
        }

        foreach ($outcome['memberIds'] as $memberId) {
            \App\Jobs\AgencyProspectingInitialSendJob::dispatch($memberId);
        }

        return redirect()
            ->route('customer.workspaces.prospecting.campaigns.show', [$workspaceUid, $campaign->uid])
            ->with('flash_success', 'Campaign started.');
    }

    /**
     * Runtime pass — pause/resume ONLY. This route can never set Draft,
     * and can never activate a Draft campaign: a DRAFT campaign may only
     * ever reach "active" through startCampaign()'s own readiness
     * validation + initial-send dispatch. Correction 1 — the accepted
     * `status` values themselves are now restricted to active/paused
     * (Draft was previously accepted by validation and merely blocked by
     * one conditional, which forged requests could still use to move
     * Active/Paused back to Draft — a real lifecycle violation). Every
     * transition where the campaign is not currently Draft is therefore
     * a plain Active<->Paused toggle. Correction 13 — resuming from
     * Paused back to Active re-dispatches any still-pending, not-yet-sent,
     * not-cancelled follow-up exactly once (the job's own durable
     * operation_key prevents a duplicate send even if resume is called
     * more than once).
     */
    public function updateCampaignStatus(Request $request, string $workspaceUid, AgencyProspectCampaign $campaign): RedirectResponse
    {
        $workspace = $this->resolveEntitledWorkspace($workspaceUid);
        $this->resolveWorkspaceCampaign($workspace, $campaign);

        $validated = $request->validate([
            'status' => ['required', 'in:active,paused'],
        ]);

        if ($campaign->status === AgencyProspectCampaignStatus::Draft) {
            return redirect()->route('customer.workspaces.prospecting.campaigns.show', [$workspaceUid, $campaign->uid])
                ->with('flash_error', 'Use Start Campaign to activate a draft campaign.');
        }

        $newStatus = AgencyProspectCampaignStatus::from($validated['status']);
        $isResuming = $campaign->status === AgencyProspectCampaignStatus::Paused && $newStatus === AgencyProspectCampaignStatus::Active;

        $campaign->update(['status' => $newStatus->value]);

        if ($isResuming) {
            $this->resumePendingFollowUps($campaign);
        }

        return redirect()
            ->route('customer.workspaces.prospecting.campaigns.show', [$workspaceUid, $campaign->uid])
            ->with('flash_success', 'Campaign status updated.');
    }

    private function resumePendingFollowUps(AgencyProspectCampaign $campaign): void
    {
        $eligible = $campaign->members()
            ->whereNotNull('followup_at')
            ->whereNull('followup_sent_at')
            ->whereNull('followup_cancelled_at')
            ->whereNotIn('stage', [AgencyProspectStage::Booked->value, AgencyProspectStage::StoppedOptOut->value])
            ->whereHas('prospect', fn ($query) => $query->where('status', AgencyProspectStatus::Active->value))
            ->get();

        foreach ($eligible as $member) {
            $delay = $member->followup_at->isFuture() ? $member->followup_at : now();
            \App\Jobs\AgencyProspectingFollowUpJob::dispatch($member->id)->delay($delay);
        }
    }

    /**
     * Explicit prospect enrollment — the ONLY path that associates a
     * prospect with a campaign. Both the campaign (route-bound) and the
     * submitted prospect uid are independently re-authorized against this
     * same Workspace; a foreign-Workspace prospect uid fails exactly like
     * an unknown one. Correction 1 — a terminal-status prospect (Stopped
     * or Booked) must never be newly enrolled: the UI's own enrollable-
     * prospect chooser already filters to Active only, but that is
     * display convenience, not enforcement, so this is checked here
     * server-side regardless of what was submitted. Correction 3 — the
     * Active-status check and the membership creation now both happen
     * after lockWorkspaceProspect() acquires the same row lock
     * stopProspect()/markProspectBooked() use, inside one transaction, so
     * a concurrent STOP (or mark-booked) can never race a new membership
     * into existence against a prospect that has just become non-Active:
     * whichever request locks the row first wins, and a losing enrollment
     * attempt sees the freshly-committed terminal status and refuses.
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

        return DB::transaction(function () use ($workspace, $workspaceUid, $campaign, $prospect) {
            $locked = $this->lockWorkspaceProspect($workspace, $prospect);

            if ($locked->status !== AgencyProspectStatus::Active) {
                return redirect()
                    ->route('customer.workspaces.prospecting.campaigns.show', [$workspaceUid, $campaign->uid])
                    ->with('flash_error', 'Only active prospects can be enrolled.');
            }

            AgencyProspectCampaignMember::firstOrCreate(
                ['campaign_id' => $campaign->id, 'prospect_id' => $locked->id],
                ['workspace_id' => $workspace->id, 'enrolled_at' => now()],
            );

            return redirect()
                ->route('customer.workspaces.prospecting.campaigns.show', [$workspaceUid, $campaign->uid])
                ->with('flash_success', 'Prospect enrolled.');
        });
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
            'booking_url' => ['nullable', 'string', 'max:2048', 'url'],
            'follow_up_delay_hours' => ['nullable', 'integer', 'min:1', 'max:168'],
        ]);

        // follow_up_delay_hours has a NOT NULL default (24) at the schema
        // level; unlike the free-text context fields, a request that
        // simply omits it must never null out an already-configured value.
        $validated['follow_up_delay_hours'] = $validated['follow_up_delay_hours']
            ?? AgencyProspectingSetting::where('workspace_id', $workspace->id)->value('follow_up_delay_hours')
            ?? 24;

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
    //
    // resolveEntitledWorkspace()/hasProspectingRole()/accessibleWorkspaces()
    // now live in ResolvesAgencyProspectingWorkspace (used above), shared
    // verbatim with AgencyProspectingChannelController so the invariant
    // cannot drift between Prospecting's own sub-pages.

    private function resolveWorkspaceProspect(Workspace $workspace, AgencyProspect $prospect): AgencyProspect
    {
        abort_unless((int) $prospect->workspace_id === (int) $workspace->id, 404);

        return $prospect;
    }

    /**
     * Correction 3 — the single row-locking seam for every action that
     * determines or changes a prospect's terminal/enrollment state
     * (stopProspect(), markProspectBooked(), enrollProspect()). Must
     * always be called from inside an open DB::transaction() — a
     * lockForUpdate() issued outside one releases its lock the instant
     * the SELECT completes (MySQL autocommit), which would provide no
     * serialization at all. Re-fetches by persistent identity, scoped to
     * the already-resolved explicit Workspace (never Auth::id(), a
     * Business, or any legacy resolver) — never trusts the possibly-stale
     * $prospect model resolved before the transaction opened. A prospect
     * that no longer exists, or no longer belongs to this Workspace, by
     * the time the lock is acquired fails closed identically to an
     * unknown one.
     */
    private function lockWorkspaceProspect(Workspace $workspace, AgencyProspect $prospect): AgencyProspect
    {
        $locked = AgencyProspect::where('id', $prospect->id)
            ->where('workspace_id', $workspace->id)
            ->lockForUpdate()
            ->first();

        abort_unless($locked !== null, 404);

        return $locked;
    }

    private function resolveWorkspaceCampaign(Workspace $workspace, AgencyProspectCampaign $campaign): AgencyProspectCampaign
    {
        abort_unless((int) $campaign->workspace_id === (int) $workspace->id, 404);

        return $campaign;
    }
}
