<?php

namespace App\Library\GoogleBusinessProfile;

use App\DTO\GoogleBusinessProfile\GoogleComparisonRow;
use App\Enums\GoogleBusinessProfile\GoogleComparisonStatus;
use App\Models\Business;
use App\Models\BusinessGoogleLocation;

/**
 * Presentation-only shaping of one binding for the GBP overview page.
 *
 * It owns NO comparison semantics: every row, status and value comes from
 * GoogleBusinessProfileComparator, and every Google value comes from the
 * bounded mirror already held on the binding. This class only splits the
 * comparator's rows into "differs" / "matches" and picks the handful of
 * mirror fields the overview card shows. Nothing is persisted and nothing
 * is sent to Google.
 *
 * Privacy (contract §23.4): the locality/country are only surfaced when
 * the read mask permits the address for this location.
 */
final class GoogleBusinessProfileOverviewPresenter
{
    /** Comparator rows that are display-only but worth showing under "Show all details". */
    private const DETAIL_FIELDS = ['Additional categories', 'Open status', 'Service model', 'Service areas'];

    public function __construct(
        private readonly GoogleBusinessProfileComparator $comparator,
        private readonly GoogleBusinessProfileReadMask $readMask,
    ) {
    }

    /**
     * @return array{
     *     fresh: bool,
     *     title: ?string,
     *     phone: ?string,
     *     website: ?string,
     *     category: ?string,
     *     location: ?string,
     *     details: array<int, array{label: string, value: string}>,
     *     differences: array<int, GoogleComparisonRow>,
     *     matchCount: int
     * }
     */
    public function present(Business $business, BusinessGoogleLocation $binding): array
    {
        $location = $binding->businessLocation;
        $fresh = $binding->mirrorIsFresh();
        $mirror = $fresh ? $binding->freshMirror() : [];

        $rows = ($fresh && $location !== null)
            ? $this->comparator->compare($business, $location, $binding)
            : [];

        $differences = array_values(array_filter($rows, fn (GoogleComparisonRow $row) => $this->differs($row)));
        $matchCount = count(array_filter($rows, fn (GoogleComparisonRow $row) => $row->status === GoogleComparisonStatus::Match));

        $details = [];
        foreach ($rows as $row) {
            if (in_array($row->field, self::DETAIL_FIELDS, true) && $row->googleValue !== null) {
                $details[] = ['label' => $row->field, 'value' => $row->googleValue];
            }
        }

        $place = null;
        if ($location !== null && $this->readMask->addressPermittedForLocation($location)) {
            $parts = array_filter([
                $this->clean($mirror['locality'] ?? null),
                $this->clean($mirror['region_code'] ?? null),
            ]);
            $place = $parts === [] ? null : implode(', ', $parts);
        }

        return [
            'fresh' => $fresh,
            'title' => $this->clean($mirror['title'] ?? null) ?? $this->clean($binding->bound_title_snapshot),
            'phone' => $this->clean($mirror['phone_primary'] ?? null),
            'website' => $this->clean($mirror['website_uri'] ?? null),
            'category' => $this->clean($mirror['primary_category_name'] ?? null),
            'location' => $place,
            'details' => $details,
            'differences' => $differences,
            'matchCount' => $matchCount,
        ];
    }

    /**
     * A difference is a row the comparator rated as a mismatch or as set on
     * only one side. Two absences (e.g. opening hours) are not a difference.
     */
    private function differs(GoogleComparisonRow $row): bool
    {
        return match ($row->status) {
            GoogleComparisonStatus::Mismatch => true,
            GoogleComparisonStatus::NotSetOnPlatform,
            GoogleComparisonStatus::NotSetOnGoogle => $row->platformValue !== null || $row->googleValue !== null,
            default => false,
        };
    }

    private function clean(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
