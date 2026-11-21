<?php

namespace App\Http\Controllers\Mobile;

use App\Models\MobileDevice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Push-token registration for the CURRENT device only (the one bound to the presented token). The token is
 * stored server-side to address pushes later; it is never returned. No push is sent in Phase 1.
 */
class MobileDeviceController extends MobileController
{
    public function register(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'push_provider' => ['required', 'in:expo,fcm,apns'],
            'push_token' => ['required', 'string', 'min:16', 'max:512'],
            'app_version' => ['sometimes', 'nullable', 'string', 'max:32'],
        ]);

        if ($v->fails()) {
            return $this->fail('validation_failed', $v->errors()->first(), 422);
        }

        $device = $this->current($request);

        if ($device === null) {
            return $this->fail('unauthenticated', 'Sign in again.', 401);
        }

        $token = (string) $request->input('push_token');
        $hash = hash('sha256', $token);

        // A push token belongs to one install: if another account's row held it (shared phone), that row loses it.
        MobileDevice::query()->where('push_token_hash', $hash)->whereKeyNot($device->id)
            ->update(['push_token' => null, 'push_token_hash' => null, 'push_provider' => null]);

        $device->forceFill([
            'push_provider' => $request->input('push_provider'),
            'push_token' => $token,
            'push_token_hash' => $hash,
            'app_version' => $request->input('app_version', $device->app_version),
            'last_seen_at' => now(),
        ])->save();

        return response()->json(['data' => ['device_uid' => $device->uid, 'push_registered' => true]]);
    }

    public function unregister(Request $request): JsonResponse
    {
        $device = $this->current($request);
        $device?->forceFill(['push_provider' => null, 'push_token' => null, 'push_token_hash' => null])->save();

        return response()->json(['data' => ['push_registered' => false]]);
    }

    private function current(Request $request): ?MobileDevice
    {
        return MobileDevice::query()
            ->where('personal_access_token_id', $this->actor($request)->currentAccessToken()->id)
            ->where('user_id', $this->actor($request)->id)->first();
    }
}
