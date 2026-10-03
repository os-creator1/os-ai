<?php

namespace App\Library\Documents\Templates;

use App\Enums\Documents\DocumentTemplateStatus;
use App\Library\Documents\Blocks\BlockSchema;
use App\Library\Documents\Blocks\DocumentMergeFields;
use App\Library\Documents\Editor\DocumentEditorToolbox;
use App\Models\Business;
use App\Models\DocumentTemplate;

/**
 * Implementation Contract 17B §6/§8b — the bootstrap JSON the ONE visual editor
 * opens a TEMPLATE with (`mode: 'template'`; the platform-template stage passes
 * `'platform_template'` and no Business).
 *
 * It deliberately carries none of a document's commerce: lines, totals,
 * schedule and plan are empty, there is no delivery / contact / send data, and
 * the editor draws the product and payment areas as generic placeholders.
 */
final class DocumentTemplateEditorState
{
    /**
     * @param  array<string, string>  $urls  library, edit, blocks, preview
     * @return array<string, mixed>
     */
    public function bootstrap(DocumentTemplate $template, ?Business $business, array $urls, string $mode = 'template'): array
    {
        $editable = $template->status !== DocumentTemplateStatus::Archived;

        return [
            'mode' => $mode,
            'template' => [
                'uid' => (string) $template->uid,
                'name' => (string) $template->name,
                'description' => $template->description,
                'type' => $template->template_type->value,
                'status' => $template->status->value,
                'is_platform' => $template->isPlatformOwned(),
            ],
            // The store reads these; a template has no recipient and no money.
            'document' => [
                'uid' => (string) $template->uid,
                'title' => (string) $template->name,
                'kind' => 'proposal',
                'status' => 'template',
                'requires_signature' => false,
                'currency_code' => (string) ($business?->currency_code ?: 'USD'),
                'recipient' => ['name' => null, 'email' => null, 'phone' => null],
            ],
            'editable' => $editable,
            'is_block_document' => true,
            'is_legacy' => false,
            'can_upgrade' => false,
            'blocks' => is_array($template->blocks) ? array_values($template->blocks) : [],
            'lock_version' => (int) $template->lock_version,
            'currency_code' => (string) ($business?->currency_code ?: 'USD'),
            'lines' => [],
            'totals' => ['subtotal_minor' => 0, 'total_minor' => 0],
            'schedule' => [],
            'plan' => null,
            'plan_invalid' => false,
            'plan_error' => null,
            'delivery' => [],
            'contact' => ['name' => '', 'email' => ''],
            'business' => ['name' => (string) ($business?->name ?? '')],
            'images' => $business === null ? [] : DocumentEditorToolbox::images($business),
            'merge_samples' => DocumentMergeFields::sample(),
            'toolbox' => [
                'categories' => DocumentEditorToolbox::categories(),
                'block_types' => BlockSchema::TYPES,
                'merge_fields' => DocumentMergeFields::catalog(),
                'limits' => ['max_blocks' => BlockSchema::MAX_BLOCKS, 'max_bytes' => BlockSchema::MAX_BYTES, 'max_run_text' => BlockSchema::MAX_RUN_TEXT],
                'plan' => ['structures' => ['full', 'deposit'], 'full_due' => ['on_signing', 'date'], 'balance_due' => ['after_deposit', 'date']],
            ],
            'urls' => $urls,
        ];
    }
}
