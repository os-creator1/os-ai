<?php

namespace App\Models;

use App\Enums\Business\BusinessPricingMethod;
use App\Enums\Business\BusinessPrimaryConversionGoal;
use App\Library\Traits\HasUid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Website Guided Generation contract §4.2. One row per Business
 * (business_id unique, migration 1). The ONLY authorized write path is
 * App\Library\Business\BusinessKnowledgeProfileManager (§4.3) -- no
 * controller, job, or other service writes this table directly.
 */
class BusinessKnowledgeProfile extends Model
{
    use HasUid;

    protected $fillable = [
        'business_id',
        'vertical_key',
        'pricing_method',
        'financing_available',
        'offers',
        'differentiators',
        'ideal_customers',
        'customer_problems',
        'credentials',
        'years_operating',
        'warranties_guarantees',
        'primary_conversion_goal',
        'conversion_target',
        'brand_voice',
        'prohibited_claims',
        'growth_priority_service_ids',
        'growth_priority_location_ids',
        'testimonials',
        'reviews_source',
    ];

    protected $casts = [
        'pricing_method' => BusinessPricingMethod::class,
        'financing_available' => 'boolean',
        'offers' => 'array',
        'differentiators' => 'array',
        'customer_problems' => 'array',
        'credentials' => 'array',
        'years_operating' => 'integer',
        'primary_conversion_goal' => BusinessPrimaryConversionGoal::class,
        'prohibited_claims' => 'array',
        'growth_priority_service_ids' => 'array',
        'growth_priority_location_ids' => 'array',
        'testimonials' => 'array',
    ];

    /**
     * business_knowledge_profiles.uid is a database UUID column;
     * HasUid's default generateUid() uses uniqid(), which is not a valid
     * UUID. Mirrors Website::generateUid() exactly.
     */
    public function generateUid(): void
    {
        $this->uid = (string) Str::uuid();
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
