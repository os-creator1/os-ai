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
        'doing_business_as',
        'additional_information',
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
        'support_contact',
        'support_contact_confirmed_at',
        'contact_phone',
        'contact_first_name',
        'contact_last_name',
        'message_volume',
        'opt_in_image_url',
        'brand_vertical',
        'message_purposes',
        'local_setup_started_at',
        'use_case',
        'opt_in_method',
        'campaign_description',
        'sample_message_1',
        'sample_message_2',
        'privacy_policy_url',
        'terms_url',
        'consent_confirmations',
        'provider_brand_id',
        'provider_brand_tcr_id',
        'brand_status',
        'brand_status_detail',
        'brand_created_at',
        'brand_status_checked_at',
        'campaign_status_checked_at',
        'campaign_issue',
        'campaign_issue_permanent',
        'provider_campaign_id',
        'provider_registration_id',
        'rejection_reason',
        'submitted_at',
        'approved_at',
        'rejected_at',
    ];

    protected $casts = [
        'business_id' => 'integer',
        'number_type' => PhoneNumberType::class,
        'status' => MessagingRegistrationStatus::class,
        'consent_confirmations' => 'array',
        'support_contact_confirmed_at' => 'datetime',
        'brand_created_at' => 'datetime',
        'brand_status_checked_at' => 'datetime',
        'campaign_status_checked_at' => 'datetime',
        'campaign_issue_permanent' => 'boolean',
        'entity_type' => MessagingEntityType::class,
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'message_purposes' => 'array',
        'local_setup_started_at' => 'datetime',
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
