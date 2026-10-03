<?php

namespace App\Models;

use App\Enums\CustomFields\CustomFieldType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A Business-wide Custom Field definition. A plain record: every create, edit,
 * archive and reorder goes through `CustomFieldDefinitionManager`, and every
 * value write through `CustomFieldValueService`. See the migration docblock.
 */
class CustomFieldDefinition extends Model
{
    public const ENTITY_CONTACT = 'contact';

    protected $fillable = [
        'business_id',
        'entity',
        'key',
        'label',
        'type',
        'options',
        'position',
    ];

    protected $casts = [
        'options' => 'array',
        'position' => 'integer',
        'archived_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $definition): void {
            if ($definition->uid === null) {
                $definition->uid = (string) Str::uuid();
            }
        });
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class, 'business_id');
    }

    public function fieldType(): CustomFieldType
    {
        return CustomFieldType::from((string) $this->type);
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /** The canonical merge token, e.g. `{{contact.event_date}}`. */
    public function token(): string
    {
        return '{{' . $this->entity . '.' . $this->key . '}}';
    }

    /** @return list<array{id: string, label: string}> */
    public function optionList(): array
    {
        return is_array($this->options) ? array_values($this->options) : [];
    }
}
