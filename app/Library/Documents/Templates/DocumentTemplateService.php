<?php

namespace App\Library\Documents\Templates;

use App\Enums\Documents\DocumentStatus;
use App\Enums\Documents\DocumentTemplateStatus;
use App\Enums\Documents\DocumentTemplateType;
use App\Enums\Documents\DocumentVersionState;
use App\Exceptions\Documents\DocumentTemplateConflictException;
use App\Exceptions\Documents\DocumentTemplateRefusedException;
use App\Library\Documents\Blocks\BlockSchema;
use App\Library\Documents\Blocks\DocumentMergeFields;
use App\Library\Documents\DocumentManager;
use App\Library\Documents\Editor\DocumentEditorState;
use App\Models\Business;
use App\Models\BusinessDocument;
use App\Models\BusinessDocumentVersion;
use App\Models\CatalogItemImage;
use App\Models\DocumentTemplate;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Implementation Contract 17B §6 — everything a template does.
 *
 * THE RULE (product owner): SAVE THE LAYOUT, NOT THE PRODUCT OR CONTACT.
 * A template holds layout, block order, styling, text, headings,
 * Business-owned catalog images, merge-field TOKENS, the signature block's
 * position and a generic `product_list` placeholder (presentation flags only).
 * It never holds a Contact, a recipient, a product / package, line items,
 * prices, a deposit / balance, payment dates, `payment_plan`, a schedule, send
 * state or signature state. That is enforced structurally rather than by
 * stripping: a template is built ONLY from `content.blocks` (never from
 * `content.payment_plan`, `content.parties`, lines or the document row), and
 * BlockSchema reduces a `product_list` block to its two presentation booleans.
 *
 * Using a template always creates a Business-owned document DRAFT; the template
 * is only ever READ (never mutated) and the document keeps no reference to it.
 *
 * Authority: DocumentTemplateAccess decides who may touch which template.
 * `update()` takes the acting Business as its scope (null = platform-owned
 * templates only) so a Business template can never be edited through the
 * platform path or the other way round.
 */
final class DocumentTemplateService
{
    public const NAME_MAX = 191;
    public const DESCRIPTION_MAX = 1000;

    /** Image blocks dropped by the most recent save / instantiate on THIS instance. */
    private int $lastDroppedImages = 0;

    public function __construct(
        private readonly DocumentManager $manager,
        private readonly DocumentEditorState $state,
        private readonly DocumentTemplateAccess $access,
    ) {
    }

    public function access(): DocumentTemplateAccess
    {
        return $this->access;
    }

    /** How many image blocks the last saveFromDocument() / instantiate() left out (not owned / gone). */
    public function lastDroppedImages(): int
    {
        return $this->lastDroppedImages;
    }

    // ---- create ------------------------------------------------------------------------------

    /**
     * "Save as template". Works from the open draft's blocks or, for a sent /
     * signed document, the current issued version's blocks (read-only: the
     * document is never written).
     *
     * @throws DocumentTemplateRefusedException legacy / non-block document
     * @throws \App\Exceptions\Documents\InvalidDocumentBlocksException
     */
    public function saveFromDocument(BusinessDocument $document, string $name, string $type, ?string $description, User $actor): DocumentTemplate
    {
        $business = Business::findOrFail($document->business_id);
        $version = $this->state->version($document);
        $content = is_array($version?->content) ? $version->content : [];

        // Classic prose is not a layout: it has to be converted to blocks first. A document with no content at all
        // (nothing written yet) is simply an empty layout.
        if (! BlockSchema::hasBlocks($content) && isset($content['body']) && is_string($content['body']) && trim($content['body']) !== '') {
            throw new DocumentTemplateRefusedException('This document was written in the classic text editor. Convert it to the visual editor before saving it as a template.');
        }

        $this->lastDroppedImages = 0;
        $blocks = $this->ownedImagesOnly(is_array($content['blocks'] ?? null) ? $content['blocks'] : [], $business, $this->lastDroppedImages);

        return $this->persistNew($business, $name, $type, $description, $blocks, $actor);
    }

    public function createBlank(Business $business, string $name, string $type, User $actor): DocumentTemplate
    {
        return $this->persistNew($business, $name, $type, null, [], $actor);
    }

    /**
     * Copy a Business template into a new Business template named "Copy of …".
     * Business templates only: a platform template is never duplicated here.
     */
    public function duplicate(DocumentTemplate $template, Business $business, User $actor): DocumentTemplate
    {
        $this->assertOwned($template, $business);

        $this->lastDroppedImages = 0;
        $blocks = $this->ownedImagesOnly(is_array($template->blocks) ? $template->blocks : [], $business, $this->lastDroppedImages);
        $name = Str::limit('Copy of ' . $template->name, self::NAME_MAX, '');

        return $this->persistNew($business, $name, $template->template_type->value, $template->description, $blocks, $actor);
    }

    // ---- use ---------------------------------------------------------------------------------

    /**
     * Put a template's layout onto a document DRAFT. The template is only read.
     * The payment plan / lines / schedule of the draft are never touched (the
     * manager's saveBlocks keeps every other content key as it is) and nothing
     * of the template's identity is stored on the document.
     *
     * Image blocks the draft's Business does not own are dropped (fail closed).
     *
     * @throws ModelNotFoundException            template not usable by this Business
     * @throws DocumentTemplateRefusedException  archived template / document not a plain draft
     * @throws \App\Exceptions\Documents\DocumentDraftConflictException
     */
    public function instantiate(DocumentTemplate $template, BusinessDocument $draft, User $actor): BusinessDocument
    {
        return DB::transaction(function () use ($template, $draft, $actor) {
            $draft = BusinessDocument::query()->whereKey($draft->id)->lockForUpdate()->firstOrFail();
            $business = Business::findOrFail($draft->business_id);

            // Re-read the template under the access rules; never trust the passed model's status.
            $fresh = DocumentTemplate::query()->whereKey($template->id)->first() ?? throw (new ModelNotFoundException())->setModel(DocumentTemplate::class);
            $this->access->assertUsable($business, $fresh);

            if ($draft->status !== DocumentStatus::Draft) {
                throw new DocumentTemplateRefusedException('A template can only be used on a draft document.');
            }

            $version = BusinessDocumentVersion::query()
                ->where('business_document_id', $draft->id)
                ->where('state', DocumentVersionState::Draft->value)
                ->first() ?? throw new DocumentTemplateRefusedException('A template can only be used on a draft document.');

            $content = is_array($version->content) ? $version->content : [];
            if (! BlockSchema::hasBlocks($content) && isset($content['body']) && is_string($content['body']) && trim($content['body']) !== '') {
                throw new DocumentTemplateRefusedException('Convert this document to the visual editor before using a template on it.');
            }

            $this->lastDroppedImages = 0;
            $source = is_array($fresh->blocks) ? $fresh->blocks : [];
            // Platform templates carry no images in V1; anything that slipped in is dropped, not trusted.
            $blocks = $fresh->isPlatformOwned()
                ? $this->withoutImages($source, $this->lastDroppedImages)
                : $this->ownedImagesOnly($source, $business, $this->lastDroppedImages);

            // Re-validate: BlockSchema is the only writer-side authority (also reduces product_list to presentation flags).
            $blocks = BlockSchema::normalize($blocks, $this->blockOptions($fresh, $business));

            return $this->manager->saveBlocks($draft, $blocks, null, (int) $version->lock_version);
        });
    }

    // ---- edit --------------------------------------------------------------------------------

    /**
     * Edit a template, conditional on `lock_version`.
     *
     * @param  array{name?: string, description?: ?string, template_type?: string, blocks?: mixed}  $changes
     * @param  Business|null  $scope  the acting Business (its OWN template), or null for a platform-owned template
     *
     * @throws DocumentTemplateConflictException
     * @throws \App\Exceptions\Documents\InvalidDocumentBlocksException
     */
    public function update(DocumentTemplate $template, array $changes, int $expectedLockVersion, ?Business $scope = null): DocumentTemplate
    {
        if ($scope !== null) {
            $this->assertOwned($template, $scope);
        } elseif (! $template->isPlatformOwned()) {
            throw (new ModelNotFoundException())->setModel(DocumentTemplate::class);
        }

        $row = [];

        if (array_key_exists('name', $changes)) {
            $row['name'] = $this->cleanName($changes['name']);
        }
        if (array_key_exists('description', $changes)) {
            $row['description'] = $this->cleanDescription($changes['description']);
        }
        if (array_key_exists('template_type', $changes)) {
            $row['template_type'] = $this->type($changes['template_type'])->value;
        }
        if (array_key_exists('blocks', $changes)) {
            $options = $this->blockOptions($template, $scope);
            $row['blocks'] = json_encode(BlockSchema::normalize($changes['blocks'], $options), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $row['schema_version'] = BlockSchema::SCHEMA_VERSION;
        }

        return DB::transaction(function () use ($template, $row, $expectedLockVersion, $scope) {
            $current = DocumentTemplate::query()->whereKey($template->id)->lockForUpdate()->firstOrFail();

            if ($scope !== null && $current->status === DocumentTemplateStatus::Archived) {
                throw new DocumentTemplateRefusedException('This template is archived. Restore it to edit it.');
            }

            // The conditional update: succeeds only against the version the caller saw.
            $affected = DB::table('document_templates')
                ->where('id', $current->id)
                ->where('lock_version', $expectedLockVersion)
                ->update($row + ['lock_version' => DB::raw('lock_version + 1'), 'updated_at' => now()]);

            if ($affected !== 1) {
                throw new DocumentTemplateConflictException((int) $current->lock_version);
            }

            return $current->refresh();
        });
    }

    // ---- lifecycle ---------------------------------------------------------------------------

    /** Hide a Business template from pickers. Documents already created from it are unaffected (they hold no reference). */
    public function archive(DocumentTemplate $template, Business $business): DocumentTemplate
    {
        $this->assertOwned($template, $business);
        $template->status = DocumentTemplateStatus::Archived;
        $template->save();

        return $template;
    }

    public function restore(DocumentTemplate $template, Business $business): DocumentTemplate
    {
        $this->assertOwned($template, $business);
        $template->status = DocumentTemplateStatus::Active;
        $template->save();

        return $template;
    }

    // ---- read models -------------------------------------------------------------------------

    /**
     * The Business's own templates, newest edit first.
     *
     * @return Collection<int, DocumentTemplate>
     */
    public function listFor(Business $business, bool $includeArchived = false): Collection
    {
        return DocumentTemplate::query()
            ->where('business_id', $business->id)
            ->when(! $includeArchived, fn ($q) => $q->where('status', DocumentTemplateStatus::Active->value))
            ->when($includeArchived, fn ($q) => $q->where('status', '!=', DocumentTemplateStatus::Draft->value))
            ->orderByDesc('updated_at')->orderByDesc('id')
            ->get();
    }

    /**
     * Platform templates this Business is recommended (active only). Empty
     * until the niche-blueprint implementation is bound.
     *
     * @return Collection<int, DocumentTemplate>
     */
    public function recommendedFor(Business $business): Collection
    {
        return app(RecommendedPlatformTemplates::class)->forBusiness($business)
            ->filter(fn ($t) => $t instanceof DocumentTemplate && $t->business_id === null && $t->status === DocumentTemplateStatus::Active)
            ->values();
    }

    /**
     * A one-line text snippet for a picker card, from the first heading / text
     * blocks (merge tokens shown by their label). No thumbnails in V1.
     */
    public function snippet(DocumentTemplate $template, int $max = 140): string
    {
        $parts = [];

        foreach (is_array($template->blocks) ? $template->blocks : [] as $block) {
            if (! is_array($block) || ! in_array($block['type'] ?? null, ['heading', 'text'], true)) {
                continue;
            }

            $text = '';
            foreach (is_array($block['data']['runs'] ?? null) ? $block['data']['runs'] : [] as $run) {
                if (! is_array($run)) {
                    continue;
                }
                $text .= isset($run['merge']) && is_string($run['merge']) ? DocumentMergeFields::label($run['merge']) : (is_string($run['t'] ?? null) ? $run['t'] : '');
            }

            $text = trim(preg_replace('/\s+/', ' ', $text) ?? '');
            if ($text !== '') {
                $parts[] = $text;
            }
            if (count($parts) >= 2 || mb_strlen(implode(' · ', $parts)) >= $max) {
                break;
            }
        }

        return Str::limit(implode(' · ', $parts), $max);
    }

    // ---- internals ---------------------------------------------------------------------------

    /**
     * @param  array<int, mixed>  $blocks
     */
    private function persistNew(Business $business, string $name, string $type, ?string $description, array $blocks, User $actor): DocumentTemplate
    {
        $name = $this->cleanName($name);
        $description = $this->cleanDescription($description);
        $type = $this->type($type);

        // Normalised here, with images bound to THIS Business's catalog images: only the sanitised shape is stored.
        $blocks = BlockSchema::normalize($blocks, ['image_owned' => $this->imageOwner($business)]);

        $template = new DocumentTemplate([
            'business_id' => $business->id,
            'template_type' => $type,
            'name' => $name,
            'description' => $description,
            'blocks' => $blocks,
            'schema_version' => BlockSchema::SCHEMA_VERSION,
            'created_by_user_id' => $actor->id,
        ]);
        $template->status = DocumentTemplateStatus::Active;
        $template->save();

        return $template->refresh();
    }

    /** @return array<string, mixed> */
    private function blockOptions(DocumentTemplate $template, ?Business $business): array
    {
        if ($template->isPlatformOwned()) {
            return ['allow_images' => false];
        }

        $business ??= Business::findOrFail($template->business_id);

        return ['image_owned' => $this->imageOwner($business)];
    }

    private function imageOwner(Business $business): \Closure
    {
        $businessId = (int) $business->id;

        return fn (string $uid): bool => CatalogItemImage::query()
            ->where('uid', $uid)
            ->whereHas('catalogItem', fn ($query) => $query->where('business_id', $businessId))
            ->exists();
    }

    /**
     * Keep every block except image blocks the Business does not own.
     *
     * @param  array<int, mixed>  $blocks
     * @return array<int, mixed>
     */
    private function ownedImagesOnly(array $blocks, Business $business, int &$dropped): array
    {
        $owns = $this->imageOwner($business);
        $verdicts = [];

        return array_values(array_filter($blocks, function ($block) use ($owns, &$verdicts, &$dropped) {
            if (! is_array($block) || ($block['type'] ?? null) !== 'image') {
                return true;
            }

            $uid = $block['data']['catalog_image_uid'] ?? null;
            $uid = is_string($uid) ? $uid : '';
            $verdicts[$uid] ??= Str::isUuid($uid) && $owns($uid);

            if (! $verdicts[$uid]) {
                $dropped++;
            }

            return $verdicts[$uid];
        }));
    }

    /**
     * @param  array<int, mixed>  $blocks
     * @return array<int, mixed>
     */
    private function withoutImages(array $blocks, int &$dropped): array
    {
        return array_values(array_filter($blocks, function ($block) use (&$dropped) {
            if (is_array($block) && ($block['type'] ?? null) === 'image') {
                $dropped++;

                return false;
            }

            return true;
        }));
    }

    private function assertOwned(DocumentTemplate $template, Business $business): void
    {
        if (! $this->access->isOwnedBy($template, $business)) {
            throw (new ModelNotFoundException())->setModel(DocumentTemplate::class);
        }
    }

    private function cleanName(mixed $name): string
    {
        $name = is_string($name) ? trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $name) ?? '') : '';

        if ($name === '' || mb_strlen($name) > self::NAME_MAX) {
            throw \Illuminate\Validation\ValidationException::withMessages(['name' => 'Give the template a name of up to ' . self::NAME_MAX . ' characters.']);
        }

        return $name;
    }

    private function cleanDescription(mixed $description): ?string
    {
        if ($description === null) {
            return null;
        }

        $description = is_string($description) ? trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $description) ?? '') : '';

        if (mb_strlen($description) > self::DESCRIPTION_MAX) {
            throw \Illuminate\Validation\ValidationException::withMessages(['description' => 'The description can be at most ' . self::DESCRIPTION_MAX . ' characters.']);
        }

        return $description === '' ? null : $description;
    }

    private function type(mixed $type): DocumentTemplateType
    {
        return (is_string($type) ? DocumentTemplateType::tryFrom($type) : null)
            ?? throw \Illuminate\Validation\ValidationException::withMessages(['template_type' => 'Choose Proposal or Contract.']);
    }
}
