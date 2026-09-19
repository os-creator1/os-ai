<?php

namespace App\Models;

use App\Enums\Documents\DocumentSignatureMethod;
use App\Library\Traits\HasUid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Implementation Contract 17 §5.5 — first-party, provider-neutral TYPED
 * electronic-signature evidence. Write-once: `created_at` only.
 *
 * A TECHNICAL signing record (§6.5): one signer, no countersignature, bound to
 * an exact immutable issued version and its content hash. It makes no legal
 * claim and must never be described as a qualified/advanced/identity-verified
 * signature. Nothing in this sub-slice writes one; signing execution is
 * Sub-slice C.
 */
class BusinessDocumentSignature extends Model
{
    use HasUid;

    const UPDATED_AT = null;

    protected $fillable = [
        'business_document_id',
        'business_document_version_id',
        'signed_content_hash',
        'signer_name',
        'signer_email',
        'typed_name',
        'signature_method',
        'consent_statement',
        'consent_statement_hash',
        'ip_address',
        'user_agent',
        'signed_at',
    ];

    protected $casts = [
        'signature_method' => DocumentSignatureMethod::class,
        'signed_at' => 'datetime',
    ];

    public function generateUid(): void
    {
        $this->uid = (string) Str::uuid();
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(BusinessDocument::class, 'business_document_id');
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(BusinessDocumentVersion::class, 'business_document_version_id');
    }
}
