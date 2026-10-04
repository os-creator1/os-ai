<?php

namespace App\Library\NicheBlueprint\Adapters;

use App\Enums\NicheBlueprint\BlueprintUpdatePolicy;
use App\Models\Business;
use App\Models\DocumentTemplate;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Implementation Contract 17B §6b / Contract 20 §10 — the Proposal / Contract
 * template component adapter (`document_template`).
 *
 * REFERENCE ONLY, NOTHING IS COPIED. This is the deliberate exception to Contract
 * 20's "copy, never link" rule, and the only one: a platform document template
 * is not Business state, it is platform-owned canonical content that a Business
 * is merely RECOMMENDED. So `install()` creates no `document_templates` row and
 * writes nothing into the Business; it returns an InstalledComponentReference
 * that points at the platform template, which the installer records as
 * provenance (`installed_record_type = 'document_template'`,
 * `installed_record_id = <platform template id>`). The Business-facing effect
 * ("Recommended for your business") is computed live from the PUBLISHED
 * blueprint version by RecommendedPlatformTemplates — never from the
 * installation row — so an installation record neither grants nor is required
 * for a recommendation.
 *
 * Idempotent by construction (it writes nothing); the installer's own
 * (business, blueprint, component_key) record is still the one "already
 * installed?" authority.
 *
 * `validateDescriptor()` runs at blueprint PUBLISH time (§6.2 gate 5): the
 * payload must be `{template_uid: <uuid of an existing PLATFORM template>}`
 * with an optional `label`. A Business-owned template uid, an unknown uid or a
 * malformed one can therefore never be published into a blueprint. The
 * template's status is deliberately NOT checked: disabling a template takes
 * effect immediately for recommendations regardless of blueprint versions.
 */
final class DocumentTemplateComponentAdapter implements BlueprintComponentAdapter, BlueprintComponentDefinition
{
    public const TYPE = 'document_template';

    /** The PlatformFeature a document template component is gated on (`PlatformFeature::PaymentsContracts`). */
    public const FEATURE_KEY = 'payments_contracts';

    public function componentType(): string
    {
        return self::TYPE;
    }

    public function validateDescriptor(array $payload): void
    {
        $this->platformTemplate($payload);
        $this->paymentTerms($payload);

        $label = $payload['label'] ?? null;

        if ($label !== null && (! is_string($label) || mb_strlen($label) > 191)) {
            throw new InvalidArgumentException('A document_template component "label" must be a string of at most 191 characters.');
        }
    }

    public function install(Business $business, array $payload, ?int $actorUserId): InstalledComponentReference
    {
        // Reference-only: look the platform template up, create nothing.
        $template = $this->platformTemplate($payload);

        return new InstalledComponentReference(self::TYPE, (int) $template->id);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function platformTemplate(array $payload): DocumentTemplate
    {
        $uid = $payload['template_uid'] ?? null;

        if (! is_string($uid) || ! Str::isUuid($uid)) {
            throw new InvalidArgumentException('A document_template component payload must carry a "template_uid" (UUID).');
        }

        return DocumentTemplate::query()
            ->where('uid', $uid)
            ->whereNull('business_id')
            ->first()
            ?? throw new InvalidArgumentException('A document_template component must reference an existing platform template.');
    }

    /**
     * Payment-term DEFAULTS recorded beside the template recommendation:
     * deposit percent and balance-due offset. Guidance read through
     * BlueprintConfigReader; never applied to an invoice or payment.
     *
     * @return array{deposit_percent: ?int, balance_due_days_before_event: ?int, note: ?string}|null
     */
    private function paymentTerms(array $payload): ?array
    {
        $terms = $payload['payment_terms'] ?? null;

        if ($terms === null) {
            return null;
        }

        if (! is_array($terms)) {
            throw new InvalidArgumentException('"payment_terms" must be an object.');
        }

        foreach (['deposit_percent' => 100, 'balance_due_days_before_event' => 365] as $key => $max) {
            $v = $terms[$key] ?? null;

            if ($v !== null && (! is_int($v) || $v < 0 || $v > $max)) {
                throw new InvalidArgumentException("\"payment_terms.{$key}\" must be a whole number from 0 to {$max}.");
            }
        }

        $note = $terms['note'] ?? null;

        if ($note !== null && (! is_string($note) || mb_strlen($note) > 500)) {
            throw new InvalidArgumentException('"payment_terms.note" must be text of at most 500 characters.');
        }

        return [
            'deposit_percent' => $terms['deposit_percent'] ?? null,
            'balance_due_days_before_event' => $terms['balance_due_days_before_event'] ?? null,
            'note' => $note,
        ];
    }

    public function surface(): string
    {
        return 'documents';
    }

    public function updatePolicy(): BlueprintUpdatePolicy
    {
        return BlueprintUpdatePolicy::Live;
    }

    public function featureKey(): string
    {
        return self::FEATURE_KEY;
    }

    public function typeLabel(): string
    {
        return 'Proposal / contract template';
    }

    public function summary(array $payload): string
    {
        $template = DocumentTemplate::query()->where('uid', $payload['template_uid'] ?? '')->whereNull('business_id')->first();

        return ($template->name ?? 'Unknown template').(isset($payload['payment_terms']['deposit_percent'])
            ? ' · '.$payload['payment_terms']['deposit_percent'].'% deposit' : '');
    }

    public function formFields(): array
    {
        $templates = DocumentTemplate::query()->whereNull('business_id')->orderBy('name')->get()
            ->mapWithKeys(fn ($t) => [(string) $t->uid => (string) $t->name.' ('.($t->template_type->value ?? $t->template_type).')'])->all();

        return [
            ['name' => 'template_uid', 'label' => 'Platform template', 'type' => 'select', 'required' => true, 'options' => $templates],
            ['name' => 'label', 'label' => 'Label (optional)', 'type' => 'text', 'required' => false],
            ['name' => 'deposit_percent', 'label' => 'Default deposit %', 'type' => 'number', 'required' => false],
            ['name' => 'balance_due_days_before_event', 'label' => 'Balance due (days before event)', 'type' => 'number', 'required' => false],
            ['name' => 'payment_note', 'label' => 'Payment terms note', 'type' => 'text', 'required' => false],
        ];
    }

    public function payloadFromInput(array $input): array
    {
        $payload = ['template_uid' => trim((string) ($input['template_uid'] ?? ''))];

        if (trim((string) ($input['label'] ?? '')) !== '') {
            $payload['label'] = trim((string) $input['label']);
        }

        $terms = [];

        foreach (['deposit_percent', 'balance_due_days_before_event'] as $key) {
            if (trim((string) ($input[$key] ?? '')) !== '') {
                $terms[$key] = (int) $input[$key];
            }
        }

        if (trim((string) ($input['payment_note'] ?? '')) !== '') {
            $terms['note'] = trim((string) $input['payment_note']);
        }

        if ($terms !== []) {
            $payload['payment_terms'] = $terms;
        }

        $this->validateDescriptor($payload);

        return $payload;
    }

    public function inputFromPayload(array $payload): array
    {
        return [
            'template_uid' => $payload['template_uid'] ?? '',
            'label' => $payload['label'] ?? '',
            'deposit_percent' => $payload['payment_terms']['deposit_percent'] ?? '',
            'balance_due_days_before_event' => $payload['payment_terms']['balance_due_days_before_event'] ?? '',
            'payment_note' => $payload['payment_terms']['note'] ?? '',
        ];
    }
}