<?php

namespace App\Library\Documents\Blocks;

use App\Exceptions\Documents\InvalidDocumentBlocksException;
use Illuminate\Support\Str;

/**
 * Implementation Contract 17B §2 — the ONLY writer-side authority on the block
 * document. It validates AND sanitises: the array it returns is the only shape
 * that may be persisted to `business_document_versions.content.blocks` or
 * `document_templates.blocks`, and the shape DocumentBlockRenderer trusts.
 *
 * Pure data, never executable: unknown block types are rejected, unknown keys
 * are dropped (or rejected with `strict_keys`), links are limited to
 * http(s)/mailto/tel, merge tokens to the DocumentMergeFields allow-list, and
 * every size is bounded. It holds presentation only — lines, prices and the
 * payment schedule live in the canonical commerce tables, and `product_list`
 * carries no money.
 *
 * Options (all optional):
 *   allow_images       bool, default true. Platform templates pass false (17B
 *                      §6: no platform media seam in V1).
 *   image_owned        callable(string $catalogImageUid): bool. When given, an
 *                      image block must reference an image it approves (the
 *                      Business's own CatalogItemImage). Without it only the
 *                      uid FORMAT is checked.
 *   strict_keys        bool, default false. Reject (instead of drop) unknown
 *                      keys on a block or its data.
 */
final class BlockSchema
{
    public const SCHEMA_VERSION = 2;
    public const MAX_BLOCKS = 200;
    public const MAX_BYTES = 204800;
    public const MAX_RUN_TEXT = 2000;
    public const MAX_RUNS = 100;
    public const MAX_HREF = 2000;

    public const TYPES = [
        'heading', 'text', 'image', 'divider', 'spacer', 'page_break',
        'section', 'business_details', 'product_list', 'payment_terms', 'signature',
    ];

    private const ALIGNS = ['left', 'center', 'right'];
    private const BUSINESS_FIELDS = ['name', 'phone', 'email', 'website'];

    /** @var array<string, string> */
    private array $errors = [];

    /** @var array<string, mixed> */
    private array $options;

    private function __construct(array $options)
    {
        $this->options = $options + ['allow_images' => true, 'image_owned' => null, 'strict_keys' => false];
    }

    /**
     * Validate + sanitise a block list.
     *
     * @param  array<string, mixed>  $options
     * @return array<int, array{id: string, type: string, data: array<string, mixed>}>
     *
     * @throws InvalidDocumentBlocksException
     */
    public static function normalize(mixed $blocks, array $options = []): array
    {
        $schema = new self($options);
        $result = $schema->run($blocks);

        if ($schema->errors !== []) {
            throw new InvalidDocumentBlocksException($schema->errors);
        }

        return $result;
    }

    /**
     * The persisted `content` fragment for a block document.
     *
     * @param  array<string, mixed>  $options
     * @return array{schema_version: int, blocks: array<int, array<string, mixed>>}
     */
    public static function document(mixed $blocks, array $options = []): array
    {
        return ['schema_version' => self::SCHEMA_VERSION, 'blocks' => self::normalize($blocks, $options)];
    }

    public static function hasBlocks(mixed $content): bool
    {
        return is_array($content) && is_array($content['blocks'] ?? null);
    }

    /**
     * @param  array<int, array<string, mixed>>  $blocks
     */
    public static function countOfType(array $blocks, string $type): int
    {
        return count(array_filter($blocks, fn ($block) => is_array($block) && ($block['type'] ?? null) === $type));
    }

    /**
     * §2 — a proposal that requires a signature may be sent only with exactly
     * one signature block. normalize() already refuses a second one.
     *
     * @param  array<int, array<string, mixed>>  $blocks
     *
     * @throws InvalidDocumentBlocksException
     */
    public static function assertSendable(array $blocks, bool $requiresSignature): void
    {
        if ($requiresSignature && self::countOfType($blocks, 'signature') !== 1) {
            throw new InvalidDocumentBlocksException(['blocks' => 'Add exactly one signature block before sending.']);
        }
    }

    /**
     * Is this href one of the permitted schemes? Exposed so the editor and
     * the tests share one definition.
     */
    public static function isSafeHref(mixed $href): bool
    {
        if (! is_string($href)) {
            return false;
        }

        $href = trim($href);

        if ($href === '' || strlen($href) > self::MAX_HREF || preg_match('/[\x00-\x20\x7F\\\\]/', $href) === 1) {
            return false;
        }

        if (preg_match('/^(https?):\/\/([^\/?#]+)/i', $href, $m) === 1) {
            return filter_var($href, FILTER_VALIDATE_URL) !== false && $m[2] !== '';
        }

        if (preg_match('/^mailto:([^?]+)(\?.*)?$/i', $href, $m) === 1) {
            return filter_var(rawurldecode($m[1]), FILTER_VALIDATE_EMAIL) !== false;
        }

        if (preg_match('/^tel:\+?[0-9().-]{3,32}$/i', $href) === 1) {
            return true;
        }

        return false;
    }

    private function run(mixed $blocks): array
    {
        if (! is_array($blocks) || ! array_is_list($blocks)) {
            $this->errors['blocks'] = 'Blocks must be a list.';

            return [];
        }

        if (count($blocks) > self::MAX_BLOCKS) {
            $this->errors['blocks'] = 'A document can have at most ' . self::MAX_BLOCKS . ' blocks.';

            return [];
        }

        $out = [];
        $ids = [];
        $counts = ['product_list' => 0, 'signature' => 0];

        foreach ($blocks as $index => $block) {
            $path = "blocks.{$index}";

            if (! is_array($block)) {
                $this->errors[$path] = 'Each block must be an object.';
                continue;
            }

            $this->rejectUnknownKeys($block, ['id', 'type', 'data'], $path);

            $type = $block['type'] ?? null;

            if (! is_string($type) || ! in_array($type, self::TYPES, true)) {
                $this->errors["{$path}.type"] = 'Unknown block type.';
                continue;
            }

            if (isset($counts[$type]) && ++$counts[$type] > 1) {
                $this->errors["{$path}.type"] = $type === 'product_list'
                    ? 'A document can have only one product block.'
                    : 'A document can have only one signature block.';
                continue;
            }

            $id = $this->blockId($block['id'] ?? null, $ids, $path);
            $data = $block['data'] ?? [];

            if (! is_array($data)) {
                $this->errors["{$path}.data"] = 'Block data must be an object.';
                continue;
            }

            $out[] = ['id' => $id, 'type' => $type, 'data' => $this->data($type, $data, "{$path}.data")];
        }

        if ($this->errors === [] && strlen((string) json_encode($out)) > self::MAX_BYTES) {
            $this->errors['blocks'] = 'The document is too large.';
        }

        return $out;
    }

    private function blockId(mixed $id, array &$ids, string $path): string
    {
        if ($id === null || $id === '') {
            $id = (string) Str::uuid();
        } elseif (! is_string($id) || preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id) !== 1) {
            $this->errors["{$path}.id"] = 'Invalid block id.';
            $id = (string) Str::uuid();
        } elseif (isset($ids[$id])) {
            $this->errors["{$path}.id"] = 'Duplicate block id.';
        }

        $ids[$id] = true;

        return $id;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function data(string $type, array $data, string $path): array
    {
        return match ($type) {
            'heading' => $this->heading($data, $path),
            'text' => $this->text($data, $path),
            'image' => $this->image($data, $path),
            'spacer' => $this->spacer($data, $path),
            'section' => $this->section($data, $path),
            'business_details' => $this->businessDetails($data, $path),
            'product_list' => $this->productList($data, $path),
            'signature' => $this->signature($data, $path),
            default => $this->empty($data, $path), // divider, page_break, payment_terms
        };
    }

    private function empty(array $data, string $path): array
    {
        $this->rejectUnknownKeys($data, [], $path);

        return [];
    }

    private function heading(array $data, string $path): array
    {
        $this->rejectUnknownKeys($data, ['level', 'align', 'runs'], $path);

        $level = $data['level'] ?? 2;

        if (! is_int($level) && ! (is_string($level) && ctype_digit($level))) {
            $this->errors["{$path}.level"] = 'Heading level must be 1, 2 or 3.';
            $level = 2;
        } elseif ((int) $level < 1 || (int) $level > 3) {
            $this->errors["{$path}.level"] = 'Heading level must be 1, 2 or 3.';
        }

        return [
            'level' => (int) $level,
            'align' => $this->align($data['align'] ?? 'left', $path),
            'runs' => $this->runs($data['runs'] ?? [], "{$path}.runs"),
        ];
    }

    private function text(array $data, string $path): array
    {
        $this->rejectUnknownKeys($data, ['align', 'runs'], $path);

        return [
            'align' => $this->align($data['align'] ?? 'left', $path),
            'runs' => $this->runs($data['runs'] ?? [], "{$path}.runs"),
        ];
    }

    private function align(mixed $align, string $path): string
    {
        if (! is_string($align) || ! in_array($align, self::ALIGNS, true)) {
            $this->errors["{$path}.align"] = 'Alignment must be left, center or right.';

            return 'left';
        }

        return $align;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function runs(mixed $runs, string $path): array
    {
        if (! is_array($runs) || ! array_is_list($runs)) {
            $this->errors[$path] = 'Text runs must be a list.';

            return [];
        }

        if (count($runs) > self::MAX_RUNS) {
            $this->errors[$path] = 'Too many text runs in one block.';

            return [];
        }

        $out = [];

        foreach ($runs as $i => $run) {
            $rp = "{$path}.{$i}";

            if (! is_array($run)) {
                $this->errors[$rp] = 'Each text run must be an object.';
                continue;
            }

            $this->rejectUnknownKeys($run, ['t', 'b', 'i', 'u', 'href', 'merge'], $rp);

            $clean = [];

            if (array_key_exists('merge', $run) && $run['merge'] !== null && $run['merge'] !== '') {
                if (! DocumentMergeFields::isAllowed($run['merge'])) {
                    $this->errors["{$rp}.merge"] = 'Unknown merge field.';
                    continue;
                }

                $clean['merge'] = $run['merge'];
            } else {
                $t = $run['t'] ?? '';

                if (! is_string($t)) {
                    $this->errors["{$rp}.t"] = 'Run text must be a string.';
                    continue;
                }

                if (mb_strlen($t) > self::MAX_RUN_TEXT) {
                    $this->errors["{$rp}.t"] = 'Run text is too long.';
                    continue;
                }

                // Control characters (other than tab/newline) have no place in prose.
                $clean['t'] = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $t) ?? '';
            }

            foreach (['b', 'i', 'u'] as $flag) {
                if (($run[$flag] ?? false) === true) {
                    $clean[$flag] = true;
                }
            }

            if (array_key_exists('href', $run) && $run['href'] !== null && $run['href'] !== '') {
                if (! self::isSafeHref($run['href'])) {
                    $this->errors["{$rp}.href"] = 'Links must start with http://, https://, mailto: or tel:.';
                    continue;
                }

                $clean['href'] = trim($run['href']);
            }

            $out[] = $clean;
        }

        return $out;
    }

    private function image(array $data, string $path): array
    {
        $this->rejectUnknownKeys($data, ['catalog_image_uid', 'alt', 'width_pct'], $path);

        if (! $this->options['allow_images']) {
            $this->errors[$path] = 'Images are not available in this template.';

            return [];
        }

        $uid = $data['catalog_image_uid'] ?? null;

        if (! is_string($uid) || ! Str::isUuid($uid)) {
            $this->errors["{$path}.catalog_image_uid"] = 'Choose one of your catalog images.';
            $uid = '';
        } elseif (is_callable($this->options['image_owned']) && ! ($this->options['image_owned'])($uid)) {
            $this->errors["{$path}.catalog_image_uid"] = 'That image is not available.';
        }

        $alt = $data['alt'] ?? '';

        if (! is_string($alt) || mb_strlen($alt) > 200) {
            $this->errors["{$path}.alt"] = 'Alt text must be at most 200 characters.';
            $alt = '';
        }

        $width = $data['width_pct'] ?? 100;

        if (! is_numeric($width) || (int) $width != $width || (int) $width < 10 || (int) $width > 100) {
            $this->errors["{$path}.width_pct"] = 'Image width must be between 10 and 100 percent.';
            $width = 100;
        }

        return ['catalog_image_uid' => $uid, 'alt' => trim($alt), 'width_pct' => (int) $width];
    }

    private function spacer(array $data, string $path): array
    {
        $this->rejectUnknownKeys($data, ['height'], $path);

        $height = $data['height'] ?? 24;

        if (! is_numeric($height) || (int) $height != $height || (int) $height < 8 || (int) $height > 120) {
            $this->errors["{$path}.height"] = 'Spacer height must be between 8 and 120.';
            $height = 24;
        }

        return ['height' => (int) $height];
    }

    private function section(array $data, string $path): array
    {
        $this->rejectUnknownKeys($data, ['title'], $path);

        return ['title' => $this->shortString($data['title'] ?? '', 200, "{$path}.title", 'Section title')];
    }

    private function signature(array $data, string $path): array
    {
        $this->rejectUnknownKeys($data, ['label'], $path);

        $label = $this->shortString($data['label'] ?? 'Signature', 100, "{$path}.label", 'Signature label');

        return ['label' => $label === '' ? 'Signature' : $label];
    }

    private function businessDetails(array $data, string $path): array
    {
        $this->rejectUnknownKeys($data, ['show'], $path);

        $show = $data['show'] ?? self::BUSINESS_FIELDS;

        if (! is_array($show) || ! array_is_list($show)) {
            $this->errors["{$path}.show"] = 'Choose which business details to show.';

            return ['show' => self::BUSINESS_FIELDS];
        }

        $clean = [];

        foreach ($show as $field) {
            if (! is_string($field) || ! in_array($field, self::BUSINESS_FIELDS, true)) {
                $this->errors["{$path}.show"] = 'Unknown business detail.';
                continue;
            }

            $clean[$field] = $field;
        }

        // Canonical order, no duplicates.
        return ['show' => array_values(array_filter(self::BUSINESS_FIELDS, fn ($f) => isset($clean[$f])))];
    }

    private function productList(array $data, string $path): array
    {
        $this->rejectUnknownKeys($data, ['show_description', 'show_quantity'], $path);

        return [
            'show_description' => $this->bool($data['show_description'] ?? true, "{$path}.show_description"),
            'show_quantity' => $this->bool($data['show_quantity'] ?? true, "{$path}.show_quantity"),
        ];
    }

    private function bool(mixed $value, string $path): bool
    {
        if (! is_bool($value)) {
            $this->errors[$path] = 'Must be true or false.';

            return true;
        }

        return $value;
    }

    private function shortString(mixed $value, int $max, string $path, string $label): string
    {
        if (! is_string($value) || mb_strlen($value) > $max) {
            $this->errors[$path] = "{$label} must be text of at most {$max} characters.";

            return '';
        }

        return trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? '');
    }

    /**
     * @param  array<string, mixed>  $value
     * @param  array<int, string>  $allowed
     */
    private function rejectUnknownKeys(array $value, array $allowed, string $path): void
    {
        if (! $this->options['strict_keys']) {
            return;
        }

        foreach (array_keys($value) as $key) {
            if (! in_array($key, $allowed, true)) {
                $this->errors["{$path}.{$key}"] = 'Unknown field.';
            }
        }
    }
}
