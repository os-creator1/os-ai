<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DemoFormVersion extends Model
{
    public const DRAFT = 'draft';

    public const PUBLISHED = 'published';

    protected $fillable = ['status', 'fields', 'updated_by', 'published_at'];

    protected $casts = ['fields' => 'array', 'published_at' => 'datetime'];
}
