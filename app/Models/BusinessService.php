<?php

namespace App\Models;

use App\Enums\Business\BusinessServiceStatus;
use App\Library\Traits\HasUid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessService extends Model
{
    use HasUid;

    protected $fillable = [
        'business_id',
        'name',
        'slug',
        'description',
        'starting_price',
        'currency_code',
        'status',
        'sort_order',
    ];

    protected $casts = [
        'status' => BusinessServiceStatus::class,
        'is_primary' => 'boolean',
        'starting_price' => 'decimal:2',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
