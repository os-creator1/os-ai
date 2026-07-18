<?php

namespace App\Models;

use App\Library\Traits\HasUid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @method static where(string $string, mixed $id)
 * @method static whereIn(string $string, $unique)
 */
class ContactGroupFields extends Model
{
    use HasUid;

    public const TYPE_DATE = 'date';

    public const TYPE_DATETIME = 'datetime';

    /**
     * @var string[]
     */
    protected $fillable = [
        'contact_group_id',
        'label',
        'type',
        'tag',
        'default_value',
        'visible',
        'required',
        'is_phone',
    ];

    protected $casts = [
        'visible' => 'boolean',
        'required' => 'boolean',
        'is_phone' => 'boolean',
    ];

    public function contactGroup(): BelongsTo
    {
        return $this->belongsTo('App\Models\ContactGroups', 'contact_group_id');
    }

    /**
     * Retrieves the field options associated with this contact group.
     */
    public function fieldOptions(): HasMany
    {
        return $this->hasMany('App\Models\ContactGroupFieldOptions', 'field_id');
    }

    /**
     * Formats a tag by removing special characters and converting it to uppercase.
     *
     * @param  string  $tag The tag to format.
     * @return string The formatted tag.
     */
    public static function formatTag($tag): string
    {
        return strtoupper(preg_replace('/[^0-9a-zA-Z_]/m', '', $tag));
    }

    /**
     * Retrieves the select options for the field.
     *
     * @return array Returns an array of select options with 'value' and 'text' keys.
     */
    public function getSelectOptions(): array
    {
        return $this->fieldOptions->map(function ($item) {
            return ['value' => $item->value, 'text' => $item->label];
        });
    }

    /**
     * Returns the control name based on the given type.
     *
     * @param  string  $type The type of the control.
     * @return string The control name.
     */
    public static function getControlNameByType(string $type): string
    {
        if ($type == 'date') {
            return 'date';
        } elseif ($type == 'number') {
            return 'number';
        } elseif ($type == 'datetime') {
            return 'datetime';
        }

        return 'text';
    }
}
