<?php

namespace App\Http\Controllers\Customer;

use App\Http\Requests\Campaigns\CampaignBuilderRequest;
use App\Http\Requests\Campaigns\MMSCampaignBuilderRequest;
use App\Http\Requests\Campaigns\MMSQuickSendRequest;
use App\Http\Requests\Campaigns\QuickSendRequest;
use App\Library\Tool;
use App\Models\Campaigns;
use App\Models\ContactGroups;
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
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberUtil;

/**
 * B1 — Outreach / Compose. A thin orchestration layer over the existing
 * Campaign send/persistence core (App\Repositories\Contracts\CampaignRepository).
 * It reuses EloquentCampaignRepository::quickSend()/campaignBuilder() unchanged,
 * and never calls quickSend() with a 3rd argument (RFC-005 conversationContext
 * stays at its default false here, matching legacy Quick Send/Campaign Builder).
 */
class OutreachController extends CustomerBaseController
{
    protected CampaignRepository $campaigns;

    public function __construct(CampaignRepository $campaigns)
    {
        $this->campaigns = $campaigns;
    }

    public function index(): View|Factory|Application|RedirectResponse
    {
        $user = Auth::user();

        $canSmsQuickSend       = $user->can('sms_quick_send');
        $canSmsCampaignBuilder = $user->can('sms_campaign_builder');
        $canMmsQuickSend       = $user->can('mms_quick_send');
        $canMmsCampaignBuilder = $user->can('mms_campaign_builder');

        $canSms = $canSmsQuickSend || $canSmsCampaignBuilder;
        $canMms = $canMmsQuickSend || $canMmsCampaignBuilder;

        if (! $canSms && ! $canMms) {
            throw new AuthorizationException();
        }

        $activeSubscription = $user->customer->activeSubscription();
        if (! $activeSubscription) {
            return redirect()->route('customer.subscriptions.index')->with([
                'status'  => 'error',
                'message' => __('locale.customer.no_active_subscription'),
            ]);
        }

        $plan_id = $activeSubscription->plan_id;
        $plan    = Plan::where('status', true)->find($plan_id);

        $coverage = CustomerBasedPricingPlan::where('plan_id', $plan_id)
            ->where('status', true)
            ->where('user_id', $user->id)
            ->get();

        if ($coverage->count() < 1) {
            $coverage = PlansCoverageCountries::where('plan_id', $plan_id)->where('status', true)->get();
        }

        $sender_ids = Senderid::where('user_id', $user->id)->where('status', 'active')->get();

        $phoneNumbers = PhoneNumbers::where('user_id', $user->id)->where('status', 'assigned')->get();

        $smsPhoneNumbers = $phoneNumbers->filter(function ($number) {
            $caps = json_decode($number->capabilities, true);

            return is_array($caps) && in_array('sms', $caps);
        });

        $mmsPhoneNumbers = $phoneNumbers->filter(function ($number) {
            $caps = json_decode($number->capabilities, true);

            return is_array($caps) && in_array('mms', $caps);
        });

        $templates      = Templates::where('user_id', $user->id)->where('status', 1)->get();
        $sendingServers = CustomerBasedSendingServer::where('user_id', $user->id)->where('status', 1)->get();
        $contact_groups = ContactGroups::where('status', 1)->where('customer_id', $user->id)->get();

        return view('customer.Outreach.index', [
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

    public function sendSms(Campaigns $campaign, QuickSendRequest $request): RedirectResponse
    {
        if (config('app.stage') === 'demo') {
            return $this->outreachError('Sorry! This option is not available in demo mode');
        }

        $user               = Auth::user();
        $customer           = $user->customer;
        $activeSubscription = $customer->activeSubscription();

        if (! $activeSubscription) {
            return $this->outreachError(__('locale.customer.no_active_subscription'));
        }

        $plan = Plan::where('status', true)->find($activeSubscription->plan_id);
        if (! $plan) {
            return $this->outreachError(__('locale.customer.no_active_subscription'));
        }

        if (config('app.trai_dlt') && $plan->is_dlt) {
            if (empty($request->input('dlt_template_id'))) {
                return $this->outreachError('DLT Template ID is required.');
            }

            if (empty($user->dlt_entity_id)) {
                return $this->outreachError('The DLT Entity ID is mandatory. Kindly reach out to the system administrator for further assistance.');
            }
        }

        $recipients = $this->getRecipients($request);

        if ($recipients->isEmpty()) {
            return $this->outreachError(__('locale.campaigns.at_least_one_number'));
        }

        if ($recipients->count() > 100) {
            return $this->outreachError(__('locale.campaigns.too_many_numbers'));
        }

        $sendingServersExist = CustomerBasedSendingServer::where('user_id', $user->id)->where('status', 1)->exists();
        if ($sendingServersExist && ! $request->has('sending_server')) {
            return $this->outreachError('Please select your sending server.');
        }

        $sendData            = $request->except('_token', 'recipients', 'delimiter');
        $sendData['message'] = str_replace("\r", '', $sendData['message']);

        $validateData = $this->campaigns->checkQuickSendValidation($sendData);
        $responseData = $validateData->getData();

        if ($responseData->status === 'error') {
            return $this->outreachError($responseData->message);
        }

        $sendData['sender_id'] = $responseData->sender_id;
        $sendData['sms_type']  = $responseData->sms_type;
        $sendData['user']      = User::find($responseData->user_id);

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
            return redirect()->route('customer.outreach.index')->with([
                'status'  => 'warning',
                'message' => implode('<br>', $errors),
            ]);
        }

        return redirect()->route('customer.outreach.campaigns')->with([
            'status'  => 'success',
            'message' => __('locale.campaigns.message_successfully_delivered'),
        ]);
    }

    public function sendMms(Campaigns $campaign, MMSQuickSendRequest $request): RedirectResponse
    {
        if (config('app.stage') === 'demo') {
            return $this->outreachError('Sorry, this feature is disabled in demo version.');
        }

        $user               = Auth::user();
        $customer           = $user->customer;
        $activeSubscription = $customer->activeSubscription();

        if (! $activeSubscription) {
            return $this->outreachError(__('locale.customer.no_active_subscription'));
        }

        $plan = Plan::where('status', true)->find($activeSubscription->plan_id);
        if (! $plan) {
            return $this->outreachError(__('locale.customer.no_active_subscription'));
        }

        $recipients = $this->getRecipients($request);
        if ($recipients->isEmpty()) {
            return $this->outreachError('No recipients found.');
        }

        if ($recipients->count() > 100) {
            return $this->outreachError(__('locale.campaigns.too_many_numbers'));
        }

        if (! $request->hasFile('mms_file')) {
            return $this->outreachError('MMS media file is required.');
        }

        $sendData              = $request->except('_token', 'recipients', 'delimiter');
        $sendData['media_url'] = Tool::uploadImage($request->file('mms_file'));

        $sendingServersExist = CustomerBasedSendingServer::where('user_id', $user->id)->where('status', 1)->exists();

        if ($sendingServersExist && ! $request->has('sending_server')) {
            return $this->outreachError('No sending server selected.');
        }

        $validateData = $this->campaigns->checkQuickSendValidation($sendData);
        $validated    = $validateData->getData();

        if ($validated->status === 'error') {
            return $this->outreachError($validated->message);
        }

        $sendData['sender_id'] = $validated->sender_id;
        $sendData['sms_type']  = 'mms';
        $sendData['user']      = User::find($validated->user_id);

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
            return $this->outreachError(implode(' ', $errors));
        }

        return redirect()->route('customer.outreach.campaigns')->with([
            'status'  => 'info',
            'message' => implode(' ', $success),
        ]);
    }

    public function storeSmsCampaign(Campaigns $campaign, CampaignBuilderRequest $request): RedirectResponse
    {
        if (config('app.stage') == 'demo') {
            return $this->outreachError('Sorry! This option is not available in demo mode');
        }

        $customer           = Auth::user()->customer;
        $activeSubscription = $customer->activeSubscription();

        if (! $activeSubscription) {
            return redirect()->route('customer.subscriptions.index')->with([
                'status'  => 'error',
                'message' => __('locale.customer.no_active_subscription'),
            ]);
        }

        $plan = Plan::where('status', true)->find($activeSubscription->plan_id);

        if (! $plan) {
            return $this->outreachError('Purchased plan is not active. Please contact support team.');
        }

        if (config('app.trai_dlt') && $plan->is_dlt && $request->input('dlt_template_id') == null) {
            return $this->outreachError('DLT Template id is required');
        }

        if (config('app.trai_dlt') && $activeSubscription->plan->is_dlt && Auth::user()->dlt_entity_id == null) {
            return $this->outreachError('The DLT Entity ID is mandatory. Kindly reach out to the system administrator for further assistance');
        }

        $data = $this->campaigns->campaignBuilder($campaign, $request->except('_token'));

        if (isset($data->getData()->status) && $data->getData()->status == 'success') {
            return redirect()->route('customer.outreach.campaigns')->with([
                'status'  => 'success',
                'message' => $data->getData()->message,
            ]);
        }

        return $this->outreachError($data->getData()->message ?? __('locale.exceptions.something_went_wrong'));
    }

    public function storeMmsCampaign(Campaigns $campaign, MMSCampaignBuilderRequest $request): RedirectResponse
    {
        if (config('app.stage') == 'demo') {
            return $this->outreachError('Sorry! This option is not available in demo mode');
        }

        $customer           = Auth::user()->customer;
        $activeSubscription = $customer->activeSubscription();

        if (! $activeSubscription) {
            return redirect()->route('customer.subscriptions.index')->with([
                'status'  => 'error',
                'message' => __('locale.customer.no_active_subscription'),
            ]);
        }

        $plan = Plan::where('status', true)->find($activeSubscription->plan_id);

        if (! $plan) {
            return $this->outreachError('Purchased plan is not active. Please contact support team.');
        }

        $campaignData             = $request->all();
        $campaignData['sms_type'] = 'mms';

        $data = $this->campaigns->campaignBuilder($campaign, $campaignData);

        if (isset($data->getData()->status) && $data->getData()->status == 'success') {
            return redirect()->route('customer.outreach.campaigns')->with([
                'status'  => 'success',
                'message' => $data->getData()->message,
            ]);
        }

        return $this->outreachError($data->getData()->message ?? __('locale.exceptions.something_went_wrong'));
    }

    public function campaigns(Request $request): View|Factory|Application
    {
        $user = Auth::user();
        if (! $user->can('sms_campaign_builder') && ! $user->can('mms_campaign_builder') && ! $user->can('sms_quick_send') && ! $user->can('mms_quick_send')) {
            throw new AuthorizationException();
        }

        $campaignList = Campaigns::where('user_id', $user->id)
            ->whereIn('sms_type', ['plain', 'unicode', 'mms'])
            ->orderByDesc('id')
            ->paginate(20);

        return view('customer.Outreach.campaigns', [
            'campaigns' => $campaignList,
        ]);
    }

    public function show(Campaigns $campaign): View|Factory|Application
    {
        $this->resolveOwnedCampaign($campaign);

        return view('customer.Outreach.show', [
            'campaign' => $campaign,
        ]);
    }

    public function pause(Campaigns $campaign): RedirectResponse
    {
        $this->resolveOwnedCampaign($campaign);

        if (config('app.stage') == 'demo') {
            return $this->campaignsError('Sorry! This option is not available in demo mode');
        }

        $data = $this->campaigns->pause($campaign);

        return $this->campaignsResult($data);
    }

    public function restart(Campaigns $campaign): RedirectResponse
    {
        $this->resolveOwnedCampaign($campaign);

        if (config('app.stage') == 'demo') {
            return $this->campaignsError('Sorry! This option is not available in demo mode');
        }

        $data = $this->campaigns->restart($campaign);

        return $this->campaignsResult($data);
    }

    public function resend(Campaigns $campaign): RedirectResponse
    {
        $this->resolveOwnedCampaign($campaign);

        if (config('app.stage') == 'demo') {
            return $this->campaignsError('Sorry! This option is not available in demo mode');
        }

        $data = $this->campaigns->resend($campaign);

        return $this->campaignsResult($data);
    }

    public function destroy(Campaigns $campaign): RedirectResponse
    {
        $this->resolveOwnedCampaign($campaign);

        if (config('app.stage') == 'demo') {
            return $this->campaignsError('Sorry! This option is not available in demo mode');
        }

        if (! $campaign->delete()) {
            return $this->campaignsError(__('locale.exceptions.something_went_wrong'));
        }

        return redirect()->route('customer.outreach.campaigns')->with([
            'status'  => 'success',
            'message' => __('locale.campaigns.campaign_was_successfully_deleted'),
        ]);
    }

    private function resolveOwnedCampaign(Campaigns $campaign): Campaigns
    {
        abort_unless($campaign->user_id === Auth::id(), 404);

        return $campaign;
    }

    private function campaignsResult($data): RedirectResponse
    {
        if (isset($data->getData()->status) && $data->getData()->status == 'success') {
            return redirect()->route('customer.outreach.campaigns')->with([
                'status'  => 'success',
                'message' => $data->getData()->message,
            ]);
        }

        return $this->campaignsError($data->getData()->message ?? __('locale.exceptions.something_went_wrong'));
    }

    private function campaignsError(string $message): RedirectResponse
    {
        return redirect()->route('customer.outreach.campaigns')->with([
            'status'  => 'error',
            'message' => $message,
        ]);
    }

    private function outreachError(string $message): RedirectResponse
    {
        return redirect()->route('customer.outreach.index')->with([
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
