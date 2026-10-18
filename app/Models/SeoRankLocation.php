<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * SEO Keyword Rank Tracking V1 — one cached provider search geography. NOT a
 * BusinessLocation. Written only by SeoRankLocationCatalog.
 *
 * @property int $id
 * @property string $provider
 * @property int $location_code
 * @property string $location_name
 * @property string $country_iso
 * @property string $location_type
 */
class SeoRankLocation extends Model
{
    protected $table = 'seo_rank_locations';

    protected $guarded = ['*'];
}
