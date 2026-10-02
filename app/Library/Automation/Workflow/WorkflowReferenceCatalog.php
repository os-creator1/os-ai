<?php

namespace App\Library\Automation\Workflow;

use App\Models\ContactGroupFields;

/**
 * Automations V2 §14.2 — every contact group and custom field ONE Business owns,
 * and every CRM sales pipeline and stage it owns, loaded once and answered from
 * memory.
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
     * @param array<int, array{id: int, name: string, archived: bool}> $pipelines CRM sales pipelines, keyed by id, in board order
     * @param array<int, array{id: int, pipeline_id: int, name: string, semantic_key: ?string, archived: bool}> $stages CRM stages, keyed by id, in board order
     * @param array<int, array{id: int, name: string, archived: bool}> $tags contact tags, keyed by id, in name order
     * @param array<int, array{id: int, name: string, lifecycle: string}> $forms forms, keyed by id, in name order
     * @param array<int, array{id: int, name: string, active: bool}> $locations the Business's Locations, keyed by id, in name order
     */
    public function __construct(
        public readonly int $businessId,
        private readonly array $groups,
        private readonly array $fields,
        private readonly array $pipelines = [],
        private readonly array $stages = [],
        private readonly array $tags = [],
        private readonly array $forms = [],
        private readonly array $locations = [],
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

    // ---------------------------------------------------------------
    // Locations — what a workflow can be bound to
    // ---------------------------------------------------------------

    /**
     * The Location, when it belongs to this Business — active or archived.
     * Foreign and nonexistent read the same: absent.
     *
     * @return array{id: int, name: string, active: bool}|null
     */
    public function location(int $locationId): ?array
    {
        return $this->locations[$locationId] ?? null;
    }

    /**
     * Every Location of the Business, archived ones flagged, for the scope picker.
     *
     * @return list<array{id: int, name: string, active: bool}>
     */
    public function locations(): array
    {
        return array_values($this->locations);
    }

    // ---------------------------------------------------------------
    // Contact tags and forms (Business-wide; never Location-scoped here)
    // ---------------------------------------------------------------

    /**
     * The tag, when it belongs to this Business — archived or not.
     *
     * @return array{id: int, name: string, archived: bool}|null
     */
    public function tag(int $tagId): ?array
    {
        return $this->tags[$tagId] ?? null;
    }

    /**
     * Every tag, archived ones flagged: the Builder offers the active ones and
     * keeps an archived one visible only where a workflow still names it.
     *
     * @return list<array{id: int, name: string, archived: bool}>
     */
    public function tags(): array
    {
        return array_values($this->tags);
    }

    /**
     * The form, when it belongs to this Business, in any lifecycle state.
     *
     * @return array{id: int, name: string, lifecycle: string}|null
     */
    public function form(int $formId): ?array
    {
        return $this->forms[$formId] ?? null;
    }

    /**
     * Every form of the Business, for the "Form submitted" trigger's filter.
     *
     * @return list<array{id: int, name: string, lifecycle: string}>
     */
    public function forms(): array
    {
        return array_values($this->forms);
    }

    // ---------------------------------------------------------------
    // CRM sales pipelines and stages (crm_* — never the Advisor domain)
    // ---------------------------------------------------------------

    /**
     * The pipeline, when it belongs to this Business — archived or not.
     *
     * @return array{id: int, name: string, archived: bool}|null
     */
    public function pipeline(int $pipelineId): ?array
    {
        return $this->pipelines[$pipelineId] ?? null;
    }

    /**
     * The stage, when it belongs to this Business through its pipeline.
     *
     * @return array{id: int, pipeline_id: int, name: string, semantic_key: ?string, archived: bool}|null
     */
    public function stage(int $stageId): ?array
    {
        return $this->stages[$stageId] ?? null;
    }

    /**
     * Every pipeline, archived ones flagged, in board order — the Builder shows
     * the active ones and keeps an archived one visible only where a workflow
     * still names it.
     *
     * @return list<array{id: int, name: string, archived: bool}>
     */
    public function pipelines(): array
    {
        return array_values($this->pipelines);
    }

    /**
     * Every stage of every pipeline, archived ones flagged, in board order.
     *
     * @return list<array{id: int, pipeline_id: int, name: string, semantic_key: ?string, archived: bool}>
     */
    public function stages(): array
    {
        return array_values($this->stages);
    }
}
