<?php

namespace App\Events;

use App\Models\ChatBox;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * An inbound message arrived in a conversation.
 *
 * Customer Experience Redesign Slice 2B — this used to broadcast on ONE
 * private channel, `chat`, that every authenticated user could join, so any
 * customer listening received every other customer's inbound traffic. It now
 * broadcasts only on the private channel of the Business that owns the
 * conversation (`chat.business.{businessUid}`, authorized in
 * routes/channels.php), and a conversation with no Business — legacy data
 * whose Business was never proven — broadcasts nowhere rather than guessing.
 */
class MessageReceived implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $message;

    public $user;

    public $data;

    /**
     * Create a new event instance.
     *
     * @return void
     */
    public function __construct($user, $message, $data)
    {
        $this->user = $user;
        $this->message = $message;
        $this->data = $data;
    }

    /**
     * The one channel of the conversation's own Business.
     *
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        $businessUid = $this->businessUid();

        return $businessUid === null ? [] : [new PrivateChannel(self::channelFor($businessUid))];
    }

    /**
     * A NULL-business conversation is never broadcast: there is no Business
     * whose listeners are entitled to it, and none is guessed.
     */
    public function broadcastWhen(): bool
    {
        return $this->businessUid() !== null;
    }

    /**
     * Only what the listening page needs to refresh the conversation: its
     * public uid and row id. The page then reads the message itself through
     * the Business-scoped notification route, which authorizes it again.
     *
     * Deliberately NOT the default of every public property. That would put
     * the Business owner's whole User record on the wire — `api_token` and
     * `two_factor_code` are not in User::$hidden — for every staff member
     * listening, along with the message text and the full conversation row.
     *
     * @return array<string, array<string, mixed>>
     */
    public function broadcastWith(): array
    {
        return [
            'data' => [
                'id' => $this->data?->id,
                'uid' => $this->data?->uid,
            ],
        ];
    }

    /**
     * The channel name, without Laravel's `private-` prefix — the form both
     * routes/channels.php and the Conversations page use.
     */
    public static function channelFor(string $businessUid): string
    {
        return 'chat.business.' . $businessUid;
    }

    private function businessUid(): ?string
    {
        if (! $this->data instanceof ChatBox || $this->data->business_id === null) {
            return null;
        }

        return $this->data->business?->uid;
    }
}
