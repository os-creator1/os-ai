<?php

namespace App\Library\Website;

use App\Models\Business;
use App\Models\WebsitePage;

/**
 * One owner-requested suggestion for a draft page's meta description. It goes through WebsiteAiGenerationClient, so it is entitled,
 * budget-checked and metered by AiGateway like every other website AI call. It READS only (the business name and the selected draft
 * page's own title and text) and WRITES nothing: the owner reviews the text and saves it through the normal page form
 * (WebsiteDraftPageService). A suggestion that is the wrong length or carries a number the source text does not contain is rejected,
 * never shown.
 */
class WebsiteMetaDescriptionSuggester
{
    public const MIN = 70;

    public const MAX = 160;

    private const CONTEXT_CAP = 1500;

    private const SKIP_KEYS = ['uid', 'id', 'type', 'url', 'href', 'link', 'image', 'asset', 'asset_uid', 'form', 'form_uid', 'icon', 'cta_url', 'src', 'anchor'];

    public function __construct(private readonly WebsiteAiGenerationClient $client)
    {
    }

    /** @return array{ok: bool, description: ?string, message: ?string, budget: bool} */
    public function suggest(Business $business, WebsitePage $page, ?int $actorUserId): array
    {
        $context = $this->context($business, $page);

        if (trim((string) $page->title) === '' && $context['body'] === '') {
            return $this->fail('This page has no content to base a description on yet. Add some content first.');
        }

        $messages = [
            ['role' => 'system', 'content' => 'You write one meta description for a small business web page. Use ONLY the facts supplied. Never invent services, locations, prices, numbers, testimonials, awards, guarantees or claims. Write 120 to 160 characters, plain and specific, no quotation marks. Reply as JSON: {"description": "..."}.'],
            ['role' => 'user', 'content' => "Business: {$context['business']}\nPage title: {$context['title']}\nPage text:\n{$context['body']}"],
        ];

        $raw = $this->client->complete($messages, $business, $actorUserId, 200);

        if ($this->client->lastCallWasBudgetExhausted()) {
            return ['ok' => false, 'description' => null, 'message' => 'Your included AI for this period is used up, so no suggestion was made. You can still write the description yourself.', 'budget' => true];
        }

        if ($raw === null) {
            return $this->fail('A suggestion is not available right now. You can still write the description yourself.');
        }

        $description = $this->clean($raw, $context['business'] . ' ' . $context['title'] . ' ' . $context['body']);

        return $description === null
            ? $this->fail('We could not make a reliable suggestion for this page. You can still write the description yourself.')
            : ['ok' => true, 'description' => $description, 'message' => null, 'budget' => false];
    }

    /** @return array{business: string, title: string, body: string} */
    private function context(Business $business, WebsitePage $page): array
    {
        $parts = [];
        $this->collect(is_array($page->sections) ? $page->sections : [], $parts);

        return [
            'business' => trim((string) $business->name),
            'title' => trim((string) $page->title),
            'body' => mb_substr(trim(implode("\n", $parts)), 0, self::CONTEXT_CAP),
        ];
    }

    private function collect(array $node, array &$parts): void
    {
        foreach ($node as $key => $value) {
            if (is_string($key) && in_array($key, self::SKIP_KEYS, true)) {
                continue;
            }

            if (is_array($value)) {
                $this->collect($value, $parts);
            } elseif (is_string($value)) {
                $text = trim(strip_tags($value));

                if (mb_strlen($text) >= 3 && ! str_starts_with($text, 'http')) {
                    $parts[] = $text;
                }
            }
        }
    }

    private function clean(string $raw, string $source): ?string
    {
        $decoded = json_decode($raw, true);
        $text = is_array($decoded) && is_string($decoded['description'] ?? null) ? $decoded['description'] : null;

        if ($text === null) {
            return null;
        }

        $text = trim(preg_replace('/\s+/u', ' ', strip_tags($text)) ?? '', " \t\n\r\0\x0B\"'");
        $length = mb_strlen($text);

        if ($length < self::MIN || $length > self::MAX) {
            return null;
        }

        // Any number in the suggestion must already be in the source text: no invented prices, years or counts.
        preg_match_all('/\d+/', $text, $numbers);

        preg_match_all('/\d+/', $source, $sourceNumbers);

        foreach ($numbers[0] as $number) {
            // Compare complete numeric tokens: "19" must not match source year "1998".
            if (! in_array($number, $sourceNumbers[0], true)) {
                return null;
            }
        }

        return $text;
    }

    /** @return array{ok: bool, description: ?string, message: ?string, budget: bool} */
    private function fail(string $message): array
    {
        return ['ok' => false, 'description' => null, 'message' => $message, 'budget' => false];
    }
}
