<?php

namespace App\Models;

use App\Library\Traits\HasUid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AgencyProspectImportBatch extends Model
{
    use HasUid;

    protected $fillable = [
        'workspace_id', 'kind', 'filename', 'status', 'region', 'headers', 'mapping', 'extra_fields', 'summary', 'group_report',
        'contact_group_id', 'group_name', 'created_by_user_id', 'imported_at',
    ];

    protected $casts = ['headers' => 'array', 'mapping' => 'array', 'extra_fields' => 'array', 'summary' => 'array', 'group_report' => 'array', 'imported_at' => 'datetime'];

    public function rows(): HasMany
    {
        return $this->hasMany(AgencyProspectImportRow::class, 'batch_id');
    }
}
