<?php

declare(strict_types=1);

namespace App\Library\Opportunity;

use App\Models\Business;

/**
 * The Home "Complete your business description" proposal.
 *
 * Deterministic and free: assembled only from confirmed, saved Business facts (name, industry, primary service,
 * primary city/region, saved service-area cities). No AI request, no price, award, experience, review, venue or
 * guarantee is ever stated. With too few facts it returns null and Home shows a manual fallback instead of invented text.
 */
final class DescriptionProposal
{
    public const MIN = 50;

    public const MAX = 5000;

    /** @return array{facts: array<string, mixed>, draft: ?string} */
    public function for(Business $business): array
    {
        $facts = $this->facts($business);

        return ['facts' => $facts, 'draft' => $this->draft($facts)];
    }

    /** @return array<string, mixed> confirmed facts only; absent facts are null/empty, never guessed */
    public function facts(Business $business): array
    {
        $location = $business->primaryLocation()->first();
        $service = $business->primaryService()->first();
        $industry = $business->industry === null ? null : (string) $business->industry->label();
        $other = trim((string) ($business->industry_other ?? ''));

        if ($business->industry?->value === 'other') {
            $industry = $other !== '' ? $other : null;
        }

        $areas = collect((array) ($location?->service_area_cities ?? []))
            ->filter(fn ($c): bool => is_string($c) && trim($c) !== '')
            ->map(fn (string $c): string => trim($c))
            ->unique()->take(5)->values()->all();

        return [
            'name' => trim((string) $business->name),
            'industry' => $industry,
            'service' => $service === null ? null : trim((string) $service->name),
            'city' => $location === null ? null : trim((string) $location->city),
            'region' => $location === null ? null : trim((string) $location->region),
            'areas' => $areas,
        ];
    }

    /** @param array<string, mixed> $facts */
    public function draft(array $facts): ?string
    {
        $name = (string) ($facts['name'] ?? '');
        $service = (string) ($facts['service'] ?? '');
        $industry = (string) ($facts['industry'] ?? '');
        $city = (string) ($facts['city'] ?? '');
        $region = (string) ($facts['region'] ?? '');

        // Enough confirmed facts: a name, something we offer or the kind of business, and where.
        if ($name === '' || ($service === '' && $industry === '') || $city === '') {
            return null;
        }

        $place = $region !== '' ? "{$city}, {$region}" : $city;
        $sentences = [$service !== '' ? "{$name} provides {$service} in {$place}." : "{$name} is a {$industry} business based in {$place}."];

        if ($service !== '' && $industry !== '') {
            $sentences[] = "We are a {$industry} business.";
        }

        $areas = array_values(array_filter((array) ($facts['areas'] ?? []), fn (string $a): bool => strcasecmp($a, $city) !== 0));

        if ($areas !== []) {
            $sentences[] = 'We also serve ' . $this->join($areas) . '.';
        }

        $sentences[] = 'Get in touch to find out how we can help.';
        $text = implode(' ', $sentences);

        return mb_strlen($text) >= self::MIN && mb_strlen($text) <= self::MAX ? $text : null;
    }

    public static function valid(string $text): bool
    {
        $length = mb_strlen(trim($text));

        return $length >= self::MIN && $length <= self::MAX;
    }

    /** @param array<int, string> $items */
    private function join(array $items): string
    {
        $last = array_pop($items);

        return $items === [] ? (string) $last : implode(', ', $items) . ' and ' . $last;
    }
}
