<?php

namespace App\Models;

use App\Enums\BusinessEmail\BusinessEmailFailureCategory;
use App\Enums\BusinessEmail\BusinessEmailMessageStatus;
use App\Enums\BusinessEmail\BusinessEmailProviderType;
use App\Enums\BusinessEmail\BusinessEmailSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One logical Business→Contact email: the idempotency identity
 * (business_id + operation_key), the state machine and the history row in
 * one. Written only by BusinessEmailSender.
 *
 * Nothing here ever holds a credential. `failure_provider_code` is
 * operator-only and is hidden from serialization.
 */
class BusinessEmailMessage extends Model
{
    protected $table = 'business_email_messages';

    protected $guarded = [];

    protected $hidden = ['failure_provider_code'];

    protected $casts = [
        'provider' => BusinessEmailProviderType::class,
        'source' => BusinessEmailSource::class,
        'status' => BusinessEmailMessageStatus::class,
        'failure_category' => BusinessEmailFailureCategory::class,
        'claimed_at' => 'datetime',
        'next_attempt_at' => 'datetime',
        'accepted_at' => 'datetime',
        'attempts' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $message): void {
            if ($message->uid === null) {
                $message->uid = (string) Str::uuid();
            }
        });
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contacts::class, 'contact_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(BusinessEmailAccount::class, 'business_email_account_id');
    }
}
