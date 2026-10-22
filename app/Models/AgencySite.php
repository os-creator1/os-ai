<?php

namespace App\Models;

use App\Library\Traits\HasUid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/** White Label — an Agency's marketing site: a mutable draft `config` plus immutable published revisions. */
class AgencySite extends Model
{
    use HasUid;

    public const DRAFT = 'draft';

    public const PUBLISHED = 'published';

    public const UNPUBLISHED = 'unpublished';

    protected $fillable = ['agency_workspace_id', 'status', 'config', 'current_step', 'updated_by_user_id'];

    protected $casts = [
        'config' => 'array',
        'current_step' => 'integer',
        'published_at' => 'datetime',
    ];

    public function generateUid(): void
    {
        $this->uid = (string) Str::uuid();
    }

    protected static function booted(): void
    {
        static::creating(function (AgencySite $site): void {
            $site->public_id ??= (string) Str::uuid();
        });
    }

    public function agencyWorkspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class, 'agency_workspace_id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(AgencySiteRevision::class);
    }

    public function publishedRevision(): BelongsTo
    {
        return $this->belongsTo(AgencySiteRevision::class, 'published_revision_id');
    }

    public function isLive(): bool
    {
        return $this->status === self::PUBLISHED && $this->published_revision_id !== null;
    }
}
