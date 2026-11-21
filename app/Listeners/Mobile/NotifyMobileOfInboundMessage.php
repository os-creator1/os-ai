<?php

namespace App\Listeners\Mobile;

use App\Events\Conversation\InboundMessageReceived;
use App\Events\Mobile\MobileConversationChanged;
use App\Library\Mobile\MobileConversationNotifier;
use App\Models\ChatBox;
use App\Models\ChatBoxMessage;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Phone live-update + push for a new inbound SMS. Hangs off the existing, authoritative InboundMessageReceived domain event
 * (emitted once per stored message by BOTH the managed and legacy paths, after commit), so it inherits the platform's own
 * attribution and webhook de-duplication; this is not a second inbound pipeline. Queued: nothing here delays the webhook.
 *
 * The event's occurrenceKey (report:{id} | operation:{id}) is the push dedupe key.
 */
class NotifyMobileOfInboundMessage implements ShouldQueue
{
    public function handle(InboundMessageReceived $event): void
    {
        $digits = (string) preg_replace('/\D+/', '', $event->senderPhone);

        if ($digits === '') {
            return;
        }

        // The conversation THIS Business holds with the sender (business-scoped: never another Business's thread).
        $box = ChatBox::query()->where('business_id', $event->businessId)->where('to', $digits)->orderByDesc('updated_at')->first();

        if ($box === null) {
            return;
        }

        // Only a message that just arrived is "new": an import or backfill of old history must not ring phones.
        $latest = ChatBoxMessage::query()->where('box_id', $box->id)->whereIn('direction', ['incoming', 'inbound'])->latest('id')->first();
        $fresh = $latest !== null && $latest->created_at !== null
            && $latest->created_at->gte(now()->subSeconds((int) config('mobile.fresh_message_seconds', 600)));

        app(MobileConversationNotifier::class)->changed($box, MobileConversationChanged::RECEIVED, $fresh ? $event->occurrenceKey : null);
    }
}
