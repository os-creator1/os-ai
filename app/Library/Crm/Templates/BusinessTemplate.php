<?php

namespace App\Library\Crm\Templates;

use InvalidArgumentException;

/**
 * An internal Business Template: the starting configuration a Business is given
 * a COPY of.
 *
 * Today it carries CRM pipelines only. It is the seam later components join —
 * linked automation recipes, forms, website/form settings — each as another list
 * on this object and another step in BusinessTemplateApplier, all following the
 * same rule: applying a template copies its configuration into rows the
 * Business owns, and records which template `key`/`version` they came from.
 *
 * `version` is the snapshot identity. Changing what a template contains means a
 * new version; Businesses that received an earlier version keep exactly what
 * they received, because nothing about them points at the template's content.
 */
final readonly class BusinessTemplate
{
    /** @var list<PipelineBlueprint> */
    public array $pipelines;

    /**
     * @param  list<PipelineBlueprint>  $pipelines
     */
    public function __construct(
        public string $key,
        public int $version,
        public string $name,
        array $pipelines,
    ) {
        if ($version < 1) {
            throw new InvalidArgumentException("Business template [{$key}] must have a positive version.");
        }

        $keys = array_map(fn (PipelineBlueprint $pipeline) => $pipeline->key, $pipelines);

        if (count($keys) !== count(array_unique($keys))) {
            throw new InvalidArgumentException("Business template [{$key}] repeats a pipeline key.");
        }

        $this->pipelines = array_values($pipelines);
    }

    public function pipeline(string $key): ?PipelineBlueprint
    {
        foreach ($this->pipelines as $pipeline) {
            if ($pipeline->key === $key) {
                return $pipeline;
            }
        }

        return null;
    }
}
