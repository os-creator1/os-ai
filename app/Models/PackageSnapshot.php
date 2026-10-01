<?php

namespace App\Models;

use App\Library\Traits\HasUid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use LogicException;

/**
 * Implementation Contract 16 §5.3 — an immutable, write-once transactional
 * record of the actual package/price used, mirroring `WebsiteRevision`'s
 * exact discipline. No `updated_at`, no soft delete, no status column.
 *
 * Casts/relations only in this sub-slice. Nothing in this codebase may
 * call `update()`/`save()` on an existing row — enforced by production
 * code never doing so (proved by a source-boundary test, Sub-slice D),
 * not by a model-level mechanism this precedent does not itself have.
 * `PackageSnapshotService::snapshot()` (Sub-slice D) is the only write
 * path.
 */
class PackageSnapshot extends Model
{
    use HasUid;

    const UPDATED_AT = null;

    protected $fillable = [
        'business_id',
        'catalog_item_id',
        'business_location_id',
        'name_at_snapshot',
        'description_at_snapshot',
        'price_minor_at_snapshot',
        'currency_code_at_snapshot',
        'schema_version',
        'created_by_user_id',
    ];

    protected $casts = [
        'price_minor_at_snapshot' => 'integer',
        'schema_version' => 'integer',
    ];

    /**
     * Write-once at the model layer as well as by the source-boundary test: an
     * Eloquent update or delete of an existing snapshot throws, so a future
     * caller cannot rewrite what a past document was priced at by accident.
     * This guards the Eloquent path only — it is not a database-level lock,
     * and a raw query-builder statement would not hit it. That limit is the
     * same one `WebsiteRevision` has and is why `PackageSnapshotBoundaryTest`
     * also scans production code for any such statement.
     */
    protected static function booted(): void
    {
        static::updating(static function (self $snapshot): never {
            throw new LogicException('A package snapshot is immutable and can never be changed after it is created.');
        });

        static::deleting(static function (self $snapshot): never {
            throw new LogicException('A package snapshot is immutable and can never be deleted.');
        });
    }

    public function generateUid(): void
    {
        $this->uid = (string) Str::uuid();
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function catalogItem(): BelongsTo
    {
        return $this->belongsTo(CatalogItem::class);
    }

    public function businessLocation(): BelongsTo
    {
        return $this->belongsTo(BusinessLocation::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
