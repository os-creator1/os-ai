<?php

namespace App\Http\Controllers\Customer\Workspace;

use App\Enums\AgencyProspecting\AgencyProspectCampaignStatus;
use App\Enums\AgencyProspecting\AgencyProspectStage;
use App\Http\Controllers\Customer\CustomerBaseController;
use App\Http\Controllers\Customer\Workspace\Concerns\ResolvesAgencyProspectingWorkspace;
use App\Http\Requests\Customer\UpdateOutreachScriptRequest;
use App\Library\AgencyOutreach\AgencyOutreachBusinessResolver;
use App\Library\AgencyOutreach\AgencyOutreachReadiness;
use App\Library\AgencyOutreach\OutreachCampaignService;
use App\Library\AgencyOutreach\OutreachContactGroupBridge;
use App\Library\AgencyOutreach\OutreachResumeService;
use App\Library\AgencyOutreach\OutreachScript;
use App\Library\AgencyOutreach\OutreachScriptDefaults;
use App\Library\AgencyOutreach\OutreachScriptManager;
use App\Library\AgencyOutreach\OutreachScriptRenderer;
use App\Library\AgencyOutreach\OutreachSenderPool;
use App\Library\AgencyOutreach\OutreachTakeoverService;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Merge\MergeFieldRegistry;
use App\Library\Workspace\WorkspaceManager;
use App\Models\AgencyProspect;
use App\Models\AgencyProspectCampaign;
use App\Models\AgencyProspectCampaignMember;
use App\Models\BookingType;
use App\Models\ChatBox;
use App\Models\ContactGroups;
use App\Models\Workspace;
use App\Repositories\Contracts\ContactsRepository;
use App\Repositories\Contracts\WorkspaceMembershipRepository;
use App\Repositories\Contracts\WorkspaceRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Agency Outreach V1 screens that are new on top of Agency Prospecting:
 * Conversations (a filtered list into the canonical inbox, never a second
 * inbox), Pause/Resume AI, Script & Settings, managed-campaign creation and
 * "Resume sending". Controllers stay thin; the work is in App\Library\AgencyOutreach.
 *
 * Every action resolves the Workspace through the same invariant as the rest of
 * Prospecting (owner or active Admin + ProspectOutreach entitlement; anything
 * else is a 404). View-As never reaches these routes: every
 * `customer.workspaces.*` route is Denied while a View-As session is active.
 */
class AgencyOutreachController extends CustomerBaseController
{
    use ResolvesAgencyProspectingWorkspace;

    private const PAGE_SIZE = 25;

    public function __construct(
        private readonly WorkspaceRepository $workspaceRepository,
        private readonly WorkspaceMembershipRepository $membershipRepository,
        private readonly EntitlementManager $entitlementManager,
    ) {
    }

    // -----------------------------------------------------------------
    // Conversations
    // -----------------------------------------------------------------

    public function conversations(Request $request, string $workspaceUid)
    {
        $workspace = $this->resolveEntitledWorkspace($workspaceUid);
        $business = AgencyOutreachBusinessResolver::forWorkspace($workspace);

        $query = AgencyProspectCampaignMember::query()
            ->where('workspace_id', $workspace->id)
            ->whereNotNull('chat_box_id')
            ->with(['prospect', 'campaign']);

        $filters = [
            'campaign' => (string) $request->query('campaign', ''),
            'stage' => (string) $request->query('stage', ''),
            'mode' => (string) $request->query('mode', ''),
            'outcome' => (string) $request->query('outcome', ''),
        ];

        if ($filters['campaign'] !== '') {
            $campaignId = AgencyProspectCampaign::where('workspace_id', $workspace->id)->where('uid', $filters['campaign'])->value('id');
            $query->where('campaign_id', $campaignId ?? 0);
        }

        if ($filters['stage'] !== '' && AgencyProspectStage::tryFrom((int) $filters['stage']) !== null) {
            $query->where('stage', (int) $filters['stage']);
        }

        if ($filters['mode'] === 'ai') {
            $query->whereNull('ai_paused_at');
        } elseif ($filters['mode'] === 'manual') {
            $query->whereNotNull('ai_paused_at');
        }

        if ($filters['outcome'] === 'booked') {
            $query->where('stage', AgencyProspectStage::Booked->value);
        } elseif ($filters['outcome'] === 'rejected') {
            $query->where('stage', AgencyProspectStage::StoppedOptOut->value);
        }

        $members = $query->orderByDesc('last_inbound_at')->orderByDesc('id')->paginate(self::PAGE_SIZE)->withQueryString();

        $links = app(\App\Library\AgencyOutreach\OutreachConversationLinks::class)->forMembers($workspace, $members);

        return view('customer.workspaces.prospecting.conversations', [
            'workspaceUid' => $workspaceUid,
            'members' => $members,
            'links' => $links,
            'filters' => $filters,
            'campaigns' => AgencyProspectCampaign::where('workspace_id', $workspace->id)->orderBy('name')->get(['uid', 'name']),
            'stages' => AgencyProspectStage::cases(),
        ]);
    }

    public function pauseAi(string $workspaceUid, string $member): RedirectResponse
    {
        $workspace = $this->resolveEntitledWorkspace($workspaceUid);
        $row = $this->workspaceMember($workspace, $member);

        app(OutreachTakeoverService::class)->pause($row, Auth::user());

        return redirect()->back()->with('flash_success', 'AI replies are paused for this prospect. Reply from Conversations.');
    }

    public function resumeAi(string $workspaceUid, string $member): RedirectResponse
    {
        $workspace = $this->resolveEntitledWorkspace($workspaceUid);
        $row = $this->workspaceMember($workspace, $member);

        app(OutreachTakeoverService::class)->resume($row, Auth::user());

        return redirect()->back()->with('flash_success', 'AI replies are back on for this prospect.');
    }

    // -----------------------------------------------------------------
    // Campaigns (managed)
    // -----------------------------------------------------------------

    public function storeManagedCampaign(Request $request, string $workspaceUid): RedirectResponse
    {
        $workspace = $this->resolveEntitledWorkspace($workspaceUid);
        $bridge = app(OutreachContactGroupBridge::class);
        $business = $bridge->businessFor($workspace, Auth::user());

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'opening_message' => ['required', 'string', 'max:1600'],
            'context' => ['nullable', 'string', 'max:10000'],
            'group_mode' => ['required', 'in:existing,new'],
            'contact_group_uid' => ['required_if:group_mode,existing', 'nullable', 'string', 'max:64'],
            'new_group_name' => ['required_if:group_mode,new', 'nullable', 'string', 'max:255'],
            'intent' => ['nullable', 'in:draft,start'],
        ], [
            'group_mode.required' => 'Choose a contact group for this campaign.',
            'contact_group_uid.required_if' => 'Select a contact group.',
            'new_group_name.required_if' => 'Enter a name for the new group.',
        ]);

        if ($business === null) {
            return back()->withInput()->withErrors(['group_mode' => 'Your Outreach business is not set up, so contact groups cannot be used yet.']);
        }

        $group = null;
        $name = null;

        if ($validated['group_mode'] === 'existing') {
            $group = $bridge->findGroup($business, (string) $validated['contact_group_uid']);

            if ($group === null) {
                return back()->withInput()->withErrors(['contact_group_uid' => 'That contact group is not available.']);
            }
        } else {
            if (! Auth::user()->can('create_contact_group')) {
                return back()->withInput()->withErrors(['new_group_name' => 'You do not have permission to create contact groups.']);
            }

            $name = trim((string) $validated['new_group_name']);

            // Same uniqueness rule as the Contacts "new group" form: per owning customer.
            if (ContactGroups::where('customer_id', $business->customer_id)->where('name', $name)->exists()) {
                return back()->withInput()->withErrors(['new_group_name' => 'A contact group with this name already exists. Select it instead.']);
            }
        }

        try {
            $campaign = DB::transaction(function () use ($validated, $workspace, $business, $bridge, $group, $name) {
                $group ??= app(ContactsRepository::class)->store([
                    'name' => $name,
                    'business_id' => $business->id,
                    'user_id' => $business->customer_id,
                ]);

                $campaign = AgencyProspectCampaign::create([
                    'workspace_id' => $workspace->id,
                    'name' => $validated['name'],
                    'context' => $validated['context'] ?? null,
                    'opening_message' => $validated['opening_message'],
                    'status' => AgencyProspectCampaignStatus::Draft->value,
                    'sending_mode' => AgencyProspectCampaign::MODE_MANAGED,
                    'recipient_source' => AgencyProspectCampaign::SOURCE_CONTACT_GROUP,
                    'contact_group_id' => $group->id,
                    'contact_group_business_id' => $business->id,
                ]);

                // Draft only: this snapshots recipients and never queues a send.
                $bridge->populate($workspace, $campaign, $group);

                return $campaign;
            });
        } catch (\DomainException $e) {
            return back()->withInput()->withErrors(['contact_group_uid' => $e->getMessage()]);
        }

        $report = $campaign->fresh()->recipient_report ?? [];

        // "Start campaign" in the New campaign panel only creates the draft and opens it: starting always goes
        // through the draft page's Start action, so the readiness, consent and recipient checks can never be skipped.
        $note = $request->input('intent') === 'start'
            ? ' Check the recipients, then press Start campaign to begin.'
            : ' Nothing has been sent.';

        return redirect()
            ->route('customer.workspaces.prospecting.campaigns.show', [$workspaceUid, $campaign->uid])
            ->with('flash_success', 'Draft created with ' . ($report['eligible'] ?? 0) . ' recipient(s) from "' . ($name ?? $group?->name) . '".' . $note);
    }

    /** Recipient summary for the picker: real counts for one group, nothing written. */
    public function groupSummary(Request $request, string $workspaceUid): JsonResponse
    {
        $workspace = $this->resolveEntitledWorkspace($workspaceUid);
        $bridge = app(OutreachContactGroupBridge::class);
        $business = $bridge->businessFor($workspace, Auth::user());
        $group = $business === null ? null : $bridge->findGroup($business, (string) $request->query('group', ''));

        abort_if($group === null, 404);

        $summary = $bridge->summarize($workspace, $group);

        return response()->json([
            'name' => $group->name,
            'group_total' => $summary['group_total'],
            'eligible' => $summary['eligible'],
            'excluded' => $summary['excluded'],
            'too_large' => $summary['too_large'],
            'reasons' => collect($summary['reasons'])->map(fn ($n, $r) => ['label' => OutreachContactGroupBridge::reasonLabel($r), 'count' => $n])->values(),
        ]);
    }

    /** Explicit, draft-only re-snapshot of the linked group. */
    public function refreshRecipients(string $workspaceUid, AgencyProspectCampaign $campaign): RedirectResponse
    {
        $workspace = $this->resolveEntitledWorkspace($workspaceUid);
        $this->abortUnlessCampaign($workspace, $campaign);
        $bridge = app(OutreachContactGroupBridge::class);
        $back = redirect()->route('customer.workspaces.prospecting.campaigns.show', [$workspaceUid, $campaign->uid]);

        $group = $campaign->usesContactGroup() ? $bridge->linkedGroup($workspace, $campaign) : null;

        if ($group === null || $bridge->businessFor($workspace, Auth::user()) === null) {
            return $back->with('flash_error', 'This campaign has no valid contact group to refresh from.');
        }

        try {
            $report = $bridge->populate($workspace, $campaign, $group);
        } catch (\DomainException $e) {
            return $back->with('flash_error', $e->getMessage());
        }

        return $back->with('flash_success', 'Recipients refreshed: ' . $report['eligible'] . ' eligible, ' . $report['excluded'] . ' excluded.');
    }

    /** Draft-only group change: replaces the group-sourced snapshot, keeps individual enrollments. */
    public function changeRecipientGroup(Request $request, string $workspaceUid, AgencyProspectCampaign $campaign): RedirectResponse
    {
        $workspace = $this->resolveEntitledWorkspace($workspaceUid);
        $this->abortUnlessCampaign($workspace, $campaign);
        $bridge = app(OutreachContactGroupBridge::class);
        $back = redirect()->route('customer.workspaces.prospecting.campaigns.show', [$workspaceUid, $campaign->uid]);

        $validated = $request->validate([
            'contact_group_uid' => ['required', 'string', 'max:64'],
            'confirm' => ['accepted'],
        ], ['confirm.accepted' => 'Confirm that you understand the recipient list will be replaced.']);

        $business = $bridge->businessFor($workspace, Auth::user());
        $group = $business === null ? null : $bridge->findGroup($business, $validated['contact_group_uid']);

        if ($group === null) {
            return $back->with('flash_error', 'That contact group is not available.');
        }

        try {
            DB::transaction(function () use ($workspace, $campaign, $group, $business, $bridge) {
                $locked = AgencyProspectCampaign::where('id', $campaign->id)->where('workspace_id', $workspace->id)->lockForUpdate()->first();

                if ($locked === null || $locked->status !== AgencyProspectCampaignStatus::Draft || ! $locked->isManaged()) {
                    throw new \DomainException('The contact group can only be changed while a campaign is still a draft.');
                }

                $bridge->clearGroupSnapshot($workspace, $locked);
                $locked->update([
                    'recipient_source' => AgencyProspectCampaign::SOURCE_CONTACT_GROUP,
                    'contact_group_id' => $group->id,
                    'contact_group_business_id' => $business->id,
                ]);
                $bridge->populate($workspace, $locked, $group);
            });
        } catch (\DomainException $e) {
            return $back->with('flash_error', $e->getMessage());
        }

        return $back->with('flash_success', 'Group changed to "' . $group->name . '". Recipients were rebuilt from it.');
    }

    /**
     * Script & Settings → Sending numbers: which of the agency's own Outreach numbers this
     * campaign starts new conversations from. Changing it never moves a prospect who already
     * has a number; it only decides where NEW conversations go. Every submitted number must be
     * in THIS Workspace's Outreach pool, or nothing is saved.
     */
    public function updateCampaignSenders(Request $request, string $workspaceUid, AgencyProspectCampaign $campaign): RedirectResponse
    {
        $workspace = $this->resolveEntitledWorkspace($workspaceUid);
        $this->abortUnlessCampaign($workspace, $campaign);
        $back = redirect()->to(route('customer.workspaces.prospecting.script.show', $workspaceUid) . '?senders_campaign=' . urlencode((string) $campaign->uid) . '#sc-senders');

        $validated = $request->validate([
            'channels' => ['array', 'max:500'],
            'channels.*' => ['string', 'max:64'],
            'select_all' => ['nullable', 'boolean'],
        ]);

        $pool = app(OutreachSenderPool::class);
        $channels = $pool->poolChannels((int) $workspace->id);

        if ($request->boolean('select_all')) {
            $chosen = $channels->filter(fn ($channel) => $pool->availability($channel)['ok']);
        } else {
            $uids = array_values(array_unique($validated['channels'] ?? []));
            $chosen = $channels->filter(fn ($channel) => in_array((string) $channel->uid, $uids, true));

            if ($chosen->count() !== count($uids)) {
                return $back->with('flash_error', 'One of those numbers is not one of this agency\'s Outreach numbers. Nothing was changed.');
            }
        }

        if ($chosen->isEmpty()) {
            return $back->with('flash_error', 'Select at least one sending number for this campaign.');
        }

        $saved = DB::transaction(function () use ($workspace, $campaign, $chosen) {
            $locked = AgencyProspectCampaign::where('id', $campaign->id)->where('workspace_id', $workspace->id)->lockForUpdate()->first();

            if ($locked === null || ! $locked->isManaged() || $locked->status === AgencyProspectCampaignStatus::Ended) {
                return false;
            }

            $locked->update(['sender_channel_ids' => $chosen->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all()]);

            return true;
        });

        if (! $saved) {
            return $back->with('flash_error', 'An ended campaign\'s sending numbers can no longer be changed.');
        }

        return $back->with('flash_success', 'Sending numbers saved for "' . $campaign->name . '". Prospects who already have a number keep it.');
    }

    /** Script & Settings → Sending numbers: what the section shows for one campaign. */
    private function senderSection(Workspace $workspace, ?\App\Models\Business $business, ?string $campaignUid, ?int $openerCap): array
    {
        $pool = app(OutreachSenderPool::class);
        $campaigns = AgencyProspectCampaign::query()
            ->where('workspace_id', $workspace->id)
            ->where('sending_mode', AgencyProspectCampaign::MODE_MANAGED)
            ->where('status', '!=', AgencyProspectCampaignStatus::Ended->value)
            ->orderBy('name')
            ->get();
        $campaign = $campaigns->firstWhere('uid', $campaignUid) ?? $campaigns->first();
        $poolIds = $pool->poolChannels((int) $workspace->id)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $selection = $campaign !== null ? $pool->selection($campaign) : null;
        $effective = $campaign !== null ? $pool->campaignChannels($campaign, $business)->pluck('id')->map(fn ($id) => (int) $id)->all() : [];

        $assigned = $campaign === null ? collect() : AgencyProspectCampaignMember::query()
            ->where('campaign_id', $campaign->id)->whereNotNull('sender_channel_id')
            ->selectRaw('sender_channel_id, COUNT(*) as aggregate')->groupBy('sender_channel_id')
            ->pluck('aggregate', 'sender_channel_id');
        $held = \App\Models\AgencyProspectMessage::query()
            ->where('workspace_id', $workspace->id)
            ->where('status', \App\Models\AgencyProspectMessage::STATUS_PAUSED)
            ->where('failure_reason', 'like', OutreachSenderPool::REASON_SENDER_UNAVAILABLE . ':%')
            ->selectRaw('channel_id, COUNT(*) as aggregate')->groupBy('channel_id')
            ->pluck('aggregate', 'channel_id');

        $numbers = \App\Models\AgencyProspectingChannel::query()
            ->where('workspace_id', $workspace->id)
            ->where('provider', \App\Models\SendingServer::TYPE_TELNYX)
            ->with('sendingServer')
            ->orderBy('id')
            ->get()
            ->map(function ($channel) use ($pool, $poolIds, $effective, $assigned, $held) {
                $inPool = in_array((int) $channel->id, $poolIds, true);
                $state = $pool->availability($channel);

                return [
                    'uid' => (string) $channel->uid,
                    'number' => (string) $channel->sender_number,
                    'in_pool' => $inPool,
                    'selected' => in_array((int) $channel->id, $effective, true),
                    'available' => $inPool && $state['ok'],
                    'reason' => ! $inPool ? 'Not added to Outreach. Add it in Phone & texting.' : ($state['ok'] ? null : $state['message']),
                    'assigned' => (int) ($assigned[$channel->id] ?? 0),
                    'held' => (int) ($held[$channel->id] ?? 0),
                ];
            })
            ->values();

        $eligibleSelected = $numbers->filter(fn ($n) => $n['selected'] && $n['available'])->values();
        $shares = OutreachSenderPool::distribution((int) ($openerCap ?? 0), $eligibleSelected->count());

        return [
            'campaigns' => $campaigns->map(fn ($c) => ['uid' => (string) $c->uid, 'name' => $c->name])->all(),
            'campaign' => $campaign === null ? null : ['uid' => (string) $campaign->uid, 'name' => $campaign->name, 'configured' => $selection !== null],
            'numbers' => $numbers->all(),
            'cap' => $openerCap,
            'distribution' => $eligibleSelected->map(fn ($n, $i) => ['number' => $n['number'], 'share' => $shares[$i] ?? 0])->values()->all(),
        ];
    }

    private function abortUnlessCampaign(Workspace $workspace, AgencyProspectCampaign $campaign): void
    {
        abort_unless((int) $campaign->workspace_id === (int) $workspace->id && $campaign->isManaged(), 404);
    }

    public function resumeSending(string $workspaceUid): RedirectResponse
    {
        $workspace = $this->resolveEntitledWorkspace($workspaceUid);

        $reason = app(AgencyOutreachReadiness::class)->forWorkspace($workspace)->firstBlockingReason();

        if ($reason !== null) {
            return redirect()->back()->with('flash_error', $reason);
        }

        $resumed = app(OutreachResumeService::class)->resumePausedSends($workspace);

        return redirect()->back()->with('flash_success', $resumed > 0
            ? "Sending resumed for {$resumed} prospect(s)."
            : 'Nothing was waiting to be sent.');
    }

    // -----------------------------------------------------------------
    // Script & Settings
    // -----------------------------------------------------------------

    public function script(Request $request, string $workspaceUid)
    {
        $workspace = $this->resolveEntitledWorkspace($workspaceUid);
        $business = AgencyOutreachBusinessResolver::forWorkspace($workspace);
        $script = OutreachScript::forWorkspace($workspace);
        $settings = $script->settings();

        $values = [];

        foreach (OutreachScriptDefaults::FIELDS as $field) {
            $values[$field] = $script->get($field);
        }

        $bookingTypes = $business === null ? collect() : BookingType::query()
            ->where('is_active', true)
            ->whereHas('location', fn ($q) => $q->where('business_id', $business->id))
            ->orderBy('name')
            ->get(['id', 'name', 'public_booking_uuid']);

        $picker = [
            'groups' => app(MergeFieldRegistry::class)->catalog(
                $business ?? new \App\Models\Business(),
                [MergeFieldRegistry::GROUP_AGENCY, MergeFieldRegistry::GROUP_PROSPECT],
            ),
            'extra' => [],
        ];

        $openerCap = $settings?->opener_daily_cap ?? (config('outreach.send_pacing.opener_daily_cap') > 0 ? (int) config('outreach.send_pacing.opener_daily_cap') : null);

        return view('customer.workspaces.prospecting.script', [
            'workspaceUid' => $workspaceUid,
            'senders' => $this->senderSection($workspace, $business, is_string($request->query('senders_campaign')) ? $request->query('senders_campaign') : null, $openerCap),
            'values' => $values,
            'agencyName' => $settings?->agency_name,
            'websiteUrl' => $settings?->website_url,
            'bookingUrl' => $settings?->booking_url,
            'replyDelay' => collect(\App\Library\AgencyOutreach\OutreachReplyTiming::windows($workspace))->map(fn (array $w) => ['min' => intdiv($w[0], 60), 'max' => intdiv($w[1], 60)])->all(),
            'openerDailyCap' => $settings?->opener_daily_cap ?? (config('outreach.send_pacing.opener_daily_cap') > 0 ? (int) config('outreach.send_pacing.opener_daily_cap') : null),
            'followupEnabled' => $script->followUpEnabled(),
            'followupDelay' => $script->followUpDelayHours(),
            'aiEnabled' => $script->aiEnabled(),
            'bookingTypes' => $bookingTypes->map(fn (BookingType $type) => [
                'name' => $type->name,
                'url' => route('public.booking.show', $type->public_booking_uuid),
            ])->all(),
            'picker' => $picker,
            'previewUrl' => route('customer.workspaces.prospecting.script.preview', $workspaceUid),
            'sequenceSteps' => \App\Library\AgencyOutreach\OutreachSequence::forWorkspace($workspace)->steps(),
            'sequencePaused' => $script->sequencePaused(),
            'faqEntries' => \App\Library\AgencyOutreach\OutreachFaq::forWorkspace($workspace)->all(),
            'faqFallback' => \App\Library\AgencyOutreach\OutreachFaq::forWorkspace($workspace)->fallback(),
            'starterTemplate' => collect([1, 2, 3])->map(fn (int $n) => [
                'body' => \App\Library\AgencyOutreach\OutreachScriptDefaults::all()['message_' . $n],
                'advance_on' => 'reply',
                'delay_hours' => null,
                'enabled' => true,
            ])->push([
                'body' => \App\Library\AgencyOutreach\OutreachScriptDefaults::all()['followup_message'],
                'advance_on' => 'time',
                'delay_hours' => 24,
                'enabled' => true,
            ])->all(),
            'campaignOptions' => \App\Models\AgencyProspectCampaign::query()
                ->where('workspace_id', $workspace->id)->orderBy('name')->get(['id', 'name'])
                ->map(fn ($c) => ['id' => (int) $c->id, 'name' => $c->name])->all(),
            'unknown' => collect($values)->map(fn ($text) => OutreachScriptRenderer::unknownTokens($text, $workspace))->filter()->all(),
        ]);
    }

    public function updateScript(UpdateOutreachScriptRequest $request, string $workspaceUid): RedirectResponse
    {
        $workspace = $this->resolveEntitledWorkspace($workspaceUid);

        $fields = $request->validated();
        unset($fields['sequence_steps_present'], $fields['faq_entries_present']);

        // The legacy switches are only written by a form that carries them.
        if ($request->has('followup_enabled')) {
            $fields['followup_enabled'] = $request->boolean('followup_enabled');
        }

        if ($request->has('ai_enabled')) {
            $fields['ai_enabled'] = $request->boolean('ai_enabled');
        }

        // An emptied list posts nothing, so the editor says it was on the page.
        if ($request->boolean('sequence_steps_present')) {
            $fields['sequence_steps'] = $fields['sequence_steps'] ?? [];
        }

        if ($request->boolean('faq_entries_present')) {
            $fields['faq_entries'] = $fields['faq_entries'] ?? [];
        }

        if ($request->filled('faq_snapshot') && array_key_exists('faq_entries', $fields)) {
            $fields['faq_snapshot'] = (string) $request->input('faq_snapshot');
        }

        OutreachScriptManager::save($workspace, $fields, Auth::user());

        $warnings = [];
        $script = OutreachScript::forWorkspace($workspace);

        $sequence = \App\Library\AgencyOutreach\OutreachSequence::forWorkspace($workspace);

        if (! $sequence->containsCalendarLink()) {
            $warnings[] = 'None of your messages includes your calendar link, so Outreach cannot start until one does.';
        }

        foreach ($sequence->steps() as $position => $step) {
            $unknown = OutreachScriptRenderer::unknownTokens($step['body'], $workspace);

            if ($unknown !== []) {
                $warnings[] = 'Message ' . ($position + 1) . ' has fields that are not recognised and will be left blank: ' . implode(', ', $unknown) . '.';
            }
        }

        $redirect = redirect()
            ->route('customer.workspaces.prospecting.script.show', $workspaceUid)
            ->with('flash_success', 'Script and settings saved.');

        return $warnings === [] ? $redirect : $redirect->with('outreach_warnings', $warnings);
    }

    public function previewScript(Request $request, string $workspaceUid): JsonResponse
    {
        $workspace = $this->resolveEntitledWorkspace($workspaceUid);

        $text = (string) $request->input('text', '');
        abort_if(mb_strlen($text) > 2000, 422);

        $sample = new AgencyProspect([
            'workspace_id' => $workspace->id,
            'company_name' => 'Example Company',
            'contact_name' => 'Alex Morgan',
        ]);

        $canonical = \App\Library\AgencyOutreach\OutreachScriptTokens::canonicalise($text);

        return response()->json([
            'preview' => OutreachScriptRenderer::render($canonical, $workspace, $sample),
            'unknown' => OutreachScriptRenderer::unknownTokens($canonical, $workspace),
            'has_calendar_link' => str_contains($canonical, '{{agency.calendar_link}}'),
        ]);
    }

    private function workspaceMember(Workspace $workspace, string $memberUid): AgencyProspectCampaignMember
    {
        $member = AgencyProspectCampaignMember::where('uid', $memberUid)
            ->where('workspace_id', $workspace->id)
            ->first();

        abort_if($member === null, 404);

        return $member;
    }
}
