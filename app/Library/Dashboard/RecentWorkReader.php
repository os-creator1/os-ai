<?php

namespace App\Library\Dashboard;

use App\Enums\GoogleBusinessProfile\GoogleOperationStatus;
use App\Enums\GoogleBusinessProfile\GoogleOperationType;
use App\Enums\Opportunity\OpportunityStatus;
use App\Library\Analytics\BusinessAnalyticsQueries;
use App\Library\Opportunity\OpportunityTypeRegistry;
use App\Models\Business;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Unified Business Home §14 (H-5) — the factual "Recent work" timeline.
 *
 * WHAT THIS IS NOT. It is not an activity-event layer, and it creates no
 * projection table: every item is a row this application already persisted for
 * its own reasons, read where it lives. Nothing is inferred, and a thing that
 * leaves no row leaves no item — a website ROLLBACK writes no revision, so no
 * rollback ever appears here. That is a known gap, recorded in §14, not
 * something to invent.
 *
 * FIVE SOURCES, FIVE STATEMENTS. Each source runs exactly one bounded,
 * Business-scoped query ordered by `created_at` DESC with its own LIMIT; the
 * results are merged in PHP, newest first, and the top $limit kept. There is
 * no union, no query per row, and the count does not move when the data grows.
 *
 * WHAT IT NEVER READS. Automation runs are not read here: `automation_executions`
 * belongs to the analytics seam (Automations V2 §15.4 gives the V2-H lane
 * ownership of every execution read), so the failure item comes from
 * BusinessAnalyticsQueries and moves with it. Billing, Agency prospecting and
 * anything belonging to another Business are not sources at all.
 */
final class RecentWorkReader
{
    /** Per source, per §14. */
    public const PER_SOURCE_LIMIT = 10;

    /**
     * The Google operations a customer would recognise as something that
     * happened to their listing. Token and mirror refreshes are machinery,
     * not work, and never appear (§14).
     */
    private const GOOGLE_OPERATIONS = [
        GoogleOperationType::ConnectCompleted->value => 'Google connected',
        GoogleOperationType::Disconnected->value => 'Google disconnected',
        GoogleOperationType::LocationBound->value => 'Google listing linked',
        GoogleOperationType::LocationUnbound->value => 'Google listing unlinked',
    ];

    public function __construct(private readonly BusinessAnalyticsQueries $analyticsQueries)
    {
    }

    /**
     * The newest things that happened to this Business, newest first.
     *
     * @return array<int, RecentWorkItem>
     */
    public function recent(Business $business, int $limit = self::PER_SOURCE_LIMIT): array
    {
        $items = array_merge(
            $this->websiteVersions($business),
            $this->completedRecommendations($business),
            $this->automationFailures($business),
            $this->googleWork($business),
            $this->businessDetailUpdates($business),
        );

        usort($items, fn (RecentWorkItem $a, RecentWorkItem $b) => $b->at <=> $a->at);

        return array_slice($items, 0, max(0, $limit));
    }

    /**
     * Website versions this Business actually published. `websites.business_id`
     * is unique, so the join cannot multiply rows.
     *
     * @return array<int, RecentWorkItem>
     */
    private function websiteVersions(Business $business): array
    {
        $rows = DB::table('website_revisions as r')
            ->join('websites as w', 'w.id', '=', 'r.website_id')
            ->where('w.business_id', $business->id)
            ->orderByDesc('r.created_at')
            ->orderByDesc('r.id')
            ->limit(self::PER_SOURCE_LIMIT)
            ->get(['r.version_number', 'r.created_at']);

        $items = [];

        foreach ($rows as $row) {
            $items[] = new RecentWorkItem(
                key: 'website_version_published',
                text: 'Website version ' . (int) $row->version_number . ' published',
                at: CarbonImmutable::parse((string) $row->created_at),
                routeName: 'customer.workspaces.businesses.website.show',
                permissions: ['website'],
                featureKey: 'website_generation',
            );
        }

        return $items;
    }

    /**
     * Recommendations this Business completed. The title is the registry's own
     * — never assembled from a raw type name — and an opportunity whose type
     * the registry does not define falls back to the title stored on the row
     * itself. When neither exists there is nothing truthful to say, so nothing
     * is said.
     *
     * @return array<int, RecentWorkItem>
     */
    private function completedRecommendations(Business $business): array
    {
        $rows = DB::table('opportunity_transitions as t')
            ->join('opportunities as o', 'o.id', '=', 't.opportunity_id')
            ->where('o.business_id', $business->id)
            ->where('t.to_status', OpportunityStatus::Completed->value)
            ->orderByDesc('t.created_at')
            ->orderByDesc('t.id')
            ->limit(self::PER_SOURCE_LIMIT)
            ->get(['o.id', 'o.worker_key', 'o.type', 'o.title', 't.created_at']);

        $items = [];

        foreach ($rows as $row) {
            $definition = OpportunityTypeRegistry::get((string) $row->worker_key, (string) $row->type);
            $title = $definition['title_template'] ?? ($row->title !== null ? (string) $row->title : null);

            if ($title === null || trim($title) === '') {
                continue;
            }

            $items[] = new RecentWorkItem(
                key: 'recommendation_completed',
                text: 'Completed: ' . $title,
                at: CarbonImmutable::parse((string) $row->created_at),
                routeName: 'customer.opportunities.show',
                routeParameters: [(string) $row->id],
                permissions: ['access_backend'],
                businessScoped: false,
            );
        }

        return $items;
    }

    /**
     * Automation failures, grouped per automation per Business-local day by the
     * seam that owns the ledger. Absent — never zero, never an empty item —
     * when that seam reports its table missing.
     *
     * @return array<int, RecentWorkItem>
     */
    private function automationFailures(Business $business): array
    {
        $groups = $this->analyticsQueries->recentAutomationFailures($business, self::PER_SOURCE_LIMIT);

        if ($groups === null) {
            return [];
        }

        $items = [];

        foreach ($groups as $group) {
            $failures = (int) $group['failures'];

            $items[] = new RecentWorkItem(
                key: 'automation_failed',
                text: $group['automation'] . ' failed ' . $failures . ' ' . ($failures === 1 ? 'time' : 'times'),
                at: $group['at'],
                routeName: 'customer.workspaces.businesses.automations.index',
                permissions: ['automations'],
                featureKey: 'automations',
            );
        }

        return $items;
    }

    /**
     * The four Google operations that describe something a customer did or
     * had done to their listing, and only where the operation actually
     * succeeded: a pending or failed attempt is not a thing that happened.
     *
     * @return array<int, RecentWorkItem>
     */
    private function googleWork(Business $business): array
    {
        $rows = DB::table('business_google_operations')
            ->where('business_id', $business->id)
            ->whereIn('operation_type', array_keys(self::GOOGLE_OPERATIONS))
            ->where('status', GoogleOperationStatus::Succeeded->value)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::PER_SOURCE_LIMIT)
            ->get(['operation_type', 'created_at']);

        $items = [];

        foreach ($rows as $row) {
            $items[] = new RecentWorkItem(
                key: 'google_' . $row->operation_type,
                text: self::GOOGLE_OPERATIONS[(string) $row->operation_type],
                at: CarbonImmutable::parse((string) $row->created_at),
                routeName: 'customer.workspaces.businesses.gbp.index',
                permissions: ['view_google_business_profile'],
                featureKey: 'google_business_profile_module',
            );
        }

        return $items;
    }

    /**
     * Business details. The ledger records one row per FIELD, which is the
     * right shape for an audit and the wrong shape for a Home: "Business
     * details updated" is what a person did, so the rows are grouped per actor
     * per Business-local day and no raw payload is ever shown.
     *
     * The grouping is done in PHP over one bounded read, because the local day
     * is the BUSINESS's and a SQL date function would have to assume a fixed
     * offset that daylight saving breaks. The read takes the newest rows only,
     * and when it fills its bound the oldest group is dropped rather than
     * reported with a count that might be short.
     *
     * @return array<int, RecentWorkItem>
     */
    private function businessDetailUpdates(Business $business): array
    {
        $rows = DB::table('business_knowledge_profile_changes')
            ->where('business_id', $business->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::PER_SOURCE_LIMIT * self::GROUPING_FAN_OUT)
            ->get(['actor_user_id', 'created_at']);

        $timezone = (string) ($business->timezone ?: config('app.timezone', 'UTC'));
        $groups = [];

        foreach ($rows as $row) {
            $at = CarbonImmutable::parse((string) $row->created_at);
            $key = (int) $row->actor_user_id . '@' . $at->setTimezone($timezone)->format('Y-m-d');

            if (! isset($groups[$key])) {
                $groups[$key] = $at;
            }
        }

        if ($rows->count() === self::PER_SOURCE_LIMIT * self::GROUPING_FAN_OUT && count($groups) > 1) {
            array_pop($groups);
        }

        $items = [];

        foreach (array_slice($groups, 0, self::PER_SOURCE_LIMIT) as $at) {
            $items[] = new RecentWorkItem(
                key: 'business_details_updated',
                text: 'Business details updated',
                at: $at,
                routeName: 'customer.workspaces.businesses.knowledge-profile.show',
                permissions: ['website'],
            );
        }

        return $items;
    }

    /**
     * How many rows a grouped source reads to build its bounded list of
     * groups. Generous enough that a busy day still yields ten distinct
     * groups, small enough to stay a bounded read.
     */
    private const GROUPING_FAN_OUT = 20;
}
