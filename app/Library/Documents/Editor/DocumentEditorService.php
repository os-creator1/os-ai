<?php

namespace App\Library\Documents\Editor;

use App\Exceptions\Documents\InvalidDocumentPaymentPlanException;
use App\Library\Catalog\CatalogMoney;
use App\Library\Catalog\Exceptions\CatalogRuleException;
use App\Library\Documents\Blocks\BlockSchema;
use App\Library\Documents\DocumentManager;
use App\Models\BusinessDocument;
use App\Models\BusinessDocumentLineItem;
use App\Models\BusinessDocumentVersion;
use App\Models\CatalogItem;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

/**
 * Implementation Contract 17B — what the editor's JSON endpoints do, so the
 * controller stays a thin adapter. Every method is one DocumentManager draft
 * mutation (the only writer), carries the caller's `expected_lock_version`, and
 * answers with the state the editor needs next, including the NEW lock_version
 * the manager reported from inside the same locked transaction.
 *
 * Money crosses this boundary as a decimal string in the document's currency
 * ("250.00") and is converted with CatalogMoney; the manager only ever sees
 * minor units.
 */
final class DocumentEditorService
{
    public function __construct(
        private readonly DocumentManager $manager,
        private readonly DocumentEditorState $state,
    ) {
    }

    /**
     * Autosave target.
     *
     * @return array{lock_version: int, title: string, blocks: array<int, array<string, mixed>>}
     */
    public function saveBlocks(BusinessDocument $document, mixed $blocks, ?string $title, ?int $expected): array
    {
        $this->refuseLegacyBody($document);

        $saved = $this->manager->saveBlocks($document, $blocks, $title, $expected);
        $version = $this->state->version($saved);

        return [
            'lock_version' => (int) $this->manager->lastLockVersion(),
            'title' => (string) $saved->title,
            'blocks' => is_array($version?->content['blocks'] ?? null) ? $version->content['blocks'] : [],
        ];
    }

    /**
     * @param  array<string, mixed>  $input  structure, deposit (decimal string), full_due, full_due_date, balance_due, balance_due_date
     * @return array<string, mixed>
     */
    public function applyPlan(BusinessDocument $document, array $input, ?int $expected): array
    {
        $plan = [
            'structure' => $input['structure'] ?? null,
            'full_due' => $input['full_due'] ?? 'on_signing',
            'full_due_date' => $input['full_due_date'] ?? null,
            'balance_due' => $input['balance_due'] ?? 'after_deposit',
            'balance_due_date' => $input['balance_due_date'] ?? null,
        ];

        if (($input['structure'] ?? null) === 'deposit') {
            try {
                $plan['deposit_minor'] = CatalogMoney::toMinor(isset($input['deposit']) ? (string) $input['deposit'] : null, (string) $document->currency_code);
            } catch (CatalogRuleException $e) {
                throw new InvalidDocumentPaymentPlanException(['deposit' => $e->getMessage()]);
            }
        }

        $this->manager->setPaymentPlan($document, $plan, $expected);

        return $this->done($document);
    }

    /**
     * @return array<string, mixed>
     */
    public function addCatalogLine(BusinessDocument $document, CatalogItem $item, int $quantity, ?string $price, User $actor, ?int $expected): array
    {
        $this->manager->addCatalogLine($document, $item, $quantity, $actor, $this->price($document, $price), $expected);

        return $this->done($document);
    }

    /**
     * @return array<string, mixed>
     */
    public function addCustomLine(BusinessDocument $document, string $name, ?string $description, int $quantity, string $price, ?int $expected): array
    {
        $minor = $this->price($document, $price);
        if ($minor === null) {
            throw ValidationException::withMessages(['price' => 'Enter a price.']);
        }

        $this->manager->addCustomLine($document, $name, $description, $quantity, $minor, $expected);

        return $this->done($document);
    }

    /**
     * @return array<string, mixed>
     */
    public function updateQuantity(BusinessDocument $document, string $lineUid, int $quantity, ?int $expected): array
    {
        $this->manager->updateLineQuantity($document, $this->line($document, $lineUid), $quantity, $expected);

        return $this->done($document);
    }

    /**
     * @return array<string, mixed>
     */
    public function removeLine(BusinessDocument $document, string $lineUid, ?int $expected): array
    {
        $this->manager->removeLine($document, $this->line($document, $lineUid), $expected);

        return $this->done($document);
    }

    /**
     * @param  array<int, string>  $lineUids
     * @return array<string, mixed>
     */
    public function reorderLines(BusinessDocument $document, array $lineUids, ?int $expected): array
    {
        $ids = [];
        foreach ($lineUids as $uid) {
            $ids[] = (int) $this->line($document, $uid)->id;
        }

        $this->manager->reorderLines($document, $ids, $expected);

        return $this->done($document);
    }

    /**
     * @return array<string, mixed>
     */
    private function done(BusinessDocument $document): array
    {
        return $this->state->commerce($document->refresh(), $this->manager->lastLockVersion());
    }

    /** A line of THIS document's versions only — a foreign line's uid is simply not found. */
    private function line(BusinessDocument $document, string $uid): BusinessDocumentLineItem
    {
        return BusinessDocumentLineItem::query()
            ->where('uid', $uid)
            ->whereIn('business_document_version_id', BusinessDocumentVersion::query()->where('business_document_id', $document->id)->select('id'))
            ->first() ?? throw (new ModelNotFoundException())->setModel(BusinessDocumentLineItem::class);
    }

    private function price(BusinessDocument $document, ?string $price): ?int
    {
        try {
            return CatalogMoney::toMinor($price, (string) $document->currency_code);
        } catch (CatalogRuleException $e) {
            throw ValidationException::withMessages(['price' => $e->getMessage()]);
        }
    }

    /** Legacy prose has to be converted first, or saving blocks would orphan it. */
    private function refuseLegacyBody(BusinessDocument $document): void
    {
        $version = $this->state->version($document);
        $content = is_array($version?->content) ? $version->content : [];

        if (! BlockSchema::hasBlocks($content) && isset($content['body']) && is_string($content['body']) && trim($content['body']) !== '') {
            throw ValidationException::withMessages(['document' => 'Convert this document to the visual editor before editing its layout.']);
        }
    }
}
