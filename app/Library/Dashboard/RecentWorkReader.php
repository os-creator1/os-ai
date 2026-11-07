<?php

namespace App\Library\Dashboard;

use App\Enums\GoogleBusinessProfile\GoogleOperationStatus;
use App\Enums\GoogleBusinessProfile\GoogleOperationType;
use App\Enums\Opportunity\OpportunityStatus;
use App\Library\Analytics\BusinessAnalyticsQueries;
use App\Enums\Seo\ArticleStatus;
use App\Enums\Seo\SeoAuditRunStatus;
use App\Enums\Seo\SeoRankRunState;
use App\Library\Opportunity\OpportunityTypeRegistry;
use App\Library\Workspace\LocationAccessGuard;
use App\Models\ExternalSiteCrawl;
use Illuminate\Support\Str;
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

    public function __construct(private readonly BusinessAnalyticsQueries $analyticsQueries, private readonly LocationAccessGuard $locationGuard)
    {
    }

    /**
     * The newest things that happened to this Business, newest first.
     *
     * @return array<int, RecentWorkItem>
     */
    public function recent(Business $business, int $limit = self::PER_SOURCE_LIMIT, ?int $actorUserId = null): array
    {
        // Location-scoped sources (rank checks via their keyword, articles) honour the actor's Location reach; null actor = Business-wide.
        // The reach is resolved lazily: only when a Location-bound row is actually read, so Business-wide data costs no extra statement.
        $reach = null;
        $locationIds = function () use (&$reach, $business, $actorUserId): ?array {
            return $actorUserId === null ? null : ($reach ??= $this->locationGuard->accessibleLocationIdsForBusiness($actorUserId, $business));
        };

        $items = array_merge(
            $this->rankChecks($business, $locationIds),
            $this->seoAudits($business),
            $this->publishedArticles($business, $locationIds),
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
                routeName: 'customer.workspaces.businesses.automations.workflows.index',
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
     * Completed Google ranking checks, grouped per trigger per Business-local day (never one event per keyword). The keyword count is the
     * number of DISTINCT tracked keywords whose completed runs the rows prove; "scheduled" / "manual" is the stored trigger. Nothing here
     * says rankings improved. Provider ids, prices and task ids are never read.
     *
     * @param  \Closure(): (array<int, int>|null)  $locationIds
     * @return array<int, RecentWorkItem>
     */
    private function rankChecks(Business $business, \Closure $locationIds): array
    {
        $query = DB::table('seo_rank_check_runs as r')
            ->join('seo_rank_targets as t', 't.id', '=', 'r.seo_rank_target_id')
            ->join('seo_keywords as k', 'k.id', '=', 't.seo_keyword_id')
            ->where('r.business_id', $business->id)
            ->where('t.business_id', $business->id)
            ->where('r.state', SeoRankRunState::Completed->value)
            ->whereNotNull('r.completed_at');

        $bound = self::PER_SOURCE_LIMIT * self::GROUPING_FAN_OUT;
        $rows = $query->orderByDesc('r.completed_at')->orderByDesc('r.id')->limit($bound)->get(['r.trigger', 'r.completed_at', 't.seo_keyword_id', 'k.business_location_id']);
        $rawCount = $rows->count();
        $rows = $this->visibleRows($rows, $locationIds);
        $timezone = (string) ($business->timezone ?: config('app.timezone', 'UTC'));
        $groups = [];

        foreach ($rows as $row) {
            $at = CarbonImmutable::parse((string) $row->completed_at);
            $trigger = (string) $row->trigger === 'manual' ? 'manual' : 'scheduled';
            $key = $trigger . '@' . $at->setTimezone($timezone)->format('Y-m-d');
            $groups[$key] ??= ['trigger' => $trigger, 'at' => $at, 'keywords' => []];
            $groups[$key]['keywords'][(int) $row->seo_keyword_id] = true;
        }

        // A full read may have cut the oldest day short: drop it rather than report a count that might be low.
        if ($rawCount === $bound && count($groups) > 1) {
            array_pop($groups);
        }

        $items = [];

        foreach (array_slice($groups, 0, self::PER_SOURCE_LIMIT) as $group) {
            $count = count($group['keywords']);
            $noun = $count === 1 ? 'keyword' : 'keywords';

            $items[] = new RecentWorkItem(
                key: 'rank_check_' . $group['trigger'],
                text: ($group['trigger'] === 'manual' ? 'Manual Google ranking check for ' : 'Scheduled Google ranking check for ') . $count . ' ' . $noun,
                at: $group['at'],
                routeName: 'customer.workspaces.businesses.seo.keywords.index',
                permissions: ['view_seo'],
                featureKey: 'seo_basic_visibility',
            );
        }

        return $items;
    }

    /**
     * Website SEO audits whose stored lifecycle proves a result: MotionGrove website audits (status completed) and external-site crawls
     * (completed, or partial — said so). Counts come from the persisted columns only. A finding is never described as fixed.
     *
     * @return array<int, RecentWorkItem>
     */
    private function seoAudits(Business $business): array
    {
        $items = [];

        $runs = DB::table('seo_audit_runs')
            ->where('business_id', $business->id)
            ->where('status', SeoAuditRunStatus::Completed->value)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::PER_SOURCE_LIMIT)
            ->get(['critical_count', 'warning_count', 'info_count', 'created_at']);

        foreach ($runs as $run) {
            $items[] = new RecentWorkItem(
                key: 'seo_audit_completed',
                text: 'Website SEO audit completed' . self::findingsText($run->critical_count, $run->warning_count, $run->info_count),
                at: CarbonImmutable::parse((string) $run->created_at),
                routeName: 'customer.workspaces.businesses.seo.audit.index',
                permissions: ['view_seo'],
                featureKey: 'seo_module',
            );
        }

        $crawls = DB::table('external_site_crawls')
            ->where('business_id', $business->id)
            ->whereIn('status', [ExternalSiteCrawl::COMPLETED, ExternalSiteCrawl::PARTIAL])
            ->whereNotNull('finished_at')
            ->orderByDesc('finished_at')
            ->orderByDesc('id')
            ->limit(self::PER_SOURCE_LIMIT)
            ->get(['status', 'critical_count', 'warning_count', 'info_count', 'finished_at']);

        foreach ($crawls as $crawl) {
            $partial = $crawl->status === ExternalSiteCrawl::PARTIAL;

            $items[] = new RecentWorkItem(
                key: $partial ? 'external_audit_partial' : 'external_audit_completed',
                text: ($partial ? 'External website audit finished with partial coverage' : 'External website audit completed') . self::findingsText($crawl->critical_count, $crawl->warning_count, $crawl->info_count),
                at: CarbonImmutable::parse((string) $crawl->finished_at),
                routeName: 'customer.workspaces.businesses.website.external.audit',
                permissions: ['website'],
                featureKey: 'website_generation',
            );
        }

        return $items;
    }

    /** ": N issues found" only when all three persisted counts exist; otherwise nothing is claimed. */
    private static function findingsText(mixed $critical, mixed $warning, mixed $info): string
    {
        if ($critical === null || $warning === null || $info === null) {
            return '';
        }

        $total = (int) $critical + (int) $warning + (int) $info;

        return $total === 0 ? ': no issues recorded' : ': ' . $total . ' ' . ($total === 1 ? 'issue' : 'issues') . ' found';
    }

    /**
     * Blog articles whose publication is verified: status published AND a published_at that has arrived. Drafts, scheduled and archived
     * articles are never listed. The stored `source` is how an article was CREATED, not how it was published, so no automatic-publication
     * claim is ever made.
     *
     * @param  \Closure(): (array<int, int>|null)  $locationIds
     * @return array<int, RecentWorkItem>
     */
    private function publishedArticles(Business $business, \Closure $locationIds): array
    {
        $query = DB::table('website_articles')
            ->where('business_id', $business->id)
            ->where('status', ArticleStatus::Published->value)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', CarbonImmutable::now('UTC')->format('Y-m-d H:i:s'));

        $rows = $query->orderByDesc('published_at')->orderByDesc('id')->limit(self::PER_SOURCE_LIMIT)->get(['uid', 'title', 'published_at', 'business_location_id']);
        $items = [];

        foreach ($this->visibleRows($rows, $locationIds) as $row) {
            $title = trim((string) $row->title);

            $items[] = new RecentWorkItem(
                key: 'article_published',
                text: $title === '' ? 'Blog article published' : 'Blog article published: ' . Str::limit($title, 60),
                at: CarbonImmutable::parse((string) $row->published_at),
                routeName: 'customer.workspaces.businesses.seo.content.articles.preview',
                routeParameters: [(string) $row->uid],
                permissions: ['view_seo'],
                featureKey: 'seo_basic_visibility',
            );
        }

        return $items;
    }

    /**
     * A Business-wide row (NULL Location) is visible to everyone with Business access; a Location row only to actors who reach it.
     * The actor's reach is read at most once, and only when a Location-bound row exists.
     *
     * @param  \Illuminate\Support\Collection<int, object>  $rows
     * @return \Illuminate\Support\Collection<int, object>
     */
    private function visibleRows($rows, \Closure $locationIds)
    {
        if ($rows->every(fn ($row) => $row->business_location_id === null)) {
            return $rows;
        }

        $reach = $locationIds();

        return $reach === null ? $rows : $rows->filter(fn ($row) => $row->business_location_id === null || in_array((int) $row->business_location_id, $reach, true))->values();
    }

    /**
     * How many rows a grouped source reads to build its bounded list of
     * groups. Generous enough that a busy day still yields ten distinct
     * groups, small enough to stay a bounded read.
     */
    private const GROUPING_FAN_OUT = 20;
}
