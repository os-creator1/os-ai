<?php

namespace App\Models;

use App\Library\Traits\HasUid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** An immutable, published copy of an AgencySite's config. Never updated after creation. */
class AgencySiteRevision extends Model
{
    use HasUid;

    public const UPDATED_AT = null;

    protected $fillable = ['agency_site_id', 'number', 'config', 'published_by_user_id'];

    protected $casts = ['config' => 'array'];

    public function generateUid(): void
    {
        $this->uid = (string) Str::uuid();
    }
}
