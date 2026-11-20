<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Explicit, deduplicated provenance: this Contact Group references this logical Outreach prospect. */
class AgencyProspectGroupLink extends Model
{
    protected $fillable = ['workspace_id', 'contact_group_id', 'prospect_id', 'contact_id', 'import_batch_id', 'contact_in_group'];

    protected $casts = ['contact_in_group' => 'boolean'];
}
