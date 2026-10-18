<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SEO Content Engine V1 — a slug a published article used to have. Looked up by the public renderer
 * to 301 an old indexed URL to the article's current one. Rows are only ever added.
 *
 * @property int $id
 * @property int $website_article_id
 * @property int $website_id
 * @property string $slug
 */
class WebsiteArticleSlugHistory extends Model
{
    protected $table = 'website_article_slug_history';

    public $timestamps = false;

    protected $fillable = ['website_article_id', 'website_id', 'slug', 'created_at'];

    protected $casts = ['created_at' => 'datetime'];

    public function article(): BelongsTo
    {
        return $this->belongsTo(WebsiteArticle::class, 'website_article_id');
    }
}
