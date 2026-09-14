<?php

namespace App\Library\Automation\Workflow;

use App\Models\Business;
use Illuminate\Support\Facades\DB;

/**
 * Loads a WorkflowReferenceCatalog in exactly ONE statement, whatever the size
 * of the Business or of any workflow that will be checked against it.
 *
 * Two families of reference live in a workflow document, and both are read here
 * at once, as two halves of one UNION ALL:
 *
 *   CONTACT  groups LEFT JOIN fields, filtered on the group's business_id: a
 *            group with no fields still arrives (that is what the LEFT JOIN is
 *            for), and every field arrives with the group — and therefore the
 *            Business — it belongs to, plus the type that decides its operator
 *            family.
 *   CRM      sales pipelines LEFT JOIN their stages, filtered on the pipeline's
 *            business_id — the CRM Opportunities domain (crm_*), never the AI COO
 *            Advisor's `opportunities`. Archived rows arrive too, flagged, so a
 *            workflow still pointing at one is told it is archived rather than
 *            that it does not exist.
 *
 * One statement rather than two because the Builder reads this catalog on every
 * load, and §18 holds that load to a fixed number of feature-owned queries: the
 * CRM pickers must not cost a query of their own. Each half selects the same
 * columns under neutral names, and `source` says which half a row came from.
 */
class WorkflowReferenceCatalogLoader
{
    public function forBusiness(Business|int $business): WorkflowReferenceCatalog
    {
        $businessId = $business instanceof Business ? (int) $business->id : $business;

        $contact = DB::table('contact_groups')
            ->leftJoin('contact_group_fields', 'contact_group_fields.contact_group_id', '=', 'contact_groups.id')
            ->where('contact_groups.business_id', $businessId)
            ->select([
                DB::raw("'contact' as source"),
                DB::raw('0 as parent_position'),
                'contact_groups.id as parent_id',
                'contact_groups.name as parent_name',
                DB::raw('null as parent_archived_at'),
                DB::raw('0 as child_position'),
                'contact_group_fields.id as child_id',
                'contact_group_fields.label as child_name',
                'contact_group_fields.type as field_type',
                'contact_group_fields.is_phone as field_is_phone',
                DB::raw('null as stage_semantic_key'),
                DB::raw('null as child_archived_at'),
            ]);

        $crm = DB::table('crm_pipelines')
            ->leftJoin('crm_pipeline_stages', 'crm_pipeline_stages.pipeline_id', '=', 'crm_pipelines.id')
            ->where('crm_pipelines.business_id', $businessId)
            ->select([
                DB::raw("'crm' as source"),
                'crm_pipelines.position as parent_position',
                'crm_pipelines.id as parent_id',
                'crm_pipelines.name as parent_name',
                'crm_pipelines.archived_at as parent_archived_at',
                'crm_pipeline_stages.position as child_position',
                'crm_pipeline_stages.id as child_id',
                'crm_pipeline_stages.name as child_name',
                DB::raw('null as field_type'),
                DB::raw('null as field_is_phone'),
                'crm_pipeline_stages.semantic_key as stage_semantic_key',
                'crm_pipeline_stages.archived_at as child_archived_at',
            ]);

        // Contact groups by name (as before); pipelines and their stages in the
        // order the CRM board shows them.
        $rows = $contact->unionAll($crm)
            ->orderBy('source')
            ->orderBy('parent_position')
            ->orderBy('parent_name')
            ->orderBy('parent_id')
            ->orderBy('child_position')
            ->orderBy('child_id')
            ->get();

        $groups = [];
        $fields = [];
        $pipelines = [];
        $stages = [];

        foreach ($rows as $row) {
            $parentId = (int) $row->parent_id;

            if ($row->source === 'crm') {
                $pipelines[$parentId] ??= [
                    'id' => $parentId,
                    'name' => (string) $row->parent_name,
                    'archived' => $row->parent_archived_at !== null,
                ];

                if ($row->child_id !== null) {
                    $stages[(int) $row->child_id] = [
                        'id' => (int) $row->child_id,
                        'pipeline_id' => $parentId,
                        'name' => (string) $row->child_name,
                        'semantic_key' => $row->stage_semantic_key === null ? null : (string) $row->stage_semantic_key,
                        'archived' => $row->child_archived_at !== null,
                    ];
                }

                continue;
            }

            $groups[$parentId] ??= ['id' => $parentId, 'name' => (string) $row->parent_name];

            if ($row->child_id === null) {
                continue;
            }

            $fieldId = (int) $row->child_id;

            $fields[$fieldId] = [
                'id' => $fieldId,
                'contact_group_id' => $parentId,
                'label' => (string) $row->child_name,
                'type' => (string) $row->field_type,
                'is_phone' => (bool) $row->field_is_phone,
            ];
        }

        ksort($fields);

        return new WorkflowReferenceCatalog($businessId, $groups, $fields, $pipelines, $stages);
    }
}
