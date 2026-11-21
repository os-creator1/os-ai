<?php

namespace App\Library\Mobile\Push;

use App\Models\MobileDevice;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Delivers ONE privacy-safe "new message" push to a user's devices.
 *
 * PRIVACY: the payload is fixed, generic text plus two opaque uids used only for navigation. No message body, name,
 * number or Business name is ever included; the phone re-fetches through the authorized API after the tap.
 *
 * DUPLICATES: each (device, dedupe_key) is claimed by inserting into mobile_push_deliveries BEFORE the provider call
 * (UNIQUE key). A replay/retry of the same occurrence loses that insert and sends nothing. A transport failure releases
 * the claim so the queue's own retry can try again; a provider answer (even an error) keeps it.
 *
 * TOKEN HYGIENE: malformed tokens and the provider's DeviceNotRegistered are cleared from the device row so they are
 * never used again.
 */
final class MobilePushSender
{
    public const TITLE = 'MotionGrove';
    public const BODY = 'New message received.';

    public function __construct(private readonly MobilePushTransport $transport)
    {
    }

    /**
     * @param  iterable<MobileDevice>  $devices
     * @return int number of pushes handed to the provider
     */
    public function sendNewMessage(iterable $devices, string $dedupeKey, string $businessUid, string $conversationUid): int
    {
        if (! (bool) config('mobile.push.enabled')) {
            return 0;
        }

        $claimed = [];
        $messages = [];

        foreach ($devices as $device) {
            $token = (string) $device->push_token;

            if (! self::validToken($token)) {
                $this->clearToken($device);

                continue;
            }

            try {
                $row = DB::table('mobile_push_deliveries')->insertGetId([
                    'mobile_device_id' => $device->id, 'dedupe_key' => $dedupeKey, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
                ]);
            } catch (UniqueConstraintViolationException) {
                continue; // already sent (or in flight) for this occurrence
            }

            $claimed[] = [$device, $row];
            $messages[] = [
                'to' => $token,
                'title' => self::TITLE,
                'body' => self::BODY,
                'sound' => 'default',
                'priority' => 'high',
                'channelId' => 'default',
                'data' => ['v' => 1, 't' => 'message', 'b' => $businessUid, 'c' => $conversationUid],
            ];
        }

        if ($messages === []) {
            return 0;
        }

        try {
            $tickets = $this->transport->send($messages);
        } catch (\Throwable $e) {
            // Nothing is known to have been accepted: release the claims so a queue retry can send.
            DB::table('mobile_push_deliveries')->whereIn('id', array_column($claimed, 1))->delete();
            Log::warning('mobile_push_transport_failed', ['exception' => $e::class]);

            throw $e;
        }

        foreach ($claimed as $i => [$device, $rowId]) {
            $ticket = $tickets[$i] ?? ['status' => 'error', 'error' => 'missing_ticket'];
            DB::table('mobile_push_deliveries')->where('id', $rowId)->update(['status' => $ticket['status'] === 'ok' ? 'sent' : 'error', 'updated_at' => now()]);

            if (($ticket['error'] ?? null) === 'DeviceNotRegistered') {
                $this->clearToken($device);
            }
        }

        return count($messages);
    }

    public static function validToken(string $token): bool
    {
        return (bool) preg_match('/^(Exponent|Expo)PushToken\[[A-Za-z0-9_\-]{10,}\]$/', $token);
    }

    private function clearToken(MobileDevice $device): void
    {
        $device->forceFill(['push_provider' => null, 'push_token' => null, 'push_token_hash' => null])->save();
    }
}
