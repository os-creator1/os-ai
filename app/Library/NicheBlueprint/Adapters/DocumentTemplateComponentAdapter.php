<?php

namespace App\Library\NicheBlueprint\Adapters;

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
final class DocumentTemplateComponentAdapter implements BlueprintComponentAdapter
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
}
