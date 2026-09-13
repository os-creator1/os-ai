<?php

namespace App\Library\Automation\Workflow;

use App\Models\Business;
use App\Models\ContactGroups;

/**
 * Loads a WorkflowReferenceCatalog in exactly ONE statement, whatever the size
 * of the Business or of any workflow that will be checked against it.
 *
 * Groups LEFT JOIN fields, filtered on the group's business_id: a group with no
 * fields still arrives (that is what the LEFT JOIN is for), and every field
 * arrives with the group — and therefore the Business — it belongs to, plus the
 * type that decides its operator family. There is no second read for either.
 */
class WorkflowReferenceCatalogLoader
{
    public function forBusiness(Business|int $business): WorkflowReferenceCatalog
    {
        $businessId = $business instanceof Business ? (int) $business->id : $business;

        $rows = ContactGroups::query()
            ->leftJoin('contact_group_fields', 'contact_group_fields.contact_group_id', '=', 'contact_groups.id')
            ->where('contact_groups.business_id', $businessId)
            ->orderBy('contact_groups.name')
            ->orderBy('contact_groups.id')
            ->orderBy('contact_group_fields.id')
            ->toBase()
            ->get([
                'contact_groups.id as group_id',
                'contact_groups.name as group_name',
                'contact_group_fields.id as field_id',
                'contact_group_fields.label as field_label',
                'contact_group_fields.type as field_type',
                'contact_group_fields.is_phone as field_is_phone',
            ]);

        $groups = [];
        $fields = [];

        foreach ($rows as $row) {
            $groupId = (int) $row->group_id;

            $groups[$groupId] ??= ['id' => $groupId, 'name' => (string) $row->group_name];

            if ($row->field_id === null) {
                continue;
            }

            $fieldId = (int) $row->field_id;

            $fields[$fieldId] = [
                'id' => $fieldId,
                'contact_group_id' => $groupId,
                'label' => (string) $row->field_label,
                'type' => (string) $row->field_type,
                'is_phone' => (bool) $row->field_is_phone,
            ];
        }

        ksort($fields);

        return new WorkflowReferenceCatalog($businessId, $groups, $fields);
    }
}
