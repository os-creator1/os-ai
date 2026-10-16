<?php

namespace App\Library\ExternalSite\Audit;

/**
 * One finding the evaluator decided on, before it is stored: a rule key, the page it
 * belongs to (null = about the whole site), a priority taken from the catalogue, a
 * stable scope and BOUNDED evidence.
 *
 * Evidence is plain data the crawler MEASURED, in a fixed shape:
 *   observed  one short sentence of what was actually seen
 *   values    scalar measurements (a count, a length, a status code ...)
 *   related   [{url, status?, note?}] other URLs involved (the destination of a broken
 *             link, the pages that share a title ...), at most 12
 *   proposal  an optional concrete suggested change (a replacement address, a
 *             suggested title) the owner can copy
 *
 * `findingKey()` is stable across crawls (rule + page + scope, each normalised), so a
 * later crawl can tell a finding that persists from a new one and verify a fix.
 */
final class ExternalFindingDraft
{
    private const MAX_RELATED = 12;

    private const MAX_TEXT = 300;

    /** @var array{observed: string, values: array<string, scalar|null>, related: list<array{url: string, status: ?int, note: ?string}>, proposal: ?string} */
    public readonly array $evidence;

    /**
     * @param  array<string, mixed>  $evidence
     */
    public function __construct(
        public readonly string $ruleKey,
        public readonly ?string $pageUrl,
        public readonly string $priority,
        public readonly string $scope = '',
        public readonly string $groupKey = '',
        array $evidence = [],
        public readonly ?string $confidence = null,
        public readonly string $importance = ExternalPageImportance::STANDARD,
    ) {
        $related = [];

        foreach (array_slice((array) ($evidence['related'] ?? []), 0, self::MAX_RELATED) as $item) {
            $related[] = [
                'url' => mb_substr((string) ($item['url'] ?? ''), 0, 1000),
                'status' => isset($item['status']) ? (int) $item['status'] : null,
                'note' => isset($item['note']) ? mb_substr((string) $item['note'], 0, 200) : null,
            ];
        }

        $values = [];

        foreach ((array) ($evidence['values'] ?? []) as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $values[(string) $key] = is_string($value) ? mb_substr($value, 0, self::MAX_TEXT) : $value;
            } elseif (is_array($value)) {
                // A short list of scalars (for example the headings seen) is kept as a bounded list.
                $values[(string) $key] = array_slice(array_map(fn ($v): string => mb_substr((string) (is_scalar($v) ? $v : json_encode($v)), 0, 200), array_values($value)), 0, 12);
            }
        }

        $this->evidence = [
            'observed' => mb_substr((string) ($evidence['observed'] ?? ''), 0, 500),
            'values' => $values,
            'related' => $related,
            'proposal' => isset($evidence['proposal']) ? mb_substr((string) $evidence['proposal'], 0, 500) : null,
        ];
    }

    public function findingKey(): string
    {
        $page = $this->pageUrl === null ? '' : (\App\Library\ExternalSite\UrlResolver::pageKey($this->pageUrl) ?? $this->pageUrl);

        return sha1($this->ruleKey.'|'.$page.'|'.$this->scope);
    }
}
