<?php

namespace App\Models;

use App\Enums\Entitlement\PlatformFeature;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Existence of a row = the tier structurally includes that feature
 * (packaging only) — independent of PlatformFeatureRegistry's implementation
 * availability (RFC-004 §10.2/§11).
 */
class WorkspacePlanFeature extends Model
{
    protected $table = 'workspace_plan_features';

    protected $fillable = [
        'workspace_plan_catalog_id',
        'feature_key',
    ];

    protected $casts = [
        'feature_key' => PlatformFeature::class,
    ];

    public function catalog(): BelongsTo
    {
        return $this->belongsTo(WorkspacePlanCatalog::class, 'workspace_plan_catalog_id');
    }
}
