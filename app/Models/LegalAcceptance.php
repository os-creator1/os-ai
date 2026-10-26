<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LegalAcceptance extends Model
{
    public $timestamps = false;

    protected $table = 'legal_acceptances';

    protected $guarded = [];

    protected $casts = ['accepted_at' => 'datetime'];

    public function snapshot()
    {
        return $this->belongsTo(LegalDocumentSnapshot::class, 'snapshot_id');
    }
}
