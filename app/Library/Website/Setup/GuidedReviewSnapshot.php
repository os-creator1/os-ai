<?php

namespace App\Library\Website\Setup;

use App\Enums\Website\WebsiteAssetPurpose;
use App\Models\Business;
use App\Models\QuestionnaireResponse;

/**
 * The final review of a guided setup, before anything is generated: what the
 * website will be built from, in plain numbers, and which real photographs are
 * still missing (a draft may be generated without them; publishing needs them —
 * see WebsiteMediaRequirements). Reads the owner's answers and uploads only;
 * writes nothing.
 */
final class GuidedReviewSnapshot
{
    /**
     * @return ?array{business: array<string, mixed>, counts: array<string, int|string|null>, missing: array<int, array{title: string, detail: string, step: string}>, template: ?string, booking: ?string}
     *         null for a questionnaire that is not the guided one
     */
    public function forResponse(Business $business, QuestionnaireResponse $response): ?array
    {
        $steps = collect($response->version->steps());

        if (! $steps->contains(fn (array $s) => ($s['target_module'] ?? null) === 'business_service' && in_array('cover', (array) ($s['row_fields'] ?? []), true))) {
            return null;
        }

        $answers = (array) ($response->answers ?? []);
        $website = $response->website;
        $named = fn (string $key) => array_values(array_filter((array) ($answers[$key] ?? []), fn ($row) => is_array($row) && trim((string) ($row['name'] ?? '')) !== ''));

        $booths = array_values(array_filter($named('booth_services'), fn ($row) => $row['visible'] ?? true));
        $events = array_values(array_filter($named('event_types'), fn ($row) => $row['visible'] ?? true));
        $offersBackdrops = ($answers['offers_backdrops'] ?? null) === true;
        $backdrops = $offersBackdrops ? $named('backdrops') : [];
        $packages = (array) (WizardPresentationAnswers::catalogSelection($response) ?? []);

        $gallery = $website?->assets()->where('purpose', WebsiteAssetPurpose::Gallery->value)->orderBy('sort_order')->get() ?? collect();
        $hasHero = $gallery->isNotEmpty() || ($website !== null && is_string($website->theme['hero_asset_uid'] ?? null));

        $missing = [];

        if (! $hasHero) {
            $missing[] = ['title' => 'Homepage cover photo', 'detail' => 'Upload at least one real event photo and choose it as your homepage cover.', 'step' => 'gallery'];
        }

        foreach ($booths as $row) {
            if (empty($row['image_path'])) {
                $missing[] = ['title' => 'Photo needed: ' . $row['name'], 'detail' => 'Every booth shown on your website needs a real photo of that booth. Add one — or hide the booth.', 'step' => 'booth_services'];
            }
        }

        foreach ($backdrops as $row) {
            if (empty($row['image_path']) && empty($row['images'])) {
                $missing[] = ['title' => 'Photo needed: ' . $row['name'], 'detail' => 'A backdrop needs a real photo to be shown. Add one — or remove the backdrop.', 'step' => 'backdrops'];
            }
        }

        $photos = $gallery->count()
            + count(array_filter($booths, fn ($row) => ! empty($row['image_path'])))
            + count(array_filter($backdrops, fn ($row) => ! empty($row['image_path'])));

        $booking = match ($answers['primary_cta'] ?? null) {
            'call' => 'Call now',
            'book_now' => 'Book now',
            'quote_request' => 'Request a quote',
            default => null,
        };

        return [
            'business' => [
                'name' => (string) ($answers['business_name'] ?? $business->name),
                'phone' => (string) ($answers['phone'] ?? $business->phone),
                'email' => (string) ($answers['email'] ?? $business->email),
                'base' => collect([$answers['base_location']['city'] ?? null, $answers['base_location']['region'] ?? null])->filter()->implode(', '),
                'areas' => count((array) ($answers['service_area_cities'] ?? [])),
            ],
            'counts' => [
                'booths' => count($booths),
                'events' => count($events),
                'packages' => count($packages),
                'backdrops' => count($backdrops),
                'photos' => $photos,
                'pricing' => $answers['pricing_strategy'] ?? null,
            ],
            'missing' => $missing,
            'template' => $website?->template_key,
            'booking' => $booking,
        ];
    }
}
