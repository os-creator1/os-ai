<?php

namespace App\Library\Automation\Workflow;

use App\Models\Business;
use Illuminate\Support\Facades\DB;

/**
 * Loads a WorkflowReferenceCatalog in exactly ONE statement, whatever the size
 * of the Business or of any workflow that will be checked against it.
 *
 * Four families of reference live in a workflow document, and all are read here
 * at once, as four halves of one UNION ALL:
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
                DB::raw('null as extra_int'),
                DB::raw('null as extra_text'),
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
                DB::raw('null as extra_int'),
                DB::raw('null as extra_text'),
            ]);

        // Contact tags and forms: one row each, in the parent columns (a tag's
        // `archived_at` is its parent archive flag; a form's lifecycle state rides
        // in `field_type`, the one free text column a parent-only row leaves null).
        $tag = DB::table('tags')
            ->where('tags.business_id', $businessId)
            ->select([
                DB::raw("'tag' as source"),
                DB::raw('0 as parent_position'),
                'tags.id as parent_id',
                'tags.name as parent_name',
                'tags.archived_at as parent_archived_at',
                DB::raw('0 as child_position'),
                DB::raw('null as child_id'),
                DB::raw('null as child_name'),
                DB::raw('null as field_type'),
                DB::raw('null as field_is_phone'),
                DB::raw('null as stage_semantic_key'),
                DB::raw('null as child_archived_at'),
                DB::raw('null as extra_int'),
                DB::raw('null as extra_text'),
            ]);

        $form = DB::table('forms')
            ->where('forms.business_id', $businessId)
            ->select([
                DB::raw("'form' as source"),
                DB::raw('0 as parent_position'),
                'forms.id as parent_id',
                'forms.name as parent_name',
                DB::raw('null as parent_archived_at'),
                DB::raw('0 as child_position'),
                DB::raw('null as child_id'),
                DB::raw('null as child_name'),
                'forms.lifecycle_state as field_type',
                DB::raw('null as field_is_phone'),
                DB::raw('null as stage_semantic_key'),
                DB::raw('null as child_archived_at'),
                // The page count of the form's CURRENT version: one page is a form, two
                // or more a questionnaire (Forms V1 has one definition for both). A
                // version that predates pages reads as one page.
                DB::raw('COALESCE((SELECT JSON_LENGTH(fv.pages) FROM form_versions fv WHERE fv.form_id = forms.id AND fv.version = forms.current_version LIMIT 1), 1) as extra_int'),
                DB::raw('null as extra_text'),
            ]);

        // The Business's Locations: one row each. Its lifecycle state rides in
        // `field_type`, as a form's does.
        $location = DB::table('business_locations')
            ->where('business_locations.business_id', $businessId)
            ->select([
                DB::raw("'location' as source"),
                DB::raw('0 as parent_position'),
                'business_locations.id as parent_id',
                DB::raw("COALESCE(business_locations.name, '') as parent_name"),
                DB::raw('null as parent_archived_at'),
                DB::raw('0 as child_position'),
                DB::raw('null as child_id'),
                DB::raw('null as child_name'),
                'business_locations.lifecycle_state as field_type',
                DB::raw('null as field_is_phone'),
                DB::raw('null as stage_semantic_key'),
                DB::raw('null as child_archived_at'),
                DB::raw('null as extra_int'),
                DB::raw('null as extra_text'),
            ]);

        // Booking types: a booking type belongs to one Location (booking_types has no
        // business_id of its own, so the Business is read through that Location). The
        // Location rides in `extra_int` and whether it is active in `extra_text`.
        $booking = DB::table('booking_types')
            ->join('business_locations as bt_loc', 'bt_loc.id', '=', 'booking_types.business_location_id')
            ->where('bt_loc.business_id', $businessId)
            ->select([
                DB::raw("'booking_type' as source"),
                DB::raw('0 as parent_position'),
                'booking_types.id as parent_id',
                'booking_types.name as parent_name',
                DB::raw('null as parent_archived_at'),
                DB::raw('0 as child_position'),
                DB::raw('null as child_id'),
                DB::raw('null as child_name'),
                DB::raw('null as field_type'),
                DB::raw('null as field_is_phone'),
                DB::raw('null as stage_semantic_key'),
                DB::raw('null as child_archived_at'),
                'booking_types.business_location_id as extra_int',
                DB::raw("CASE WHEN booking_types.is_active = 1 THEN '1' ELSE '0' END as extra_text"),
            ]);

        // Catalog items (products, packages, services): the type rides in
        // `field_type`, the unit price in `extra_int` and the lifecycle state in
        // `extra_text`; an archived item is flagged by its archive time.
        $catalog = DB::table('catalog_items')
            ->where('catalog_items.business_id', $businessId)
            ->select([
                DB::raw("'catalog_item' as source"),
                DB::raw('0 as parent_position'),
                'catalog_items.id as parent_id',
                'catalog_items.name as parent_name',
                'catalog_items.archived_at as parent_archived_at',
                DB::raw('0 as child_position'),
                DB::raw('null as child_id'),
                DB::raw('null as child_name'),
                'catalog_items.type as field_type',
                DB::raw('null as field_is_phone'),
                DB::raw('null as stage_semantic_key'),
                DB::raw('null as child_archived_at'),
                'catalog_items.price_minor as extra_int',
                'catalog_items.lifecycle_state as extra_text',
            ]);

        // Business-wide Custom Fields (Contact scope), archived ones flagged: the
        // same statement carries them so the Builder's condition picker, the merge
        // picker and the compiler's reference checks cost no query of their own.
        // The key rides in `child_name`, the type in `field_type` and the options
        // (JSON text) in the one free text column, `stage_semantic_key`.
        $custom = DB::table('custom_field_definitions')
            ->where('custom_field_definitions.business_id', $businessId)
            ->where('custom_field_definitions.entity', 'contact')
            ->select([
                DB::raw("'custom' as source"),
                'custom_field_definitions.position as parent_position',
                'custom_field_definitions.id as parent_id',
                'custom_field_definitions.label as parent_name',
                'custom_field_definitions.archived_at as parent_archived_at',
                DB::raw('0 as child_position'),
                DB::raw('null as child_id'),
                'custom_field_definitions.key as child_name',
                'custom_field_definitions.type as field_type',
                DB::raw('null as field_is_phone'),
                DB::raw('CAST(custom_field_definitions.options AS CHAR) as stage_semantic_key'),
                DB::raw('null as child_archived_at'),
                DB::raw('null as extra_int'),
                DB::raw('null as extra_text'),
            ]);

        // Contact groups by name (as before); pipelines and their stages in the
        // order the CRM board shows them; tags, forms and Locations by name; custom fields
        // in their configured order.
        $rows = $contact->unionAll($crm)->unionAll($tag)->unionAll($form)->unionAll($custom)->unionAll($location)->unionAll($booking)->unionAll($catalog)
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
        $tags = [];
        $forms = [];
        $customFields = [];
        $locations = [];
        $bookingTypes = [];
        $catalogItems = [];

        foreach ($rows as $row) {
            $parentId = (int) $row->parent_id;

            if ($row->source === 'custom') {
                $options = is_string($row->stage_semantic_key) ? json_decode($row->stage_semantic_key, true) : null;

                $customFields[(string) $row->child_name] = [
                    'id' => $parentId,
                    'key' => (string) $row->child_name,
                    'label' => (string) $row->parent_name,
                    'type' => (string) $row->field_type,
                    'archived' => $row->parent_archived_at !== null,
                    'options' => is_array($options) ? array_values($options) : [],
                ];

                continue;
            }

            if ($row->source === 'booking_type') {
                $bookingTypes[$parentId] = [
                    'id' => $parentId,
                    'name' => (string) $row->parent_name,
                    'location_id' => (int) $row->extra_int,
                    'active' => (string) $row->extra_text === '1',
                ];

                continue;
            }

            if ($row->source === 'catalog_item') {
                $catalogItems[$parentId] = [
                    'id' => $parentId,
                    'name' => (string) $row->parent_name,
                    'type' => (string) $row->field_type,
                    'price_minor' => $row->extra_int === null ? null : (int) $row->extra_int,
                    'active' => (string) $row->extra_text === 'active' && $row->parent_archived_at === null,
                ];

                continue;
            }

            if ($row->source === 'location') {
                $locations[$parentId] = [
                    'id' => $parentId,
                    'name' => (string) $row->parent_name,
                    'active' => (string) $row->field_type === \App\Enums\Business\BusinessLocationLifecycleState::Active->value,
                ];

                continue;
            }

            if ($row->source === 'tag') {
                $tags[$parentId] = [
                    'id' => $parentId,
                    'name' => (string) $row->parent_name,
                    'archived' => $row->parent_archived_at !== null,
                ];

                continue;
            }

            if ($row->source === 'form') {
                $forms[$parentId] = [
                    'id' => $parentId,
                    'name' => (string) $row->parent_name,
                    'lifecycle' => (string) $row->field_type,
                    'pages' => max(1, (int) $row->extra_int),
                ];

                continue;
            }

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

        return new WorkflowReferenceCatalog($businessId, $groups, $fields, $pipelines, $stages, $tags, $forms, $customFields, $locations, $bookingTypes, $catalogItems);
    }
}
