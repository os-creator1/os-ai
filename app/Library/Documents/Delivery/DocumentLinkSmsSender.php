<?php

namespace App\Library\Documents\Delivery;

use App\Library\Messaging\BusinessSmsSendingPath;
use App\Models\Business;
use App\Models\BusinessDocument;
use App\Models\Campaigns;
use App\Models\Contacts;
use App\Models\User;
use App\Repositories\Contracts\CampaignRepository;
use Throwable;

/**
 * Contract 17B §7 — the document secure link, delivered by text message.
 *
 * THE SEND PATH IS NOT NEW. Exactly the door SendSmsNodeExecutor uses:
 * CampaignRepository::checkQuickSendValidation() then quickSend(), with
 * `business_id` on the payload and on a transient Campaigns instance, and the
 * sender / transport / phone resolved by the shared BusinessSmsSendingPath. No
 * provider, adapter, wallet or `sms_unit` arithmetic exists here; whatever the
 * core does for managed delegation, BYO dispatch, measurement and accounting
 * happens once, in its own code.
 *
 * CONSENT at the action boundary: the document's Contact must be subscribed
 * (a contact who replied STOP is never texted). The number is the document's
 * own FROZEN `recipient_phone_snapshot`, never the live Contact phone.
 *
 * Failure is a reason CODE, never a thrown message: nothing here can carry the
 * link into a log.
 */
class DocumentLinkSmsSender
{
    public const MAX_CUSTOM_MESSAGE = 320;

    public const REASON_NO_CONTACT = 'contact_missing';
    public const REASON_UNSUBSCRIBED = 'contact_unsubscribed';
    public const REASON_NO_PHONE = 'phone_missing';
    public const REASON_PHONE_INVALID = 'phone_invalid';
    public const REASON_NO_PATH = 'no_business_sending_path';
    public const REASON_REJECTED = 'sender_rejected';
    public const REASON_FAILED = 'send_failed';

    public function __construct(
        private readonly CampaignRepository $campaigns,
        private readonly BusinessSmsSendingPath $path,
    ) {
    }

    /**
     * Whether a text could be sent right now. Null = sendable, else a reason
     * code. Creates nothing and calls no provider.
     */
    public function preflight(BusinessDocument $document): ?string
    {
        $prepared = $this->prepare($document);

        return is_string($prepared) ? $prepared : null;
    }

    /**
     * Send the link. Null on success, else a reason code.
     *
     * @param  string  $message  the full text body (already containing the link)
     */
    public function deliver(BusinessDocument $document, string $message): ?string
    {
        $prepared = $this->prepare($document);

        if (is_string($prepared)) {
            return $prepared;
        }

        ['business' => $business, 'path' => $path, 'phone' => $phone] = $prepared;

        $sendData = [
            'business_id' => (int) $business->id,
            'user_id' => (int) $business->customer_id,
            'message' => $message,
            'sms_type' => 'plain',
            'originator' => 'sender_id',
            'sender_id' => $path['originator'],
        ];

        // A managed Business has no legacy gateway; only a BYO send carries one.
        if ($path['sending_server'] !== null) {
            $sendData['sending_server'] = $path['sending_server'];
        }

        try {
            $validation = $this->campaigns->checkQuickSendValidation($sendData)->getData();

            if (($validation->status ?? 'error') !== 'success') {
                return self::REASON_REJECTED;
            }

            $sendData['sender_id'] = $validation->sender_id;
            $sendData['sms_type'] = $validation->sms_type;
            $sendData['user'] = User::query()->find($validation->user_id);
            $sendData['country_code'] = $phone['country_code'];
            $sendData['recipient'] = $phone['recipient'];
            $sendData['region_code'] = $phone['region_code'];

            $campaign = new Campaigns();
            $campaign->business_id = (int) $business->id;

            $response = $this->campaigns->quickSend($campaign, $sendData)->getData();
        } catch (Throwable) {
            // Outcome unknown: a failure, never a retry (the job runs once).
            return self::REASON_FAILED;
        }

        return in_array($response->status ?? 'error', ['success', 'info'], true) ? null : self::REASON_FAILED;
    }

    /**
     * The text body: the sender's own words (or the safe default) and ALWAYS the
     * real link, appended server-side. Nothing in a custom message is trusted to
     * carry the link.
     */
    public function compose(BusinessDocument $document, string $url, ?string $custom): string
    {
        $custom = $custom === null ? '' : trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $custom) ?? '');

        if ($custom !== '') {
            return mb_substr($custom, 0, self::MAX_CUSTOM_MESSAGE) . ' ' . $url;
        }

        return $this->defaultPrefix($document) . ' ' . $url;
    }

    public function defaultPrefix(BusinessDocument $document): string
    {
        $business = Business::query()->find($document->business_id);
        $name = (string) ($business?->name ?? config('app.name'));
        $action = $document->requires_signature ? 'Review and sign:' : 'Review:';

        return sprintf('%s sent you "%s". %s', $name, (string) $document->title, $action);
    }

    /**
     * @return array{business: Business, path: array{originator: string, sending_server: int|null}, phone: array{country_code: int, region_code: string, recipient: string}}|string
     */
    private function prepare(BusinessDocument $document): array|string
    {
        $business = Business::query()->find($document->business_id);
        $contact = $document->contact_id === null ? null : Contacts::query()
            ->whereKey($document->contact_id)->where('business_id', $document->business_id)->first();

        if ($business === null || $contact === null) {
            return self::REASON_NO_CONTACT;
        }

        if ($contact->status !== Contacts::STATUS_SUBSCRIBE) {
            return self::REASON_UNSUBSCRIBED;
        }

        $number = trim((string) $document->recipient_phone_snapshot);

        if ($number === '') {
            return self::REASON_NO_PHONE;
        }

        $phone = $this->path->parsePhone($number);

        if ($phone === null) {
            return self::REASON_PHONE_INVALID;
        }

        $path = $this->path->resolve($business);

        if ($path === null) {
            return self::REASON_NO_PATH;
        }

        return ['business' => $business, 'path' => $path, 'phone' => $phone];
    }
}
