<?php

namespace App\Models;

use App\Library\Traits\HasUid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A Business's CRM sales pipeline. Not the AI COO `Opportunity` domain.
 *
 * @property int $id
 * @property string $uid
 * @property int $business_id
 * @property string $name
 * @property int $position
 * @property ?string $template_key
 * @property ?int $template_version
 * @property ?string $template_pipeline_key
 * @property ?\Illuminate\Support\Carbon $archived_at
 */
class CrmPipeline extends Model
{
    use HasUid;

    protected $table = 'crm_pipelines';

    protected $fillable = [
        'business_id',
        'name',
        'position',
        'template_key',
        'template_version',
        'template_pipeline_key',
        'created_by_user_id',
        'archived_at',
    ];

    protected $casts = [
        'position' => 'integer',
        'template_version' => 'integer',
        'archived_at' => 'datetime',
    ];

    public function generateUid(): void
    {
        $this->uid = (string) Str::uuid();
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function stages(): HasMany
    {
        return $this->hasMany(CrmPipelineStage::class, 'pipeline_id')->orderBy('position')->orderBy('id');
    }

    public function activeStages(): HasMany
    {
        return $this->stages()->whereNull('archived_at');
    }

    public function scopeForBusiness(Builder $query, Business|int $business): Builder
    {
        return $query->where('business_id', $business instanceof Business ? $business->id : $business);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }
}
