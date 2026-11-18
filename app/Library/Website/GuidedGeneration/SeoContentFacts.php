<?php

namespace App\Library\Website\GuidedGeneration;

use App\Library\Website\WebsitePageStrategy;
use App\Models\Business;
use Illuminate\Support\Str;

/**
 * The bounded, confirmed facts that SEO content quality is built from and checked against: the primary market, the primary
 * service, the real services/events/areas and the confirmed differentiators. One source for three consumers — the AI prompt
 * (so a generated page can name them), PageContentQuality (so a page that ignores them is rejected) and the owner-facing
 * content-gap diagnostics (so a missing fact is reported, never filled with invented copy).
 *
 * Every list is capped, so passing it to the AI keeps the token-aware batching budget (GenerationBatchPlanner) honest: the
 * prompt estimate is built from the very same bundle. Reads only canonical Business data; no AI, no network.
 *
 * @phpstan-type Facts array{market: ?array{city: string, region: ?string}, primary_service: ?string, services: array<int, array{name: string, description: ?string}>, events: array<int, string>, areas: array<int, string>, differentiators: array<int, string>}
 */
final class SeoContentFacts
{
    public const MAX_SERVICES = 10;
    public const MAX_EVENTS = 6;
    public const MAX_AREAS = 10;
    public const MAX_DIFFERENTIATORS = 6;
    private const MAX_TEXT = 120;

    /**
     * @param  array<string, mixed>  $canonicalFacts  GuidedWebsiteGenerationClient::canonicalFacts()
     * @return array<string, mixed>
     */
    public static function build(Business $business, WebsitePageStrategy $strategy, array $canonicalFacts): array
    {
        $location = $business->primaryLocation()->first();
        $city = trim((string) ($location?->city ?? ''));
        $region = trim((string) ($location?->region ?? ''));

        $services = $strategy->eligibleServices($business)->take(self::MAX_SERVICES)->map(fn ($service) => [
            'name' => trim((string) $service->name),
            'description' => ($text = self::clean($service->description)) === '' ? null : $text,
        ])->filter(fn (array $service) => $service['name'] !== '')->values()->all();

        $events = $strategy->eligibleEventTypes($business)->take(self::MAX_EVENTS)->pluck('name')->map(fn ($name) => trim((string) $name))->filter()->values()->all();

        $areas = collect((array) ($location?->service_area_cities ?? []))
            ->map(fn ($entry) => trim((string) Str::before((string) (is_array($entry) ? ($entry['name'] ?? '') : $entry), ',')))
            ->filter()
            ->reject(fn (string $area) => $city !== '' && Str::slug($area) === Str::slug($city))
            ->unique(fn (string $area) => Str::slug($area))
            ->take(self::MAX_AREAS)
            ->values()
            ->all();

        $differentiators = collect((array) ($canonicalFacts['differentiators'] ?? []))
            ->map(fn ($item) => self::clean(is_string($item) ? $item : null))
            ->filter()
            ->take(self::MAX_DIFFERENTIATORS)
            ->values()
            ->all();

        return [
            'market' => $city === '' ? null : ['city' => $city, 'region' => $region === '' ? null : $region],
            'primary_service' => $services[0]['name'] ?? null,
            'services' => $services,
            'events' => $events,
            'areas' => $areas,
            'differentiators' => $differentiators,
        ];
    }

    /**
     * The part of the bundle the AI is shown (differentiators already travel in confirmed_facts, so they are not repeated).
     *
     * @param  array<string, mixed>  $facts
     * @return array<string, mixed>
     */
    public static function promptContext(array $facts): array
    {
        return array_filter([
            'primary_market' => $facts['market'] ?? null,
            'primary_service' => $facts['primary_service'] ?? null,
            'services' => array_map(fn (array $service) => array_filter($service, fn ($value) => $value !== null), (array) ($facts['services'] ?? [])),
            'event_types' => $facts['events'] ?? [],
            'areas_served' => $facts['areas'] ?? [],
        ], fn ($value) => $value !== null && $value !== []);
    }

    /**
     * Actionable, owner-facing statements of what real information is missing for stronger pages. Nothing here is ever turned
     * into copy: a gap means the page says less, not that something is invented.
     *
     * @param  array<string, mixed>  $facts
     * @param  array<int, array<string, mixed>>  $plan
     * @return array<int, string>
     */
    public static function gaps(array $facts, array $plan = []): array
    {
        $gaps = [];

        if (($facts['market'] ?? null) === null) {
            $gaps[] = 'Content gap: add the city (and state) of your main location, so the home page headline can say where you work.';
        }

        if (($facts['services'] ?? []) === []) {
            $gaps[] = 'Content gap: add at least one active service, so the website can say what you offer and give it a page.';
        } else {
            $undescribed = array_values(array_map(fn (array $service) => $service['name'], array_filter($facts['services'], fn (array $service) => $service['description'] === null)));
            if ($undescribed !== []) {
                $gaps[] = 'Content gap: add a short description (what it is, who it is for, what is included) for ' . implode(', ', array_slice($undescribed, 0, 5)) . ', so its page can be specific instead of generic.';
            }
        }

        if (($facts['differentiators'] ?? []) === []) {
            $gaps[] = 'Content gap: add what genuinely sets you apart (confirmed differentiators), so the home page can state real reasons to choose you.';
        }

        foreach ($plan as $page) {
            $area = is_array($page['entity'] ?? null) ? ($page['entity']['area'] ?? null) : null;
            $local = is_array($page['entity'] ?? null) ? (array) ($page['entity']['local_facts'] ?? []) : [];

            if (is_string($area) && $local === []) {
                $gaps[] = "Content gap: add a venue, travel or setup detail for {$area}, so its page has local information of its own.";
            }
        }

        return $gaps;
    }

    private static function clean(?string $text): string
    {
        $clean = trim(preg_replace('/\s+/u', ' ', strip_tags((string) $text)) ?? '');

        return Str::limit($clean, self::MAX_TEXT, '');
    }
}
