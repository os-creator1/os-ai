<?php

namespace App\Library\Seo;

use App\Enums\Seo\SeoCitationDisplayState;
use App\Enums\Seo\SeoCitationStatus;
use App\Enums\Seo\SeoDirectoryImportance;
use App\Enums\Seo\SeoDirectoryTrackingMode;
use App\Enums\Seo\SeoNapFieldResult;
use App\Models\SeoCitation;
use App\Models\SeoCitationDirectory;

/**
 * Contract 18 §8.5 — one (Location, directory) line of the Citations page.
 *
 * `nap` is the read-time comparison; it is presentation only and is never
 * persisted or fed back into `status`. `safeListingUrl` is the listing URL
 * AFTER the render-time https check: null whenever the stored value is
 * absent or not a safe https URL, so a view that only ever links
 * `safeListingUrl` cannot render an unsafe link.
 *
 * `listedAddress` is null whenever the Location is not permitted to expose an
 * address, whatever the row holds — a privacy floor on the read side that
 * mirrors the write-side refusal. `phoneCompared` is false where the Business
 * phone is not claimed for this Location (a secondary Location), so a phone
 * that was not compared is not reported as one that could not be verified.
 */
final class SeoCitationRow
{
    /**
     * @param  array{name: SeoNapFieldResult, phone: SeoNapFieldResult, address: SeoNapFieldResult}  $nap
     */
    public function __construct(
        public readonly SeoCitationDirectory $directory,
        public readonly ?SeoCitation $citation,
        public readonly SeoCitationStatus $status,
        public readonly ?string $safeListingUrl,
        public readonly ?string $listedName,
        public readonly ?string $listedPhone,
        public readonly ?string $listedAddress,
        public readonly array $nap,
        public readonly bool $writable,
        public readonly ?SeoDirectoryImportance $importance = null,
        public readonly ?string $nicheLabel = null,
        public readonly ?string $nicheGuidance = null,
        public readonly ?string $listedWebsite = null,
        public readonly SeoNapFieldResult $websiteResult = SeoNapFieldResult::NotComparable,
        public readonly bool $reviewDue = false,
        public readonly bool $offered = true,
        public readonly bool $phoneCompared = true,
    ) {
    }

    /** Effective importance: niche override, else the directory's own default. */
    public function importance(): SeoDirectoryImportance
    {
        return $this->importance ?? $this->directory->importance ?? SeoDirectoryImportance::Recommended;
    }

    public function isCustom(): bool
    {
        return $this->directory->isCustom();
    }

    public function trackingMode(): SeoDirectoryTrackingMode
    {
        return $this->directory->tracking_mode ?? SeoDirectoryTrackingMode::Assisted;
    }

    /**
     * Setup progress, defined precisely: a listing is COMPLETE only when the
     * user marked it Listed AND has recorded something about it (a listing
     * link, a value, or a check date). A directory merely existing in the
     * catalog — or being marked Listed with nothing recorded — is not complete.
     */
    public function isComplete(): bool
    {
        return $this->status === SeoCitationStatus::Listed && $this->hasRecordedDetails();
    }

    /**
     * Not-applicable rows, and HISTORY-ONLY rows (a record kept for a directory that is no longer
     * offered for this Location: disabled, un-recommended, or outside the Location's country), are
     * out of every denominator and out of "needs attention".
     */
    public function countsTowardProgress(): bool
    {
        return $this->offered && $this->status !== SeoCitationStatus::NotApplicable;
    }

    public function isNotApplicable(): bool
    {
        return $this->status === SeoCitationStatus::NotApplicable;
    }

    /** A readable record for a directory that is not (or no longer) offered here. Never actionable. */
    public function isHistoryOnly(): bool
    {
        return ! $this->offered;
    }

    /** Nothing recorded to compare yet (and not marked not-applicable). */
    public function isNotChecked(): bool
    {
        $tally = $this->napTally();

        return $this->countsTowardProgress() && $tally['matched'] + $tally['mismatched'] === 0;
    }

    /**
     * A real PROBLEM the owner should look at: a recorded value differs from
     * the business profile, the owner marked the listing as needing a
     * correction, or a manual listing is due a review. The ONE definition
     * behind the "Needs attention" count, the list filter and the row flag, so
     * the three cannot disagree.
     */
    public function needsAttention(): bool
    {
        return $this->countsTowardProgress() && ($this->displayState()->isProblem() || $this->reviewDue);
    }

    /**
     * Setup still to do (not started, or in progress). Deliberately NOT part of
     * "Needs attention": an untouched directory is work to do, not a problem.
     */
    public function needsSetup(): bool
    {
        return $this->countsTowardProgress() && $this->displayState()->needsSetup();
    }

    /**
     * Recorded values the comparison could not confirm either way (the listing
     * says something, but it cannot be told whether it is the same as the
     * business profile). They are neither matches nor mismatches, so a row
     * that has any is never called "Accurate".
     *
     * @return array<int, string>
     */
    public function unverifiedFields(): array
    {
        $recorded = ['name' => $this->listedName, 'phone' => $this->listedPhone, 'address' => $this->listedAddress];
        $fields = [];

        foreach ($recorded as $field => $value) {
            // The Business phone is deliberately not compared at a secondary Location: that is not "unverified".
            if ($field === 'phone' && ! $this->phoneCompared) {
                continue;
            }

            if ($value !== null && ($this->nap[$field] ?? null) === SeoNapFieldResult::NotComparable) {
                $fields[] = $field;
            }
        }

        if ($this->listedWebsite !== null && $this->websiteResult === SeoNapFieldResult::NotComparable) {
            $fields[] = 'website';
        }

        return $fields;
    }

    /**
     * Per-field tallies of the read-time comparison. `comparable` counts the
     * fields that HAVE a canonical value to compare against; a field with no
     * recorded listing value is `unchecked`, never `mismatched`.
     *
     * @return array{matched: int, mismatched: int, unchecked: int, comparable: int, differing: array<int, string>}
     */
    public function napTally(): array
    {
        $tally = ['matched' => 0, 'mismatched' => 0, 'unchecked' => 0, 'comparable' => 0, 'differing' => []];

        foreach ($this->nap as $field => $result) {
            if ($result === SeoNapFieldResult::NotComparable) {
                continue;
            }

            $tally['comparable']++;

            match ($result) {
                SeoNapFieldResult::Consistent => $tally['matched']++,
                SeoNapFieldResult::Mismatch => $tally['differing'][] = $field,
                default => $tally['unchecked']++,
            };
        }

        // Website joins the tally only where BOTH values exist.
        if ($this->websiteResult === SeoNapFieldResult::Consistent) {
            $tally['comparable']++;
            $tally['matched']++;
        } elseif ($this->websiteResult === SeoNapFieldResult::Mismatch) {
            $tally['comparable']++;
            $tally['differing'][] = 'website';
        }

        $tally['mismatched'] = count($tally['differing']);

        return $tally;
    }

    /**
     * The status the dashboard shows. Display only: derived from the user's
     * own status and the comparison, never persisted and never written back.
     */
    public function displayState(): SeoCitationDisplayState
    {
        $tally = $this->napTally();

        return match ($this->status) {
            SeoCitationStatus::NotApplicable => SeoCitationDisplayState::NotApplicable,
            SeoCitationStatus::NeedsCorrection => SeoCitationDisplayState::NeedsAttention,
            default => match (true) {
                $tally['mismatched'] > 0 => SeoCitationDisplayState::NeedsAttention,
                $this->status === SeoCitationStatus::Listed
                    && $tally['comparable'] > 0 && $tally['unchecked'] === 0 && $this->unverifiedFields() === [] => SeoCitationDisplayState::Accurate,
                $this->status === SeoCitationStatus::Listed => SeoCitationDisplayState::Listed,
                $this->status === SeoCitationStatus::InProgress => SeoCitationDisplayState::InProgress,
                default => SeoCitationDisplayState::NotStarted,
            },
        };
    }

    /**
     * SETUP status — the owner's own progress, separate from whether the
     * recorded details match (napBadge()). Two badges, two meanings.
     *
     * @return array{label: string, variant: string, icon: string}
     */
    public function setupBadge(): array
    {
        return match ($this->status) {
            SeoCitationStatus::NotApplicable => ['label' => 'Not applicable', 'variant' => 'neutral', 'icon' => 'minus'],
            SeoCitationStatus::NeedsCorrection => ['label' => 'Needs attention', 'variant' => 'warning', 'icon' => 'triangle-alert'],
            SeoCitationStatus::InProgress => ['label' => 'In progress', 'variant' => 'accent', 'icon' => 'clock'],
            SeoCitationStatus::Listed => $this->hasRecordedDetails()
                ? ['label' => 'Listed', 'variant' => 'success', 'icon' => 'check']
                : ['label' => 'Listed · add details', 'variant' => 'accent', 'icon' => 'circle-dashed'],
            default => ['label' => 'Needs setup', 'variant' => 'accent', 'icon' => 'circle-dashed'],
        };
    }

    /**
     * NAP comparison — "3 / 3 match", "Phone differs" or "Not checked". Counts
     * only fields where both a business value and a recorded value exist, so a
     * missing value is never a mismatch.
     *
     * @return array{label: string, variant: string, icon: string}
     */
    public function napBadge(): array
    {
        $tally = $this->napTally();
        $compared = $tally['matched'] + $tally['mismatched'];

        if ($compared === 0) {
            return ['label' => 'Not checked', 'variant' => 'neutral', 'icon' => 'circle-dashed'];
        }

        if ($tally['mismatched'] > 0) {
            $names = array_map('ucfirst', $tally['differing']);

            return ['label' => implode(' & ', $names) . ' differ' . (count($names) === 1 ? 's' : ''), 'variant' => 'warning', 'icon' => 'triangle-alert'];
        }

        return ['label' => $tally['matched'] . ' / ' . $compared . ' match', 'variant' => 'success', 'icon' => 'circle-check'];
    }

    /** True once the user has recorded anything at all for this listing. */
    public function hasRecordedDetails(): bool
    {
        return $this->listedName !== null
            || $this->listedPhone !== null
            || $this->listedAddress !== null
            || $this->listedWebsite !== null
            || $this->safeListingUrl !== null
            || $this->citation?->last_verified_at !== null;
    }

    /** One short helper line explaining the state; fixed copy plus field names only. */
    public function helperText(): string
    {
        $tally = $this->napTally();

        return match ($this->displayState()) {
            SeoCitationDisplayState::NotApplicable => 'You marked this directory as not applicable.',
            SeoCitationDisplayState::NeedsAttention => $tally['mismatched'] > 0
                ? ucfirst(implode(' and ', $tally['differing'])) . ($tally['mismatched'] === 1 ? ' differs' : ' differ') . ' from your business profile.'
                : 'You marked this listing as needing a correction.',
            SeoCitationDisplayState::Accurate => 'Everything you recorded matches your business profile.',
            SeoCitationDisplayState::Listed => $this->listedHelperText($tally),
            SeoCitationDisplayState::InProgress => 'Setup is in progress.',
            SeoCitationDisplayState::NotStarted => $this->hasRecordedDetails()
                ? 'Not marked as listed yet.'
                : 'No listing details recorded yet.',
        };
    }

    /**
     * @param  array{matched: int, mismatched: int, unchecked: int, comparable: int, differing: array<int, string>}  $tally
     */
    private function listedHelperText(array $tally): string
    {
        $unverified = $this->unverifiedFields();
        $note = $unverified === []
            ? ''
            : ' ' . ucfirst(implode(' and ', $unverified)) . (count($unverified) === 1 ? ' was' : ' were') . ' recorded but could not be verified automatically — check by eye.';

        if ($tally['matched'] > 0) {
            return $tally['matched'] . ' of ' . $tally['comparable'] . ' details checked and matching.' . $note;
        }

        return $unverified === [] ? 'Listed. Record what it shows to compare it.' : 'Listed.' . $note;
    }
}
