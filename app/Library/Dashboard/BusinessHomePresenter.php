<?php

namespace App\Library\Dashboard;

use App\DTO\Analytics\AutomationKpis;
use App\Enums\Dashboard\AttentionSeverity;
use App\Enums\Dashboard\AttentionType;
use App\Enums\Dashboard\HeadlinePolarity;
use App\Enums\Dashboard\HeadlineTrend;
use App\Enums\Opportunity\OpportunityFreshness;
use App\Enums\Opportunity\OpportunityStatus;
use App\Http\Requests\Analytics\AnalyticsRangeRequest;
use App\Library\Analytics\AnalyticsDateRange;
use App\Library\Analytics\BusinessAnalyticsQueries;
use App\Library\Analytics\BusinessDashboardAnalyticsPresenter;
use App\Library\Conversations\BusinessConversationReadModel;
use App\Library\Navigation\CustomerContext;
use App\Library\Navigation\CustomerShellComposer;
use App\Library\Navigation\MenuEntitlements;
use App\Models\Business;
use App\Models\Opportunity;
use App\Models\User;
use App\Repositories\Contracts\OpportunityRepository;
use Illuminate\Pagination\PaginationState;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The Business Home: a billing exception only when one is real, what has
 * actually changed since this customer was last here, what the Advisor
 * recommends, how the selected period compares with the one before it, and
 * at most four next actions —
 * all for the ONE Business the CustomerContext resolved (the viewed client's,
 * while viewing as one).
 *
 * Customer Experience Slice 4 §4 built it; Unified Business Home H-1 took
 * billing off it as a metric (§5), H-2 added the activity window (§2.3), and
 * H-3 gave Business performance its own period selector and chart (§2.5).
 *
 * Auth::id() is never a tenant key here: the actor id is only ever the
 * capability actor (permissions, payer authority). Every figure comes from
 * the context's Business, which the resolver re-authorized on this very
 * request through WorkspaceManager::userCanAccessBusiness().
 *
 * Its sources, and nothing else:
 *  - the request's one MenuEntitlements snapshot (Slice 2A, §10), read through
 *    CustomerShellComposer so the shell and the page share it;
 *  - DashboardStatusReader — one statement for wallet, website and Google;
 *  - BusinessDashboardAnalyticsPresenter — B5's own KPI methods, two ranges;
 *  - BusinessConversationReadModel::startedCount() — Slice 2B's seam;
 *  - OpportunityRepository — open AND current recommendations;
 *  - BusinessAnalyticsQueries::countsBetween() / automationCountsBetween() —
 *    B5's own instant-window counts for the activity band;
 *  - HomeVisitMarker — the per-user, per-Business visit window (§2.3).
 *
 * Each band is built in its own try: one failing source degrades only its
 * own band (§12).
 *
 * While viewing as a client every figure is the viewed Business's, every
 * link passes ViewAsRouteClassification, and nothing that costs, funds,
 * touches a provider or switches identity is offered (§11).
 */
final class BusinessHomePresenter
{
    /** §6 — the same source-controlled bound as the former panel. Never request input. */
    public const RECOMMENDATION_LIMIT = 5;

    public const MAX_QUICK_ACTIONS = 4;

    public function __construct(
        private readonly CustomerShellComposer $shell,
        private readonly DashboardStatusReader $statusReader,
        private readonly BusinessDashboardAnalyticsPresenter $analytics,
        private readonly BusinessAnalyticsQueries $analyticsQueries,
        private readonly BusinessConversationReadModel $conversations,
        private readonly OpportunityRepository $opportunities,
        private readonly HomeVisitMarker $visits,
        private readonly RecentWorkReader $recentWorkReader,
        private readonly DashboardLinkGate $links,
        private readonly ParentAccountSwitch $parentSwitch,
    ) {
    }

    /**
     * Null only when the context's Business no longer exists inside its
     * Workspace, in which case the caller renders the Account frame instead.
     *
     * @param  array<string, mixed>  $rangeInput  the Business performance
     *   period, as the customer's own query string ("range", "start", "end").
     *   Invalid input is refused and the default window renders instead (H-3).
     */
    public function present(CustomerContext $context, User $user, array $rangeInput = []): ?DashboardSnapshot
    {
        $candidate = $context->selectedBusiness;
        $workspace = $context->frameWorkspace();

        if (! $context->isBusinessFrame() || $candidate === null || $workspace === null) {
            return null;
        }

        $business = Business::query()
            ->whereKey($candidate->id)
            ->where('workspace_id', $workspace->id)
            ->first();

        if ($business === null) {
            return null;
        }

        $entitlements = $this->shell->currentMenuEntitlements($context);
        $scoped = [$workspace->uid, $candidate->uid];
        $bands = [];
        $failed = [];

        $status = null;
        $statusFailed = false;

        try {
            $status = $this->statusReader->forBusiness((int) $business->id);
        } catch (Throwable $e) {
            report($e);
            $statusFailed = true;
        }

        // The B5 comparison feeds the headline row and the one attention type
        // B5 owns (AutomationFailing); it is only read when one of them can
        // actually render for this actor.
        $canSeeResults = Gate::forUser($user)->allows('view_reports');
        $automationsShown = $entitlements->allows('automations') && Gate::forUser($user)->allows('automations');
        $comparison = null;
        $comparisonFailed = false;

        // H-3 — the customer's own period, through the Results range rules.
        // An unusable range (a preset that does not exist, a reversed range,
        // one longer than the 92-day maximum) is refused rather than
        // approximated: Home renders its default window and says so.
        [$selectedRange, $rangeRejected] = $this->selectedRange($business, $rangeInput);

        if ($canSeeResults || $automationsShown) {
            try {
                $comparison = $this->analytics->comparison($business, $selectedRange);
            } catch (Throwable $e) {
                report($e);
                $comparisonFailed = true;
            }
        }

        // 0 — Billing exception strip, 1 — Attention
        //
        // Billing left the Business Home as a metric (§5): it appears here
        // only as ONE compact exception a customer can actually act on, and
        // only for an actor whose remediation route resolves. Everything else
        // billing lives in Settings.
        if ($statusFailed) {
            $failed[] = DashboardSnapshot::BAND_ATTENTION;
        } else {
            $automationFailures = $automationsShown && $comparison !== null && $comparison['current']['automations'] instanceof AutomationKpis
                ? $comparison['current']['automations']->failed()
                : 0;
            $items = $this->attention($context, $user, $entitlements, $scoped, $candidate->name, $status, $automationFailures);

            $billing = array_values(array_filter($items, fn (AttentionItem $item) => self::isBilling($item->type)));
            $attention = array_values(array_filter($items, fn (AttentionItem $item) => ! self::isBilling($item->type)));

            if ($billing !== []) {
                $bands[DashboardSnapshot::BAND_BILLING_EXCEPTION] = $billing[0];
            }

            if ($attention !== []) {
                $bands[DashboardSnapshot::BAND_ATTENTION] = $attention;
            }
        }

        // 1b — Business activity: what actually changed since this customer
        // last used this Business (§2.3). Absent on a first visit.
        try {
            $activity = $this->businessActivity($business, $context, $user, $entitlements);

            if ($activity !== null) {
                $bands[DashboardSnapshot::BAND_ACTIVITY] = $activity;
            }
        } catch (Throwable $e) {
            report($e);
            $failed[] = DashboardSnapshot::BAND_ACTIVITY;
        }

        // 2 — Recommended next steps
        if (config('opportunity.enabled', false)) {
            try {
                $recommendations = $this->recommendations($business, $context, $user, $entitlements);

                if ($recommendations !== null) {
                    $bands[DashboardSnapshot::BAND_RECOMMENDATIONS] = $recommendations;
                }
            } catch (Throwable $e) {
                report($e);
                $failed[] = DashboardSnapshot::BAND_RECOMMENDATIONS;
            }
        }

        // 3 — Recent / headline figures
        if ($canSeeResults) {
            if ($comparisonFailed || $comparison === null) {
                $failed[] = DashboardSnapshot::BAND_HEADLINES;
            } else {
                try {
                    $bands[DashboardSnapshot::BAND_HEADLINES] = $this->headlines($business, $context, $user, $entitlements, $scoped, $comparison, $rangeRejected);
                } catch (Throwable $e) {
                    report($e);
                    $failed[] = DashboardSnapshot::BAND_HEADLINES;
                }
            }
        }

        // 4 — Visibility: is the website live, is Google connected (§2.6).
        // It costs no query of its own: every fact is a column of the status
        // row this request already read.
        if ($statusFailed) {
            $failed[] = DashboardSnapshot::BAND_VISIBILITY;
        } elseif ($status !== null) {
            try {
                $visibility = $this->visibility($context, $user, $entitlements, $scoped, $status);

                if ($visibility !== null) {
                    $bands[DashboardSnapshot::BAND_VISIBILITY] = $visibility;
                }
            } catch (Throwable $e) {
                report($e);
                $failed[] = DashboardSnapshot::BAND_VISIBILITY;
            }
        }

        // 5 — Conversations: are customers writing, are we answering, and is
        // anyone waiting right now (§2.6). Every figure comes from Slice 2B's
        // read model, the only reader of the conversation table.
        if ($entitlements->allows('conversations') && Gate::forUser($user)->allows('chat_box')) {
            try {
                $bands[DashboardSnapshot::BAND_CONVERSATIONS] = $this->conversations($business, $context, $user, $entitlements, $scoped, $selectedRange);
            } catch (Throwable $e) {
                report($e);
                $failed[] = DashboardSnapshot::BAND_CONVERSATIONS;
            }
        }

        // 6 — Automations: completed and failed runs for the selected period,
        // from the B5 comparison this request already built. No second
        // automation analytics implementation, and no run semantics of its
        // own — whatever automationKpis() currently counts is what shows.
        if ($automationsShown) {
            if ($comparisonFailed) {
                $failed[] = DashboardSnapshot::BAND_AUTOMATIONS;
            } elseif ($comparison !== null) {
                try {
                    $automations = $this->automations($context, $user, $entitlements, $scoped, $comparison);

                    if ($automations !== null) {
                        $bands[DashboardSnapshot::BAND_AUTOMATIONS] = $automations;
                    }
                } catch (Throwable $e) {
                    report($e);
                    $failed[] = DashboardSnapshot::BAND_AUTOMATIONS;
                }
            }
        }

        // 7 — Recent work: what actually happened to this Business, from the
        // rows that already prove it (§14). Absent when nothing did.
        try {
            $recent = $this->recentWork($context, $user, $entitlements, $scoped, $business);

            if ($recent !== null) {
                $bands[DashboardSnapshot::BAND_RECENT_WORK] = $recent;
            }
        } catch (Throwable $e) {
            report($e);
            $failed[] = DashboardSnapshot::BAND_RECENT_WORK;
        }

        // Spend: gone from the Business Home entirely (§5). Balance,
        // spend, top-ups and invoices live in Settings → Billing, which is
        // unchanged; Home speaks about billing only through the exception
        // strip above.

        // 7 — Quick actions
        $bands[DashboardSnapshot::BAND_ACTIONS] = $this->actions($context, $user, $entitlements, $scoped, $status);

        return new DashboardSnapshot(
            kind: DashboardSnapshot::KIND_BUSINESS,
            frameLabel: $context->isAgency() ? 'Client account home' : 'Business home',
            heading: $candidate->name,
            bands: $bands,
            failedBands: $failed,
        );
    }

    /**
     * §5 — only what a status column proves, each with a real remediation the
     * actor can reach through the four-way rule. An item whose fix is out of
     * reach is not rendered (§5.1): restricted staff, who never see billing,
     * get no billing item rather than a dead end.
     *
     * @param  array<int, string>  $scoped
     * @return array<int, AttentionItem>
     */
    private function attention(CustomerContext $context, User $user, MenuEntitlements $entitlements, array $scoped, string $scope, BusinessStatusRow $status, int $automationFailures): array
    {
        $types = $status->attentionTypes();

        if ($automationFailures > 0) {
            $types[] = AttentionType::AutomationFailing;
        }

        $items = [];

        foreach ($types as $type) {
            if (! $this->isActionable($type, $status)) {
                continue;
            }

            $url = $this->remediationUrl($type, $context, $user, $entitlements, $scoped);

            if ($url === null) {
                continue;
            }

            $text = $type->sentence();
            $consequence = $type->consequence();

            $items[] = new AttentionItem($type, $type->severity(), $scope, $consequence === null ? $text : $text . ' ' . $consequence, $type->actionLabel(), $url);
        }

        return self::ordered($items);
    }

    /**
     * H-1 — Home speaks about billing only where the customer actually has
     * something to do. A balance under the customer's own automatic top-up
     * threshold is the NORMAL trigger for a top-up that then happens by
     * itself, so while automatic top-up is on it is not an exception: either
     * it works, or the top-up that stopped working is itself the exception
     * (AutoRechargeFailing), which names the thing the customer can fix.
     * Every other case is a real block or a debt, and still appears.
     */
    private function isActionable(AttentionType $type, BusinessStatusRow $status): bool
    {
        if ($type !== AttentionType::LowBalance) {
            return true;
        }

        return ! $status->autoRechargeEnabled;
    }

    /** The five billing cases, which render only as the exception strip (§5.2). */
    public static function isBilling(AttentionType $type): bool
    {
        return in_array($type, [
            AttentionType::WalletSuspended,
            AttentionType::OutstandingDebt,
            AttentionType::PaidActivityPaused,
            AttentionType::LowBalance,
            AttentionType::AutoRechargeFailing,
        ], true);
    }

    /**
     * Severity first (blocking, warning, informational), then scope, then
     * the type's own declared order — deterministic, never insertion luck.
     *
     * @param  array<int, AttentionItem>  $items
     * @return array<int, AttentionItem>
     */
    public static function ordered(array $items): array
    {
        $order = array_flip(array_map(fn (AttentionType $type) => $type->value, AttentionType::cases()));

        usort($items, fn (AttentionItem $a, AttentionItem $b) => [$a->severity->rank(), $a->scope, $order[$a->type->value]]
            <=> [$b->severity->rank(), $b->scope, $order[$b->type->value]]);

        return $items;
    }

    /**
     * @param  array<int, string>  $scoped
     */
    private function remediationUrl(AttentionType $type, CustomerContext $context, User $user, MenuEntitlements $entitlements, array $scoped): ?string
    {
        return match ($type) {
            AttentionType::WalletSuspended, AttentionType::OutstandingDebt, AttentionType::PaidActivityPaused,
            AttentionType::LowBalance, AttentionType::AutoRechargeFailing => $context->canManageBilling()
                ? $this->links->url($context, $user, $entitlements, 'customer.workspaces.businesses.usage-billing.show', $scoped, ['access_backend'])
                : null,
            AttentionType::WebsiteUnpublished => $this->links->url($context, $user, $entitlements, 'customer.workspaces.businesses.website.show', $scoped, ['website'], 'website_generation'),
            AttentionType::GoogleConnectionLost, AttentionType::GoogleLocationUnhealthy => $this->links->url($context, $user, $entitlements, 'customer.workspaces.businesses.gbp.index', $scoped, ['view_google_business_profile'], 'google_business_profile_module'),
            AttentionType::AutomationFailing => $this->links->url($context, $user, $entitlements, 'customer.workspaces.businesses.automations.index', $scoped, ['automations'], 'automations'),
        };
    }

    /**
     * §2.3 (H-2) — "what actually changed since this customer last used this
     * Business", and nothing else.
     *
     * Every figure is a canonical count from the seam that owns it, over the
     * one instant window the visit marker chose: new contacts and received
     * messages from B5 in a single statement, new conversations from Slice
     * 2B's read model, completed and failed automation runs from the B5/V2-H
     * execution reader. Nothing here is a lead, a booking, a visitor, a
     * ranking or a rate — none of those has a canonical source, so Home never
     * claims one. Zero-value items are left out entirely; when nothing moved
     * the band says so in one quiet line.
     *
     * The band is absent on a first visit: there is no earlier point to
     * compare against, and inventing one would be a fabricated delta.
     *
     * @return array{window: HomeActivityWindow, items: array<int, array{key: string, text: string}>}|null
     */
    private function businessActivity(Business $business, CustomerContext $context, User $user, MenuEntitlements $entitlements): ?array
    {
        // Looking at someone else's Business never consumes their window:
        // neither an agency viewing a client nor an impersonated session
        // (`temp_user_id`, the same marker Slice 4's parent switch reads).
        $writable = ! $context->isViewingAsClient() && ! session()->has('temp_user_id');
        $window = $this->visits->observe($business, $context->userId, $writable);

        if ($window === null) {
            return null;
        }

        $counts = $this->analyticsQueries->countsBetween($business, $window->start, $window->end);
        $items = [];

        if ($counts['newContacts'] > 0) {
            $items[] = ['key' => 'new_contacts', 'text' => self::plural($counts['newContacts'], 'new contact', 'new contacts')];
        }

        if ($entitlements->allows('conversations') && Gate::forUser($user)->allows('chat_box')) {
            $conversations = $this->conversations->startedCount($business, $window->start, $window->end);

            if ($conversations > 0) {
                $items[] = ['key' => 'new_conversations', 'text' => self::plural($conversations, 'new conversation', 'new conversations')];
            }
        }

        if ($counts['messagesReceived'] > 0) {
            $items[] = ['key' => 'messages_received', 'text' => self::plural($counts['messagesReceived'], 'message received', 'messages received')];
        }

        if ($entitlements->allows('automations') && Gate::forUser($user)->allows('automations')) {
            $automations = $this->analyticsQueries->automationCountsBetween($business, $window->start, $window->end);

            if ($automations !== null && $automations['completed'] > 0) {
                $items[] = ['key' => 'automations_completed', 'text' => self::plural($automations['completed'], 'automation completed', 'automations completed')];
            }

            if ($automations !== null && $automations['failed'] > 0) {
                $items[] = ['key' => 'automations_failed', 'text' => self::plural($automations['failed'], 'automation failed', 'automations failed')];
            }
        }

        return [
            'window' => $window,
            'items' => array_slice($items, 0, max(1, (int) config('home.activity_max_items', 5))),
        ];
    }

    /** "1 new contact" / "3 new contacts" — the figure always leads. */
    private static function plural(int $count, string $singular, string $plural): string
    {
        return number_format($count) . ' ' . ($count === 1 ? $singular : $plural);
    }

    /**
     * §6 — AI recommendations only, never a prerequisite failure: open AND
     * current, the first five, for the selected Business. Null (the band is
     * absent) when there is nothing to recommend.
     *
     * The Advisor pages resolve the actor's own PRIMARY Business
     * (OpportunityController), so a recommendation links there only when the
     * selected Business is that one; for any other Business the band still
     * shows its recommendations, without a link that would open a different
     * Business's list.
     *
     * @return array{items: array<int, array{title: string, detected: ?string, url: ?string}>, allUrl: ?string}|null
     */
    private function recommendations(Business $business, CustomerContext $context, User $user, MenuEntitlements $entitlements): ?array
    {
        // paginateForCustomer() pages from the request's `page` input; the
        // dashboard always reads the first page, so a query string can never
        // choose which recommendations render. The framework's own resolvers
        // are restored immediately afterwards.
        Paginator::currentPageResolver(static fn () => 1);

        try {
            $page = $this->opportunities->paginateForCustomer($business, [
                'status' => OpportunityStatus::Open->value,
                'freshness' => OpportunityFreshness::Current->value,
            ]);
        } finally {
            PaginationState::resolveUsing(app());
        }

        $rows = collect($page->items())->take(self::RECOMMENDATION_LIMIT);

        if ($rows->isEmpty()) {
            return null;
        }

        $candidate = $context->selectedBusiness;
        $linksToAdvisor = $candidate !== null && $candidate->customerId === $context->userId && $candidate->isPrimary;

        $items = $rows->map(fn (Opportunity $opportunity) => [
            'title' => (string) $opportunity->title,
            'detected' => $opportunity->first_detected_at?->format('M j, Y'),
            'url' => $linksToAdvisor
                ? $this->links->url($context, $user, $entitlements, 'customer.opportunities.show', [(string) $opportunity->id], ['access_backend'])
                : null,
        ])->values()->all();

        return [
            'items' => $items,
            'allUrl' => $linksToAdvisor
                ? $this->links->url($context, $user, $entitlements, 'customer.opportunities.index', [], ['access_backend'])
                : null,
        ];
    }

    /**
     * §4.1, H-3 §2.5 — Business performance: the period the customer
     * selected, and exactly three canonical figures in KPI-priority order.
     *
     * New contacts and messages received are B5's own K4 and M3 for the
     * selected window; new conversations is Slice 2B's read model, which
     * remains the only reader of the conversation table. Each is compared
     * with the equal-length window immediately before it, and every
     * comparison is DESCRIPTIVE: a rise in any of these is a factual change,
     * never a win. No outbound volume, provider acceptance, failed send,
     * automation run, lead, booking or conversion figure appears here,
     * because none of them is either canonical or an outcome.
     *
     * The chart is loaded afterwards, by the browser, from B5's own series
     * endpoint for the same range: this request calculates no series.
     *
     * @param  array<int, string>  $scoped
     * @param  array{current: array<string, mixed>, previous: array<string, mixed>}  $comparison
     * @return array<string, mixed>
     */
    private function headlines(Business $business, CustomerContext $context, User $user, MenuEntitlements $entitlements, array $scoped, array $comparison, bool $rangeRejected): array
    {
        /** @var AnalyticsDateRange $currentRange */
        $currentRange = $comparison['current']['range'];
        /** @var AnalyticsDateRange $previousRange */
        $previousRange = $comparison['previous']['range'];
        $window = self::windowPhrase($currentRange);
        $before = self::previousNoun($previousRange);
        $items = [];

        $contacts = new HeadlineComparison($comparison['current']['contacts']->newInRange, $comparison['previous']['contacts']->newInRange);
        $items[] = new Headline(
            key: 'new_contacts',
            label: 'New contacts',
            figure: number_format($contacts->current),
            figureCaption: 'Added to this business, ' . $window . '.',
            comparison: $contacts,
            polarity: HeadlinePolarity::DescriptiveGrowth,
            comparisonSentence: $contacts->sentence($before),
            interpretation: match ($contacts->trend) {
                HeadlineTrend::Up => number_format($contacts->absoluteDelta) . ' more ' . ($contacts->absoluteDelta === 1 ? 'contact was' : 'contacts were') . ' added than in ' . $before . '.',
                HeadlineTrend::Down => number_format(abs($contacts->absoluteDelta)) . ' fewer ' . (abs($contacts->absoluteDelta) === 1 ? 'contact was' : 'contacts were') . ' added than in ' . $before . '.',
                HeadlineTrend::Unchanged => 'The same number of contacts were added as in ' . $before . '.',
            },
            judgement: null,
        );

        // Slice 2B's seam, both ranges bound exactly as AnalyticsDateRange
        // built them: startUtc/endUtc are already the storage-timezone bounds
        // localDayStartInStorageTz() produced, which is the timezone the
        // conversation rows' created_at is written in — so no second
        // conversion here (Correction 1, decision C). B5 never reads this
        // table, and Home never queries it directly.
        if ($entitlements->allows('conversations') && Gate::forUser($user)->allows('chat_box')) {
            $started = new HeadlineComparison(
                $this->conversations->startedCount($business, $currentRange->startUtc, $currentRange->endUtc),
                $this->conversations->startedCount($business, $previousRange->startUtc, $previousRange->endUtc),
            );

            $items[] = $this->volumeHeadline(
                'new_conversations',
                'New conversations',
                "Started in this business's inbox, " . $window . '.',
                $started,
                [
                    HeadlineTrend::Up->value => 'More conversations started than in ' . $before . '.',
                    HeadlineTrend::Down->value => 'Fewer conversations started than in ' . $before . '.',
                    HeadlineTrend::Unchanged->value => 'The same number of conversations started as in ' . $before . '.',
                ],
                'A change in volume is a fact, not a result.',
                $before,
            );
        }

        $received = new HeadlineComparison($comparison['current']['messages']->inbound, $comparison['previous']['messages']->inbound);
        $items[] = $this->volumeHeadline(
            'messages_received',
            'Messages received',
            'Received by this business, ' . $window . '.',
            $received,
            [
                HeadlineTrend::Up->value => 'More messages came in than in ' . $before . '.',
                HeadlineTrend::Down->value => 'Fewer messages came in than in ' . $before . '.',
                HeadlineTrend::Unchanged->value => 'As many messages came in as in ' . $before . '.',
            ],
            'A change in volume is a fact, not a result.',
            $before,
        );

        $rangeParameters = $currentRange->queryParameters();

        return [
            'items' => $items,
            'range' => $currentRange,
            'previousRange' => $previousRange,
            'rangeRejected' => $rangeRejected,
            'currentLabel' => self::rangeLabel($currentRange),
            'previousLabel' => self::rangeLabel($previousRange),
            'formAction' => route('user.home'),
            // B5's own series endpoint, for the very range shown above. The
            // browser fetches it after the page; Home loads no series itself.
            'seriesUrl' => $this->links->url($context, $user, $entitlements, 'customer.workspaces.businesses.analytics.series', array_merge($scoped, $rangeParameters), ['view_reports']),
            'resultsUrl' => $this->links->url($context, $user, $entitlements, 'customer.workspaces.businesses.analytics.overview', array_merge($scoped, $rangeParameters), ['view_reports']),
        ];
    }

    /**
     * §2.6 (H-4) — Visibility: is the website live, and is Google connected?
     *
     * Two facts, each already on the status row this request read, so the
     * band costs NO query of its own. Each tile needs both the entitlement
     * that sells the surface and the permission that opens it: a role who may
     * not see the Website page is not told about the website on Home either.
     *
     * The vocabulary is the one those pages already use, not a new one, and
     * nothing is inferred beyond the column: no visitors, no traffic, no SEO
     * score, no "healthy". A connection that was revoked is "Connection lost"
     * — it existed and broke; one that was never made, is still mid-connect,
     * or was switched off is "Not connected".
     *
     * @param  array<int, string>  $scoped
     * @return array<string, mixed>|null
     */
    private function visibility(CustomerContext $context, User $user, MenuEntitlements $entitlements, array $scoped, BusinessStatusRow $status): ?array
    {
        $items = [];

        if ($entitlements->allows('website_generation') && Gate::forUser($user)->allows('website')) {
            $items[] = [
                'key' => 'website',
                'label' => 'Website',
                'state' => match ($status->websiteStatus) {
                    'published' => 'Published',
                    'draft' => 'Draft',
                    'archived' => 'Archived',
                    default => 'Not created',
                },
                'note' => null,
                'severity' => null,
                'url' => $this->links->url($context, $user, $entitlements, 'customer.workspaces.businesses.website.show', $scoped, ['website'], 'website_generation'),
            ];
        }

        if ($entitlements->allows('google_business_profile_module') && Gate::forUser($user)->allows('view_google_business_profile')) {
            $lost = $status->googleConnectionState === 'revoked';
            $unhealthy = $status->unhealthyGoogleLocations;

            $items[] = [
                'key' => 'google',
                'label' => 'Google',
                'state' => match (true) {
                    $status->googleConnectionState === 'active' => 'Connected',
                    $lost => 'Connection lost',
                    default => 'Not connected',
                },
                'note' => $unhealthy > 0
                    ? $unhealthy . ' ' . ($unhealthy === 1 ? 'listing needs' : 'listings need') . ' attention'
                    : null,
                'severity' => $lost || $unhealthy > 0 ? AttentionSeverity::Warning : null,
                'url' => $this->links->url($context, $user, $entitlements, 'customer.workspaces.businesses.gbp.index', $scoped, ['view_google_business_profile'], 'google_business_profile_module'),
            ];
        }

        return $items === [] ? null : ['items' => $items];
    }

    /**
     * §2.6 (H-4) — Conversations: are customers writing, did we answer, and
     * is anyone waiting right now?
     *
     * Incoming and Replied describe the SELECTED period, the same window
     * Business performance shows. Awaiting reply is current state and is
     * deliberately NOT tied to the period: "three people are waiting" would
     * be a lie if it meant "three people were waiting last month".
     *
     * Every figure comes from Slice 2B's read model, which stays the only
     * reader of the conversation table — this presenter issues no SQL of its
     * own, and B5 still never touches it.
     *
     * @param  array<int, string>  $scoped
     * @return array<string, mixed>
     */
    private function conversations(Business $business, CustomerContext $context, User $user, MenuEntitlements $entitlements, array $scoped, AnalyticsDateRange $range): array
    {
        $window = self::windowPhrase($range);
        // One statement for the pair (§16), one for the current state.
        ['incoming' => $incoming, 'replied' => $replied] = $this->conversations->periodCounts($business, $range->startUtc, $range->endUtc);
        $awaiting = $this->conversations->awaitingReplyCount($business);
        $grace = max(0, (int) config('conversations.awaiting_reply_grace_minutes', 5));

        return [
            'items' => [
                [
                    'key' => 'incoming',
                    'label' => 'Incoming',
                    'figure' => number_format($incoming),
                    'caption' => 'Conversations a customer wrote in, ' . $window . '.',
                    'severity' => null,
                ],
                [
                    'key' => 'replied',
                    'label' => 'Replied',
                    'figure' => number_format($replied),
                    'caption' => 'Of those, the ones this business answered. A person or an automation both count.',
                    'severity' => null,
                ],
                [
                    'key' => 'awaiting_reply',
                    'label' => 'Awaiting reply',
                    'figure' => number_format($awaiting),
                    'caption' => $awaiting > 0
                        ? 'Waiting right now — the customer wrote last, more than ' . $grace . ' minutes ago.'
                        : 'Nobody is waiting for an answer right now.',
                    'severity' => $awaiting > 0 ? AttentionSeverity::Warning : null,
                ],
            ],
            'rangeLabel' => self::rangeLabel($range),
            'inboxUrl' => $this->links->url($context, $user, $entitlements, 'customer.workspaces.businesses.conversations.index', $scoped, ['chat_box'], 'conversations'),
        ];
    }

    /**
     * §2.6 (H-4) — Automations: completed and failed runs for the selected
     * period, from the B5 figures this request already loaded.
     *
     * There is no second automation analytics implementation here and no run
     * semantics of this slice's own: whatever `automationKpis()` currently
     * counts as an execution is exactly what shows. Drafts, saved definitions
     * and enrolments are not runs and are not counted, because that seam does
     * not count them. The band is absent when nothing ran and nothing failed.
     *
     * @param  array<int, string>  $scoped
     * @param  array{current: array<string, mixed>, previous: array<string, mixed>}  $comparison
     * @return array<string, mixed>|null
     */
    private function automations(CustomerContext $context, User $user, MenuEntitlements $entitlements, array $scoped, array $comparison): ?array
    {
        $kpis = $comparison['current']['automations'];

        if (! $kpis instanceof AutomationKpis) {
            return null;
        }

        $completed = $kpis->succeeded();
        $failed = $kpis->failed();

        if ($kpis->executionsInRange === 0 && $failed === 0) {
            return null;
        }

        /** @var AnalyticsDateRange $range */
        $range = $comparison['current']['range'];
        $window = self::windowPhrase($range);

        return [
            'items' => [
                [
                    'key' => 'completed',
                    'label' => 'Completed',
                    'figure' => number_format($completed),
                    'caption' => 'Runs that finished, ' . $window . '.',
                    'severity' => null,
                ],
                [
                    'key' => 'failed',
                    'label' => 'Failed',
                    'figure' => number_format($failed),
                    'caption' => $failed > 0 ? 'Runs that did not finish, ' . $window . '.' : 'Nothing failed, ' . $window . '.',
                    'severity' => $failed > 0 ? AttentionSeverity::Warning : null,
                ],
            ],
            'rangeLabel' => self::rangeLabel($range),
            // The same destination AttentionType::AutomationFailing remediates to.
            'reviewUrl' => $failed > 0
                ? $this->links->url($context, $user, $entitlements, 'customer.workspaces.businesses.automations.index', $scoped, ['automations'], 'automations')
                : null,
        ];
    }

    /**
     * §14 (H-5) — Recent work: the factual timeline.
     *
     * The reader decides WHAT happened; this method decides only whether this
     * actor can open each destination. When they cannot, the item still
     * renders — as plain text rather than a link, because the event happened
     * either way and hiding it would make the timeline lie by omission.
     *
     * @param  array<int, string>  $scoped
     * @return array<string, mixed>|null
     */
    private function recentWork(CustomerContext $context, User $user, MenuEntitlements $entitlements, array $scoped, Business $business): ?array
    {
        $items = [];

        foreach ($this->recentWorkReader->recent($business) as $item) {
            $items[] = [
                'key' => $item->key,
                'text' => $item->text,
                'at' => $item->at,
                'url' => $this->links->url(
                    $context,
                    $user,
                    $entitlements,
                    $item->routeName,
                    $item->businessScoped ? array_merge($scoped, $item->routeParameters) : $item->routeParameters,
                    $item->permissions,
                    $item->featureKey,
                ),
            ];
        }

        return $items === [] ? null : ['items' => $items];
    }

    /**
     * The Business performance window, from the customer's own query string.
     *
     * Shape and semantics are the Results rules, unchanged and unduplicated:
     * AnalyticsRangeRequest::ruleSet() for the shape, then
     * AnalyticsDateRange::fromInput() for the calendar semantics (strict
     * dates, no reversed range, at most MAX_CUSTOM_DAYS local dates). Home
     * never approximates a range it cannot honour: it falls back to its
     * default window and says so.
     *
     * @param  array<string, mixed>  $input
     * @return array{0: AnalyticsDateRange, 1: bool}  the window, and whether the request was refused
     */
    private function selectedRange(Business $business, array $input): array
    {
        $timezone = (string) ($business->timezone ?: config('app.timezone', 'UTC'));
        $default = AnalyticsDateRange::preset(BusinessDashboardAnalyticsPresenter::DEFAULT_PRESET, $timezone);

        $input = array_intersect_key($input, array_flip(['range', 'start', 'end']));

        if ($input === [] || ($input['range'] ?? null) === null) {
            return [$default, false];
        }

        try {
            $validated = Validator::make($input, AnalyticsRangeRequest::ruleSet())->validate();

            return [AnalyticsDateRange::fromInput($validated, $timezone), false];
        } catch (ValidationException) {
            return [$default, true];
        }
    }

    /**
     * The selected window as it reads INSIDE a sentence: "this month",
     * "last 30 days" — but a custom range keeps its own capitalisation,
     * because "aug 1, 2026 to aug 31, 2026" is not English.
     */
    private static function windowPhrase(AnalyticsDateRange $range): string
    {
        return $range->preset === AnalyticsDateRange::PRESET_CUSTOM
            ? $range->label()
            : strtolower($range->label());
    }

    /**
     * What the figures are compared against, always by its real length:
     * "the previous 10 days" for a 10-day window, whatever preset produced
     * it. Nothing ever says "30 days" about a window that is not 30 days.
     */
    private static function previousNoun(AnalyticsDateRange $previous): string
    {
        $days = $previous->days();

        return 'the previous ' . $days . ($days === 1 ? ' day' : ' days');
    }

    /**
     * A volume metric: descriptive copy only, never a judgement (§4.5).
     *
     * @param  array<string, string>  $copy  trend value => sentence
     */
    private function volumeHeadline(string $key, string $label, string $caption, HeadlineComparison $comparison, array $copy, string $note, string $previousNoun = 'the previous period'): Headline
    {
        return new Headline(
            key: $key,
            label: $label,
            figure: number_format($comparison->current),
            figureCaption: $caption,
            comparison: $comparison,
            polarity: HeadlinePolarity::Descriptive,
            comparisonSentence: $comparison->sentence($previousNoun),
            interpretation: $copy[$comparison->trend->value] . ' ' . $note,
            judgement: null,
        );
    }

    /**
     * §11 — at most four, each through the four-way rule, only canonical
     * Business-scoped routes. While viewing as a client nothing that costs,
     * funds, touches a provider or switches identity is offered.
     *
     * There is no "Send a message": the legacy outbound Send page is no longer
     * a customer destination (the Messages menu offers Inbox only), so Home
     * does not promote it either. After Open inbox and Add contact, the
     * remaining places go, in order, to: the team member's "Login as Parent"
     * (Correction 1, decision D), then one setup action proven by the same
     * status column that raised its attention item. Funding is not offered
     * here at all: billing is a Settings destination (H-1 §5).
     *
     * @param  array<int, string>  $scoped
     * @return array{items: array<int, DashboardAction>, parentMessage: ?string}
     */
    private function actions(CustomerContext $context, User $user, MenuEntitlements $entitlements, array $scoped, ?BusinessStatusRow $status): array
    {
        $actions = [];

        if ($url = $this->links->url($context, $user, $entitlements, 'customer.workspaces.businesses.conversations.index', $scoped, ['chat_box'], 'conversations')) {
            $actions[] = new DashboardAction('inbox', 'Open inbox', $url, 'inbox');
        }

        if ($url = $this->links->url($context, $user, $entitlements, 'customer.workspaces.businesses.people.add', $scoped, DashboardLinkGate::CONTACT_PERMISSIONS)) {
            $actions[] = new DashboardAction('add_contact', 'Add contact', $url, 'user-plus');
        }

        $parent = $this->parentSwitch->for($user, $context);
        $extras = [];

        if ($parent !== null) {
            $extras[] = new DashboardAction('login_as_parent', $parent['label'], $parent['url'], 'log-in', DashboardAction::KIND_ACCOUNT);
        }

        // No "Add funds": funding is a Settings → Billing action, and Home
        // promotes it only through the exception strip, when there is a real
        // billing problem to fix (§5).

        if ($status !== null) {
            $setup = $this->setupAction($context, $user, $entitlements, $scoped, $status);

            if ($setup !== null) {
                $extras[] = $setup;
            }
        }

        foreach ($extras as $extra) {
            if (count($actions) >= self::MAX_QUICK_ACTIONS) {
                break;
            }

            $actions[] = $extra;
        }

        return [
            'items' => array_slice($actions, 0, self::MAX_QUICK_ACTIONS),
            'parentMessage' => $parent !== null && in_array('login_as_parent', array_map(fn (DashboardAction $a) => $a->key, $actions), true)
                ? $parent['message']
                : null,
        ];
    }

    /**
     * One high-value setup action, only where the status column that raises
     * the matching attention item proves it (§11). Reconnecting Google is a
     * provider action, so it is never offered while viewing as a client.
     *
     * @param  array<int, string>  $scoped
     */
    private function setupAction(CustomerContext $context, User $user, MenuEntitlements $entitlements, array $scoped, BusinessStatusRow $status): ?DashboardAction
    {
        $types = $status->attentionTypes();

        if (in_array(AttentionType::WebsiteUnpublished, $types, true)
            && ($url = $this->links->url($context, $user, $entitlements, 'customer.workspaces.businesses.website.show', $scoped, ['website'], 'website_generation'))) {
            return new DashboardAction('publish_website', 'Publish your website', $url, 'globe');
        }

        if (! $context->isViewingAsClient() && in_array(AttentionType::GoogleConnectionLost, $types, true)
            && ($url = $this->links->url($context, $user, $entitlements, 'customer.workspaces.businesses.gbp.index', $scoped, ['view_google_business_profile'], 'google_business_profile_module'))) {
            return new DashboardAction('reconnect_google', 'Reconnect Google', $url, 'map-pin', DashboardAction::KIND_PROVIDER);
        }

        return null;
    }

    private static function percent(float $rate): string
    {
        return number_format($rate, 1) . '%';
    }

    /** "Aug 13 – Sep 11": the range's own Business-local dates. */
    public static function rangeLabel(AnalyticsDateRange $range): string
    {
        return $range->startLocal->format('M j') . ' – ' . $range->endLocal->format('M j');
    }
}
