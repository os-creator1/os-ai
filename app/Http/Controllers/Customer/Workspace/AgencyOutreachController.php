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
use App\Library\AgencyOutreach\OutreachResumeService;
use App\Library\AgencyOutreach\OutreachScript;
use App\Library\AgencyOutreach\OutreachScriptDefaults;
use App\Library\AgencyOutreach\OutreachScriptManager;
use App\Library\AgencyOutreach\OutreachScriptRenderer;
use App\Library\AgencyOutreach\OutreachTakeoverService;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Merge\MergeFieldRegistry;
use App\Library\Workspace\WorkspaceManager;
use App\Models\AgencyProspect;
use App\Models\AgencyProspectCampaign;
use App\Models\AgencyProspectCampaignMember;
use App\Models\BookingType;
use App\Models\ChatBox;
use App\Models\Workspace;
use App\Repositories\Contracts\WorkspaceMembershipRepository;
use App\Repositories\Contracts\WorkspaceRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

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

        $boxUids = $business === null || $members->isEmpty()
            ? collect()
            : ChatBox::query()->where('business_id', $business->id)->whereIn('id', $members->pluck('chat_box_id'))->pluck('uid', 'id');

        $canOpen = $business !== null && app(WorkspaceManager::class)->userCanAccessBusiness((int) Auth::id(), $business);

        $links = [];

        foreach ($members as $member) {
            $uid = $boxUids->get($member->chat_box_id);
            $links[$member->id] = $canOpen && $uid !== null
                ? route('customer.workspaces.businesses.conversations.index', [$workspace->uid, $business->uid]) . '?open=' . urlencode((string) $uid)
                : null;
        }

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

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'opening_message' => ['required', 'string', 'max:1600'],
            'context' => ['nullable', 'string', 'max:10000'],
        ]);

        $campaign = AgencyProspectCampaign::create([
            'workspace_id' => $workspace->id,
            'name' => $validated['name'],
            'context' => $validated['context'] ?? null,
            'opening_message' => $validated['opening_message'],
            'status' => AgencyProspectCampaignStatus::Draft->value,
            'sending_mode' => AgencyProspectCampaign::MODE_MANAGED,
        ]);

        return redirect()
            ->route('customer.workspaces.prospecting.campaigns.show', [$workspaceUid, $campaign->uid])
            ->with('flash_success', 'Campaign created. Add prospects, then start it.');
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

    public function script(string $workspaceUid)
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

        return view('customer.workspaces.prospecting.script', [
            'workspaceUid' => $workspaceUid,
            'values' => $values,
            'agencyName' => $settings?->agency_name,
            'websiteUrl' => $settings?->website_url,
            'bookingUrl' => $settings?->booking_url,
            'followupEnabled' => $script->followUpEnabled(),
            'followupDelay' => $script->followUpDelayHours(),
            'aiEnabled' => $script->aiEnabled(),
            'bookingTypes' => $bookingTypes->map(fn (BookingType $type) => [
                'name' => $type->name,
                'url' => route('public.booking.show', $type->public_booking_uuid),
            ])->all(),
            'picker' => $picker,
            'previewUrl' => route('customer.workspaces.prospecting.script.preview', $workspaceUid),
            'unknown' => collect($values)->map(fn ($text) => OutreachScriptRenderer::unknownTokens($text, $workspace))->filter()->all(),
        ]);
    }

    public function updateScript(UpdateOutreachScriptRequest $request, string $workspaceUid): RedirectResponse
    {
        $workspace = $this->resolveEntitledWorkspace($workspaceUid);

        $fields = $request->validated();
        // An unchecked box is simply absent from the request.
        $fields['followup_enabled'] = $request->boolean('followup_enabled');
        $fields['ai_enabled'] = $request->boolean('ai_enabled');

        OutreachScriptManager::save($workspace, $fields, Auth::user());

        $warnings = [];
        $script = OutreachScript::forWorkspace($workspace);

        if (! str_contains($script->get('message_3'), '{{agency.calendar_link}}')) {
            $warnings[] = 'Message 3 does not include your calendar link, so Outreach cannot start until it does.';
        }

        foreach (['message_1', 'message_2', 'message_3', 'followup_message'] as $field) {
            $unknown = OutreachScriptRenderer::unknownTokens($script->get($field), $workspace);

            if ($unknown !== []) {
                $warnings[] = 'Some fields in ' . str_replace('_', ' ', $field) . ' are not recognised and will be left blank: ' . implode(', ', $unknown) . '.';
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
