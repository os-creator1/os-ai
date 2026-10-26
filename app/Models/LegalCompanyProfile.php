<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One immutable revision of the platform operator's legal identity. */
class LegalCompanyProfile extends Model
{
    public $timestamps = false;

    protected $table = 'legal_company_profiles';

    protected $guarded = [];

    protected $casts = ['created_at' => 'datetime'];
}
