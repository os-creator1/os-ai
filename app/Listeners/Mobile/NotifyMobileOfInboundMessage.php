<?php

namespace App\Listeners\Mobile;

use App\Events\Conversation\InboundMessageReceived;
use App\Events\Mobile\MobileConversationChanged;
use App\Jobs\Mobile\SendMobilePush;
use App\Library\Mobile\MobileConversationNotifier;
use App\Models\ChatBox;
use App\Models\ChatBoxMessage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

/**
 * Phone live-update + push for a new inbound SMS. Hangs off the existing, authoritative InboundMessageReceived domain event
 * (emitted once per stored message by BOTH the managed and legacy paths, after commit), so it inherits the platform's own
 * attribution and webhook de-duplication; this is not a second inbound pipeline. Queued: nothing here delays the webhook.
 *
 * The CONVERSATION is the exact one the emitting path filed the message under (carried on the event), verified to belong to the
 * event's Business — never "the latest thread with that phone number", which is wrong for a Business with several numbers.
 * The live event goes out here; each user's push is its own queued, retryable SendMobilePush job keyed by the event's
 * occurrenceKey (the exactly-once key).
 */
class NotifyMobileOfInboundMessage implements ShouldQueue
{
    public function handle(InboundMessageReceived $event): void
    {
        if ($event->conversationBoxId === null) {
            return;
        }

        $box = ChatBox::query()->where('id', $event->conversationBoxId)->where('business_id', $event->businessId)->first();

        if ($box === null) {
            return;
        }

        $users = app(MobileConversationNotifier::class)->changed($box, MobileConversationChanged::RECEIVED);

        // Only a message that just arrived is "new": an import or backfill of old history must not ring phones.
        $latest = ChatBoxMessage::query()->where('box_id', $box->id)->whereIn('direction', ['incoming', 'inbound'])->latest('id')->first();
        $fresh = $latest !== null && $latest->created_at !== null
            && $latest->created_at->gte(now()->subSeconds((int) config('mobile.fresh_message_seconds', 600)));

        if (! $fresh) {
            return;
        }

        foreach ($users as $user) {
            try {
                SendMobilePush::dispatch((int) $user->id, (int) $box->id, $event->occurrenceKey);
            } catch (\Throwable $e) {
                // Never let a push problem surface in the inbound pipeline (synchronous queue drivers run the job inline).
                Log::warning('mobile_push_failed', ['exception' => $e::class]);
            }
        }
    }
}
