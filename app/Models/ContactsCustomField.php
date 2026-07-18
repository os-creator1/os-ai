<?php

namespace App\Models;

use App\Library\Traits\QueryHelper;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @method static where(string $string, string $uid)
 * @method static create(array $array)
 */
class ContactsCustomField extends Model
{
    use QueryHelper;

    protected $table = 'contacts_custom_field';

    protected $fillable = [
        'field_id', 'contact_id', 'value',
    ];

    /**
     * Retrieves the associated ContactGroupFields model.
     *
     * @return BelongsTo The associated ContactGroupFields model.
     */
    public function field(): BelongsTo
    {

        return $this->belongsTo(ContactGroupFields::class, 'field_id', 'id');
    }
}
