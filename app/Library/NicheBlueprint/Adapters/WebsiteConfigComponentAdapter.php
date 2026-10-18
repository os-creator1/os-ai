<?php

namespace App\Library\NicheBlueprint\Adapters;

use App\Enums\NicheBlueprint\BlueprintUpdatePolicy;
use App\Models\WebsiteTemplate;
use InvalidArgumentException;

/**
 * Blueprint V2 — the stable Website CONFIGURATION SEAM.
 *
 * Carries the preferred Website template key, page strategy, default section
 * structure per page, navigation and niche content prompts. It never touches
 * the Website renderer or the `websites`/`website_pages` tables: the Website
 * module reads it through `BlueprintConfigReader::websiteConfig()` and decides
 * how to apply it. See docs/product/NICHE-BLUEPRINT-V2.md "Website seam" for
 * the adapter the final Website integration may need.
 */
final class WebsiteConfigComponentAdapter extends ConfigOnlyBlueprintComponentAdapter
{
    public const TYPE = 'website_config';

    public function componentType(): string
    {
        return self::TYPE;
    }

    public function validateDescriptor(array $payload): void
    {
        $this->parse($payload);
    }

    /** @return array<string, mixed> */
    private function parse(array $payload): array
    {
        $templateKey = $this->requireString($payload, 'template_key', 80);

        if (! WebsiteTemplate::query()->where('key', $templateKey)->where('is_active', true)->exists()) {
            throw new InvalidArgumentException("\"template_key\" [{$templateKey}] is not an active Website template.");
        }

        $pages = $payload['pages'] ?? [];

        if (! is_array($pages) || ! array_is_list($pages) || $pages === []) {
            throw new InvalidArgumentException('Add at least one page to the page strategy.');
        }

        $clean = [];

        foreach ($pages as $i => $page) {
            $n = $i + 1;

            if (! is_array($page)) {
                throw new InvalidArgumentException("Page {$n} must be an object.");
            }

            $clean[] = [
                'page' => $this->requireString($page, 'page', 40),
                'title' => $this->requireString($page, 'title', 120),
                'sections' => $this->stringList($page, 'sections', 30, 60, false),
            ];
        }

        return [
            'template_key' => $templateKey,
            'pages' => $clean,
            'navigation' => $this->stringList($payload, 'navigation', 12, 60, false),
            'content_prompts' => $this->stringList($payload, 'content_prompts', 40, 500, false),
        ];
    }

    public function surface(): string
    {
        return 'website';
    }

    public function updatePolicy(): BlueprintUpdatePolicy
    {
        return BlueprintUpdatePolicy::Copy;
    }

    public function featureKey(): string
    {
        return 'website_generation';
    }

    public function typeLabel(): string
    {
        return 'Website defaults';
    }

    public function summary(array $payload): string
    {
        return 'Template '.($payload['template_key'] ?? '?').', '.count($payload['pages'] ?? []).' pages';
    }

    public function formFields(): array
    {
        $templates = WebsiteTemplate::query()->where('is_active', true)->orderBy('key')->get()
            ->mapWithKeys(fn ($t) => [(string) $t->key => (string) $t->display_name.' ('.$t->key.')'])->all();

        return [
            ['name' => 'template_key', 'label' => 'Preferred Website template', 'type' => 'select', 'required' => true, 'options' => $templates],
            ['name' => 'pages', 'label' => 'Page strategy', 'type' => 'lines', 'required' => true,
                'help' => 'One per line: page key | Page title | section, section, section'],
            ['name' => 'navigation', 'label' => 'Navigation', 'type' => 'lines', 'required' => false, 'help' => 'One menu label per line, in order.'],
            ['name' => 'content_prompts', 'label' => 'Niche content prompts', 'type' => 'lines', 'required' => false,
                'help' => 'One prompt/default per line, used by guided content generation.'],
        ];
    }

    public function payloadFromInput(array $input): array
    {
        $pages = [];

        foreach ($this->linesOf($input['pages'] ?? '') as $line) {
            [$key, $title, $sections] = $this->pipeParts($line, 3);
            $pages[] = [
                'page' => (string) $key,
                'title' => (string) $title,
                'sections' => array_values(array_filter(array_map('trim', explode(',', (string) $sections)))),
            ];
        }

        $payload = [
            'template_key' => trim((string) ($input['template_key'] ?? '')),
            'pages' => $pages,
            'navigation' => $this->linesOf($input['navigation'] ?? ''),
            'content_prompts' => $this->linesOf($input['content_prompts'] ?? ''),
        ];

        $this->parse($payload);

        return $payload;
    }

    public function inputFromPayload(array $payload): array
    {
        $lines = array_map(
            fn (array $p) => $p['page'].' | '.$p['title'].' | '.implode(', ', $p['sections'] ?? []),
            $payload['pages'] ?? [],
        );

        return [
            'template_key' => $payload['template_key'] ?? '',
            'pages' => implode("\n", $lines),
            'navigation' => implode("\n", $payload['navigation'] ?? []),
            'content_prompts' => implode("\n", $payload['content_prompts'] ?? []),
        ];
    }
}
