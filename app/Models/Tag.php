<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

/**
 * Contact Tags foundation — the canonical, Business-wide tag definition.
 *
 * Never Location-scoped; see the `create_tags_table` migration's own
 * docblock for why. `archived_at` is a lifecycle marker, not a deletion:
 * every mutation and read of this model's own state goes through
 * `TagManager`, which is the one boundary allowed to create, rename or
 * archive a tag, or attach/detach it on a Contact — this model stays a
 * plain record, never performing its own tenancy or uniqueness checks.
 */
class Tag extends Model
{
    protected $fillable = [
        'business_id',
        'name',
        'normalized_name',
    ];

    protected $casts = [
        'archived_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $tag): void {
            if ($tag->uid === null) {
                $tag->uid = (string) Str::uuid();
            }
        });
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class, 'business_id');
    }

    public function contacts(): BelongsToMany
    {
        return $this->belongsToMany(Contacts::class, 'contact_tags', 'tag_id', 'contact_id')
            ->withPivot('id', 'business_id', 'created_at');
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }
}
