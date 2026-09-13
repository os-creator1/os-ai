<?php

namespace App\Library\Website;

use App\Models\Website;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Website Generation + Hosting Slice A contract §14/§15/§16. Builds a
 * prompt from ONLY the §15 AVAILABLE-classified Business fields for the
 * one Business being generated for, calls WebsiteAiGenerationClient,
 * and validates the structured JSON response server-side against the
 * exact bounded component schema — an unvalidated model response is
 * never persisted, not even transiently. At most one bounded automatic
 * retry (re-prompting with the specific validation errors appended)
 * before returning a clear failure.
 *
 * v1 simplification (locked, §16): generated content is always
 * asset-reference-free — every image/background_image field is emitted
 * empty and filled in afterward by the human editor.
 *
 * Generation is user-initiated only, writes to DRAFT pages only, and is
 * only supported for a Website that has no pages yet — Slice A does not
 * define merge/overwrite semantics for regenerating an already-populated
 * draft.
 */
final class WebsiteAiDraftGenerator
{
    private const MAX_PAGES = 20;

    /**
     * §11.4 — the last generate() stopped because the included AI is used
     * up, which is a different fact from "generation failed" and needs a
     * different sentence.
     */
    private bool $pausedByBudget = false;

    public function __construct(
        private readonly WebsiteAiGenerationClient $client,
        private readonly WebsiteSectionValidator $sectionValidator,
        private readonly WebsiteDraftPageService $draftPages,
    ) {
    }

    /**
     * @return bool true on success, false on any failure (missing/
     *         inactive AI provider, invalid response after the one
     *         bounded retry, etc.) — never throws for an AI-side failure.
     * @throws ValidationException only if the Website already has pages
     */
    public function generate(Website $website): bool
    {
        if ($website->pages()->exists()) {
            throw ValidationException::withMessages([
                'website' => ['AI generation is only available before any pages exist on this Website.'],
            ]);
        }

        $context = $this->buildContext($website);
        $messages = $this->buildMessages($context);
        $business = $website->business;
        $actorUserId = Auth::id();

        $this->pausedByBudget = false;

        $pages = $this->requestAndValidate($messages, $business, $actorUserId);

        // Correction 6 — the one bounded retry exists to correct a malformed
        // response. A refused budget is not malformed, and asking again
        // cannot make the allowance reappear: retrying would burn a second
        // refusal and delay the honest answer.
        if ($pages === null && ! $this->pausedByBudget) {
            $messages[] = ['role' => 'user', 'content' => 'The previous response was not valid JSON matching the required schema. Please respond again with ONLY a valid JSON object matching the schema.'];
            $pages = $this->requestAndValidate($messages, $business, $actorUserId);
        }

        if ($pages === null) {
            return false;
        }

        foreach ($pages as $pageData) {
            $this->draftPages->createPage($website, $pageData);
        }

        return true;
    }

    /**
     * §11.4 — did the last generate() stop because the budget is exhausted,
     * rather than because the provider or the response failed? Editing the
     * website by hand is unaffected either way; only the sentence differs.
     */
    public function lastRunWasPausedByBudget(): bool
    {
        return $this->pausedByBudget;
    }

    /**
     * §11.4 — when the included AI comes back. The allowance is a UTC
     * calendar month for every current policy, so the next period begins
     * on the first of next month; the sentence names a date the customer
     * can act on rather than a vague 'later'.
     */
    public function budgetResetsOnLabel(): string
    {
        return \Carbon\CarbonImmutable::now('UTC')->addMonthNoOverflow()->startOfMonth()->format('j F');
    }

    private function requestAndValidate(array $messages, \App\Models\Business $business, ?int $actorUserId): ?array
    {
        $raw = $this->client->complete($messages, $business, $actorUserId);

        if ($this->client->lastCallWasBudgetExhausted()) {
            $this->pausedByBudget = true;

            return null;
        }

        if ($raw === null) {
            return null;
        }

        $decoded = json_decode($raw, true);

        if (! is_array($decoded) || ! isset($decoded['pages']) || ! is_array($decoded['pages'])) {
            return null;
        }

        if (count($decoded['pages']) === 0 || count($decoded['pages']) > self::MAX_PAGES) {
            return null;
        }

        $homeCount = 0;
        $validated = [];

        foreach ($decoded['pages'] as $page) {
            if (! is_array($page) || ! isset($page['title'], $page['sections']) || ! is_array($page['sections'])) {
                return null;
            }

            $isHome = (bool) ($page['is_home'] ?? false);
            $homeCount += $isHome ? 1 : 0;
            $slug = $isHome ? null : ($page['slug'] ?? null);

            if (! $isHome && (! is_string($slug) || ! WebsiteSlugRules::isValid($slug))) {
                return null;
            }

            try {
                $this->sectionValidator->validate($page['sections'], [], false);
            } catch (ValidationException) {
                return null;
            }

            $validated[] = [
                'title' => (string) $page['title'],
                'is_home' => $isHome,
                'slug' => $slug,
                'sections' => $page['sections'],
            ];
        }

        if ($homeCount !== 1) {
            return null;
        }

        return $validated;
    }

    private function buildContext(Website $website): array
    {
        $business = $website->business;
        $location = $business?->primaryLocation;

        return array_filter([
            'name' => $business?->name,
            'description' => $business?->description,
            'industry' => $business?->industry?->value,
            'phone' => $business?->phone,
            'email' => $business?->email,
            'city' => $location?->city,
            'region' => $location?->region,
            'services' => $business?->services?->pluck('name')->all(),
        ], fn ($value) => $value !== null && $value !== [] && $value !== '');
    }

    private function buildMessages(array $context): array
    {
        $schema = 'A JSON object with a single key "pages", an array of 1-' . self::MAX_PAGES . ' page objects. '
            . 'Each page object has: "title" (string), "is_home" (boolean, exactly one page must be true), '
            . '"slug" (lowercase-hyphenated string, required unless is_home is true, omit/null when is_home is true), '
            . '"sections" (array of section objects). Each section object has "type" (one of: hero, text, image_text, services, '
            . 'testimonials, faq, cta, contact_details) and "data" (an object matching that section type). '
            . 'Never include an "image" or "background_image" field with any value — omit them entirely.';

        return [
            ['role' => 'system', 'content' => 'You generate initial draft website content for a local business. Respond with ONLY a valid JSON object, no prose. Schema: ' . $schema],
            ['role' => 'user', 'content' => 'Generate an initial small website (home page plus 1-3 supporting pages) for this business: ' . json_encode($context)],
        ];
    }
}
