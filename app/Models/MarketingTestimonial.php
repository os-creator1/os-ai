<?php

namespace App\Models;

use App\Library\Marketing\YoutubeUrlParser;
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

    /**
     * The 11-character YouTube video ID, or null when `video_url` is empty
     * or is not a (safely parseable) YouTube link.
     */
    public function youtubeVideoId(): ?string
    {
        return $this->video_url ? YoutubeUrlParser::extractVideoId($this->video_url) : null;
    }

    /**
     * Whether this testimonial has anything to actually show on the
     * homepage: an uploaded poster, or a YouTube link (whose own thumbnail
     * stands in for a poster).
     */
    public function hasDisplayableMedia(): bool
    {
        return ! blank($this->poster_image_path) || $this->youtubeVideoId() !== null;
    }
}
