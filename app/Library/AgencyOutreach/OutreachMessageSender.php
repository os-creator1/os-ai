<?php

namespace App\Library\AgencyOutreach;

use App\Library\Messaging\BusinessMessagingIdentityResolver;
use App\Library\Messaging\Exceptions\MessagingCampaignAssignmentNotConfirmedException;
use App\Library\Messaging\Exceptions\MessagingIdentityConflictException;
use App\Library\Messaging\Exceptions\MessagingInsufficientFundsException;
use App\Library\Messaging\Exceptions\MessagingProviderNotConfiguredException;
use App\Library\Messaging\ManagedMessageDispatcher;
use App\Models\Blacklists;
use App\Models\Business;
use App\Models\Campaigns;
use App\Models\Country;
use App\Models\SpamWord;
use App\Models\User;
use App\Repositories\Contracts\CampaignRepository;
use Illuminate\Support\Facades\DB;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberUtil;
use Throwable;

/**
 * Outreach's ONLY way to reach a prospect (contract §6): the canonical send core
 * — CampaignRepository::quickSend() with `business_id` = the Agency's own Business,
 * `managed_operation_key` and `require_managed` — so managed number resolution, the
 * Blacklists check, Conversations history, usage measurement and per-segment wallet
 * billing all happen once, in their own code. There is no provider call, no second
 * sender and no BYO channel here.
 *
 * `require_managed` makes "the Business is not on managed messaging" a refusal
 * (blocked), never a silent fall-through to a legacy gateway.
 *
 * THE INPUT SHAPE is the one Conversations uses for a managed reply
 * (ChatBoxController::buildManualSendInput): the managed dispatcher chooses the number
 * itself, so the legacy originator re-authorisation of checkQuickSendValidation()
 * (written for `phone_numbers` / SenderID rows, which a managed Business does not have)
 * is not applicable and is not called; the one check it adds that does apply — spam
 * words — is kept below.
 *
 * Every refusal is reported as an OutreachSendResult with an exact reason; nothing
 * throws to the caller. The operation key is the idempotency key: sending the same key
 * again returns the recorded outcome without a second provider call.
 */
final class OutreachMessageSender
{
    public function __construct(
        private readonly CampaignRepository $campaigns,
        private readonly BusinessMessagingIdentityResolver $identities,
    ) {
    }

    /**
     * @param  string  $toPhone  the prospect's canonical digits-only number
     */
    public function send(Business $business, string $toPhone, string $body, string $operationKey): OutreachSendResult
    {
        $digits = preg_replace('/\D/', '', $toPhone) ?? '';

        if ($digits === '' || trim($body) === '') {
            return OutreachSendResult::failed('invalid_message');
        }

        if (Blacklists::query()->where('business_id', (int) $business->id)->where('number', $digits)->exists()) {
            return OutreachSendResult::blocked('opted_out');
        }

        $owner = User::query()->find($business->customer_id);

        if ($owner === null || $owner->customer === null) {
            return OutreachSendResult::blocked('business_owner_missing');
        }

        $phone = $this->parse($digits);

        if (is_string($phone)) {
            return OutreachSendResult::failed($phone);
        }

        if ($owner->customer->getOption('send_spam_message') == 'no'
            && SpamWord::whereRaw("LOWER(?) LIKE CONCAT('%', LOWER(word), '%')", [$body])->exists()) {
            return OutreachSendResult::blocked('spam_words');
        }

        try {
            $identity = $this->identities->resolveForBusiness($business);

            if ($identity === null) {
                return OutreachSendResult::blocked('messaging_not_ready');
            }

            $senderNumber = $this->identities->resolvePrimaryNumber($identity)->phone_number;

            $campaign = new Campaigns();
            $campaign->business_id = (int) $business->id;

            $response = $this->campaigns->quickSend($campaign, [
                'sender_id' => $senderNumber,
                'originator' => 'phone_number',
                'sms_type' => 'plain',
                'message' => $body,
                'exist_c_code' => 'yes',
                'user' => $owner,
                'user_id' => $owner->id,
                'business_id' => (int) $business->id,
                'country_code' => $phone['country_code'],
                'recipient' => $phone['recipient'],
                'region_code' => $phone['region_code'],
                'managed_operation_key' => $operationKey,
                'require_managed' => true,
            ])->getData();
        } catch (MessagingInsufficientFundsException) {
            return OutreachSendResult::paused('insufficient_balance');
        } catch (MessagingIdentityConflictException) {
            return OutreachSendResult::blocked('messaging_not_ready');
        } catch (MessagingCampaignAssignmentNotConfirmedException) {
            return OutreachSendResult::blocked('campaign_assignment_not_confirmed');
        } catch (MessagingProviderNotConfiguredException) {
            return OutreachSendResult::blocked('provider_not_configured');
        } catch (Throwable) {
            return OutreachSendResult::failed('send_exception');
        }

        $status = $response->status ?? 'error';

        if (in_array($status, ['success', 'info'], true)) {
            return OutreachSendResult::sent($this->providerMessageId($business, $operationKey));
        }

        $message = (string) ($response->message ?? '');

        if (stripos($message, 'blacklist') !== false) {
            return OutreachSendResult::blocked('opted_out');
        }

        $category = $response->error_category ?? null;

        return OutreachSendResult::failed(is_string($category) && $category !== '' ? 'provider_rejected:' . $category : 'send_rejected');
    }

    private function providerMessageId(Business $business, string $operationKey): ?string
    {
        $id = DB::table(ManagedMessageDispatcher::TABLE)
            ->where('business_id', (int) $business->id)
            ->where('operation_key', $operationKey)
            ->where('direction', 'outbound')
            ->value('provider_message_id');

        return $id === null ? null : (string) $id;
    }

    /**
     * @return array{country_code: int, region_code: string, recipient: string}|string the parts, or the failure reason
     */
    private function parse(string $digits): array|string
    {
        try {
            $util = PhoneNumberUtil::getInstance();
            $parsed = $util->parse('+' . $digits);
            $region = $util->getRegionCodeForNumber($parsed);
            $country = $parsed->getCountryCode();

            if (! $util->isPossibleNumber($parsed) || empty($country) || empty($region)) {
                return 'invalid_phone_number';
            }

            $national = $parsed->isItalianLeadingZero()
                ? '0' . $parsed->getNationalNumber()
                : (string) $parsed->getNationalNumber();

            if (! Country::query()->where('country_code', $country)->where('iso_code', $region)->exists()) {
                // quickSend() would refuse it with the same words; failing here names the cause.
                return 'country_not_enabled';
            }

            return ['country_code' => $country, 'region_code' => $region, 'recipient' => $national];
        } catch (NumberParseException) {
            return 'invalid_phone_number';
        }
    }
}
