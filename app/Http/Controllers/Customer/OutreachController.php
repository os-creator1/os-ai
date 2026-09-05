<?php

namespace App\Http\Controllers\Customer;

use App\Http\Requests\Campaigns\CampaignBuilderRequest;
use App\Http\Requests\Campaigns\MMSCampaignBuilderRequest;
use App\Http\Requests\Campaigns\MMSQuickSendRequest;
use App\Http\Requests\Campaigns\QuickSendRequest;
use App\Library\Tool;
use App\Library\Workspace\WorkspaceManager;
use App\Models\Business;
use App\Models\Campaigns;
use App\Models\ContactGroups;
use App\Models\Contacts;
use App\Models\Country;
use App\Models\CustomerBasedPricingPlan;
use App\Models\CustomerBasedSendingServer;
use App\Models\PhoneNumbers;
use App\Models\Plan;
use App\Models\PlansCoverageCountries;
use App\Models\Senderid;
use App\Models\Templates;
use App\Models\User;
use App\Repositories\Contracts\CampaignRepository;
use App\Repositories\Contracts\WorkspaceRepository;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberUtil;

/**
 * B1 Pass 2 — Outreach / Compose, cut over to explicit Business scope.
 *
 * A thin orchestration layer over the existing Campaign send/persistence
 * core (App\Repositories\Contracts\CampaignRepository). It still reuses
 * EloquentCampaignRepository::quickSend()/campaignBuilder()/
 * checkQuickSendValidation() unchanged in signature — Business context is
 * threaded through as additive $input/$sendData array keys
 * ('business_id', 'user_id') those methods already read (or now read),
 * never as a new method parameter, so RFC-005's
 * QuickSendNonConversationCallersUnaffectedTest's exact-signature
 * assertions stay intact and quickSend() is still never called with a 3rd
 * argument.
 *
 * Every action resolves its Business via the exact RFC-003 §14.1
 * boundary (WorkspaceRepository::findByUid()/businessesForWorkspace() +
 * WorkspaceManager::userCanAccessBusiness()), mirroring
 * Customer\Business\UsageBillingController::resolveViewableBusiness()
 * verbatim — never business.customer_id === Auth::id().
 */
class OutreachController extends CustomerBaseController
{
    public function __construct(
        private readonly CampaignRepository $campaigns,
        private readonly WorkspaceRepository $workspaceRepository,
        private readonly WorkspaceManager $workspaceManager,
    ) {
    }

    /**
     * Bare /outreach entry/selector route. Never guesses a Business.
     */
    public function entry(): View|Factory|Application|RedirectResponse
    {
        $accessible = $this->accessibleBusinesses();

        if (count($accessible) === 0) {
            return view('customer.Outreach.entry', ['accessible' => []]);
        }

        if (count($accessible) === 1) {
            [$workspace, $business] = $accessible[0];

            return redirect()->route('customer.workspaces.businesses.outreach.index', [$workspace->uid, $business->uid]);
        }

        return view('customer.Outreach.entry', ['accessible' => $accessible]);
    }

    /**
     * Renamed from index() (kept by the legacy B1 controller) to avoid a
     * fatal LSP signature-compatibility error against
     * CustomerBaseController::index(), which takes zero parameters.
     */
    public function compose(string $workspaceUid, string $businessUid): View|Factory|Application|RedirectResponse
    {
        $business = $this->resolveAccessibleBusiness($workspaceUid, $businessUid);
        $user     = Auth::user();

        $canSmsQuickSend       = $user->can('sms_quick_send');
        $canSmsCampaignBuilder = $user->can('sms_campaign_builder');
        $canMmsQuickSend       = $user->can('mms_quick_send');
        $canMmsCampaignBuilder = $user->can('mms_campaign_builder');

        $canSms = $canSmsQuickSend || $canSmsCampaignBuilder;
        $canMms = $canMmsQuickSend || $canMmsCampaignBuilder;

        if (! $canSms && ! $canMms) {
            throw new AuthorizationException();
        }

        $activeSubscription = $business->customer->activeSubscription();
        if (! $activeSubscription) {
            return redirect()->route('customer.subscriptions.index')->with([
                'status'  => 'error',
                'message' => __('locale.customer.no_active_subscription'),
            ]);
        }

        $plan_id = $activeSubscription->plan_id;
        $plan    = Plan::where('status', true)->find($plan_id);

        // Legacy Subscription/Plan/coverage stays Customer-level (not
        // Business-level) by design — resolved from the selected
        // Business's owning customer, never the acting Workspace member.
        $coverage = CustomerBasedPricingPlan::where('plan_id', $plan_id)
            ->where('status', true)
            ->where('user_id', $business->customer_id)
            ->get();

        if ($coverage->count() < 1) {
            $coverage = PlansCoverageCountries::where('plan_id', $plan_id)->where('status', true)->get();
        }

        $sender_ids = Senderid::where('business_id', $business->id)->where('status', 'active')->get();

        $phoneNumbers = PhoneNumbers::where('business_id', $business->id)->where('status', 'assigned')->get();

        $smsPhoneNumbers = $phoneNumbers->filter(function ($number) {
            $caps = json_decode($number->capabilities, true);

            return is_array($caps) && in_array('sms', $caps);
        });

        $mmsPhoneNumbers = $phoneNumbers->filter(function ($number) {
            $caps = json_decode($number->capabilities, true);

            return is_array($caps) && in_array('mms', $caps);
        });

        $templates      = Templates::where('business_id', $business->id)->where('status', 1)->get();
        $sendingServers = CustomerBasedSendingServer::where('business_id', $business->id)->where('status', 1)->get();
        $contact_groups = ContactGroups::where('status', 1)->where('business_id', $business->id)->get();

        return view('customer.Outreach.index', [
            'workspaceUid'           => $workspaceUid,
            'businessUid'            => $businessUid,
            'canSms'                 => $canSms,
            'canMms'                 => $canMms,
            'canSmsQuickSend'        => $canSmsQuickSend,
            'canSmsCampaignBuilder'  => $canSmsCampaignBuilder,
            'canMmsQuickSend'        => $canMmsQuickSend,
            'canMmsCampaignBuilder'  => $canMmsCampaignBuilder,
            'sender_ids'             => $sender_ids,
            'smsPhoneNumbers'        => $smsPhoneNumbers,
            'mmsPhoneNumbers'        => $mmsPhoneNumbers,
            'coverage'               => $coverage,
            'templates'              => $templates,
            'sendingServers'         => $sendingServers,
            'contact_groups'         => $contact_groups,
            'plan_id'                => $plan_id,
            'showDlt'                => (bool) (config('app.trai_dlt') && $plan && $plan->is_dlt),
        ]);
    }

    public function sendSms(Campaigns $campaign, QuickSendRequest $request, string $workspaceUid, string $businessUid): RedirectResponse
    {
        $business = $this->resolveAccessibleBusiness($workspaceUid, $businessUid);

        if (config('app.stage') === 'demo') {
            return $this->outreachError($workspaceUid, $businessUid, 'Sorry! This option is not available in demo mode');
        }

        $activeSubscription = $business->customer->activeSubscription();

        if (! $activeSubscription) {
            return $this->outreachError($workspaceUid, $businessUid, __('locale.customer.no_active_subscription'));
        }

        $plan = Plan::where('status', true)->find($activeSubscription->plan_id);
        if (! $plan) {
            return $this->outreachError($workspaceUid, $businessUid, __('locale.customer.no_active_subscription'));
        }

        $businessOwner = $business->customer->user;

        if (config('app.trai_dlt') && $plan->is_dlt) {
            if (empty($request->input('dlt_template_id'))) {
                return $this->outreachError($workspaceUid, $businessUid, 'DLT Template ID is required.');
            }

            if (empty($businessOwner?->dlt_entity_id)) {
                return $this->outreachError($workspaceUid, $businessUid, 'The DLT Entity ID is mandatory. Kindly reach out to the system administrator for further assistance.');
            }
        }

        $recipients = $this->getRecipients($request);

        if ($recipients->isEmpty()) {
            return $this->outreachError($workspaceUid, $businessUid, __('locale.campaigns.at_least_one_number'));
        }

        if ($recipients->count() > 100) {
            return $this->outreachError($workspaceUid, $businessUid, __('locale.campaigns.too_many_numbers'));
        }

        $sendingServersExist = CustomerBasedSendingServer::where('business_id', $business->id)->where('status', 1)->exists();
        if ($sendingServersExist && ! $request->has('sending_server')) {
            return $this->outreachError($workspaceUid, $businessUid, 'Please select your sending server.');
        }

        $sendData               = $request->except('_token', 'recipients', 'delimiter');
        $sendData['message']    = str_replace("\r", '', $sendData['message']);
        $sendData['business_id'] = $business->id;
        $sendData['user_id']     = $business->customer_id;

        $validateData = $this->campaigns->checkQuickSendValidation($sendData);
        $responseData = $validateData->getData();

        if ($responseData->status === 'error') {
            return $this->outreachError($workspaceUid, $businessUid, $responseData->message);
        }

        $sendData['sender_id'] = $responseData->sender_id;
        $sendData['sms_type']  = $responseData->sms_type;
        $sendData['user']      = User::find($responseData->user_id);

        // The explicit Business, never the LegacyBusinessResolver, decides
        // tenancy for this send — set it on the transient (unsaved)
        // Campaigns instance before quickSend() so every Reports/
        // TrackingLog row it creates inherits business_id from $this.
        $campaign->business_id = $business->id;

        $errors = [];

        foreach ($recipients as $recipient) {
            $cleanRecipient = str_replace(['(', ')', '+', '-', ' '], '', $recipient);
            $phone          = $this->getPhoneNumber($cleanRecipient, $request->input('country_code'));

            if (! is_array($phone)) {
                $errors[] = $phone;
                continue;
            }

            $coverage = $plan->plansCoverageCountries->firstWhere('country_id', $phone['country_id']);

            if (! $coverage) {
                $errors[] = __('locale.campaigns.country_not_covered', ['country' => $phone['country_code']]);
                continue;
            }

            $options = json_decode($coverage['options'], true);
            if (empty($options['plain'])) {
                $errors[] = __('locale.campaigns.sms_not_covered', ['country' => $phone['country_code'], 'sms_type' => 'Plain']);
                continue;
            }

            $sendData['country_code'] = $phone['country_code'];
            $sendData['recipient']    = $phone['recipient'];
            $sendData['region_code']  = $phone['region_code'];

            $data = $this->campaigns->quickSend($campaign, $sendData);

            if ($data->getData()->status !== 'success') {
                $errors[] = $data->getData()->message;
            }
        }

        if (! empty($errors)) {
            return redirect()->route('customer.workspaces.businesses.outreach.index', [$workspaceUid, $businessUid])->with([
                'status'  => 'warning',
                'message' => implode('<br>', $errors),
            ]);
        }

        return redirect()->route('customer.workspaces.businesses.outreach.campaigns', [$workspaceUid, $businessUid])->with([
            'status'  => 'success',
            'message' => __('locale.campaigns.message_successfully_delivered'),
        ]);
    }

    public function sendMms(Campaigns $campaign, MMSQuickSendRequest $request, string $workspaceUid, string $businessUid): RedirectResponse
    {
        $business = $this->resolveAccessibleBusiness($workspaceUid, $businessUid);

        if (config('app.stage') === 'demo') {
            return $this->outreachError($workspaceUid, $businessUid, 'Sorry, this feature is disabled in demo version.');
        }

        $activeSubscription = $business->customer->activeSubscription();

        if (! $activeSubscription) {
            return $this->outreachError($workspaceUid, $businessUid, __('locale.customer.no_active_subscription'));
        }

        $plan = Plan::where('status', true)->find($activeSubscription->plan_id);
        if (! $plan) {
            return $this->outreachError($workspaceUid, $businessUid, __('locale.customer.no_active_subscription'));
        }

        $recipients = $this->getRecipients($request);
        if ($recipients->isEmpty()) {
            return $this->outreachError($workspaceUid, $businessUid, 'No recipients found.');
        }

        if ($recipients->count() > 100) {
            return $this->outreachError($workspaceUid, $businessUid, __('locale.campaigns.too_many_numbers'));
        }

        if (! $request->hasFile('mms_file')) {
            return $this->outreachError($workspaceUid, $businessUid, 'MMS media file is required.');
        }

        $sendData               = $request->except('_token', 'recipients', 'delimiter');
        $sendData['media_url']  = Tool::uploadImage($request->file('mms_file'));
        $sendData['business_id'] = $business->id;
        $sendData['user_id']     = $business->customer_id;

        $sendingServersExist = CustomerBasedSendingServer::where('business_id', $business->id)->where('status', 1)->exists();

        if ($sendingServersExist && ! $request->has('sending_server')) {
            return $this->outreachError($workspaceUid, $businessUid, 'No sending server selected.');
        }

        $validateData = $this->campaigns->checkQuickSendValidation($sendData);
        $validated    = $validateData->getData();

        if ($validated->status === 'error') {
            return $this->outreachError($workspaceUid, $businessUid, $validated->message);
        }

        $sendData['sender_id'] = $validated->sender_id;
        $sendData['sms_type']  = 'mms';
        $sendData['user']      = User::find($validated->user_id);

        $campaign->business_id = $business->id;

        $errors  = [];
        $success = [];

        foreach ($recipients as $recipient) {
            $phone = $this->getPhoneNumber($recipient, $request->input('country_code'));

            if (! is_array($phone)) {
                $errors[] = $phone;
                continue;
            }

            $coverage = $plan->plansCoverageCountries->firstWhere('country_id', $phone['country_id']);
            if (! $coverage) {
                $errors[] = __('locale.campaigns.country_not_covered', ['country' => $phone['country_code']]);
                continue;
            }

            $options = json_decode($coverage['options'], true);
            if (empty($options['mms'])) {
                $errors[] = __('locale.campaigns.sms_not_covered', ['country' => $phone['country_code'], 'sms_type' => 'MMS']);
                continue;
            }

            $sendData['country_code'] = $phone['country_code'];
            $sendData['recipient']    = $phone['recipient'];
            $sendData['region_code']  = $phone['region_code'];

            $response = $this->campaigns->quickSend($campaign, $sendData);
            $result   = $response->getData();

            if ($result->status === 'error') {
                $errors[] = $result->message;
            } elseif (in_array($result->status, ['success', 'info'])) {
                $success[] = $result->message;
            }
        }

        if (! empty($errors)) {
            return $this->outreachError($workspaceUid, $businessUid, implode(' ', $errors));
        }

        return redirect()->route('customer.workspaces.businesses.outreach.campaigns', [$workspaceUid, $businessUid])->with([
            'status'  => 'info',
            'message' => implode(' ', $success),
        ]);
    }

    public function storeSmsCampaign(Campaigns $campaign, CampaignBuilderRequest $request, string $workspaceUid, string $businessUid): RedirectResponse
    {
        $business = $this->resolveAccessibleBusiness($workspaceUid, $businessUid);

        if (config('app.stage') == 'demo') {
            return $this->outreachError($workspaceUid, $businessUid, 'Sorry! This option is not available in demo mode');
        }

        $activeSubscription = $business->customer->activeSubscription();

        if (! $activeSubscription) {
            return redirect()->route('customer.subscriptions.index')->with([
                'status'  => 'error',
                'message' => __('locale.customer.no_active_subscription'),
            ]);
        }

        $plan = Plan::where('status', true)->find($activeSubscription->plan_id);

        if (! $plan) {
            return $this->outreachError($workspaceUid, $businessUid, 'Purchased plan is not active. Please contact support team.');
        }

        $businessOwner = $business->customer->user;

        if (config('app.trai_dlt') && $plan->is_dlt && $request->input('dlt_template_id') == null) {
            return $this->outreachError($workspaceUid, $businessUid, 'DLT Template id is required');
        }

        if (config('app.trai_dlt') && $plan->is_dlt && empty($businessOwner?->dlt_entity_id)) {
            return $this->outreachError($workspaceUid, $businessUid, 'The DLT Entity ID is mandatory. Kindly reach out to the system administrator for further assistance');
        }

        $input               = $request->except('_token');
        $input['business_id'] = $business->id;
        $input['user_id']     = $business->customer_id;

        $data = $this->campaigns->campaignBuilder($campaign, $input);

        if (isset($data->getData()->status) && $data->getData()->status == 'success') {
            return redirect()->route('customer.workspaces.businesses.outreach.campaigns', [$workspaceUid, $businessUid])->with([
                'status'  => 'success',
                'message' => $data->getData()->message,
            ]);
        }

        return $this->outreachError($workspaceUid, $businessUid, $data->getData()->message ?? __('locale.exceptions.something_went_wrong'));
    }

    public function storeMmsCampaign(Campaigns $campaign, MMSCampaignBuilderRequest $request, string $workspaceUid, string $businessUid): RedirectResponse
    {
        $business = $this->resolveAccessibleBusiness($workspaceUid, $businessUid);

        if (config('app.stage') == 'demo') {
            return $this->outreachError($workspaceUid, $businessUid, 'Sorry! This option is not available in demo mode');
        }

        $activeSubscription = $business->customer->activeSubscription();

        if (! $activeSubscription) {
            return redirect()->route('customer.subscriptions.index')->with([
                'status'  => 'error',
                'message' => __('locale.customer.no_active_subscription'),
            ]);
        }

        $plan = Plan::where('status', true)->find($activeSubscription->plan_id);

        if (! $plan) {
            return $this->outreachError($workspaceUid, $businessUid, 'Purchased plan is not active. Please contact support team.');
        }

        $campaignData               = $request->all();
        $campaignData['sms_type']   = 'mms';
        $campaignData['business_id'] = $business->id;
        $campaignData['user_id']     = $business->customer_id;

        $data = $this->campaigns->campaignBuilder($campaign, $campaignData);

        if (isset($data->getData()->status) && $data->getData()->status == 'success') {
            return redirect()->route('customer.workspaces.businesses.outreach.campaigns', [$workspaceUid, $businessUid])->with([
                'status'  => 'success',
                'message' => $data->getData()->message,
            ]);
        }

        return $this->outreachError($workspaceUid, $businessUid, $data->getData()->message ?? __('locale.exceptions.something_went_wrong'));
    }

    public function campaigns(Request $request, string $workspaceUid, string $businessUid): View|Factory|Application
    {
        $business = $this->resolveAccessibleBusiness($workspaceUid, $businessUid);
        $user     = Auth::user();

        if (! $user->can('sms_campaign_builder') && ! $user->can('mms_campaign_builder') && ! $user->can('sms_quick_send') && ! $user->can('mms_quick_send')) {
            throw new AuthorizationException();
        }

        $campaignList = Campaigns::where('business_id', $business->id)
            ->whereIn('sms_type', ['plain', 'unicode', 'mms'])
            ->orderByDesc('id')
            ->paginate(20);

        return view('customer.Outreach.campaigns', [
            'workspaceUid' => $workspaceUid,
            'businessUid'  => $businessUid,
            'campaigns'    => $campaignList,
        ]);
    }

    public function show(string $workspaceUid, string $businessUid, Campaigns $campaign): View|Factory|Application
    {
        $business = $this->resolveAccessibleBusiness($workspaceUid, $businessUid);
        $this->resolveOwnedCampaign($campaign, $business);

        return view('customer.Outreach.show', [
            'workspaceUid' => $workspaceUid,
            'businessUid'  => $businessUid,
            'campaign'     => $campaign,
        ]);
    }

    public function pause(string $workspaceUid, string $businessUid, Campaigns $campaign): RedirectResponse
    {
        $business = $this->resolveAccessibleBusiness($workspaceUid, $businessUid);
        $this->resolveOwnedCampaign($campaign, $business);

        if (config('app.stage') == 'demo') {
            return $this->campaignsError($workspaceUid, $businessUid, 'Sorry! This option is not available in demo mode');
        }

        $data = $this->campaigns->pause($campaign);

        return $this->campaignsResult($workspaceUid, $businessUid, $data);
    }

    public function restart(string $workspaceUid, string $businessUid, Campaigns $campaign): RedirectResponse
    {
        $business = $this->resolveAccessibleBusiness($workspaceUid, $businessUid);
        $this->resolveOwnedCampaign($campaign, $business);

        if (config('app.stage') == 'demo') {
            return $this->campaignsError($workspaceUid, $businessUid, 'Sorry! This option is not available in demo mode');
        }

        $data = $this->campaigns->restart($campaign);

        return $this->campaignsResult($workspaceUid, $businessUid, $data);
    }

    public function resend(string $workspaceUid, string $businessUid, Campaigns $campaign): RedirectResponse
    {
        $business = $this->resolveAccessibleBusiness($workspaceUid, $businessUid);
        $this->resolveOwnedCampaign($campaign, $business);

        if (config('app.stage') == 'demo') {
            return $this->campaignsError($workspaceUid, $businessUid, 'Sorry! This option is not available in demo mode');
        }

        $data = $this->campaigns->resend($campaign);

        return $this->campaignsResult($workspaceUid, $businessUid, $data);
    }

    public function destroy(string $workspaceUid, string $businessUid, Campaigns $campaign): RedirectResponse
    {
        $business = $this->resolveAccessibleBusiness($workspaceUid, $businessUid);
        $this->resolveOwnedCampaign($campaign, $business);

        if (config('app.stage') == 'demo') {
            return $this->campaignsError($workspaceUid, $businessUid, 'Sorry! This option is not available in demo mode');
        }

        if (! $campaign->delete()) {
            return $this->campaignsError($workspaceUid, $businessUid, __('locale.exceptions.something_went_wrong'));
        }

        return redirect()->route('customer.workspaces.businesses.outreach.campaigns', [$workspaceUid, $businessUid])->with([
            'status'  => 'success',
            'message' => __('locale.campaigns.campaign_was_successfully_deleted'),
        ]);
    }

    /**
     * Outreach-specific template lookup — never the legacy, user_id-scoped
     * customer.templates.show_data endpoint. Foreign-Business and
     * nonexistent template ids fail identically (the same JSON error
     * shape the existing composer JS already handles).
     */
    public function templateData(string $workspaceUid, string $businessUid, $id): JsonResponse
    {
        $business = $this->resolveAccessibleBusiness($workspaceUid, $businessUid);

        $data = Templates::where('business_id', $business->id)->find($id);

        if ($data) {
            return response()->json([
                'status'          => 'success',
                'dlt_template_id' => $data->dlt_template_id,
                'message'         => $data->message,
            ]);
        }

        return response()->json([
            'status'  => 'error',
            'message' => __('locale.templates.template_info_not_found'),
        ]);
    }

    /**
     * Business-scoped eligible-contact count for the Campaign Builder
     * preview, mirroring customer.contacts.count_contact's response shape
     * (a bare integer body) without depending on that legacy, user_id-
     * scoped endpoint.
     */
    public function countContacts(Request $request, string $workspaceUid, string $businessUid): Response
    {
        $business = $this->resolveAccessibleBusiness($workspaceUid, $businessUid);

        $groupIds = ContactGroups::where('business_id', $business->id)
            ->whereIn('id', (array) $request->input('contact_group_ids', []))
            ->pluck('id');

        $count = Contacts::whereIn('group_id', $groupIds)->where('status', 'subscribe')->count();

        return response((string) $count);
    }

    /**
     * @return array<int, array{0: \App\Models\Workspace, 1: Business}>
     */
    private function accessibleBusinesses(): array
    {
        $userId     = (int) Auth::id();
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
     * verbatim: unknown Workspace, unknown Business, Business in the wrong
     * Workspace, and an inaccessible Business all fail identically as 404.
     */
    private function resolveAccessibleBusiness(string $workspaceUid, string $businessUid): Business
    {
        $workspace = $this->workspaceRepository->findByUid($workspaceUid);

        if ($workspace === null) {
            abort(404);
        }

        $business = $this->workspaceRepository->businessesForWorkspace($workspace)->firstWhere('uid', $businessUid);

        if ($business === null || ! $this->workspaceManager->userCanAccessBusiness((int) Auth::id(), $business)) {
            abort(404);
        }

        return $business;
    }

    private function resolveOwnedCampaign(Campaigns $campaign, Business $business): Campaigns
    {
        abort_unless($campaign->business_id === $business->id, 404);

        return $campaign;
    }

    private function campaignsResult(string $workspaceUid, string $businessUid, $data): RedirectResponse
    {
        if (isset($data->getData()->status) && $data->getData()->status == 'success') {
            return redirect()->route('customer.workspaces.businesses.outreach.campaigns', [$workspaceUid, $businessUid])->with([
                'status'  => 'success',
                'message' => $data->getData()->message,
            ]);
        }

        return $this->campaignsError($workspaceUid, $businessUid, $data->getData()->message ?? __('locale.exceptions.something_went_wrong'));
    }

    private function campaignsError(string $workspaceUid, string $businessUid, string $message): RedirectResponse
    {
        return redirect()->route('customer.workspaces.businesses.outreach.campaigns', [$workspaceUid, $businessUid])->with([
            'status'  => 'error',
            'message' => $message,
        ]);
    }

    private function outreachError(string $workspaceUid, string $businessUid, string $message): RedirectResponse
    {
        return redirect()->route('customer.workspaces.businesses.outreach.index', [$workspaceUid, $businessUid])->with([
            'status'  => 'error',
            'message' => $message,
        ]);
    }

    private function getRecipients(Request $request)
    {
        $delimiter  = $request->input('delimiter');
        $recipients = $request->input('recipients');

        $recipientsArray = match ($delimiter) {
            ',', ';', '|' => collect(explode($delimiter, $recipients)),
            'tab' => collect(explode(' ', $recipients)),
            'new_line' => collect(explode("\n", $recipients)),
            default => collect([$recipients]),
        };

        return $recipientsArray->map(function ($item) {
            return trim($item);
        })->filter(function ($item) {
            return ! empty($item);
        })->unique();
    }

    private function getPhoneNumber($recipient, $countryCodeInput)
    {
        try {
            $countryCode = null;
            if ($countryCodeInput != 0) {
                $country     = Country::find($countryCodeInput);
                $countryCode = $country?->country_code;
            }

            $phoneUtil         = PhoneNumberUtil::getInstance();
            $phoneNumberObject = $phoneUtil->parse('+' . $countryCode . $recipient);
            $regionCode        = $phoneUtil->getRegionCodeForNumber($phoneNumberObject);
            $countryCode       = $phoneNumberObject->getCountryCode();

            $nationalNumber = $phoneNumberObject->isItalianLeadingZero()
                ? '0' . $phoneNumberObject->getNationalNumber()
                : $phoneNumberObject->getNationalNumber();

            if (! $phoneUtil->isPossibleNumber($phoneNumberObject) || empty($countryCode) || empty($regionCode)) {
                return __('locale.customer.invalid_phone_number', ['phone' => $countryCode . $nationalNumber]);
            }

            $country   = Country::where('country_code', $countryCode)->where('iso_code', $regionCode)->first();
            $countryId = $country?->id;

            return [
                'country_code' => $countryCode,
                'region_code'  => $regionCode,
                'recipient'    => $nationalNumber,
                'country_id'   => $countryId,
            ];
        } catch (NumberParseException $exception) {
            return $exception->getMessage();
        }
    }
}
