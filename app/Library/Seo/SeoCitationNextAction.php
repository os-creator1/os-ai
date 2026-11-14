<?php

namespace App\Library\Seo;

use App\Enums\Seo\SeoCitationDisplayState;
use App\Enums\Seo\SeoDirectoryImportance;

/**
 * The ONE prioritized next action for a Location's Citations page, plus the
 * reusable listing kit and the honest per-directory action wording.
 *
 * Pure: derived only from a SeoCitationLocationSection (already scoped to an
 * accessible Location), no I/O. It does not run a second recommendation engine
 * — the Google / inaccurate / missing ordering is the section's own
 * attentionItems(), the same rows the Growth `citations.*` rules read; this
 * class only puts "missing canonical business information" first and wraps the
 * winner with a why, a precise action and its evidence.
 *
 * "Not checked" is never "missing": a directory with no record is described as
 * "not checked yet". Nothing here marks anything Listed or verified, and no
 * ranking outcome is promised.
 *
 * Order: 1 missing business information, 2 Google connection/health/differences
 * (or stale Google data, stated as not verified), 3 known listing
 * inconsistencies, 4 unhandled Essential/Recommended directories, 5 unhandled
 * niche-specific directories, 6 listings due a review, else "all clear".
 */
final class SeoCitationNextAction
{
    public const MISSING_INFO = 'missing_info';
    public const GOOGLE = 'google';
    public const INCONSISTENT = 'inconsistent';
    public const PRIORITY_DIRECTORY = 'priority_directory';
    public const NICHE_DIRECTORY = 'niche_directory';
    public const REVIEW_DUE = 'review_due';
    public const CLEAR = 'clear';

    private const WHY_NAP = 'Directories and Google compare your name, address and phone, so keeping them consistent helps customers trust and find the right details. Listings do not guarantee any ranking change.';

    /**
     * @return array{kind: string, title: string, why: string, action: string, target: string, row: ?SeoCitationRow, evidence: array<int, string>}
     *   target: 'settings' | 'google' | 'directory' | 'none'
     */
    public static function for(SeoCitationLocationSection $section): array
    {
        $missing = self::missingInformation($section);

        if ($missing !== []) {
            return self::make(self::MISSING_INFO, 'Complete your business information', 'Every directory asks for these details, and they cannot be guessed. ' . self::WHY_NAP, 'Complete business profile', 'settings', null,
                array_map(fn (string $f) => $f . ' is not set in your business profile.', $missing));
        }

        $items = $section->attentionItems();

        foreach ($items as $item) {
            if ($item['row'] === null) {
                return self::make(self::GOOGLE, 'Fix your Google Business Profile', $item['message'] . ' Google is the listing customers see first in local search.', $item['action'], 'google', null, self::googleEvidence($section));
            }
        }

        if ($section->googleState() === SeoCitationLocationSection::GOOGLE_CONNECTED && ! $section->googleCheckedAutomatically()) {
            return self::make(self::GOOGLE, 'Refresh your Google Business Profile details', 'Google details for this location are missing or out of date, so they have not been compared with your business profile. This is not a verification.', 'Open Google Business Profile', 'google', null,
                ['Connected to Google, but no fresh details were available to compare.']);
        }

        foreach ($items as $item) {
            if ($item['kind'] === 'inaccurate') {
                return self::make(self::INCONSISTENT, 'Correct ' . $item['name'], 'A recorded listing differs from your business information, or you marked it as needing correction. ' . self::WHY_NAP, self::directoryAction($item['row'])['label'], 'directory', $item['row'], [$item['message']]);
            }
        }

        foreach ([self::PRIORITY_DIRECTORY, self::NICHE_DIRECTORY] as $kind) {
            foreach ($items as $item) {
                $row = $item['row'];

                if ($item['kind'] !== 'missing' || $row->isCustom()) {
                    continue;
                }

                $isNiche = $row->nicheGuidance !== null;
                $important = in_array($row->importance(), [SeoDirectoryImportance::Essential, SeoDirectoryImportance::Recommended], true);

                if (($kind === self::PRIORITY_DIRECTORY && $important) || ($kind === self::NICHE_DIRECTORY && $isNiche)) {
                    $evidence = ['No details recorded for this directory yet — it has not been checked, which does not mean it is missing.'];

                    if ($isNiche) {
                        $evidence[] = ($section->nicheLabel ?? 'Your industry') . ' guidance: ' . $row->nicheGuidance;
                    }

                    return self::make($kind, 'Check ' . $item['name'], 'Customers use this directory to find businesses like yours. ' . self::WHY_NAP, self::directoryAction($row)['label'], 'directory', $row, $evidence);
                }
            }
        }

        foreach ($items as $item) {
            if ($item['kind'] === 'stale') {
                return self::make(self::REVIEW_DUE, 'Re-check ' . $item['name'], $item['message'], 'Review existing listing', 'directory', $item['row'], ['Last confirmed a while ago.']);
            }
        }

        return self::make(self::CLEAR, 'Nothing urgent right now', 'No known inconsistencies and no priority directory is waiting. Only recorded results are counted — directories you have not recorded are not assumed missing.', 'Review directory inventory', 'none', null, []);
    }

    /**
     * What a directory's main action can really do. It only opens the official page (existing catalog
     * URL); nothing is published or marked Listed by opening it.
     *
     * @return array{label: string, hint: ?string}
     */
    public static function directoryAction(SeoCitationRow $row): array
    {
        $key = $row->directory->key;
        $hasRecord = $row->hasRecordedDetails();

        if ($row->isCustom()) {
            return ['label' => $hasRecord ? 'Review existing listing' : 'Open listing setup', 'hint' => null];
        }

        if ($key === 'bing_places') {
            return $hasRecord
                ? ['label' => 'Review existing listing', 'hint' => null]
                : ['label' => 'Import from Google', 'hint' => 'Bing Places offers an official import from your Google Business Profile. Sign in there to start it; MotionGrove does not import for you.'];
        }

        if ($key === 'apple_business') {
            return $hasRecord
                ? ['label' => 'Review existing listing', 'hint' => null]
                : ['label' => 'Set up Apple Business', 'hint' => 'Opens Apple\'s official Apple Business page. Sign in with an Apple Account to claim or add your place.'];
        }

        return ['label' => $hasRecord ? 'Review existing listing' : 'Open listing setup', 'hint' => null];
    }

    /**
     * The confirmed details a directory form asks for. Only values the business actually has; a missing
     * value is null (never invented) and a private address is never included.
     *
     * @return array<int, array{key: string, label: string, value: ?string, note: ?string}>
     */
    public static function listingKit(SeoCitationLocationSection $section): array
    {
        $areas = $section->location->service_area_cities;
        $areas = is_array($areas) ? implode(', ', array_filter(array_map(fn ($c) => trim((string) $c), $areas))) : '';

        if ($section->addressPermitted) {
            $place = ['key' => 'address', 'label' => 'Public address', 'value' => $section->canonical['address'] ?? null, 'note' => null];
        } else {
            $place = ['key' => 'service_area', 'label' => 'Service area', 'value' => $areas !== '' ? $areas : null, 'note' => 'The street address is private for this location and is not shown.'];
        }

        return [
            ['key' => 'name', 'label' => 'Business name', 'value' => $section->canonical['name'] ?? null, 'note' => null],
            ['key' => 'phone', 'label' => 'Business phone', 'value' => $section->canonical['phone'] ?? null, 'note' => null],
            ['key' => 'website', 'label' => 'Website', 'value' => $section->canonicalWebsite, 'note' => null],
            $place,
            ['key' => 'category', 'label' => 'Business category', 'value' => $section->nicheLabel, 'note' => null],
            ['key' => 'description', 'label' => 'Service description', 'value' => $section->businessDescription, 'note' => null],
        ];
    }

    /** @return array<int, string> labels of required canonical details that are not set */
    private static function missingInformation(SeoCitationLocationSection $section): array
    {
        $out = [];

        foreach (['name' => 'Business name', 'phone' => 'Business phone'] as $key => $label) {
            if (blank($section->canonical[$key] ?? null)) {
                $out[] = $label;
            }
        }

        if (blank($section->canonicalWebsite)) {
            $out[] = 'Website';
        }

        if ($section->addressPermitted && blank($section->canonical['address'] ?? null)) {
            $out[] = 'Public address';
        }

        return $out;
    }

    /** @return array<int, string> */
    private static function googleEvidence(SeoCitationLocationSection $section): array
    {
        $out = [];

        if ($section->googleState() === SeoCitationLocationSection::GOOGLE_CONNECTION_LOST) {
            $out[] = 'The Google connection is not active, so Google details are not being read.';
        }

        if (($problem = $section->googleHealthProblem()) !== null) {
            $out[] = $problem;
        }

        foreach ($section->googleDifferingFields() as $field) {
            $out[] = 'Google shows a different ' . strtolower($field) . ' than your business profile.';
        }

        return $out;
    }

    private static function make(string $kind, string $title, string $why, string $action, string $target, ?SeoCitationRow $row, array $evidence): array
    {
        return compact('kind', 'title', 'why', 'action', 'target', 'row', 'evidence');
    }
}
