<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Contact Tags foundation — the Contact<->Tag membership row itself, as a
 * real model rather than an anonymous pivot: `ContactTagManager` needs this
 * row's own `id` (the stable occurrence anchor the `ContactTagAdded`/
 * `ContactTagRemoved` events key on) back from every attach/detach call.
 */
class ContactTag extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'contact_id',
        'tag_id',
        'business_id',
    ];

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contacts::class, 'contact_id');
    }

    public function tag(): BelongsTo
    {
        return $this->belongsTo(Tag::class, 'tag_id');
    }
}
