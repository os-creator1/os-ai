<?php

namespace App\Library\Mobile;

use App\Events\Mobile\MobileConversationChanged;
use App\Library\Mobile\Push\MobilePushSender;
use App\Models\Business;
use App\Models\ChatBox;
use Illuminate\Support\Facades\Log;

/**
 * The single entry point the messaging seams call when a conversation changes. It NEVER throws: a broadcast or push
 * problem must not fail or delay the inbound webhook, the send, or the delivery-status write that triggered it.
 */
final class MobileConversationNotifier
{
    public function __construct(private readonly MobileRecipients $recipients, private readonly MobilePushSender $push)
    {
    }

    /**
     * @param  string|null  $pushDedupeKey  non-null => also push (inbound only). The occurrence key that makes it exactly-once per device.
     */
    public function changed(ChatBox $box, string $type, ?string $pushDedupeKey = null): void
    {
        try {
            $business = Business::query()->find($box->business_id);

            if ($business === null) {
                return;
            }

            foreach ($this->recipients->users($business, $box) as $user) {
                event(new MobileConversationChanged((string) $user->uid, (string) $business->uid, (string) $box->uid, $type));

                if ($pushDedupeKey !== null) {
                    $this->push->sendNewMessage($this->recipients->pushDevicesFor($user), $pushDedupeKey, (string) $business->uid, (string) $box->uid);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('mobile_notify_failed', ['type' => $type, 'exception' => $e::class]);
        }
    }
}
