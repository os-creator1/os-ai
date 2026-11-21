<?php

namespace App\Http\Controllers\Mobile;

use App\Models\MobileDevice;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Token login for the phone. The credential check mirrors the web login (email + password, account must be
 * enabled). Accounts with two-factor enabled are REFUSED for now (`two_factor_unsupported`) rather than skipped:
 * a mobile 2FA challenge is a Phase 2 item, and bypassing a second factor is never acceptable.
 */
class MobileAuthController extends MobileController
{
    public const TOKEN_DAYS = 30;

    public function login(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'email' => ['required', 'string', 'email', 'max:190'],
            'password' => ['required', 'string', 'max:200'],
            'device' => ['required', 'array'],
            'device.installation_id' => ['required', 'string', 'min:8', 'max:64', 'regex:/^[A-Za-z0-9_\-:.]+$/'],
            'device.platform' => ['required', 'in:ios,android'],
            'device.name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'device.app_version' => ['sometimes', 'nullable', 'string', 'max:32'],
        ]);

        if ($v->fails()) {
            return $this->fail('validation_failed', $v->errors()->first(), 422);
        }

        $key = 'mobile-login:' . sha1(Str::lower((string) $request->input('email')) . '|' . $request->ip());

        if (RateLimiter::tooManyAttempts($key, 5)) {
            return $this->fail('too_many_attempts', 'Too many attempts. Try again shortly.', 429)
                ->header('Retry-After', (string) RateLimiter::availableIn($key));
        }

        /** @var User|null $user */
        $user = User::query()->where('email', (string) $request->input('email'))->first();
        $valid = $user !== null && Hash::check((string) $request->input('password'), (string) $user->password);

        if (! $valid || ! $user->status) {
            RateLimiter::hit($key, 60);

            // The same answer for an unknown email, a wrong password and a disabled account.
            return $this->fail('invalid_credentials', 'These credentials do not match.', 401);
        }

        if ($user->two_factor) {
            return $this->fail('two_factor_unsupported', 'Accounts using two-factor sign-in cannot use the mobile app yet.', 403);
        }

        RateLimiter::clear($key);

        return response()->json($this->issue($user, (array) $request->input('device')), 200)->header('Cache-Control', 'no-store');
    }

    /** Rotates the current token: the old one stops working, a fresh 30-day one is returned. */
    public function refresh(Request $request): JsonResponse
    {
        $user = $this->actor($request);
        $device = MobileDevice::query()->where('personal_access_token_id', $user->currentAccessToken()->id)->first();

        if ($device === null) {
            return $this->fail('unauthenticated', 'Sign in again.', 401);
        }

        $payload = $this->issue($user, [
            'installation_id' => $device->installation_id, 'platform' => $device->platform,
            'name' => $device->device_name, 'app_version' => $device->app_version,
        ], $device);

        return response()->json($payload)->header('Cache-Control', 'no-store');
    }

    public function logout(Request $request): JsonResponse
    {
        $token = $this->actor($request)->currentAccessToken();

        DB::transaction(function () use ($token): void {
            MobileDevice::query()->where('personal_access_token_id', $token->id)->delete();
            $token->delete();
        });

        return response()->json(['data' => ['signed_out' => true]]);
    }

    /** @param array<string, mixed> $device */
    private function issue(User $user, array $device, ?MobileDevice $replacing = null): array
    {
        return DB::transaction(function () use ($user, $device, $replacing): array {
            // One live token per installation: signing in again (or refreshing) retires the previous one.
            $stale = MobileDevice::query()->where('user_id', $user->id)->where('installation_id', $device['installation_id'])->get();
            $carry = $replacing ?? $stale->first();

            foreach ($stale as $old) {
                $user->tokens()->whereKey($old->personal_access_token_id)->delete();
                $old->delete();
            }

            $expires = now()->addDays(self::TOKEN_DAYS);
            $new = $user->createToken('mobile:' . $device['installation_id'], ['mobile'], $expires);

            MobileDevice::query()->create([
                'uid' => (string) Str::uuid(),
                'user_id' => $user->id,
                'personal_access_token_id' => $new->accessToken->id,
                'installation_id' => $device['installation_id'],
                'platform' => $device['platform'],
                'device_name' => $device['name'] ?? null,
                'app_version' => $device['app_version'] ?? null,
                // A refresh keeps the already-registered push token so notifications do not lapse.
                'push_provider' => $carry?->push_provider,
                'push_token' => $carry?->push_token,
                'push_token_hash' => $carry?->push_token_hash,
                'last_seen_at' => now(),
            ]);

            return ['data' => [
                'token_type' => 'Bearer',
                'token' => $new->plainTextToken,
                'expires_at' => $expires->toIso8601String(),
                'user' => ['uid' => $user->uid, 'name' => trim($user->first_name . ' ' . $user->last_name), 'email' => $user->email],
            ]];
        });
    }
}
