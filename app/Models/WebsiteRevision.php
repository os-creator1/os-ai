<?php

namespace App\Models;

use App\Library\Traits\HasUid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Website Generation + Hosting Slice A contract §5.3/§10/§11. An
 * immutable, write-once publication snapshot. No `updated_at` (contract
 * §5.3) — nothing in this codebase may UPDATE a row in this table after
 * insert; every write path in this feature only ever INSERTs a new row
 * or reads an existing one.
 */
class WebsiteRevision extends Model
{
    use HasUid;

    const UPDATED_AT = null;

    protected $fillable = [
        'uid',
        'website_id',
        'version_number',
        'snapshot',
        'schema_version',
        'created_by',
    ];

    protected $casts = [
        'snapshot' => 'array',
    ];

    public function generateUid()
    {
        $this->uid = (string) Str::uuid();
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
