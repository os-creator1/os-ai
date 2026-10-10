<?php

namespace App\Library\Website;

use App\Library\NicheBlueprint\Workspace\BlueprintConfigReader;
use App\Models\Business;
use Illuminate\Support\Collection;

/**
 * The Website module's ONE consumer of the Niche Blueprint's `website_config` seam
 * (BlueprintConfigReader::websiteConfig). It is deliberately thin:
 *
 *  - it only TRANSLATES the Blueprint's vocabulary into what the live Website module
 *    actually offers (template keys, page keys / types, section types), and drops anything
 *    that has no live counterpart — it never invents a template, a page or a section;
 *  - it only SUGGESTS: the wizard pre-selects the preferred template (the owner still
 *    chooses) and the guided-generation prompt gets niche hints. Nothing here writes to a
 *    Website, so an existing or customised Website is never overwritten by a Blueprint.
 *
 * If the Website's vocabulary changes, the mapping below is the only place to adapt.
 */
class WebsiteBlueprintDefaults
{
    /**
     * Blueprint section names -> the Website's real section types. The Website renders
     * catalog packages and add-ons through its `services` section, so both map to it.
     * A name that is neither listed here nor already a Website section type is dropped.
     */
    private const SECTION_MAP = [
        'packages' => 'services',
        'add_ons' => 'services',
    ];

    private const MAX_PROMPTS = 10;
    private const MAX_PROMPT_LENGTH = 500;

    public function __construct(
        private readonly BlueprintConfigReader $reader,
        private readonly ?\App\Library\Acquisition\PurposeWebsiteIntents $intents = null,
    ) {
    }

    /**
     * The Blueprint's preferred template, only when the Website actually offers it to this
     * Business (active and for its niche). Otherwise null: the wizard simply shows no
     * pre-selection rather than offering something that cannot be chosen.
     *
     * @param  Collection<int, \App\Models\WebsiteTemplate>|iterable  $offered
     */
    public function preferredTemplateKey(Business $business, iterable $offered): ?string
    {
        $key = $this->reader->websiteConfig($business)['template_key'] ?? null;

        if (! is_string($key) || $key === '') {
            return null;
        }

        foreach ($offered as $template) {
            if ((string) $template->key === $key) {
                return $key;
            }
        }

        return null;
    }

    /**
     * Niche hints for guided generation, restricted to the plan the Website itself built:
     * suggested sections are the Blueprint's, translated, and intersected with what each
     * planned page is allowed to contain. Null when the Business has no usable defaults, so
     * the generation prompt is byte-for-byte unchanged for every Business without a Blueprint.
     *
     * @param  array<int, array<string, mixed>>  $plan  WebsitePageStrategy::buildPlan() output
     * @return array{content_prompts: list<string>, suggested_sections: array<string, list<string>>}|null
     */
    public function generationHints(Business $business, array $plan): ?array
    {
        $config = $this->reader->websiteConfig($business);

        // Acquisition Purpose V1: each goal's website intent (a student page and a separate teacher page for
        // tutoring) guides generation too. A Business with no goals is byte-for-byte unaffected.
        $intentPrompts = ($this->intents ?? app(\App\Library\Acquisition\PurposeWebsiteIntents::class))->generationPrompts($business);

        if ($config === null && $intentPrompts === []) {
            return null;
        }

        $config ??= [];

        $prompts = [];
        foreach ((array) ($config['content_prompts'] ?? []) as $prompt) {
            $prompt = trim((string) $prompt);
            if ($prompt !== '') {
                $prompts[] = mb_substr($prompt, 0, self::MAX_PROMPT_LENGTH);
            }
        }
        foreach ($intentPrompts as $prompt) {
            $prompts[] = $prompt;
        }

        $prompts = array_slice($prompts, 0, self::MAX_PROMPTS);

        $bySection = [];
        foreach ((array) ($config['pages'] ?? []) as $page) {
            $bySection[(string) ($page['page'] ?? '')] = (array) ($page['sections'] ?? []);
        }

        $suggested = [];
        foreach ($plan as $planned) {
            $wanted = $bySection[(string) $planned['page_key']] ?? $bySection[(string) $planned['page_type']] ?? null;

            if ($wanted === null) {
                continue;
            }

            $allowed = (array) ($planned['allowed_section_types'] ?? []);
            $sections = [];
            foreach ($wanted as $section) {
                $section = self::SECTION_MAP[$section] ?? (string) $section;
                if (in_array($section, $allowed, true) && ! in_array($section, $sections, true)) {
                    $sections[] = $section;
                }
            }

            if ($sections !== []) {
                $suggested[(string) $planned['page_key']] = $sections;
            }
        }

        if ($prompts === [] && $suggested === []) {
            return null;
        }

        return ['content_prompts' => $prompts, 'suggested_sections' => $suggested];
    }
}
