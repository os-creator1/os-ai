<?php

namespace App\Library\Forms;

use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\Form;
use Illuminate\Support\Facades\DB;

/**
 * The Analytics tab's numbers — ONLY what the system actually records.
 *
 * AVAILABLE: completed submissions (`form_submissions`, one row per logical
 * response), how many were tied to a Contact / an Opportunity, per Location and
 * per day; and, for a multi-page questionnaire, how many visitors started
 * (`form_sessions`, created when the first page is saved) and how many finished.
 *
 * NOT AVAILABLE, and therefore never shown: page views and — for an ordinary
 * one-page form — starts. Nothing records that a visitor merely opened the link
 * or began typing, so a view count, a conversion rate or an abandonment rate for
 * a one-page form would be invented. See docs/automation/FORMS-VISUAL-BUILDER-V1.md.
 *
 * LOCATION ACL. Everything is bounded to the Locations the actor may reach (the
 * same set FormSubmissionReader uses), so a Location-limited member never
 * receives another Location's count or a total that would disclose it.
 */
final class FormAnalyticsReader
{
    public const DAYS = 30;

    /**
     * @param  array<int, BusinessLocation>  $visible  FormSubmissionReader::visibleLocations() for this actor
     * @return array<string, mixed>
     */
    public function summary(Business $business, Form $form, array $visible, bool $multiPage): array
    {
        $locationIds = array_keys($visible);

        $empty = [
            'submissions' => 0,
            'last_30_days' => 0,
            'with_contact' => 0,
            'with_opportunity' => 0,
            'by_location' => [],
            'by_day' => [],
            'questionnaire' => null,
        ];

        if ($locationIds === []) {
            return $empty;
        }

        $base = fn () => DB::table('form_submissions')
            ->where('business_id', $business->id)
            ->where('form_id', $form->id)
            ->whereIn('business_location_id', $locationIds);

        $since = now()->subDays(self::DAYS - 1)->startOfDay();

        $totals = $base()->selectRaw('COUNT(*) as total, SUM(created_at >= ?) as recent, SUM(contact_id IS NOT NULL) as with_contact, SUM(crm_opportunity_id IS NOT NULL) as with_opportunity', [$since])->first();

        $byDay = $base()->where('created_at', '>=', $since)
            ->selectRaw('DATE(created_at) as day, COUNT(*) as n')->groupBy('day')->pluck('n', 'day')->all();

        $series = [];
        for ($day = $since->copy(); $day->lte(now()); $day->addDay()) {
            $series[$day->toDateString()] = (int) ($byDay[$day->toDateString()] ?? 0);
        }

        $byLocation = $base()->selectRaw('business_location_id, COUNT(*) as n')->groupBy('business_location_id')->pluck('n', 'business_location_id')->all();

        $result = [
            'submissions' => (int) ($totals->total ?? 0),
            'last_30_days' => (int) ($totals->recent ?? 0),
            'with_contact' => (int) ($totals->with_contact ?? 0),
            'with_opportunity' => (int) ($totals->with_opportunity ?? 0),
            'by_location' => collect($visible)->map(fn (BusinessLocation $location) => [
                'name' => $location->name ?: 'Unnamed location',
                'count' => (int) ($byLocation[$location->id] ?? 0),
            ])->values()->all(),
            'by_day' => $series,
            'questionnaire' => null,
        ];

        if ($multiPage) {
            $sessions = DB::table('form_sessions')
                ->join('form_deployments', 'form_deployments.id', '=', 'form_sessions.form_deployment_id')
                ->where('form_deployments.form_id', $form->id)
                ->whereIn('form_deployments.business_location_id', $locationIds)
                ->selectRaw('COUNT(*) as started, SUM(form_sessions.finalized_at IS NOT NULL) as finished')
                ->first();

            $started = (int) ($sessions->started ?? 0);
            $finished = (int) ($sessions->finished ?? 0);

            $result['questionnaire'] = [
                'started' => $started,
                'finished' => $finished,
                'completion_rate' => $started > 0 ? round($finished / $started * 100) : null,
            ];
        }

        return $result;
    }
}
