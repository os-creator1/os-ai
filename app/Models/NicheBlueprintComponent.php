<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Contract 20 §5.3 — one component of one Blueprint VERSION.
 *
 * `required_feature_key` is NOT NULL at the database layer and is always a
 * known, Business-scoped `PlatformFeature` value by the time a version is
 * published (§6.2 validates it there). There is no ungated Blueprint
 * component: a component that cannot name a feature cannot be published.
 * This model does not itself re-validate the key — that is the publisher's
 * job at the publish boundary, exactly as `PipelineBlueprint` validates where
 * a template is defined rather than where it is copied.
 *
 * `component_key` is the durable identity an installation record points at,
 * so it is stable across versions; changing it makes a DIFFERENT component.
 *
 * @property int $id
 * @property int $blueprint_version_id
 * @property int $blueprint_id
 * @property string $component_key
 * @property string $component_type
 * @property string $required_feature_key
 * @property array $payload
 * @property int $position
 */
class NicheBlueprintComponent extends Model
{
    protected $table = 'niche_blueprint_components';

    protected $fillable = [
        'blueprint_version_id',
        'blueprint_id',
        'component_key',
        'component_type',
        'required_feature_key',
        'payload',
        'position',
    ];

    protected $casts = [
        'blueprint_version_id' => 'integer',
        'blueprint_id' => 'integer',
        'payload' => 'array',
        'position' => 'integer',
    ];

    public function version(): BelongsTo
    {
        return $this->belongsTo(NicheBlueprintVersion::class, 'blueprint_version_id');
    }

    public function blueprint(): BelongsTo
    {
        return $this->belongsTo(NicheBlueprint::class, 'blueprint_id');
    }
}
