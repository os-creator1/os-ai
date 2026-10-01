<?php

namespace App\Models;

use App\Enums\Forms\FormDeploymentSource;
use App\Library\Traits\HasUid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Forms V1 — "this form, at this Location, from this source".
 *
 * The deterministic evidence a public submission's Location is read from. The
 * `uid` is the public identifier a visitor's link carries (a real UUID, never
 * the uniqid()-shaped HasUid default). Written only by `FormManager`.
 *
 * @property int $id
 * @property string $uid
 * @property int $form_id
 * @property int $business_location_id
 * @property string $source
 * @property bool $is_enabled
 */
class FormDeployment extends Model
{
    use HasUid;

    protected $fillable = [
        'form_id',
        'business_location_id',
        'source',
        'is_enabled',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
    ];

    public function generateUid(): void
    {
        $this->uid = (string) Str::uuid();
    }

    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(BusinessLocation::class, 'business_location_id');
    }

    public function sourceEnum(): ?FormDeploymentSource
    {
        return FormDeploymentSource::tryFrom($this->source);
    }
}
