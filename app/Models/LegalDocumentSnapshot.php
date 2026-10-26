<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** The exact rendered text of a legal document that was accepted. Never updated. */
class LegalDocumentSnapshot extends Model
{
    public $timestamps = false;

    protected $table = 'legal_document_snapshots';

    protected $guarded = [];

    protected $casts = ['created_at' => 'datetime'];
}
