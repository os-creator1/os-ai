<?php

namespace App\Library\Mobile;

use App\Events\Mobile\MobileConversationChanged;
use App\Models\Business;
use App\Models\ChatBox;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * The single entry point the messaging seams call when a conversation changes. It NEVER throws: a broadcast or push
 * problem must not fail or delay the inbound webhook, the send, or the delivery-status write that triggered it.
 */
final class MobileConversationNotifier
{
    public function __construct(private readonly MobileRecipients $recipients)
    {
    }

    /**
     * Tells every eligible user's phones that the conversation changed (live event). Push is NOT sent from here: it is
     * a separate queued, retryable job (SendMobilePush) so a provider outage can be retried without re-sending events.
     *
     * @return Collection<int, \App\Models\User> the users that were notified (empty on any failure)
     */
    public function changed(ChatBox $box, string $type): Collection
    {
        try {
            $business = Business::query()->find($box->business_id);

            if ($business === null) {
                return collect();
            }

            $users = $this->recipients->users($business, $box);

            foreach ($users as $user) {
                try {
                    event(new MobileConversationChanged((string) $user->uid, (string) $business->uid, (string) $box->uid, $type));
                } catch (\Throwable $e) {
                    // One recipient's broadcast problem must not skip the others.
                    Log::warning('mobile_broadcast_failed', ['type' => $type, 'exception' => $e::class]);
                }
            }

            return $users;
        } catch (\Throwable $e) {
            Log::warning('mobile_notify_failed', ['type' => $type, 'exception' => $e::class]);

            return collect();
        }
    }
}
