<?php

namespace App\Http\Controllers\Customer\Business;

use App\Enums\CustomFields\CustomFieldType;
use App\Enums\Entitlement\PlatformFeature;
use App\Http\Controllers\Customer\Business\Concerns\ResolvesBusinessTenancy;
use App\Http\Controllers\Customer\CustomerBaseController;
use App\Library\CustomFields\CustomFieldDefinitionManager;
use App\Library\CustomFields\CustomFieldRuleException;
use App\Models\Business;
use App\Models\CustomFieldDefinition;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Settings → Custom fields: the Business-wide definitions (create, rename,
 * archive/restore, reorder). Every rule lives in CustomFieldDefinitionManager;
 * this controller resolves tenancy, authorizes, and renders its answer.
 *
 * Permissions reuse `view_contact` / `update_contact` (no new permission
 * strings), like Contact Tags. A field uid of another Business is a 404.
 */
class CustomFieldsController extends CustomerBaseController
{
    use ResolvesBusinessTenancy;

    private const VIEW_PERMISSION = 'view_contact';

    private const MANAGE_PERMISSION = 'update_contact';

    public function __construct(private readonly CustomFieldDefinitionManager $definitions)
    {
    }

    public function list(string $workspaceUid, string $businessUid): View
    {
        [$workspace, $business] = $this->tenancy($workspaceUid, $businessUid);
        $this->authorize(self::VIEW_PERMISSION);

        return view('customer.settings.custom-fields.index', [
            'workspace' => $workspace,
            'business' => $business,
            'fields' => $this->definitions->forBusiness($business, true),
            'types' => CustomFieldType::cases(),
        ]);
    }

    public function store(string $workspaceUid, string $businessUid, Request $request): RedirectResponse
    {
        [, $business] = $this->tenancy($workspaceUid, $businessUid);
        $this->authorize(self::MANAGE_PERMISSION);

        $data = $request->validate([
            'label' => ['required', 'string', 'max:200'],
            'type' => ['required', 'string', 'in:' . implode(',', CustomFieldType::values())],
            'options' => ['nullable', 'string', 'max:5000'],
        ]);

        try {
            $field = $this->definitions->create($business, $data['label'], $data['type'], $this->optionLines($data['options'] ?? ''));
        } catch (CustomFieldRuleException $exception) {
            return back()->withInput()->withErrors(['custom_field' => $exception->getMessage()]);
        }

        return back()->with('flash_success', sprintf('"%s" added. Use it as %s.', $field->label, $field->token()));
    }

    public function update(string $workspaceUid, string $businessUid, string $fieldUid, Request $request): RedirectResponse
    {
        [, $business] = $this->tenancy($workspaceUid, $businessUid);
        $this->authorize(self::MANAGE_PERMISSION);
        $field = $this->field($business, $fieldUid);

        $data = $request->validate([
            'label' => ['required', 'string', 'max:200'],
            'options' => ['nullable', 'array', 'max:60'],
            'options.*.id' => ['nullable', 'string', 'max:40'],
            'options.*.label' => ['nullable', 'string', 'max:200'],
            'new_options' => ['nullable', 'string', 'max:5000'],
        ]);

        try {
            $this->definitions->update(
                $business,
                $field,
                $data['label'],
                $field->fieldType()->hasOptions() ? $this->editedOptions($data) : null,
            );
        } catch (CustomFieldRuleException $exception) {
            return back()->withInput()->withErrors(['custom_field' => $exception->getMessage()]);
        }

        return back()->with('flash_success', 'Saved. The merge field ' . $field->token() . ' did not change.');
    }

    public function archive(string $workspaceUid, string $businessUid, string $fieldUid): RedirectResponse
    {
        [, $business] = $this->tenancy($workspaceUid, $businessUid);
        $this->authorize(self::MANAGE_PERMISSION);

        $this->definitions->archive($business, $this->field($business, $fieldUid));

        return back()->with('flash_success', 'Archived. Existing messages and automations that use it keep working.');
    }

    public function restore(string $workspaceUid, string $businessUid, string $fieldUid): RedirectResponse
    {
        [, $business] = $this->tenancy($workspaceUid, $businessUid);
        $this->authorize(self::MANAGE_PERMISSION);

        $this->definitions->restore($business, $this->field($business, $fieldUid));

        return back()->with('flash_success', 'Restored.');
    }

    public function move(string $workspaceUid, string $businessUid, string $fieldUid, Request $request): RedirectResponse
    {
        [, $business] = $this->tenancy($workspaceUid, $businessUid);
        $this->authorize(self::MANAGE_PERMISSION);
        $data = $request->validate(['direction' => ['required', 'in:up,down']]);

        $this->definitions->move($business, $this->field($business, $fieldUid), $data['direction']);

        return back();
    }

    /** @return array{0: \App\Models\Workspace, 1: Business} */
    private function tenancy(string $workspaceUid, string $businessUid): array
    {
        return $this->resolveEntitledBusinessTenancy($workspaceUid, $businessUid, PlatformFeature::Crm->value);
    }

    private function field(Business $business, string $uid): CustomFieldDefinition
    {
        return $this->definitions->findByUid($business, $uid) ?? abort(404);
    }

    /**
     * Editing: each existing option posts its stable id with an editable label
     * (renaming keeps the id, so stored values survive; a blank label removes
     * the option), and `new_options` appends one new option per line.
     *
     * @param  array<string, mixed>  $data
     * @return list<array{id?: string, label: string}>
     */
    private function editedOptions(array $data): array
    {
        $options = [];

        foreach ($data['options'] ?? [] as $row) {
            if (trim((string) ($row['label'] ?? '')) !== '') {
                $options[] = ['id' => (string) ($row['id'] ?? ''), 'label' => (string) $row['label']];
            }
        }

        return array_merge($options, $this->optionLines((string) ($data['new_options'] ?? '')));
    }

    /**
     * One option per line (a new field's initial options).
     *
     * @return list<array{label: string}>
     */
    private function optionLines(string $text): array
    {
        $options = [];

        foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $line) {
            if (trim($line) !== '') {
                $options[] = ['label' => trim($line)];
            }
        }

        return $options;
    }
}
