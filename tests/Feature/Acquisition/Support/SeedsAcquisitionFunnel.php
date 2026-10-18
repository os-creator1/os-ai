<?php

namespace Tests\Feature\Acquisition\Support;

use App\Models\Business;
use App\Models\CrmPipeline;
use App\Models\CrmPipelineStage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Raw-table seeders for the Acquisition / Ads-decision tests: a pipeline with
 * stages, contacts whose FIRST attribution touch carries a provider tag, and the
 * opportunities those contacts opened. Direct inserts keep the fixtures
 * explicit about exactly what the readers see.
 */
trait SeedsAcquisitionFunnel
{
    /**
     * @param  list<array{0: string, 1: ?string}>  $stages  [name, semantic key] in order
     */
    protected function seedPipeline(Business $business, string $name, array $stages): CrmPipeline
    {
        $pipeline = CrmPipeline::create(['business_id' => $business->id, 'name' => $name, 'position' => (int) CrmPipeline::query()->where('business_id', $business->id)->count()]);

        foreach ($stages as $i => [$stageName, $key]) {
            CrmPipelineStage::create(['business_id' => $business->id, 'pipeline_id' => $pipeline->id, 'name' => $stageName, 'semantic_key' => $key, 'position' => $i]);
        }

        return $pipeline->fresh();
    }

    /**
     * One lead: a contact, its first touch (utm_source / click id) and an
     * opportunity in the pipeline at the given stage position and status.
     *
     * @param  array<string, mixed>  $touch  columns of lead_attribution_touches (utm_source, gclid ...); empty = no touch
     */
    protected function seedLead(Business $business, CrmPipeline $pipeline, int $stagePosition, string $status, array $touch, string $createdAt = '2026-09-20 10:00:00'): int
    {
        $contactId = DB::table('contacts')->insertGetId([
            'uid' => (string) Str::uuid(),
            'customer_id' => $business->customer_id,
            'business_id' => $business->id,
            'phone' => '+1555' . random_int(1000000, 9999999),
            'status' => 'subscribe',
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        if ($touch !== []) {
            DB::table('lead_attribution_touches')->insert($touch + [
                'uid' => (string) Str::uuid(),
                'business_id' => $business->id,
                'contact_id' => $contactId,
                'subject_type' => 'form_submission',
                'subject_id' => $contactId,
                'entry_surface' => 'public_form',
                'touch_role' => 'first',
                'captured_at' => $createdAt,
                'recorded_at' => $createdAt,
            ]);
        }

        $stageId = (int) CrmPipelineStage::query()->where('pipeline_id', $pipeline->id)->where('position', $stagePosition)->value('id');

        return DB::table('crm_opportunities')->insertGetId([
            'uid' => (string) Str::uuid(),
            'business_id' => $business->id,
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stageId,
            'contact_id' => $contactId,
            'title' => 'Lead ' . $contactId,
            'status' => $status,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }
}
