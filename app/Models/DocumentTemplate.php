<?php

namespace App\Models;

use App\Enums\Documents\DocumentTemplateStatus;
use App\Enums\Documents\DocumentTemplateType;
use App\Library\Traits\HasUid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Implementation Contract 17B §6 — a reusable proposal / contract layout.
 *
 * `business_id` NULL = platform-owned canonical template; otherwise the
 * Business's private template. `blocks` is written only after
 * App\Library\Documents\Blocks\BlockSchema has validated and sanitised it.
 * `status`, `lock_version` are not mass-assignable: the template manager owns
 * them.
 */
class DocumentTemplate extends Model
{
    use HasUid;

    protected $fillable = [
        'business_id',
        'template_type',
        'name',
        'description',
        'blocks',
        'schema_version',
        'created_by_user_id',
    ];

    protected $casts = [
        'template_type' => DocumentTemplateType::class,
        'status' => DocumentTemplateStatus::class,
        'blocks' => 'array',
        'schema_version' => 'integer',
        'lock_version' => 'integer',
    ];

    public function generateUid(): void
    {
        $this->uid = (string) Str::uuid();
    }

    public function isPlatformOwned(): bool
    {
        return $this->business_id === null;
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
