<?php

namespace App\Library\Documents\Blocks;

use App\Library\Catalog\CatalogMoney;
use App\Models\BusinessDocumentVersion;
use App\Models\CatalogItemImage;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;

/**
 * Implementation Contract 17B §2 — THE ONLY renderer of a block document.
 *
 * Editor canvas, preview, Business show and the public page all render through
 * the same Blade partial (`documents.blocks.render`); only `mode` differs:
 *
 *   editor            draft canvas — merge tokens shown as chips, real lines if
 *                     the draft has any, signature / page-break placeholders.
 *   preview           draft rendered the way the recipient will see it, merge
 *                     tokens resolved against the supplied (live) context.
 *   public            an ISSUED version — merge values come ONLY from the
 *                     frozen context the caller passes; the signature block is
 *                     a slot for the caller's real sign form.
 *   template_preview  a template with no Contact or products — merge tokens
 *                     resolved from sample data, the product and payment areas
 *                     rendered as generic placeholders, images omitted.
 *
 * Every value is escaped; runs become <b>/<i>/<u>/<a> only; links carry
 * rel="noopener noreferrer nofollow". The renderer re-clamps the few numeric
 * and enum fields it uses so it stays safe even if handed an array that did
 * not pass through BlockSchema. The one trusted raw input is
 * `signature_html`, accepted ONLY as an HtmlString built by the controller
 * from its own sign-form partial.
 *
 * Options:
 *   merge            array token => value (see DocumentMergeFields).
 *   lines            iterable of line items (name, description, quantity,
 *                    unit_price_minor, line_total_minor).
 *   schedule         iterable of payment schedule items (kind, amount_minor,
 *                    due_at, status).
 *   subtotal_minor, total_minor, currency_code
 *   timezone         IANA zone for schedule dates (default app timezone).
 *   business_id      scopes image lookup to the Business's own catalog images.
 *   signature_html   HtmlString — public mode only.
 */
final class DocumentBlockRenderer
{
    public const MODES = ['editor', 'preview', 'public', 'template_preview'];

    /**
     * @param  array<int, array<string, mixed>>  $blocks
     * @param  array<string, mixed>  $options
     */
    public function render(array $blocks, string $mode = 'preview', array $options = []): HtmlString
    {
        if (! in_array($mode, self::MODES, true)) {
            $mode = 'preview';
        }

        $isTemplate = $mode === 'template_preview';
        $merge = is_array($options['merge'] ?? null) ? $options['merge'] : [];
        $currency = is_string($options['currency_code'] ?? null) ? $options['currency_code'] : null;
        $timezone = is_string($options['timezone'] ?? null) && $options['timezone'] !== '' ? $options['timezone'] : (string) config('app.timezone', 'UTC');

        $lines = $isTemplate ? [] : $this->toList($options['lines'] ?? []);
        $schedule = $isTemplate ? [] : $this->toList($options['schedule'] ?? []);

        $signatureHtml = $mode === 'public' && ($options['signature_html'] ?? null) instanceof HtmlString
            ? $options['signature_html']
            : null;

        $view = view('documents.blocks.render', [
            'blocks' => array_values(array_filter($blocks, fn ($b) => is_array($b) && in_array($b['type'] ?? null, BlockSchema::TYPES, true))),
            'mode' => $mode,
            'isTemplate' => $isTemplate,
            'lines' => $lines,
            'schedule' => $schedule,
            'subtotalMinor' => $options['subtotal_minor'] ?? null,
            'totalMinor' => $options['total_minor'] ?? null,
            'currency' => $currency,
            'signatureHtml' => $signatureHtml,
            'images' => $this->images($blocks, $options['business_id'] ?? null), // template_preview: only when the caller scopes a Business (its own template); platform templates pass none
            'runs' => fn ($runs): HtmlString => $this->runs(is_array($runs) ? $runs : [], $mode, $merge),
            'mergeValue' => fn (string $token): string => DocumentMergeFields::resolve($token, $merge),
            'money' => fn ($minor): string => CatalogMoney::format($minor === null ? null : (int) $minor, $currency),
            'dueText' => fn ($item): string => $this->dueText($item, $timezone),
            'safeHref' => fn ($href): bool => BlockSchema::isSafeHref($href),
        ]);

        return new HtmlString($view->render());
    }

    /**
     * Render an issued/draft version's block content with its canonical lines
     * and schedule. The caller supplies the merge context — frozen parties for
     * an issued version, live values for a draft.
     *
     * @param  array<string, mixed>  $merge
     * @param  array<string, mixed>  $extra
     */
    public function renderVersion(BusinessDocumentVersion $version, string $mode, array $merge, array $extra = []): HtmlString
    {
        $content = is_array($version->content) ? $version->content : [];

        return $this->render(
            is_array($content['blocks'] ?? null) ? $content['blocks'] : [],
            $mode,
            $extra + [
                'merge' => $merge,
                'lines' => $version->lineItems()->orderBy('position')->orderBy('id')->get(),
                'schedule' => $version->paymentScheduleItems()->orderBy('sequence')->get(),
                'subtotal_minor' => $version->subtotal_minor,
                'total_minor' => $version->total_minor,
                'currency_code' => $version->currency_code,
            ]
        );
    }

    /**
     * @param  array<int, mixed>  $runs
     * @param  array<string, mixed>  $merge
     */
    private function runs(array $runs, string $mode, array $merge): HtmlString
    {
        $html = '';

        foreach ($runs as $run) {
            if (! is_array($run)) {
                continue;
            }

            if (isset($run['merge']) && is_string($run['merge']) && DocumentMergeFields::isAllowed($run['merge'])) {
                $token = $run['merge'];
                $value = DocumentMergeFields::resolve($token, $merge);

                if ($mode === 'editor' || ($value === '' && $mode !== 'public')) {
                    // A visible chip, never an empty hole in the layout.
                    $inner = '<span class="doc-merge" data-token="' . e($token) . '">' . e(DocumentMergeFields::label($token)) . '</span>';
                } else {
                    $inner = e($value);
                }
            } else {
                $inner = e(is_string($run['t'] ?? null) ? $run['t'] : '');
            }

            foreach (['b', 'i', 'u'] as $tag) {
                if (($run[$tag] ?? false) === true) {
                    $inner = "<{$tag}>{$inner}</{$tag}>";
                }
            }

            if (isset($run['href']) && BlockSchema::isSafeHref($run['href'])) {
                $inner = '<a href="' . e(trim($run['href'])) . '" rel="noopener noreferrer nofollow" target="_blank">' . $inner . '</a>';
            }

            $html .= $inner;
        }

        return new HtmlString($html);
    }

    /**
     * Catalog images the document's Business actually owns, by uid. A uid that
     * is missing, foreign or unknown simply renders nothing.
     *
     * @param  array<int, mixed>  $blocks
     * @return array<string, CatalogItemImage>
     */
    private function images(array $blocks, mixed $businessId): array
    {
        if (! is_int($businessId) && ! (is_string($businessId) && ctype_digit($businessId))) {
            return [];
        }

        $uids = [];

        foreach ($blocks as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'image' && is_string($block['data']['catalog_image_uid'] ?? null)) {
                $uids[] = $block['data']['catalog_image_uid'];
            }
        }

        if ($uids === []) {
            return [];
        }

        return CatalogItemImage::query()
            ->whereIn('uid', array_unique($uids))
            ->whereHas('catalogItem', fn ($q) => $q->where('business_id', (int) $businessId))
            ->get()
            ->keyBy('uid')
            ->all();
    }

    private function dueText(mixed $item, string $timezone): string
    {
        $dueAt = is_object($item) ? ($item->due_at ?? null) : ($item['due_at'] ?? null);

        if ($dueAt !== null && $dueAt !== '') {
            try {
                $date = $dueAt instanceof CarbonInterface ? Carbon::instance($dueAt) : Carbon::parse($dueAt);

                return 'Due ' . $date->setTimezone($timezone)->format('j F Y');
            } catch (\Throwable) {
                // fall through to the relative wording
            }
        }

        $kind = is_object($item) ? ($item->kind ?? '') : ($item['kind'] ?? '');
        $kind = $kind instanceof \BackedEnum ? $kind->value : (string) $kind;

        return $kind === 'balance' ? 'Due after the deposit is paid' : 'Due after signing';
    }

    /**
     * @return array<int, mixed>
     */
    private function toList(mixed $items): array
    {
        if ($items instanceof \Illuminate\Support\Collection) {
            return $items->values()->all();
        }

        return is_iterable($items) ? array_values(is_array($items) ? $items : iterator_to_array($items, false)) : [];
    }
}
