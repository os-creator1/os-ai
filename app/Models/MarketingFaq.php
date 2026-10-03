<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class MarketingFaq extends Model
{
    protected $table = 'marketing_faqs';

    protected $fillable = [
        'question',
        'answer',
        'position',
        'is_visible',
    ];

    protected $casts = [
        'position' => 'integer',
        'is_visible' => 'boolean',
    ];

    public function scopeVisibleOrdered(Builder $query): Builder
    {
        return $query->where('is_visible', true)->orderBy('position')->orderBy('id');
    }
}
