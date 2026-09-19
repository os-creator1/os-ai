<?php

namespace App\Models;

use App\Enums\Documents\DocumentKind;
use App\Enums\Documents\DocumentStatus;
use App\Library\Traits\HasUid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

/**
 * Implementation Contract 17 §5.1/§5.2 — the one versioned document that is a
 * Proposal, a signed Contract (the signed STATE of a proposal-kind document)
 * and an Invoice. Business + Location + Contact bound; lane B only (§4).
 *
 * Casts/relations only in Sub-slice A. Lifecycle and token columns (status,
 * current_version_id, the *_at lifecycle stamps, void_reason, access_token_*,
 * expiry_reminder_*) are truth owned by the later canonical DocumentManager
 * (Sub-slices B/C/F) and are deliberately NOT mass-assignable: only creation
 * identity and authoring fields are. The rules binding location/contact/
 * opportunity together (§6.6) are that manager's job, not this model's.
 *
 * `uid` alone never authorizes access to a document (§6.3): the secure link
 * additionally requires a hashed token, and HasUid's route key is only the
 * locator half of the {uid}/{token} pair.
 */
class BusinessDocument extends Model
{
    use HasUid;

    protected $fillable = [
        'business_id',
        'business_location_id',
        'contact_id',
        'crm_opportunity_id',
        'kind',
        'requires_signature',
        'title',
        'currency_code',
        'recipient_name_snapshot',
        'recipient_email_snapshot',
        'recipient_phone_snapshot',
        'expires_at',
        'created_by_user_id',
    ];

    protected $hidden = [
        'access_token_hash',
    ];

    protected $casts = [
        'kind' => DocumentKind::class,
        'status' => DocumentStatus::class,
        'requires_signature' => 'boolean',
        'expiry_reminder_count' => 'integer',
        'sent_at' => 'datetime',
        'signed_at' => 'datetime',
        'paid_at' => 'datetime',
        'expired_at' => 'datetime',
        'voided_at' => 'datetime',
        'expires_at' => 'datetime',
        'access_token_expires_at' => 'datetime',
        'access_token_rotated_at' => 'datetime',
        'expiry_reminder_last_sent_at' => 'datetime',
    ];

    public function generateUid(): void
    {
        $this->uid = (string) Str::uuid();
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function businessLocation(): BelongsTo
    {
        return $this->belongsTo(BusinessLocation::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contacts::class, 'contact_id');
    }

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(CrmOpportunity::class, 'crm_opportunity_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(BusinessDocumentVersion::class, 'business_document_id');
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(BusinessDocumentVersion::class, 'current_version_id');
    }

    public function signature(): HasOne
    {
        return $this->hasOne(BusinessDocumentSignature::class, 'business_document_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(BusinessDocumentPayment::class, 'business_document_id');
    }
}
