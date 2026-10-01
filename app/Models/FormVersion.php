<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Forms V1 — one immutable version of what a form asks.
 *
 * Written once by `FormManager` and then never touched: an update or a delete
 * through Eloquent is refused, because a submission points at this row to stay
 * intelligible after the form is edited. There is no `updated_at`.
 *
 * @property int $id
 * @property int $form_id
 * @property int $version
 * @property string $content_hash
 * @property ?string $intro
 * @property string $submit_label
 * @property string $success_message
 * @property list<array{key: string, label: string, type: string, required: bool, options: list<string>, contact_name: bool}> $fields
 * @property bool $create_opportunity
 * @property ?int $opportunity_pipeline_id
 */
class FormVersion extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'form_id',
        'version',
        'content_hash',
        'intro',
        'submit_label',
        'success_message',
        'fields',
        'create_opportunity',
        'opportunity_pipeline_id',
        'created_by_user_id',
    ];

    protected $casts = [
        'version' => 'integer',
        'fields' => 'array',
        'create_opportunity' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('A form version is immutable; write a new version instead.');
        });

        static::deleting(function (): void {
            throw new LogicException('A form version is never deleted; submissions depend on it.');
        });
    }

    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class);
    }

    /** @return array<string, array{key: string, label: string, type: string, required: bool, options: list<string>, contact_name: bool}> */
    public function fieldsByKey(): array
    {
        $byKey = [];
        foreach ($this->fields ?? [] as $field) {
            $byKey[$field['key']] = $field;
        }

        return $byKey;
    }
}
