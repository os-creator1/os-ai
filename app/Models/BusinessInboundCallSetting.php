<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One Business's inbound-call routing. The forwarding number is encrypted and only ever dialled, never rendered. */
class BusinessInboundCallSetting extends Model
{
    public const MODE_OFF = 'off';
    public const MODE_BROWSER = 'browser';
    public const MODE_FORWARD = 'forward';
    public const MODE_BROWSER_THEN_FORWARD = 'browser_then_forward';
    public const MODES = [self::MODE_OFF, self::MODE_BROWSER, self::MODE_FORWARD, self::MODE_BROWSER_THEN_FORWARD];

    protected $table = 'business_inbound_call_settings';

    protected $guarded = [];

    protected $hidden = ['forward_number', 'verify_code', 'verify_code_hash'];

    protected $casts = [
        'business_id' => 'integer',
        'browser_ring_secs' => 'integer',
        'forward_number' => 'encrypted',
        'verify_code' => 'encrypted',
        'forward_verified_at' => 'datetime',
        'verify_expires_at' => 'datetime',
        'verify_attempts' => 'integer',
    ];

    public function usesBrowser(): bool
    {
        return in_array($this->mode, [self::MODE_BROWSER, self::MODE_BROWSER_THEN_FORWARD], true);
    }

    public function usesForwarding(): bool
    {
        return in_array($this->mode, [self::MODE_FORWARD, self::MODE_BROWSER_THEN_FORWARD], true);
    }

    /** A forward number is dialled only while the exact number on file is the one that was verified. */
    public function forwardVerified(): bool
    {
        return $this->forward_number !== null && $this->forward_number_hash !== null
            && $this->forward_verified_at !== null && hash_equals((string) $this->forward_verified_hash, (string) $this->forward_number_hash);
    }

    public static function hashNumber(int $businessId, string $e164): string
    {
        return hash_hmac('sha256', $businessId . '|' . $e164, (string) config('app.key'));
    }
}
