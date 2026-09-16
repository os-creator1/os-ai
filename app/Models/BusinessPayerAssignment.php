<?php

namespace App\Models;

use App\Enums\Usage\PayerType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessPayerAssignment extends Model
{
    protected $table = 'business_payer_assignments';

    protected $fillable = [
        'business_id',
        'payer_type',
        'effective_payment_instrument_id',
        // Implementation Contract 09 §5.1 — written only by
        // BillingProfileManager from the server-resolved relationship and
        // the managing Agency owner's own action.
        'managing_agency_relationship_id',
        'agency_rebill_consented_at',
        'agency_rebill_consented_by_user_id',
    ];

    protected $casts = [
        'payer_type' => PayerType::class,
        'agency_rebill_consented_at' => 'datetime',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function managingAgencyRelationship(): BelongsTo
    {
        return $this->belongsTo(AgencyClientWorkspaceRelationship::class, 'managing_agency_relationship_id');
    }
}
