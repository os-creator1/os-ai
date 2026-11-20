<?php

namespace App\Models;

use App\Library\Traits\HasUid;
use Illuminate\Database\Eloquent\Model;

/** "Do Not Prospect — Existing Client". Commercial exclusion; never a STOP / opt-out. */
class AgencyProspectExclusion extends Model
{
    use HasUid;

    public const REASON_MANUAL = 'manual';
    public const REASON_CLIENT_WON = 'client_won';
    public const REASON_CSV = 'csv_import';

    protected $fillable = [
        'workspace_id', 'phone', 'active_phone', 'business_name', 'reason', 'source_label', 'prospect_id',
        'import_batch_id', 'created_by_user_id', 'removed_at', 'removed_by_user_id',
    ];

    protected $casts = ['removed_at' => 'datetime'];

    public function isActive(): bool
    {
        return $this->removed_at === null;
    }
}
