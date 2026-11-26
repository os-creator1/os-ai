<?php

namespace App\Library\Mobile;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * The pending second step of a mobile sign-in for an account with two-factor enabled.
 *
 * A correct password yields an opaque challenge, never a token. The challenge lives in the cache under the SHA-256
 * of its value (the plain value exists only on the phone), expires with the code it carries, allows a bounded number
 * of wrong guesses and is consumed exactly once, under a lock, so two concurrent requests cannot both redeem it.
 *
 * The emailed/SMS code is the same kind the web sends (6 digits, 10 minutes, `TwoFactorCode` channels), but it is held
 * HERE, hashed, rather than in `users.two_factor_code`: writing that column would make the web `TwoFactor` middleware
 * push the person's already-open browser session to the verify page (and log it out on expiry).
 */
class MobileTwoFactorChallenge
{
    public const TTL_MINUTES = 10;
    public const MAX_ATTEMPTS = 5;
    public const MAX_RESENDS = 3;

    /** @param array<string, mixed> $state */
    private function __construct(public readonly string $hash, private array $state)
    {
    }

    /**
     * @param array<string, mixed> $device
     * @return array{0: self, 1: string, 2: string} the challenge, its plain value (for the phone) and the plain code (for delivery)
     */
    public static function start(User $user, array $device): array
    {
        $plain = Str::random(64);
        $code = self::newCode();
        $challenge = new self(self::hashOf($plain), [
            'user_id' => $user->id,
            'device' => $device,
            'code_hash' => self::codeHash($code),
            'attempts' => 0,
            'resends' => 0,
            'expires_at' => now()->addMinutes(self::TTL_MINUTES)->getTimestamp(),
        ]);
        $challenge->save();

        return [$challenge, $plain, $code];
    }

    public static function find(string $plain): ?self
    {
        $hash = self::hashOf($plain);
        $state = Cache::get(self::key($hash));

        if (! is_array($state) || ($state['expires_at'] ?? 0) <= now()->getTimestamp()) {
            return null;
        }

        return new self($hash, $state);
    }

    /**
     * Runs $fn with exclusive access to the challenge; reloads it inside the lock so a concurrent consume/burn is seen.
     * Returns null when the lock is busy or the challenge is gone — both are "sign in again" for the caller.
     *
     * @template T
     * @param callable(self): T $fn
     * @return T|null
     */
    public static function withLock(string $plain, callable $fn): mixed
    {
        $hash = self::hashOf($plain);
        $lock = Cache::lock('mobile-2fa-lock:' . $hash, 10);

        if (! $lock->get()) {
            return null;
        }

        try {
            $fresh = self::find($plain);

            return $fresh === null ? null : $fn($fresh);
        } finally {
            $lock->release();
        }
    }

    public function userId(): int
    {
        return (int) $this->state['user_id'];
    }

    /** @return array<string, mixed> */
    public function device(): array
    {
        return (array) $this->state['device'];
    }

    public function expiresAt(): \Illuminate\Support\Carbon
    {
        return \Illuminate\Support\Carbon::createFromTimestamp((int) $this->state['expires_at']);
    }

    public function matchesCode(string $code): bool
    {
        return hash_equals((string) $this->state['code_hash'], self::codeHash($code));
    }

    /** Counts one wrong guess; the challenge is destroyed once the limit is reached. Returns the guesses left. */
    public function recordFailure(): int
    {
        $this->state['attempts'] = (int) $this->state['attempts'] + 1;
        $left = self::MAX_ATTEMPTS - $this->state['attempts'];

        $left > 0 ? $this->save() : $this->forget();

        return max(0, $left);
    }

    /** Issues a fresh code (the old one stops working) and restarts the expiry; null once resends are used up. */
    public function regenerate(): ?string
    {
        if ((int) $this->state['resends'] >= self::MAX_RESENDS) {
            return null;
        }

        $code = self::newCode();
        $this->state['resends'] = (int) $this->state['resends'] + 1;
        $this->state['code_hash'] = self::codeHash($code);
        $this->state['attempts'] = 0;
        $this->state['expires_at'] = now()->addMinutes(self::TTL_MINUTES)->getTimestamp();
        $this->save();

        return $code;
    }

    public function forget(): void
    {
        Cache::forget(self::key($this->hash));
    }

    private function save(): void
    {
        $ttl = max(1, (int) $this->state['expires_at'] - now()->getTimestamp());
        Cache::put(self::key($this->hash), $this->state, $ttl);
    }

    private static function newCode(): string
    {
        return (string) random_int(100000, 999999);
    }

    private static function codeHash(string $code): string
    {
        return hash_hmac('sha256', $code, (string) config('app.key'));
    }

    private static function hashOf(string $plain): string
    {
        return hash('sha256', $plain);
    }

    private static function key(string $hash): string
    {
        return 'mobile-2fa:' . $hash;
    }
}
