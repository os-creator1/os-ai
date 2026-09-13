<?php

namespace App\Models;

use App\Enums\Messaging\MessagingEntityType;
use App\Enums\Messaging\MessagingRegistrationStatus;
use App\Enums\Messaging\PhoneNumberType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Text messaging setup/number/compliance hub — one row per Business, the
 * single record the carrier registration (10DLC brand+campaign for a
 * `local` number, toll-free verification for a `toll_free` number) is
 * captured into and submitted from. See the creating migration's docblock
 * for why this deliberately does not reuse Business/BusinessLocation and
 * folds brand+campaign into one row.
 *
 * Carries no credential. `provider_brand_id`/`provider_campaign_id` are
 * opaque, admin-visible-only identifiers, never rendered to the customer.
 */
class BusinessMessagingRegistration extends Model
{
    protected $table = 'business_messaging_registrations';

    protected $fillable = [
        'business_id',
        'number_type',
        'status',
        'legal_business_name',
        'entity_type',
        'ein',
        'address_line_1',
        'address_line_2',
        'city',
        'region',
        'postal_code',
        'country_code',
        'website_url',
        'contact_email',
        'contact_phone',
        'use_case',
        'opt_in_method',
        'sample_message_1',
        'sample_message_2',
        'privacy_policy_url',
        'terms_url',
        'provider_brand_id',
        'provider_campaign_id',
        'rejection_reason',
        'submitted_at',
        'approved_at',
        'rejected_at',
    ];

    protected $casts = [
        'business_id' => 'integer',
        'number_type' => PhoneNumberType::class,
        'status' => MessagingRegistrationStatus::class,
        'entity_type' => MessagingEntityType::class,
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function isApproved(): bool
    {
        return $this->status === MessagingRegistrationStatus::Approved;
    }
}
