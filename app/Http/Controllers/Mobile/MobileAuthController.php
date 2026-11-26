<?php

namespace App\Http\Controllers\Mobile;

use App\Library\Mobile\MobileTwoFactorChallenge;
use App\Models\MobileDevice;
use App\Models\User;
use App\Notifications\MobileTwoFactorCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Throwable;

/**
 * Token login for the phone. The credential check mirrors the web login (email + password, account must be
 * enabled). For an account with two-factor enabled a correct password yields NO token: it yields a short-lived,
 * single-use challenge (MobileTwoFactorChallenge) and the same 6-digit code the web sends by mail/SMS. `auth/two-factor`
 * exchanges challenge + code (or one of the account's single-use backup codes, as on the web) for the normal token.
 */
class MobileAuthController extends MobileController
{
    public const TOKEN_DAYS = 30;

    /** Wrong second-factor guesses per account (across challenges) before verification locks for the decay window. */
    public const TWO_FACTOR_FAILURES = 10;
    public const TWO_FACTOR_DECAY = 900;

    /** New challenges per account per window: each one mails/texts a code, so this also bounds message spam. */
    public const CHALLENGES_PER_WINDOW = 5;

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

        RateLimiter::clear($key);

        if ($user->two_factor) {
            return $this->challenge($user, (array) $request->input('device'));
        }

        return response()->json($this->issue($user, (array) $request->input('device')), 200)->header('Cache-Control', 'no-store');
    }

    /**
     * Second step for a two-factor account: challenge + the emailed/SMS code (method `code`, the default) or one of the
     * account's single-use backup codes (method `backup_code`). Success returns exactly what a plain login returns.
     */
    public function verifyTwoFactor(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'challenge' => ['required', 'string', 'size:64'],
            'code' => ['required', 'string', 'regex:/^\d{6}$/'],
            'method' => ['sometimes', 'in:code,backup_code'],
        ]);

        if ($v->fails()) {
            return $this->fail('validation_failed', $v->errors()->first(), 422);
        }

        $code = (string) $request->input('code');
        $method = (string) $request->input('method', 'code');

        $outcome = MobileTwoFactorChallenge::withLock((string) $request->input('challenge'), function (MobileTwoFactorChallenge $challenge) use ($code, $method): array {
            /** @var User|null $user */
            $user = User::query()->find($challenge->userId());

            if ($user === null || ! $user->status) {
                $challenge->forget();

                return ['dead'];
            }

            $limitKey = 'mobile-2fa-failures:' . $user->id;

            if (RateLimiter::tooManyAttempts($limitKey, self::TWO_FACTOR_FAILURES)) {
                return ['locked', RateLimiter::availableIn($limitKey)];
            }

            $ok = $method === 'backup_code' ? $this->consumeBackupCode($user, $code) : $challenge->matchesCode($code);

            if (! $ok) {
                RateLimiter::hit($limitKey, self::TWO_FACTOR_DECAY);

                return $challenge->recordFailure() > 0 ? ['wrong'] : ['dead'];
            }

            // Single use: the challenge is gone before the token exists.
            $challenge->forget();
            RateLimiter::clear($limitKey);

            return ['ok', $this->issue($user, $challenge->device())];
        });

        return match ($outcome[0] ?? 'dead') {
            'ok' => response()->json($outcome[1], 200)->header('Cache-Control', 'no-store'),
            'wrong' => $this->fail('invalid_two_factor_code', 'That code is not correct.', 422),
            'locked' => $this->fail('too_many_attempts', 'Too many attempts. Try again shortly.', 429)->header('Retry-After', (string) $outcome[1]),
            default => $this->challengeGone(),
        };
    }

    /** Sends a fresh code for a live challenge (the previous code stops working). */
    public function resendTwoFactor(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), ['challenge' => ['required', 'string', 'size:64']]);

        if ($v->fails()) {
            return $this->fail('validation_failed', $v->errors()->first(), 422);
        }

        $outcome = MobileTwoFactorChallenge::withLock((string) $request->input('challenge'), function (MobileTwoFactorChallenge $challenge): array {
            /** @var User|null $user */
            $user = User::query()->find($challenge->userId());

            if ($user === null || ! $user->status) {
                $challenge->forget();

                return ['dead'];
            }

            $code = $challenge->regenerate();

            if ($code === null) {
                return ['exhausted'];
            }

            return $this->deliver($user, $code) ? ['ok', $challenge->expiresAt()] : ['undeliverable'];
        });

        return match ($outcome[0] ?? 'dead') {
            'ok' => response()->json(['data' => ['sent' => true, 'expires_at' => $outcome[1]->toIso8601String()]])->header('Cache-Control', 'no-store'),
            'exhausted' => $this->fail('too_many_attempts', 'No more codes can be sent for this sign-in. Sign in again.', 429),
            'undeliverable' => $this->fail('two_factor_delivery_failed', 'The code could not be sent. Try again later.', 503),
            default => $this->challengeGone(),
        };
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
    private function challenge(User $user, array $device): JsonResponse
    {
        $windowKey = 'mobile-2fa-challenges:' . $user->id;

        if (RateLimiter::tooManyAttempts($windowKey, self::CHALLENGES_PER_WINDOW)) {
            return $this->fail('too_many_attempts', 'Too many attempts. Try again shortly.', 429)
                ->header('Retry-After', (string) RateLimiter::availableIn($windowKey));
        }

        RateLimiter::hit($windowKey, MobileTwoFactorChallenge::TTL_MINUTES * 60);

        [$challenge, $plain, $code] = MobileTwoFactorChallenge::start($user, $device);

        if (! $this->deliver($user, $code)) {
            $challenge->forget();

            return $this->fail('two_factor_delivery_failed', 'The code could not be sent. Try again later.', 503);
        }

        return response()->json(['data' => [
            'two_factor_required' => true,
            'challenge' => $plain,
            'expires_at' => $challenge->expiresAt()->toIso8601String(),
            'methods' => ['code', 'backup_code'],
        ]], 202)->header('Cache-Control', 'no-store');
    }

    private function deliver(User $user, string $code): bool
    {
        try {
            $user->notify(new MobileTwoFactorCode($code));

            return true;
        } catch (Throwable $e) {
            Log::error('Mobile two-factor code could not be sent for user ' . $user->id . ': ' . $e->getMessage());

            return false;
        }
    }

    /** Same rule as the web backup-code check: the code must be one of the account's codes, and it is consumed. */
    private function consumeBackupCode(User $user, string $code): bool
    {
        $codes = json_decode((string) $user->two_factor_backup_code, true);

        if (! is_array($codes) || ! in_array($code, array_map('strval', $codes), true)) {
            return false;
        }

        $remaining = array_values(array_filter($codes, fn ($c) => (string) $c !== $code));
        $user->forceFill(['two_factor_backup_code' => json_encode($remaining)])->save();

        return true;
    }

    private function challengeGone(): JsonResponse
    {
        return $this->fail('two_factor_challenge_invalid', 'This sign-in has expired. Sign in again.', 401);
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
