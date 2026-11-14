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
 * (missing Google data is only ever a "not verified" note — never a recommendation of its own, because with zero
 * mirror retention it would reappear on every visit and bury real work), 3 known listing
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
     * @return array{kind: string, title: string, why: string, action: ?string, target: string, row: ?SeoCitationRow, evidence: array<int, string>, directory: ?array, google_note: ?string}
     *   target: 'settings' | 'google' | 'directory' | 'none'
     */
    public static function for(SeoCitationLocationSection $section, bool $canManage = false): array
    {
        $missing = self::missingInformation($section);

        if ($missing !== []) {
            return self::make(self::MISSING_INFO, 'Complete your business information', 'Every directory asks for these details, and they cannot be guessed. ' . self::WHY_NAP, 'Complete business profile', 'settings', null,
                array_map(fn (string $f) => $f . ' is not set in your business profile.', $missing), $canManage, $section);
        }

        $items = $section->attentionItems();

        foreach ($items as $item) {
            if ($item['row'] === null) {
                return self::make(self::GOOGLE, 'Fix your Google Business Profile', $item['message'] . ' Google is the listing customers see first in local search.', $item['action'], 'google', null, self::googleEvidence($section), $canManage, $section);
            }
        }

        foreach ($items as $item) {
            if ($item['kind'] === 'inaccurate') {
                return self::make(self::INCONSISTENT, 'Correct ' . $item['name'], 'A recorded listing differs from your business information, or you marked it as needing correction. ' . self::WHY_NAP, self::directoryAction($item['row'], $canManage)['label'], 'directory', $item['row'], [$item['message']], $canManage, $section);
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
                    $evidence = [self::recordedEvidence($row)];

                    if ($isNiche) {
                        $evidence[] = ($section->nicheLabel ?? 'Your industry') . ' guidance: ' . $row->nicheGuidance;
                    }

                    return self::make($kind, 'Check ' . $item['name'], 'Customers use this directory to find businesses like yours. ' . self::WHY_NAP, self::directoryAction($row, $canManage)['label'], 'directory', $row, $evidence, $canManage, $section);
                }
            }
        }

        foreach ($items as $item) {
            if ($item['kind'] === 'stale') {
                return self::make(self::REVIEW_DUE, 'Re-check ' . $item['name'], $item['message'], self::directoryAction($item['row'], $canManage)['label'], 'directory', $item['row'], ['Last confirmed a while ago.'], $canManage, $section);
            }
        }

        return self::make(self::CLEAR, 'Nothing urgent right now', 'No known inconsistencies and no priority directory is waiting. Only recorded results are counted — directories you have not recorded are not assumed missing.', null, 'none', null, [], $canManage, $section);
    }

    /**
     * Where a directory's primary action really goes. Never invents a URL and never marks anything:
     *  - listing: a recorded, SeoLinkSafety-checked listing URL exists, so the owner can look at it (a
     *    recorded URL is the owner's own note, not proof the listing is verified);
     *  - setup:   the catalog's official claim/setup page (claim_url only; a directory homepage is not a setup flow);
     *  - drawer:  no approved URL, so the owner finds the listing themselves and records it in the drawer;
     *  - info:    same, but the viewer cannot manage it — guidance only.
     *
     * @return array{kind: string, label: ?string, url: ?string, hint: ?string}
     */
    public static function directoryAction(SeoCitationRow $row, bool $canManage = false): array
    {
        $hasRecord = $row->hasRecordedDetails();

        if ($hasRecord && $row->safeListingUrl !== null) {
            return ['kind' => 'listing', 'label' => 'Review existing listing', 'url' => $row->safeListingUrl, 'hint' => 'Opens the listing URL you recorded. Check it still shows your details, then record what you see.'];
        }

        $claim = SeoLinkSafety::safeHttpsUrl($row->directory->claim_url);

        if ($claim !== null) {
            return ['kind' => 'setup', 'label' => self::setupLabel($row), 'url' => $claim, 'hint' => self::setupHint($row)];
        }

        $manual = 'No official setup link is on file for this directory. Search for your business there yourself, then record what you find.';

        return $canManage && $row->writable
            ? ['kind' => 'drawer', 'label' => 'Record what you find', 'url' => null, 'hint' => $manual]
            : ['kind' => 'info', 'label' => null, 'url' => null, 'hint' => $manual . ' Someone with edit access can record it.'];
    }

    /** Label for the official claim/setup link (used by the hero and the drawer). */
    public static function setupLabel(SeoCitationRow $row): string
    {
        $recorded = $row->hasRecordedDetails();
        $key = $row->isCustom() ? null : $row->directory->key;

        return match (true) {
            $recorded => 'Manage listing',
            $key === 'bing_places' => 'Import from Google',
            $key === 'apple_business' => 'Set up Apple Business',
            default => 'Open listing setup',
        };
    }

    private static function setupHint(SeoCitationRow $row): ?string
    {
        if ($row->hasRecordedDetails() || $row->isCustom()) {
            return null;
        }

        return match ($row->directory->key) {
            'bing_places' => 'Bing Places offers an official import from your Google Business Profile. Sign in there to start it; MotionGrove does not import for you.',
            'apple_business' => 'Opens Apple\'s official Apple Business page. Sign in with an Apple Account to claim or add your place.',
            default => null,
        };
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
            // The phone is business-wide: for a secondary location it is not confirmed as that location's own
            // number, so it is withheld (never guessed) and the owner is told to confirm the branch number.
            $section->phoneComparable
                ? ['key' => 'phone', 'label' => 'Business phone', 'value' => $section->canonical['phone'] ?? null, 'note' => null]
                : ['key' => 'phone', 'label' => 'Location phone', 'value' => null, 'note' => 'Your business has one phone number for all locations. Confirm this location\'s own number before using it in a listing.'],
            ['key' => 'website', 'label' => 'Website', 'value' => $section->canonicalWebsite, 'note' => null],
            $place,
            ['key' => 'category', 'label' => 'Business category', 'value' => $section->nicheLabel, 'note' => null],
            ['key' => 'description', 'label' => 'Service description', 'value' => $section->businessDescription, 'note' => null],
        ];
    }

    /** Honest description of what is on record for a not-yet-complete directory. Never says verified. */
    private static function recordedEvidence(SeoCitationRow $row): string
    {
        $inProgress = $row->displayState() === SeoCitationDisplayState::InProgress;
        $checked = $row->citation?->last_verified_at;

        if (! $row->hasRecordedDetails()) {
            return $inProgress
                ? 'Setup is in progress, but no listing details are recorded yet.'
                : 'No details recorded for this directory yet — it has not been checked, which does not mean it is missing.';
        }

        $parts = [];

        if ($row->safeListingUrl !== null) {
            $parts[] = 'a listing link';
        }

        if ($row->listedName !== null || $row->listedPhone !== null || $row->listedAddress !== null || $row->listedWebsite !== null) {
            $parts[] = 'listing details';
        }

        if ($checked !== null) {
            $parts[] = 'a check date (' . $checked->format('M j, Y') . ')';
        }

        return ($inProgress ? 'Setup is in progress. ' : 'Not marked as listed yet. ')
            . 'You have recorded ' . implode(', ', $parts) . '; this has not been confirmed as accurate here.';
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

    private static function make(string $kind, string $title, string $why, ?string $action, string $target, ?SeoCitationRow $row, array $evidence, bool $canManage, SeoCitationLocationSection $section): array
    {
        $directory = $row !== null ? self::directoryAction($row, $canManage) : null;

        // Missing Google data is a "not verified" note, never a recommendation and never "healthy".
        $googleNote = $section->googleState() === SeoCitationLocationSection::GOOGLE_CONNECTED && ! $section->googleCheckedAutomatically()
            ? 'Google is connected, but no current Google details are available to compare, so your Google listing is not verified here.'
            : null;

        return compact('kind', 'title', 'why', 'action', 'target', 'row', 'evidence', 'directory') + ['google_note' => $googleNote];
    }
}
