<?php

namespace App\Listeners\Mobile;

use App\Events\Conversation\OutboundDeliveryStateChanged;
use App\Events\Mobile\MobileConversationChanged;
use App\Library\Mobile\MobileConversationNotifier;
use App\Models\ChatBox;
use App\Models\ChatBoxMessage;
use Illuminate\Contracts\Queue\ShouldQueue;

/** Tells eligible phones that a message's delivery state changed. Never pushes (status is not "new message"). */
class NotifyMobileOfOutboundStatus implements ShouldQueue
{
    public function handle(OutboundDeliveryStateChanged $event): void
    {
        $message = ChatBoxMessage::query()->where('business_messaging_operation_id', $event->operationId)->first();
        $box = $message === null ? null : ChatBox::query()->find($message->box_id);

        if ($box !== null) {
            app(MobileConversationNotifier::class)->changed($box, MobileConversationChanged::STATUS);
        }
    }
}
