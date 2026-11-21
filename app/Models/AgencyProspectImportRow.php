<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AgencyProspectImportRow extends Model
{
    protected $fillable = ['batch_id', 'workspace_id', 'row_number', 'original', 'normalized', 'phone', 'outcome', 'issues', 'prospect_id', 'contact_id'];

    protected $casts = ['original' => 'array', 'normalized' => 'array', 'issues' => 'array'];
}
