<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A video testimonial slot. Deliberately never seeded with real names,
 * wording, or video links — see the migration's own docblock. A row only
 * appears on the public homepage once an operator has both filled it in
 * AND explicitly marked it visible.
 */
class MarketingTestimonial extends Model
{
    protected $table = 'marketing_testimonials';

    protected $fillable = [
        'name',
        'business_context_label',
        'poster_image_path',
        'video_url',
        'transcript_text',
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
