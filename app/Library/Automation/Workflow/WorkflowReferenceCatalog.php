<?php

namespace App\Library\Automation\Workflow;

use App\Models\ContactGroupFields;

/**
 * Automations V2 §14.2 — every contact group and custom field ONE Business owns,
 * loaded once and answered from memory.
 *
 * A workflow document points at groups and fields by id. Whether each id belongs
 * to the workflow's Business is a tenancy question, and asking the database once
 * per reference makes the cost of that question grow with the size of the
 * workflow. This is the answer to all of them at once: WorkflowReferenceCatalogLoader
 * reads the Business's groups LEFT JOINed to their fields in a single statement,
 * and everything below is a lookup.
 *
 * Membership is the whole of the tenancy rule. The loader only ever reads rows
 * whose group carries this Business's id, so an id that is absent here is either
 * nonexistent or another Business's — and the two are deliberately
 * indistinguishable, so a refusal can never confirm that a foreign row exists.
 *
 * The same catalog serves the Builder's pickers, so a request that shows the
 * pickers AND validates the document pays for one read, not two. The picker
 * views below are partitions of the rows already held; none of them reads.
 */
final class WorkflowReferenceCatalog
{
    /**
     * @param array<int, array{id: int, name: string}> $groups keyed by id, in name order
     * @param array<int, array{id: int, contact_group_id: int, label: string, type: string, is_phone: bool}> $fields keyed by id, in id order
     */
    public function __construct(
        public readonly int $businessId,
        private readonly array $groups,
        private readonly array $fields,
    ) {
    }

    public function hasGroup(int $groupId): bool
    {
        return isset($this->groups[$groupId]);
    }

    /**
     * The field, when it belongs to this Business through its group.
     *
     * @return array{id: int, contact_group_id: int, label: string, type: string, is_phone: bool}|null
     */
    public function field(int $fieldId): ?array
    {
        return $this->fields[$fieldId] ?? null;
    }

    /** The field belongs to this Business AND to that one group of it. */
    public function fieldBelongsToGroup(int $fieldId, int $groupId): bool
    {
        return ($this->fields[$fieldId]['contact_group_id'] ?? null) === $groupId;
    }

    /**
     * Every group, including a group with no fields.
     *
     * @return list<array{id: int, name: string}>
     */
    public function groups(): array
    {
        return array_values($this->groups);
    }

    /**
     * Every field of every group.
     *
     * @return list<array{id: int, contact_group_id: int, label: string, type: string, is_phone: bool}>
     */
    public function fields(): array
    {
        return array_values($this->fields);
    }

    /**
     * The fields a date trigger can watch: date and datetime, the same set the
     * legacy date trigger and the Builder's date picker offer.
     *
     * @return list<array{id: int, contact_group_id: int, label: string, type: string, is_phone: bool}>
     */
    public function dateFields(): array
    {
        return array_values(array_filter(
            $this->fields,
            fn (array $field): bool => in_array($field['type'], [ContactGroupFields::TYPE_DATE, ContactGroupFields::TYPE_DATETIME], true),
        ));
    }

    /**
     * The fields a step may write. The phone field is a contact's identity, and
     * UpdateContactFieldNodeExecutor refuses to write it, so it is never offered.
     *
     * @return list<array{id: int, contact_group_id: int, label: string, type: string, is_phone: bool}>
     */
    public function writableFields(): array
    {
        return array_values(array_filter($this->fields, fn (array $field): bool => ! $field['is_phone']));
    }
}
