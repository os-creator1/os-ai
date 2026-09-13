<?php

namespace App\Models;

use App\Enums\Crm\CrmStageSemanticKey;
use App\Library\Traits\HasUid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One column of a CRM pipeline. `name` is the customer's label; `semantic_key`
 * is what the product means by the stage, and survives any rename.
 *
 * @property int $id
 * @property string $uid
 * @property int $business_id
 * @property int $pipeline_id
 * @property string $name
 * @property ?string $semantic_key
 * @property int $position
 * @property ?\Illuminate\Support\Carbon $archived_at
 */
class CrmPipelineStage extends Model
{
    use HasUid;

    protected $table = 'crm_pipeline_stages';

    protected $fillable = [
        'business_id',
        'pipeline_id',
        'name',
        'semantic_key',
        'position',
        'archived_at',
    ];

    protected $casts = [
        'position' => 'integer',
        'archived_at' => 'datetime',
    ];

    public function generateUid(): void
    {
        $this->uid = (string) Str::uuid();
    }

    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(CrmPipeline::class, 'pipeline_id');
    }

    public function isNewInquiry(): bool
    {
        return $this->semantic_key === CrmStageSemanticKey::NewInquiry->value;
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }
}
