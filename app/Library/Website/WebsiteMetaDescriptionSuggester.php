<?php

namespace App\Library\Website;

use App\Models\Business;
use App\Models\WebsitePage;
use Illuminate\Support\Facades\Log;

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

    /** The first attempt plus exactly one retry. */
    private const MAX_ATTEMPTS = 2;

    private const RETRY_HINTS = [
        'invalid_json' => 'Your previous answer was not a valid JSON object.',
        'missing_description' => 'Your previous answer had no "description" text.',
        'too_short' => 'Your previous description was too short. It must be between 120 and 160 characters.',
        'too_long' => 'Your previous description was too long. It must be between 120 and 160 characters.',
        'unsupported_number' => 'Your previous description used a number that is not in the supplied text. Use no digits except those in the supplied text.',
    ];

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
            ['role' => 'system', 'content' => 'You write one meta description for a small business web page. Use ONLY the facts supplied. Never invent services, locations, prices, numbers, testimonials, awards, guarantees or claims. Do not use any digit that does not already appear in the supplied text. Write between 120 and 160 characters, plain and specific, no quotation marks, no emoji, no markdown. Reply with ONLY this JSON object and nothing else: {"description": "..."}.'],
            ['role' => 'user', 'content' => "Business: {$context['business']}\nPage title: {$context['title']}\nPage text:\n{$context['body']}"],
        ];

        $source = $context['business'] . ' ' . $context['title'] . ' ' . $context['body'];

        // One first attempt and at most ONE bounded retry. Every attempt is a separate, metered AiGateway call (its budget and
        // authorization rules apply to each); nothing is retried after a refusal or a provider failure.
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $raw = $this->client->complete($messages, $business, $actorUserId, 200);

            if ($this->client->lastCallWasBudgetExhausted()) {
                return ['ok' => false, 'description' => null, 'message' => 'Your included AI for this period is used up, so no suggestion was made. You can still write the description yourself.', 'budget' => true];
            }

            if ($raw === null) {
                return $this->fail('A suggestion is not available right now. You can still write the description yourself.');
            }

            [$description, $reason] = $this->clean($raw, $source);

            if ($description !== null) {
                return ['ok' => true, 'description' => $description, 'message' => null, 'budget' => false];
            }

            // Safe diagnostics: a fixed reason code and ids only. Never the generated text, the prompt or the page content.
            Log::warning('motiongrove.website.meta_description_rejected', [
                'reason' => $reason,
                'attempt' => $attempt,
                'business_id' => $business->id,
                'page_id' => $page->id,
            ]);

            $messages = [...array_slice($messages, 0, 2), ['role' => 'user', 'content' => self::RETRY_HINTS[$reason] . ' Reply with ONLY {"description": "..."}.']];
        }

        return $this->fail('We could not make a reliable suggestion for this page. You can still write the description yourself.');
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

    /**
     * @return array{0: ?string, 1: ?string} [description, null] when valid, otherwise [null, reason code] where the reason is one of
     *                                       invalid_json, missing_description, too_short, too_long, unsupported_number.
     */
    private function clean(string $raw, string $source): array
    {
        $decoded = $this->decode($raw);

        if ($decoded === null) {
            return [null, 'invalid_json'];
        }

        $text = $decoded['description'] ?? $decoded['meta_description'] ?? null;

        if (! is_string($text) || trim($text) === '') {
            return [null, 'missing_description'];
        }

        $text = trim(preg_replace('/\s+/u', ' ', strip_tags($text)) ?? '', " \t\n\r\0\x0B\"'\u{201C}\u{201D}");
        $length = mb_strlen($text);

        if ($length < self::MIN) {
            return [null, 'too_short'];
        }

        if ($length > self::MAX) {
            return [null, 'too_long'];
        }

        // Any number in the suggestion must already be in the source text: no invented prices, years or counts.
        preg_match_all('/\d+/', $text, $numbers);

        preg_match_all('/\d+/', $source, $sourceNumbers);

        foreach ($numbers[0] as $number) {
            // Compare complete numeric tokens: "19" must not match source year "1998".
            if (! in_array($number, $sourceNumbers[0], true)) {
                return [null, 'unsupported_number'];
            }
        }

        return [$text, null];
    }

    /** Tolerates a markdown code fence or a sentence around the JSON object; anything else that is not a JSON object is invalid. */
    private function decode(string $raw): ?array
    {
        $candidates = [trim($raw)];

        $start = strpos($raw, '{');
        $end = strrpos($raw, '}');

        if ($start !== false && $end !== false && $end > $start) {
            $candidates[] = substr($raw, $start, $end - $start + 1);
        }

        foreach ($candidates as $candidate) {
            $decoded = json_decode($candidate, true);

            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    /** @return array{ok: bool, description: ?string, message: ?string, budget: bool} */
    private function fail(string $message): array
    {
        return ['ok' => false, 'description' => null, 'message' => $message, 'budget' => false];
    }
}
