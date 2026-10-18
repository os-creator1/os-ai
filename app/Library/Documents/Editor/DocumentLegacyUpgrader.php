<?php

namespace App\Library\Documents\Editor;

use App\Enums\Documents\DocumentStatus;
use App\Exceptions\Documents\DocumentDraftConflictException;
use App\Library\Documents\Blocks\BlockSchema;
use App\Library\Documents\DocumentManager;
use App\Models\BusinessDocument;
use Illuminate\Validation\ValidationException;

/**
 * Implementation Contract 17B §2 — the deterministic legacy -> blocks
 * conversion for a DRAFT document.
 *
 * `content.body` is split into paragraphs on blank lines; each becomes a text
 * block. The document's canonical product area and payment terms follow as a
 * `product_list` and a `payment_terms` block, then a `signature` block when the
 * document requires one. Block ids are fixed (`legacy-p1`, `legacy-products`,
 * ...), so the same body always yields the same blocks. The original `body` is
 * left in the content untouched (nothing is lost); once `blocks` exist the
 * renderer and the editor use them.
 *
 * Refused (nothing written) unless the document is a plain `draft` that does
 * not yet have blocks — so a second call can never convert twice or alter an
 * already-converted document, and a sent / signed / void document is never
 * rewritten.
 */
final class DocumentLegacyUpgrader
{
    private const CHUNK = 2000;

    public function __construct(
        private readonly DocumentManager $manager,
        private readonly DocumentEditorState $state,
    ) {
    }

    /**
     * @return array<int, array<string, mixed>> the blocks a legacy body converts to (no I/O)
     */
    public function blocksFor(string $body, bool $requiresSignature): array
    {
        $paragraphs = preg_split('/(?:\r\n|\r|\n){2,}/', trim($body)) ?: [];
        $paragraphs = array_values(array_filter(array_map('trim', $paragraphs), fn ($p) => $p !== ''));

        // Stay within the block limit: fold any surplus into the last text block.
        $limit = BlockSchema::MAX_BLOCKS - 4;
        if (count($paragraphs) > $limit) {
            $tail = array_splice($paragraphs, $limit - 1);
            $paragraphs[] = implode("\n\n", $tail);
        }

        $blocks = [];
        foreach ($paragraphs as $index => $paragraph) {
            $blocks[] = [
                'id' => 'legacy-p' . ($index + 1),
                'type' => 'text',
                'data' => ['align' => 'left', 'runs' => array_map(fn ($chunk) => ['t' => $chunk], mb_str_split($paragraph, self::CHUNK))],
            ];
        }

        $blocks[] = ['id' => 'legacy-products', 'type' => 'product_list', 'data' => ['show_description' => true, 'show_quantity' => true]];
        $blocks[] = ['id' => 'legacy-payment', 'type' => 'payment_terms', 'data' => []];

        if ($requiresSignature) {
            $blocks[] = ['id' => 'legacy-signature', 'type' => 'signature', 'data' => ['label' => 'Signature']];
        }

        return $blocks;
    }

    /**
     * @return array<string, mixed> the editor bootstrap after conversion
     */
    public function upgrade(BusinessDocument $document, ?int $expectedLockVersion): array
    {
        $version = $this->state->version($document);
        $content = is_array($version?->content) ? $version->content : [];

        if ($document->status !== DocumentStatus::Draft || $version === null) {
            throw ValidationException::withMessages(['document' => 'Only a draft document can be converted.']);
        }

        // A stale tab hears "reload" before it hears "already converted".
        if ($expectedLockVersion !== null && (int) $version->lock_version !== $expectedLockVersion) {
            throw new DocumentDraftConflictException((int) $version->lock_version);
        }

        if (BlockSchema::hasBlocks($content)) {
            throw ValidationException::withMessages(['document' => 'This document already uses the visual editor.']);
        }

        $body = isset($content['body']) && is_string($content['body']) ? $content['body'] : '';
        $content['blocks'] = $this->blocksFor($body, (bool) $document->requires_signature);

        $this->manager->edit($document, ['content' => $content], $expectedLockVersion);

        return $this->state->bootstrap($document->refresh());
    }
}
