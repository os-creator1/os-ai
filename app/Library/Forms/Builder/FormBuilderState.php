<?php

namespace App\Library\Forms\Builder;

use App\Enums\Forms\FormFieldType;
use App\Library\CustomFields\CustomFieldDefinitionManager;
use App\Library\CustomFields\FormFieldMapping;
use App\Models\Business;
use App\Models\CrmPipeline;
use App\Models\Form;
use App\Models\FormVersion;

/**
 * The adapter between a stored FormVersion and the visual editor's document.
 *
 * NO NEW STORAGE. The editor edits exactly the content a version already holds —
 * name, intro, button label, thank-you message, style, ordered pages and the flat
 * list of elements (each carrying its page key) — so every existing form opens
 * in the builder as it is and no data is migrated. A legacy version simply has
 * none of the optional keys (placeholder, help, width, …); the editor treats
 * their absence as "not set". Saving goes through FormManager::update(), which
 * writes the next immutable version only when the content really changed.
 *
 * This class only READS. It is also the single place that shapes the JSON the
 * browser receives, so the page never has to trust or parse a Blade-rendered
 * structure.
 */
final class FormBuilderState
{
    public function __construct(
        private readonly FormToolbox $toolbox,
        private readonly CustomFieldDefinitionManager $customFields,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function forForm(Business $business, Form $form, FormVersion $version): array
    {
        // Every Custom Field the form might reference, ARCHIVED ones included, so a
        // mapping to a since-archived field still shows its name and a notice.
        $registry = [];
        foreach ($this->customFields->forBusiness($business, true) as $definition) {
            $registry[$definition->uid] = [
                'label' => (string) $definition->label,
                'type' => $definition->type,
                'token' => $definition->token(),
                'archived' => $definition->isArchived(),
            ];
        }

        return [
            'formUid' => $form->uid,
            'version' => (int) $form->current_version,
            'status' => $form->lifecycle_state->value,
            'doc' => [
                'name' => $form->name,
                'intro' => (string) ($version->intro ?? ''),
                'submit_label' => $version->submit_label,
                'success_message' => $version->success_message,
                'design' => (object) $version->style(),
                'create_opportunity' => (bool) $version->create_opportunity,
                'opportunity_pipeline_id' => $version->opportunity_pipeline_id,
                'pages' => array_map(
                    fn (array $page): array => ['key' => $page['key'], 'title' => (string) ($page['title'] ?? '')],
                    $version->pages(),
                ),
                'fields' => $this->fields($version),
            ],
            'toolbox' => $this->toolbox->groups($business),
            'customFields' => (object) $registry,
            // Which Custom Field types each element type may feed — the server's own
            // table (FormFieldMapping), so the picker never offers a mapping the
            // server would refuse.
            'compat' => collect(FormFieldType::cases())
                ->mapWithKeys(fn (FormFieldType $type): array => [$type->value => FormFieldMapping::compatibleTypes($type->value)])
                ->all(),
            'pipelines' => CrmPipeline::query()->forBusiness($business)->active()->orderBy('position')->orderBy('id')->get(['id', 'name'])
                ->map(fn ($pipeline): array => ['id' => (int) $pipeline->id, 'name' => (string) $pipeline->name])->all(),
        ];
    }

    /**
     * Elements in the editor's shape. The page key of a legacy field with none is
     * the version's first page (the same rule FormVersion::fieldsOnPage uses).
     *
     * @return list<array<string, mixed>>
     */
    private function fields(FormVersion $version): array
    {
        $first = $version->pageKeys()[0];

        return array_map(static function (array $field) use ($first): array {
            return $field + ['page' => $first, 'options' => [], 'contact_name' => false];
        }, array_values($version->fields ?? []));
    }
}
