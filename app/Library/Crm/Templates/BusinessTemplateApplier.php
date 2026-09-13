<?php

namespace App\Library\Crm\Templates;

use App\Models\Business;
use App\Models\CrmPipeline;
use App\Models\CrmPipelineStage;
use Illuminate\Support\Facades\DB;

/**
 * Copies a Business Template into one Business.
 *
 * COPY, NEVER LINK. Every pipeline and stage created here is an ordinary row the
 * Business owns and may rename, reorder, extend or archive. The template's key,
 * version and pipeline key are written beside them as provenance, and nothing
 * ever reads the template's content back through them — so editing or
 * re-versioning a template later changes what NEW applications receive, and
 * never what an existing Business already has.
 *
 * IDEMPOTENT PER BLUEPRINT. A pipeline blueprint already copied into this
 * Business from the same template (archived or not) is not copied again, so a
 * retried setup, a double-submitted button or a future "apply template on
 * Business creation" step can run twice safely. The Business row is locked for
 * the duration, which serialises two concurrent applications to one Business.
 *
 * Later template components (automation recipes, forms, website settings) join
 * `apply()` as further steps with the same copy-and-record rule.
 */
class BusinessTemplateApplier
{
    /**
     * Apply every component of the template this release knows how to copy.
     *
     * @return list<CrmPipeline> the pipelines created by this call
     */
    public function apply(Business $business, BusinessTemplate $template, ?int $actorUserId = null): array
    {
        return $this->applyPipelines($business, $template, $actorUserId);
    }

    /**
     * @return list<CrmPipeline> the pipelines created by this call (empty when all were already copied)
     */
    public function applyPipelines(Business $business, BusinessTemplate $template, ?int $actorUserId = null): array
    {
        return DB::transaction(function () use ($business, $template, $actorUserId): array {
            Business::query()->whereKey($business->id)->lockForUpdate()->first();

            $created = [];

            foreach ($template->pipelines as $blueprint) {
                $alreadyCopied = CrmPipeline::query()
                    ->forBusiness($business)
                    ->where('template_key', $template->key)
                    ->where('template_pipeline_key', $blueprint->key)
                    ->exists();

                if ($alreadyCopied) {
                    continue;
                }

                $created[] = $this->copyPipeline($business, $template, $blueprint, $blueprint->name, $actorUserId);
            }

            return $created;
        });
    }

    /**
     * A NEW, separately named pipeline from one blueprint, even when the Business
     * already has a copy of it ("+ New pipeline"). Same copy rule, same provenance.
     */
    public function copyPipeline(Business $business, BusinessTemplate $template, PipelineBlueprint $blueprint, string $name, ?int $actorUserId = null): CrmPipeline
    {
        return DB::transaction(function () use ($business, $template, $blueprint, $name, $actorUserId): CrmPipeline {
            $last = CrmPipeline::query()->forBusiness($business)->max('position');

            $pipeline = CrmPipeline::create([
                'business_id' => $business->id,
                'name' => $name,
                'position' => $last === null ? 0 : (int) $last + 1,
                'template_key' => $template->key,
                'template_version' => $template->version,
                'template_pipeline_key' => $blueprint->key,
                'created_by_user_id' => $actorUserId,
            ]);

            foreach ($blueprint->stages as $index => $stage) {
                CrmPipelineStage::create([
                    'business_id' => $business->id,
                    'pipeline_id' => $pipeline->id,
                    'name' => $stage->name,
                    'semantic_key' => $stage->semanticKey,
                    'position' => $index,
                ]);
            }

            return $pipeline;
        });
    }
}
